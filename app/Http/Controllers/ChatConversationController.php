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

/**
 * Controller de conversas (DM 1-a-1) do chat interno.
 *
 * Endpoints expostos em routes/api.php sob prefixo /chat/conversations.
 *
 * Autorização: todo usuário autenticado pode abrir DM com qualquer outro
 * usuário ATIVO do sistema. Não há restrição por role — parte do produto
 * é justamente corretor falar direto com gestor.
 */
class ChatConversationController extends Controller
{
    /**
     * Lista as conversas do usuário logado, ordenadas pela última mensagem
     * (mais recente em cima). Traz:
     *  - other_user: {id, name, email, photo_url} do outro participante
     *  - last_message: {body, sender_id, created_at} ou null
     *  - last_message_at (pra ordenação no cliente, reforço)
     *  - unread_count: número de msgs do outro participante ainda não lidas
     *
     * Paginação não é necessária aqui — usuário típico tem <100 conversas.
     * Se virar problema, trocar por cursor().
     */
    public function index(Request $request): JsonResponse
    {
        $me = (int) Auth::id();

        $conversations = ChatConversation::query()
            ->where(function ($q) use ($me) {
                $q->where('user_a_id', $me)->orWhere('user_b_id', $me);
            })
            ->with([
                'userA:id,name,email,avatar',
                'userB:id,name,email,avatar',
                // carregamos só a última mensagem de cada conversa via
                // lastMessage() (latest), mas Eager load com hasMany
                // traz todas. Alternativa: subquery. Pra MVP, limitamos
                // manualmente depois no map().
                'lastMessage:id,conversation_id,sender_id,body,created_at',
                // Sprint 2: carrega anexos da última msg pra gerar preview
                // "📎 Anexo" quando o body vem vazio.
                'lastMessage.attachments:id,message_id,type,original_name,snapshot',
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id') // tiebreaker pra conversas sem mensagem
            ->get();

        // Carrega registros de leitura do user em UMA query, indexa por conv_id.
        $convIds = $conversations->pluck('id')->all();
        $reads   = ChatConversationRead::where('user_id', $me)
            ->whereIn('conversation_id', $convIds)
            ->get()
            ->keyBy('conversation_id');

        $result = $conversations->map(function (ChatConversation $c) use ($me, $reads) {
            $other = $c->otherParticipant($me);

            // lastMessage() retorna a coleção ordenada desc; pega o primeiro.
            $last = $c->lastMessage->first();

            // Unread = msgs do OUTRO com id > last_read_message_id do user.
            // N+1 aqui: 1 count por conversa. Aceitável pra MVP (<100 convs).
            // Se virar gargalo, migrar pra subquery única com CASE sum.
            $read = $reads->get($c->id);
            $lastReadId = $read?->last_read_message_id ?? 0;
            $unreadCount = ChatMessage::where('conversation_id', $c->id)
                ->where('sender_id', '!=', $me)
                ->where('id', '>', $lastReadId)
                ->count();

            return [
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
                    // Preview: se body vazio mas tem anexo, mostra label curto
                    // tipo "📎 Arquivo (contrato.pdf)" ou "📎 Lead: Fulano".
                    // Evita linha vazia na sidebar.
                    'body'       => $this->buildLastMessagePreview($last),
                    'sender_id'  => $last->sender_id,
                    'is_mine'    => $last->sender_id === $me,
                    'created_at' => $last->created_at,
                ] : null,
                'unread_count'    => $unreadCount,
            ];
        });

        return response()->json($result);
    }

    /**
     * Abre (ou recupera) uma conversa 1-a-1 com o user_id informado.
     *
     * Usa ordenação canônica (menor id, maior id) + firstOrCreate, então
     * é idempotente mesmo se duas abas do mesmo usuário chamarem ao mesmo
     * tempo (unique index no DB é o guardrail final).
     */
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

        // Bloqueia DM com usuário inativo — símbolo claro que a pessoa saiu
        // da empresa. Conversas antigas continuam visíveis na index(), mas
        // não dá pra iniciar nova.
        $otherUser = User::find($other);
        if (!$otherUser || ($otherUser->status ?? null) === 'inactive') {
            return response()->json([
                'message' => 'Usuário indisponível.',
            ], 422);
        }

        [$aId, $bId] = ChatConversation::canonicalPair($me, $other);

        // firstOrCreate atômico + unique index cobre race entre duas requests.
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

    /**
     * Monta preview textual da última mensagem pra sidebar. Quando body
     * é vazio (msg só com anexo), compõe label curto a partir do primeiro
     * anexo. Múltiplos anexos: mostra o primeiro + "+N".
     */
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

    /**
     * Total consolidado de mensagens não-lidas do usuário logado, somando
     * todas as conversas. Consumido pelo badge global na sidebar
     * (polling curto, todas as páginas).
     *
     * Resposta: {"total": N}
     *
     * Implementação: soma por conversa de (msgs do outro com id > last_read_id).
     * Usa uma única query agregada com join — não faz N+1.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $me = (int) Auth::id();

        // Conversas onde o user participa + reads dele (LEFT JOIN — conversa
        // sem registro de leitura conta TODAS as msgs como não-lidas).
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
                // msg.id > last_read_message_id (ou last_read_message_id IS NULL)
                $q->whereColumn('m.id', '>', 'r.last_read_message_id')
                  ->orWhereNull('r.last_read_message_id');
            })
            ->count();

        return response()->json(['total' => $total]);
    }
}
