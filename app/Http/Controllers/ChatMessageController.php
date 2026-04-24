<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatConversationRead;
use App\Models\ChatMessage;
use App\Models\ChatMessageAttachment;
use App\Models\User;
use App\Services\ChatAttachmentResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Controller de mensagens do chat interno.
 *
 * Endpoints sob /chat/conversations/{id}/messages:
 *   GET  → lista paginada (padrão: últimas 50, aceita `before_id` pra
 *          carregar histórico mais antigo ao rolar pra cima)
 *   POST → envia nova mensagem + bumpa last_message_at na conversa
 *
 * Endpoint sob /chat/conversations/{id}/read:
 *   POST → upsert do last_read_message_id do user (Sprint 3 — não-lidas)
 *
 * Sprint 2 — Anexos:
 *  - index eager-loada attachments da msg, retorna no payload
 *  - store aceita:
 *      - pending_upload_ids: ids de anexos draft (type=upload) pra adotar
 *      - references: [{type, id}] pros 3 tipos de referência (lead, emp, doc)
 *    A msg pode ter body vazio SE tiver pelo menos 1 anexo.
 *
 * Autorização: `ensureParticipant()` barra qualquer request de usuário
 * que não faz parte dos 2 participantes da conversa.
 */
class ChatMessageController extends Controller
{
    public function __construct(private ChatAttachmentResolver $resolver) {}

    /**
     * Lista mensagens da conversa, incluindo anexos.
     *
     * Query params:
     *  - before_id (int): retorna mensagens com id < before_id (scroll histórico).
     *  - after_id  (int): retorna mensagens com id > after_id (polling delta).
     *  - limit     (int, default 50, max 200)
     *
     * Ordem de resposta: crescente por id (cronológica).
     */
    public function index(Request $request, int $conversationId): JsonResponse
    {
        $conversation = ChatConversation::findOrFail($conversationId);
        $this->ensureParticipant($conversation);

        $data = $request->validate([
            'before_id' => ['nullable', 'integer', 'min:1'],
            'after_id'  => ['nullable', 'integer', 'min:1'],
            'limit'     => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $limit = (int) ($data['limit'] ?? 50);

        $query = ChatMessage::query()
            ->with('attachments') // Sprint 2: eager-load — evita N+1 no render
            ->where('conversation_id', $conversationId);

        if (!empty($data['after_id'])) {
            $query->where('id', '>', (int) $data['after_id']);
        }

        if (!empty($data['before_id'])) {
            $query->where('id', '<', (int) $data['before_id']);
            $messages = $query->orderByDesc('id')->limit($limit)->get()->reverse()->values();
        } else {
            $messages = $query->orderBy('id')->limit($limit)->get();
        }

        $payload = $messages->map(fn (ChatMessage $m) => $this->buildMessagePayload($m));

        return response()->json([
            'messages'  => $payload,
            'has_more'  => $messages->count() === $limit,
            // Sprint 3.8a — leitura do OUTRO participante. Frontend usa isso
            // pra decidir ✓ (enviada) vs ✓✓ (lida) em cada msg minha. Cada
            // polling atualiza esse cursor, então o indicador se acende na
            // hora que o peer abre a conversa.
            'peer_read' => $this->loadPeerReadState($conversation),
        ]);
    }

    /**
     * Envia nova mensagem. Aceita body + pending uploads (drafts) + references.
     *
     * Regra: msg precisa ter body OU anexo. Vazio total é 422.
     *
     * Transação:
     *   1. Cria ChatMessage
     *   2. Para cada pending_upload_id: valida (draft, dono=me), seta message_id
     *   3. Para cada reference: resolver gera snapshot + cria ChatMessageAttachment
     *   4. Bumpa conversation.last_message_at
     *   5. Avança last_read do sender
     */
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
        ]);

        $me = (int) Auth::id();
        $body = isset($data['body']) ? trim($data['body']) : '';
        $pendingIds = $data['pending_upload_ids'] ?? [];
        $references = $data['references'] ?? [];

        $hasAnyAttachment = !empty($pendingIds) || !empty($references);
        if ($body === '' && !$hasAnyAttachment) {
            return response()->json(['message' => 'Mensagem vazia.'], 422);
        }

        // Identifica o OUTRO participante — usado pra validar ACL de lead
        // e lead_document (ambos precisam enxergar o recurso referenciado).
        $peerId = $conversation->user_a_id === $me ? $conversation->user_b_id : $conversation->user_a_id;
        $peerUser = User::find($peerId);

        $message = DB::transaction(function () use ($conversation, $me, $body, $pendingIds, $references, $peerUser) {
            // 1. Cria a mensagem (body pode ser '' se só tem anexo — front renderiza só o card).
            $msg = ChatMessage::create([
                'conversation_id' => $conversation->id,
                'sender_id'       => $me,
                'body'            => $body,
            ]);

            // 2. Adota drafts: valida um por um — evita adotar draft de outro user
            //    ou draft que já foi vinculado a outra msg (race condition).
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
                    // Algum draft sumiu/foi adotado por outra msg. Aborta a
                    // transação pra não enviar msg com anexos faltando.
                    abort(422, 'Anexos inválidos ou expirados: ' . implode(', ', $missingIds));
                }

                ChatMessageAttachment::whereIn('id', $adoptedIds)
                    ->update(['message_id' => $msg->id]);
            }

            // 3. Referências: resolver + row novo pra cada.
            //    Passa $peerUser pro resolver aplicar ACL conjunto (lead/doc).
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

            // 4. Bumpa a conversa pro topo da sidebar.
            ChatConversation::where('id', $conversation->id)
                ->update(['last_message_at' => $msg->created_at]);

            // 5. Avança last_read do sender (ele "leu" a própria msg).
            ChatConversationRead::updateOrCreate(
                ['user_id' => $me, 'conversation_id' => $conversation->id],
                ['last_read_message_id' => $msg->id, 'last_read_at' => now()]
            );

            return $msg->load('attachments');
        });

        return response()->json($this->buildMessagePayload($message), 201);
    }

    /**
     * Lista mensagens pinadas (is_pinned=true) da conversa.
     * Ordem: pinned_at DESC (mais recente no topo, igual convenção do Slack/WhatsApp).
     *
     * Sprint 4.1 — aba "Importantes" do chat.
     */
    public function pinned(Request $request, int $conversationId): JsonResponse
    {
        $conversation = ChatConversation::findOrFail($conversationId);
        $this->ensureParticipant($conversation);

        $messages = ChatMessage::query()
            ->with('attachments')
            ->where('conversation_id', $conversationId)
            ->where('is_pinned', true)
            ->orderByDesc('pinned_at')
            ->orderByDesc('id')
            ->limit(100) // cap defensivo — improvável passar disso
            ->get();

        $payload = $messages->map(fn (ChatMessage $m) => $this->buildMessagePayload($m));

        return response()->json(['messages' => $payload]);
    }

    /**
     * Toggle pin/unpin de uma mensagem. POST pina, DELETE despina.
     * Qualquer participante da conversa pode pinar/despinar qualquer msg dela —
     * a ideia é "marcar essa conversa pra mim e pro outro como importante",
     * não é posse individual.
     */
    public function togglePin(Request $request, int $messageId): JsonResponse
    {
        $message = ChatMessage::findOrFail($messageId);
        $conversation = ChatConversation::findOrFail($message->conversation_id);
        $this->ensureParticipant($conversation);

        $me = (int) Auth::id();

        // POST = pinar, DELETE = despinar. Método HTTP define o estado alvo.
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

    /**
     * Marca conversa como lida (cursor monotônico).
     */
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

        return response()->json([
            'conversation_id'      => $conversationId,
            'last_read_message_id' => (int) $read->last_read_message_id,
        ]);
    }

    /**
     * Shape unificado: body + timestamps + anexos + pin.
     */
    private function buildMessagePayload(ChatMessage $m): array
    {
        return [
            'id'                  => $m->id,
            'conversation_id'     => $m->conversation_id,
            'sender_id'           => $m->sender_id,
            'body'                => $m->body,
            'created_at'          => $m->created_at,
            // Sprint 4.1 — pin de mensagens importantes.
            'is_pinned'           => (bool) $m->is_pinned,
            'pinned_at'           => $m->pinned_at,
            'pinned_by_user_id'   => $m->pinned_by_user_id,
            'attachments'         => $m->attachments
                ? $m->attachments->map(fn ($a) => $a->buildPayload())->all()
                : [],
        ];
    }

    private function ensureParticipant(ChatConversation $conversation): void
    {
        $me = (int) Auth::id();
        if ($conversation->user_a_id !== $me && $conversation->user_b_id !== $me) {
            abort(403, 'Você não faz parte dessa conversa.');
        }
    }

    /**
     * Sprint 3.8a — Devolve o cursor de leitura do OUTRO participante
     * (peer) pra essa conversa. Usado pelo frontend pra render do ✓/✓✓
     * nas mensagens que EU enviei.
     *
     * Shape:
     *   ['last_read_message_id' => int, 'last_read_at' => string|null]
     *
     * Quando o peer nunca abriu a conversa, retorna (0, null). Nesse caso
     * todas as minhas msgs aparecem como "enviada" (não lida) — correto.
     */
    private function loadPeerReadState(ChatConversation $conversation): array
    {
        $me     = (int) Auth::id();
        $peerId = $conversation->user_a_id === $me
            ? $conversation->user_b_id
            : $conversation->user_a_id;

        if (!$peerId) {
            // Peer foi removido (nullOnDelete no FK). Não dá pra ter "lido" —
            // trata como nunca-leu pra UI não mostrar checkmark azul.
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
