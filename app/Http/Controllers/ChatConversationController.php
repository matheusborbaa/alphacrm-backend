<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatConversationRead;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ChatConversationController extends Controller
{

    public function index(Request $request): JsonResponse
    {
        $me = (int) Auth::id();

        $auditMode = $this->isAuditRequest($request);

        $conversations = ChatConversation::query()
            ->when(!$auditMode, function ($q) use ($me) {

                $q->where(function ($qq) use ($me) {
                    $qq->where('user_a_id', $me)->orWhere('user_b_id', $me);
                });
            })
            ->with([
                'userA:id,name,email,avatar',
                'userB:id,name,email,avatar',

                'lastMessage:id,conversation_id,sender_id,body,created_at',

                'lastMessage.attachments:id,message_id,type,original_name,snapshot',
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        $convIds = $conversations->pluck('id')->all();
        $reads   = ChatConversationRead::where('user_id', $me)
            ->whereIn('conversation_id', $convIds)
            ->get()
            ->keyBy('conversation_id');

        $result = $conversations->map(function (ChatConversation $c) use ($me, $reads, $auditMode) {
            $other = $c->otherParticipant($me);

            $last = $c->lastMessage->first();

            $unreadCount = 0;
            if (!$auditMode) {
                $read = $reads->get($c->id);
                $lastReadId = $read?->last_read_message_id ?? 0;
                $unreadCount = ChatMessage::where('conversation_id', $c->id)
                    ->where('sender_id', '!=', $me)
                    ->where('id', '>', $lastReadId)
                    ->count();
            }

            $payload = [
                'id'              => $c->id,
                'last_message_at' => $c->last_message_at,
                'other_user'      => $other ? [
                    'id'     => $other->id,
                    'name'   => $other->name,
                    'email'  => $other->email,
                    'avatar' => $other->avatar,
                ] : null,
                'last_message'    => $last ? [
                    'id'         => $last->id,

                    'body'       => $this->buildLastMessagePreview($last),
                    'sender_id'  => $last->sender_id,
                    'is_mine'    => $last->sender_id === $me,
                    'created_at' => $last->created_at,
                ] : null,
                'unread_count'    => $unreadCount,
            ];

            if ($auditMode) {
                $payload['participants'] = [
                    $c->userA ? ['id' => $c->userA->id, 'name' => $c->userA->name, 'email' => $c->userA->email, 'avatar' => $c->userA->avatar] : null,
                    $c->userB ? ['id' => $c->userB->id, 'name' => $c->userB->name, 'email' => $c->userB->email, 'avatar' => $c->userB->avatar] : null,
                ];
                $payload['audit_mode'] = true;
            }

            return $payload;
        });

        return response()->json($result);
    }

    private function isAuditRequest(Request $request): bool
    {
        if (!$request->boolean('audit')) return false;
        $user = $request->user();
        return $user && strtolower(trim((string) ($user->role ?? ''))) === 'admin';
    }

    public function store(Request $request): JsonResponse
    {
        $me = (int) Auth::id();

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $other = (int) $data['user_id'];

        if ($other === $me) {
            return response()->json([
                'message' => 'Não é possível abrir conversa consigo mesmo.',
            ], 422);
        }

        $otherUser = User::find($other);
        if (!$otherUser || ($otherUser->status ?? null) === 'inactive') {
            return response()->json([
                'message' => 'Usuário indisponível.',
            ], 422);
        }

        [$aId, $bId] = ChatConversation::canonicalPair($me, $other);

        $conversation = ChatConversation::firstOrCreate(
            ['user_a_id' => $aId, 'user_b_id' => $bId],
            ['last_message_at' => null]
        );

        return response()->json([
            'id'              => $conversation->id,
            'last_message_at' => $conversation->last_message_at,
            'other_user'      => [
                'id'     => $otherUser->id,
                'name'   => $otherUser->name,
                'email'  => $otherUser->email,
                'avatar' => $otherUser->avatar,
            ],
            'unread_count'    => 0,
        ], $conversation->wasRecentlyCreated ? 201 : 200);
    }

    private function buildLastMessagePreview(ChatMessage $msg): string
    {
        $body = trim($msg->body ?? '');
        if ($body !== '') return $body;

        $atts = $msg->attachments ?? collect();
        if ($atts->isEmpty()) return '';

        $first = $atts->first();
        $label = $this->attachmentLabel($first);
        $extra = $atts->count() > 1 ? ' +' . ($atts->count() - 1) : '';
        return '📎 ' . $label . $extra;
    }

    private function attachmentLabel($att): string
    {
        $s = $att->snapshot ?? [];
        switch ($att->type) {
            case 'upload':         return $att->original_name ?? ($s['original_name'] ?? 'arquivo');
            case 'lead':           return 'Lead: ' . ($s['name'] ?? '#' . $att->attachable_id);
            case 'empreendimento': return 'Empr: ' . ($s['name'] ?? '#' . $att->attachable_id);
            case 'lead_document':  return 'Doc: ' . ($s['original_name'] ?? '#' . $att->attachable_id);
            default:               return 'anexo';
        }
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $me = (int) Auth::id();

        $total = DB::table('chat_conversations as c')
            ->join('chat_messages as m', 'm.conversation_id', '=', 'c.id')
            ->leftJoin('chat_conversation_reads as r', function ($join) use ($me) {
                $join->on('r.conversation_id', '=', 'c.id')
                     ->where('r.user_id', '=', $me);
            })
            ->where(function ($q) use ($me) {
                $q->where('c.user_a_id', $me)->orWhere('c.user_b_id', $me);
            })
            ->where('m.sender_id', '!=', $me)
            ->where(function ($q) {

                $q->whereColumn('m.id', '>', 'r.last_read_message_id')
                  ->orWhereNull('r.last_read_message_id');
            })
            ->count();

        return response()->json(['total' => $total]);
    }
}
