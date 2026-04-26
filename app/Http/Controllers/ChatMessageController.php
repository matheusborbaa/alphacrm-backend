<?php

namespace App\Http\Controllers;

use App\Events\ChatConversationReadUpdated;
use App\Events\ChatMessageDeleted;
use App\Events\ChatMessageEdited;
use App\Events\ChatMessageSent;
use App\Models\ChatConversation;
use App\Models\ChatConversationRead;
use App\Models\ChatMessage;
use App\Models\ChatMessageAttachment;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ChatAttachmentResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatMessageController extends Controller
{
    public function __construct(private ChatAttachmentResolver $resolver) {}

    public function index(Request $request, int $conversationId): JsonResponse
    {
        $conversation = ChatConversation::findOrFail($conversationId);

        $this->ensureCanRead($conversation);

        $data = $request->validate([
            'before_id' => ['nullable', 'integer', 'min:1'],
            'after_id'  => ['nullable', 'integer', 'min:1'],
            'limit'     => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $limit = (int) ($data['limit'] ?? 50);

        $query = ChatMessage::query()

            ->withTrashed()
            ->with('attachments')
            ->with('replyTo:id,sender_id,body')
            ->where('conversation_id', $conversationId);

        if (!empty($data['after_id'])) {
            $query->where('id', '>', (int) $data['after_id']);
        }

        if (!empty($data['before_id'])) {
            $query->where('id', '<', (int) $data['before_id']);
            $messages = $query->orderByDesc('id')->limit($limit)->get()->reverse()->values();
        } elseif (!empty($data['after_id'])) {
            $messages = $query->orderBy('id')->limit($limit)->get();
        } else {

            $messages = $query->orderByDesc('id')->limit($limit)->get()->reverse()->values();
        }

        $exposeReadReceipts = $this->canExposeReadReceipts($conversation);

        $payload = $messages->map(
            fn (ChatMessage $m) => $this->buildMessagePayload($m, $exposeReadReceipts)
        );

        return response()->json([
            'messages'  => $payload,
            'has_more'  => $messages->count() === $limit,

            'peer_read' => $exposeReadReceipts
                ? $this->loadPeerReadState($conversation)
                : ['last_read_message_id' => 0, 'last_read_at' => null],
        ]);
    }

    public function store(Request $request, int $conversationId): JsonResponse
    {
        $conversation = ChatConversation::findOrFail($conversationId);
        $this->ensureParticipant($conversation);

        $data = $request->validate([
            'body'                 => ['nullable', 'string', 'max:5000'],
            'pending_upload_ids'   => ['nullable', 'array', 'max:10'],
            'pending_upload_ids.*' => ['integer', 'min:1'],
            'references'           => ['nullable', 'array', 'max:10'],
            'references.*.type'    => ['required_with:references', 'string', 'in:lead,empreendimento,lead_document'],
            'references.*.id'      => ['required_with:references', 'integer', 'min:1'],

            'reply_to_id'          => ['nullable', 'integer', 'min:1', 'exists:chat_messages,id'],
        ]);

        $me = (int) Auth::id();
        $body = isset($data['body']) ? trim($data['body']) : '';
        $pendingIds = $data['pending_upload_ids'] ?? [];
        $references = $data['references'] ?? [];
        $replyToId  = $data['reply_to_id'] ?? null;

        if ($replyToId !== null) {
            $parentConvId = ChatMessage::where('id', $replyToId)->value('conversation_id');
            if ((int) $parentConvId !== (int) $conversation->id) {
                return response()->json(['message' => 'Reply inválido.'], 422);
            }
        }

        $hasAnyAttachment = !empty($pendingIds) || !empty($references);
        if ($body === '' && !$hasAnyAttachment) {
            return response()->json(['message' => 'Mensagem vazia.'], 422);
        }

        $peerId = $conversation->user_a_id === $me ? $conversation->user_b_id : $conversation->user_a_id;
        $peerUser = User::find($peerId);

        $message = DB::transaction(function () use ($conversation, $me, $body, $pendingIds, $references, $peerUser, $replyToId) {

            $msg = ChatMessage::create([
                'conversation_id' => $conversation->id,
                'sender_id'       => $me,
                'body'            => $body,
                'reply_to_id'     => $replyToId,
            ]);

            if (!empty($pendingIds)) {
                $drafts = ChatMessageAttachment::whereIn('id', $pendingIds)
                    ->where('uploader_user_id', $me)
                    ->whereNull('message_id')
                    ->where('type', ChatMessageAttachment::TYPE_UPLOAD)
                    ->lockForUpdate()
                    ->get();

                $adoptedIds = $drafts->pluck('id')->all();
                $missingIds = array_diff($pendingIds, $adoptedIds);
                if (!empty($missingIds)) {

                    abort(422, 'Anexos inválidos ou expirados: ' . implode(', ', $missingIds));
                }

                ChatMessageAttachment::whereIn('id', $adoptedIds)
                    ->update(['message_id' => $msg->id]);
            }

            foreach ($references as $ref) {
                $resolved = $this->resolver->resolveReference($ref['type'], (int) $ref['id'], $peerUser);
                ChatMessageAttachment::create([
                    'message_id'       => $msg->id,
                    'type'             => $resolved['type'],
                    'attachable_id'    => $resolved['attachable_id'],
                    'snapshot'         => $resolved['snapshot'],
                    'uploader_user_id' => $me,
                ]);
            }

            ChatConversation::where('id', $conversation->id)
                ->update(['last_message_at' => $msg->created_at]);

            ChatConversationRead::updateOrCreate(
                ['user_id' => $me, 'conversation_id' => $conversation->id],
                ['last_read_message_id' => $msg->id, 'last_read_at' => now()]
            );

            return $msg->load(['attachments', 'replyTo:id,sender_id,body']);
        });

        try {
            $peerId = $conversation->user_a_id === $me
                ? $conversation->user_b_id
                : $conversation->user_a_id;
            if ($peerId) {
                event(new ChatMessageSent($message, (int) $peerId));
            }
        } catch (\Throwable $e) {
            Log::warning('Falha ao broadcast ChatMessageSent', [
                'message_id' => $message->id,
                'error'      => $e->getMessage(),
            ]);
        }

        return response()->json($this->buildMessagePayload($message), 201);
    }

    public function pinned(Request $request, int $conversationId): JsonResponse
    {
        $conversation = ChatConversation::findOrFail($conversationId);

        $this->ensureCanRead($conversation);

        $messages = ChatMessage::query()
            ->with('attachments')
            ->with('replyTo:id,sender_id,body')
            ->where('conversation_id', $conversationId)
            ->where('is_pinned', true)
            ->orderByDesc('pinned_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $payload = $messages->map(fn (ChatMessage $m) => $this->buildMessagePayload($m));

        return response()->json(['messages' => $payload]);
    }

    public function togglePin(Request $request, int $messageId): JsonResponse
    {
        $message = ChatMessage::findOrFail($messageId);
        $conversation = ChatConversation::findOrFail($message->conversation_id);
        $this->ensureParticipant($conversation);

        $me = (int) Auth::id();

        if ($request->isMethod('delete')) {
            $message->is_pinned = false;
            $message->pinned_at = null;
            $message->pinned_by_user_id = null;
        } else {
            $message->is_pinned = true;
            $message->pinned_at = now();
            $message->pinned_by_user_id = $me;
        }
        $message->save();

        return response()->json($this->buildMessagePayload($message->load('attachments')));
    }

    public function markRead(Request $request, int $conversationId): JsonResponse
    {
        $conversation = ChatConversation::findOrFail($conversationId);
        $this->ensureParticipant($conversation);

        $data = $request->validate([
            'last_read_message_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $me = (int) Auth::id();

        $targetId = $data['last_read_message_id'] ?? null;
        if ($targetId === null) {
            $targetId = ChatMessage::where('conversation_id', $conversationId)->max('id') ?? 0;
        }
        $targetId = (int) $targetId;

        $read = ChatConversationRead::firstOrNew(
            ['user_id' => $me, 'conversation_id' => $conversationId]
        );

        $current = (int) ($read->last_read_message_id ?? 0);
        if ($targetId > $current) {
            $read->last_read_message_id = $targetId;
            $read->last_read_at = now();
            $read->save();
        } elseif (!$read->exists) {
            $read->last_read_message_id = $current;
            $read->last_read_at = now();
            $read->save();
        }

        $user = $request->user();
        $exposeReads = (bool) ($user->chat_read_receipts ?? true);
        if ($exposeReads && $targetId > 0) {
            ChatMessage::where('conversation_id', $conversationId)
                ->where('sender_id', '!=', $me)
                ->where('id', '<=', $targetId)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        try {
            if ($exposeReads) {
                event(new ChatConversationReadUpdated(
                    $conversation,
                    $me,
                    (int) $read->last_read_message_id,
                    $read->last_read_at?->toISOString(),
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('Falha ao broadcast ChatConversationReadUpdated', [
                'conversation_id' => $conversationId,
                'error'           => $e->getMessage(),
            ]);
        }

        return response()->json([
            'conversation_id'      => $conversationId,
            'last_read_message_id' => (int) $read->last_read_message_id,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q'               => ['required', 'string', 'min:2', 'max:200'],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
            'limit'           => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $q     = trim($data['q']);
        $limit = (int) ($data['limit'] ?? 30);
        $me    = (int) Auth::id();

        $convQuery = ChatConversation::query()
            ->where(function ($q) use ($me) {
                $q->where('user_a_id', $me)->orWhere('user_b_id', $me);
            });

        if (!empty($data['conversation_id'])) {
            $convQuery->where('id', (int) $data['conversation_id']);
        }

        $conversationIds = $convQuery->pluck('id');

        if ($conversationIds->isEmpty()) {
            return response()->json([
                'query'   => $q,
                'results' => [],
                'count'   => 0,
            ]);
        }

        $rows = ChatMessage::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where('body', 'LIKE', '%' . $this->escapeLike($q) . '%')
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id', 'conversation_id', 'sender_id', 'body', 'created_at',
            ]);

        $convs = ChatConversation::with(['userA:id,name,avatar', 'userB:id,name,avatar'])
            ->whereIn('id', $rows->pluck('conversation_id')->unique())
            ->get()
            ->keyBy('id');

        $results = $rows->map(function (ChatMessage $m) use ($q, $convs, $me) {
            $conv  = $convs->get($m->conversation_id);
            $other = $conv ? $conv->otherParticipant($me) : null;

            return [
                'id'              => $m->id,
                'conversation_id' => $m->conversation_id,
                'sender_id'       => $m->sender_id,
                'is_mine'         => $m->sender_id === $me,
                'created_at'      => $m->created_at,
                'snippet'         => $this->buildSnippet($m->body, $q),
                'other_user'      => $other ? [
                    'id'     => $other->id,
                    'name'   => $other->name,
                    'avatar' => $other->avatar,
                ] : null,
            ];
        });

        return response()->json([
            'query'   => $q,
            'results' => $results,
            'count'   => $results->count(),
        ]);
    }

    public function update(Request $request, int $messageId): JsonResponse
    {

        $message = ChatMessage::findOrFail($messageId);
        $conversation = ChatConversation::findOrFail($message->conversation_id);
        $this->ensureParticipant($conversation);

        $me = (int) Auth::id();

        if ($message->sender_id !== $me) {
            return response()->json([
                'message' => 'Você só pode editar suas próprias mensagens.',
            ], 403);
        }

        $minutesSince = $message->created_at->diffInMinutes(now());
        if ($minutesSince > 15) {
            return response()->json([
                'message' => 'Janela de edição expirou (15 minutos).',
                'code'    => 'edit_window_expired',
            ], 422);
        }

        if ($message->read_at !== null) {
            return response()->json([
                'message' => 'Mensagem já foi lida e não pode ser editada.',
                'code'    => 'already_read',
            ], 422);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        $newBody = trim($data['body']);
        if ($newBody === '') {
            return response()->json([
                'message' => 'Mensagem vazia.',
            ], 422);
        }

        if ($newBody === $message->body) {
            return response()->json($this->buildMessagePayload(
                $message->load(['attachments', 'replyTo:id,sender_id,body'])
            ));
        }

        $message->body      = $newBody;
        $message->edited_at = now();
        $message->save();

        try {
            event(new ChatMessageEdited($message));
        } catch (\Throwable $e) {
            Log::warning('Falha ao broadcast ChatMessageEdited', [
                'message_id' => $message->id,
                'error'      => $e->getMessage(),
            ]);
        }

        return response()->json($this->buildMessagePayload(
            $message->load(['attachments', 'replyTo:id,sender_id,body'])
        ));
    }

    public function destroy(Request $request, int $messageId): JsonResponse
    {
        $message = ChatMessage::findOrFail($messageId);
        $conversation = ChatConversation::findOrFail($message->conversation_id);

        $user = Auth::user();
        if (!$user) abort(401);
        $me   = (int) $user->id;
        $role = strtolower(trim((string) ($user->role ?? '')));
        $isAdmin = $role === 'admin';
        $isAuthor = $message->sender_id === $me;

        if (!$isAdmin) {
            $this->ensureParticipant($conversation);

            if (!$isAuthor) {
                return response()->json([
                    'message' => 'Você só pode apagar suas próprias mensagens.',
                ], 403);
            }

            if ($message->read_at !== null) {
                return response()->json([
                    'message' => 'Mensagem já foi lida e não pode ser apagada.',
                    'code'    => 'already_read',
                ], 422);
            }
        }

        if ($isAdmin && !$isAuthor) {
            try {
                AuditService::log(
                    'chat_admin_delete',
                    'ChatMessage',
                    $message->id,
                    $me,
                    null,
                    [
                        'conversation_id' => $message->conversation_id,
                        'sender_id'       => $message->sender_id,
                        'was_read'        => $message->read_at !== null,
                    ],
                    'chat_audit'
                );
            } catch (\Throwable $e) {

            }
        }

        $message->delete();

        try {
            event(new ChatMessageDeleted($message));
        } catch (\Throwable $e) {
            Log::warning('Falha ao broadcast ChatMessageDeleted', [
                'message_id' => $message->id,
                'error'      => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message_id' => $message->id,
            'deleted_at' => $message->deleted_at?->toISOString(),
        ]);
    }

    private function buildSnippet(?string $body, string $query): string
    {
        $body = trim((string) $body);
        if ($body === '') return '';

        $len = mb_strlen($body);
        if ($len <= 160) return $body;

        $pos = mb_stripos($body, $query);
        if ($pos === false) {
            return mb_substr($body, 0, 160) . '…';
        }

        $start  = max(0, $pos - 60);
        $prefix = $start > 0 ? '…' : '';
        $suffix = ($start + 160 < $len) ? '…' : '';
        return $prefix . mb_substr($body, $start, 160) . $suffix;
    }

    private function escapeLike(string $v): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
    }

    private function buildMessagePayload(ChatMessage $m, bool $exposeRead = true): array
    {

        $reply = null;
        if ($m->reply_to_id && $m->replyTo) {
            $parentBody = (string) ($m->replyTo->body ?? '');
            $reply = [
                'id'        => $m->replyTo->id,
                'sender_id' => $m->replyTo->sender_id,

                'snippet'   => mb_strlen($parentBody) > 120
                    ? mb_substr($parentBody, 0, 120) . '…'
                    : $parentBody,
            ];
        } elseif ($m->reply_to_id) {

            $reply = ['id' => null, 'sender_id' => null, 'snippet' => null];
        }

        $isDeleted = $m->trashed();

        return [
            'id'                  => $m->id,
            'conversation_id'     => $m->conversation_id,
            'sender_id'           => $m->sender_id,
            'body'                => $isDeleted ? '' : $m->body,
            'created_at'          => $m->created_at,

            'read_at'             => $exposeRead ? $m->read_at : null,

            'edited_at'           => $isDeleted ? null : $m->edited_at,

            'deleted_at'          => $m->deleted_at,

            'reply_to'            => $isDeleted ? null : $reply,

            'is_pinned'           => $isDeleted ? false : (bool) $m->is_pinned,
            'pinned_at'           => $isDeleted ? null : $m->pinned_at,
            'pinned_by_user_id'   => $isDeleted ? null : $m->pinned_by_user_id,
            'attachments'         => $isDeleted
                ? []
                : ($m->attachments
                    ? $m->attachments->map(fn ($a) => $a->buildPayload())->all()
                    : []),
        ];
    }

    private function canExposeReadReceipts(ChatConversation $conversation): bool
    {
        $me     = Auth::user();
        $peerId = $conversation->user_a_id === ($me->id ?? null)
            ? $conversation->user_b_id
            : $conversation->user_a_id;
        $peer = $peerId ? User::find($peerId) : null;

        $myFlag   = $me   ? (bool) ($me->chat_read_receipts   ?? true) : true;
        $peerFlag = $peer ? (bool) ($peer->chat_read_receipts ?? true) : true;

        return $myFlag && $peerFlag;
    }

    private function ensureParticipant(ChatConversation $conversation): void
    {
        $me = (int) Auth::id();
        if ($conversation->user_a_id !== $me && $conversation->user_b_id !== $me) {
            abort(403, 'Você não faz parte dessa conversa.');
        }
    }

    private function ensureCanRead(ChatConversation $conversation): void
    {
        $user = Auth::user();
        if (!$user) abort(401);

        $me = (int) $user->id;
        $isParticipant = $conversation->user_a_id === $me || $conversation->user_b_id === $me;
        if ($isParticipant) return;

        $role = strtolower(trim((string) ($user->role ?? '')));
        if ($role === 'admin') {

            try {
                AuditService::log(
                    'chat_audit_read',
                    'ChatConversation',
                    $conversation->id,
                    $me,
                    null,
                    [
                        'participants' => [$conversation->user_a_id, $conversation->user_b_id],
                    ],
                    'chat_audit'
                );
            } catch (\Throwable $e) {

            }
            return;
        }

        abort(403, 'Você não faz parte dessa conversa.');
    }

    private function loadPeerReadState(ChatConversation $conversation): array
    {
        $me     = (int) Auth::id();
        $peerId = $conversation->user_a_id === $me
            ? $conversation->user_b_id
            : $conversation->user_a_id;

        if (!$peerId) {

            return ['last_read_message_id' => 0, 'last_read_at' => null];
        }

        $row = ChatConversationRead::where('user_id', $peerId)
            ->where('conversation_id', $conversation->id)
            ->first();

        return [
            'last_read_message_id' => (int) ($row->last_read_message_id ?? 0),
            'last_read_at'         => $row?->last_read_at?->toISOString(),
        ];
    }
}
