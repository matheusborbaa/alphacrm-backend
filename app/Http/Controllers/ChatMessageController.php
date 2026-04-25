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
        // Sprint 3.9a — leitura afrouxa pra admin (auditoria LGPD). Envio,
        // markRead e pin continuam presos a participante via ensureParticipant.
        $this->ensureCanRead($conversation);

        $data = $request->validate([
            'before_id' => ['nullable', 'integer', 'min:1'],
            'after_id'  => ['nullable', 'integer', 'min:1'],
            'limit'     => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $limit = (int) ($data['limit'] ?? 50);

        $query = ChatMessage::query()
            // Sprint 4.6 — withTrashed inclui msgs apagadas; o frontend mostra
            // "Mensagem apagada" no lugar do body. Sem isso a thread "pula"
            // posições e fica confusa pra quem viu a msg antes de ser apagada.
            ->withTrashed()
            ->with('attachments') // Sprint 2: eager-load — evita N+1 no render
            ->with('replyTo:id,sender_id,body') // Sprint 4.4: msg-pai citada
            ->where('conversation_id', $conversationId);

        if (!empty($data['after_id'])) {
            $query->where('id', '>', (int) $data['after_id']);
        }

        // Sprint 4.6 fix — três cenários:
        //
        //   1) before_id presente → carregar histórico mais antigo (scroll up).
        //      Pega LIMIT msgs IMEDIATAMENTE antes de before_id.
        //      orderByDesc + limit + reverse = ordem cronológica final ASC.
        //
        //   2) after_id presente (polling delta) → pega TODAS as msgs novas
        //      depois de after_id, ordenadas ASC. limit é só guardrail.
        //      OK fazer ASC aqui porque normalmente vêm poucas msgs novas.
        //
        //   3) sem nenhum dos dois → INITIAL LOAD. Tem que pegar as ÚLTIMAS
        //      LIMIT msgs (final do histórico), não as PRIMEIRAS! O bug
        //      antigo fazia `orderBy ASC + limit` aqui — em conversas com
        //      mais de 50 msgs, isso retornava o COMEÇO da conversa em vez
        //      do fim, e o user via msgs antigas até o polling encher o
        //      resto. Mesmo padrão do branch (1) resolve.
        if (!empty($data['before_id'])) {
            $query->where('id', '<', (int) $data['before_id']);
            $messages = $query->orderByDesc('id')->limit($limit)->get()->reverse()->values();
        } elseif (!empty($data['after_id'])) {
            $messages = $query->orderBy('id')->limit($limit)->get();
        } else {
            // Initial load: últimas N msgs em ordem cronológica.
            $messages = $query->orderByDesc('id')->limit($limit)->get()->reverse()->values();
        }

        // Sprint 3.8d — regra RECÍPROCA de confirmação de leitura. Só
        // exponho read_at/peer_read se AMBOS (eu e peer) temos a flag
        // `chat_read_receipts` = true. Se qualquer um dos dois desligou,
        // ninguém vê ✓✓ — o padrão do WhatsApp.
        $exposeReadReceipts = $this->canExposeReadReceipts($conversation);

        $payload = $messages->map(
            fn (ChatMessage $m) => $this->buildMessagePayload($m, $exposeReadReceipts)
        );

        return response()->json([
            'messages'  => $payload,
            'has_more'  => $messages->count() === $limit,
            // Sprint 3.8a — leitura do OUTRO participante. Frontend usa isso
            // pra decidir ✓ (enviada) vs ✓✓ (lida) em cada msg minha. Cada
            // polling atualiza esse cursor, então o indicador se acende na
            // hora que o peer abre a conversa.
            //
            // Sprint 3.8d — zera se a regra recíproca de read receipts
            // tiver sido desligada por qualquer um dos dois.
            'peer_read' => $exposeReadReceipts
                ? $this->loadPeerReadState($conversation)
                : ['last_read_message_id' => 0, 'last_read_at' => null],
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
            // Sprint 4.4 — reply/citação. A msg-pai precisa existir E
            // pertencer à MESMA conversa (checado logo abaixo).
            'reply_to_id'          => ['nullable', 'integer', 'min:1', 'exists:chat_messages,id'],
        ]);

        $me = (int) Auth::id();
        $body = isset($data['body']) ? trim($data['body']) : '';
        $pendingIds = $data['pending_upload_ids'] ?? [];
        $references = $data['references'] ?? [];
        $replyToId  = $data['reply_to_id'] ?? null;

        // Sprint 4.4 — valida que a msg-pai está na MESMA conversa.
        // Previne ataques onde alguém replyaria a msg de outra conversa
        // pra "vazar" conteúdo via snippet. FK sozinho não cobre.
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

        // Identifica o OUTRO participante — usado pra validar ACL de lead
        // e lead_document (ambos precisam enxergar o recurso referenciado).
        $peerId = $conversation->user_a_id === $me ? $conversation->user_b_id : $conversation->user_a_id;
        $peerUser = User::find($peerId);

        $message = DB::transaction(function () use ($conversation, $me, $body, $pendingIds, $references, $peerUser, $replyToId) {
            // 1. Cria a mensagem (body pode ser '' se só tem anexo — front renderiza só o card).
            $msg = ChatMessage::create([
                'conversation_id' => $conversation->id,
                'sender_id'       => $me,
                'body'            => $body,
                'reply_to_id'     => $replyToId,
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

            return $msg->load(['attachments', 'replyTo:id,sender_id,body']);
        });

        // Sprint 4.5 — broadcast realtime pra Reverb. Dispatch DEPOIS do
        // commit da transaction pra garantir que o peer fazendo fetch no
        // GET /messages achará a msg persistida. Canal conversa.{id} avisa
        // os dois participantes; canal user.{peer_id} avisa o peer em
        // background pra badge/sidebar mesmo sem a conversa aberta.
        //
        // Silencioso: se broadcasting driver não tá configurado ou Reverb
        // está offline, o evento é dropado — o polling continua cobrindo.
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

    /**
     * Lista mensagens pinadas (is_pinned=true) da conversa.
     * Ordem: pinned_at DESC (mais recente no topo, igual convenção do Slack/WhatsApp).
     *
     * Sprint 4.1 — aba "Importantes" do chat.
     */
    public function pinned(Request $request, int $conversationId): JsonResponse
    {
        $conversation = ChatConversation::findOrFail($conversationId);
        // Leitura — admin pode ver pinadas em auditoria.
        $this->ensureCanRead($conversation);

        $messages = ChatMessage::query()
            ->with('attachments')
            ->with('replyTo:id,sender_id,body') // Sprint 4.4
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
     * Marca conversa como lida — cursor monotônico em
     * `chat_conversation_reads` MAIS timestamp individual em cada
     * chat_message (Sprint 3.8c).
     *
     * O cursor continua servindo pros unread counts e pro fallback
     * de msgs antigas (pré-sprint) que ainda não têm read_at. O update
     * por mensagem roda em UMA query com WHERE read_at IS NULL — só
     * escreve na primeira vez e preserva o timestamp original em
     * aberturas subsequentes.
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

        // Sprint 3.8c/3.8d — grava timestamp individual em cada msg do OUTRO
        // participante que ainda não foi lida. Respeita a preferência do
        // usuário: se ele desligou `chat_read_receipts`, o cursor continua
        // avançando (pra unread count) mas `read_at` não é gravado —
        // ninguém vê "visto" nas msgs que ele leu.
        $user = $request->user();
        $exposeReads = (bool) ($user->chat_read_receipts ?? true);
        if ($exposeReads && $targetId > 0) {
            ChatMessage::where('conversation_id', $conversationId)
                ->where('sender_id', '!=', $me)
                ->where('id', '<=', $targetId)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        // Sprint 4.5 — broadcast pro OUTRO participante atualizar ✓✓
        // em tempo real. Só dispara quando exposeReads é true (respeita
        // a regra recíproca); senão seria um "vazamento" de sinal de
        // leitura mesmo com read receipts desligado. O frontend filtra
        // localmente por reader_id !== me.id.
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

    /**
     * Sprint 4.2 — Busca no histórico do chat.
     *
     * Query params:
     *   - q (string, obrigatório, >=2 chars): termo de busca.
     *   - conversation_id (int, opcional): restringe a uma conversa.
     *     Quando omitido, busca em todas as conversas do user.
     *   - limit (int, default 30, max 100): quantos resultados por página.
     *
     * Retorna:
     *   {
     *     query: "termo",
     *     results: [
     *       {
     *         id, conversation_id, sender_id, created_at,
     *         snippet: "…trecho com o termo…",
     *         other_user: {id, name, avatar} // pra renderizar quando busca é global
     *       }
     *     ],
     *     count: N
     *   }
     *
     * Ordem: mais RECENTES primeiro — faz mais sentido num chat (msgs
     * novas tendem a ser as relevantes). Se virar problema com histórico
     * grande, paginar por before_id no próximo sprint.
     *
     * Segurança: o WHERE filtra só conversas onde o user é participante.
     * Admin NÃO tem escape aqui (auditoria é outro fluxo, ?audit=1 lá).
     */
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

        // IDs das conversas que o user pode pesquisar (participante em
        // alguma das pontas). Single query, retorna inteiros.
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

        // Busca case-insensitive. LIKE com wildcard nos dois lados — OK
        // pra volumes pequenos/médios (equipe); se virar gargalo, trocar
        // por FULLTEXT INDEX no MySQL depois.
        $rows = ChatMessage::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where('body', 'LIKE', '%' . $this->escapeLike($q) . '%')
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id', 'conversation_id', 'sender_id', 'body', 'created_at',
            ]);

        // Monta mapa de "outro lado" por conversation_id pra renderizar
        // "Conversa com Fulano" nos resultados. Uma query só.
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

    /**
     * Sprint 4.6 — Edita o body de uma msg.
     *
     * Regras (validadas em sequência, primeira que falhar = 422/403):
     *   1. Tem que ser o autor da msg (não-autor = 403).
     *   2. Dentro de 15min do envio (passou = 422 com janela_expirada).
     *   3. Ainda não foi lida pelo destinatário (lida = 422 ja_lida).
     *
     * Após sucesso: dispara ChatMessageEdited pra atualizar a UI dos
     * dois lados em tempo real. Anexos NÃO são tocados — só editamos texto.
     */
    public function update(Request $request, int $messageId): JsonResponse
    {
        // findOrFail SEM withTrashed: msg apagada não pode ser editada.
        // Se o user tentar (frontend bugado), retorna 404 limpo.
        $message = ChatMessage::findOrFail($messageId);
        $conversation = ChatConversation::findOrFail($message->conversation_id);
        $this->ensureParticipant($conversation);

        $me = (int) Auth::id();

        // Regra 1 — só o autor edita o próprio texto. Admin não pode
        // editar msg alheia (seria fingir ser outra pessoa, problema sério).
        if ($message->sender_id !== $me) {
            return response()->json([
                'message' => 'Você só pode editar suas próprias mensagens.',
            ], 403);
        }

        // Regra 2 — janela de 15min. Pra UX consistente entre devices,
        // reportamos 422 com código pro frontend reconhecer (e esconder o
        // botão de editar quando passar do tempo, sem precisar pollar).
        $minutesSince = $message->created_at->diffInMinutes(now());
        if ($minutesSince > 15) {
            return response()->json([
                'message' => 'Janela de edição expirou (15 minutos).',
                'code'    => 'edit_window_expired',
            ], 422);
        }

        // Regra 3 — msg lida não pode ser editada. Read_at vem do peer
        // marcando read; se ainda é null, a janela continua aberta dentro
        // dos 15min. Se virou not-null, congela o conteúdo.
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

        // No-op silencioso: se o texto não mudou, não dispara evento nem
        // marca edited_at. Evita "(editada)" aparecer sem mudança real.
        if ($newBody === $message->body) {
            return response()->json($this->buildMessagePayload(
                $message->load(['attachments', 'replyTo:id,sender_id,body'])
            ));
        }

        $message->body      = $newBody;
        $message->edited_at = now();
        $message->save();

        // Broadcast — silencioso se Reverb tá offline (igual store).
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

    /**
     * Sprint 4.6 — Apaga (soft delete) uma msg.
     *
     * Regras:
     *   - Autor: pode apagar a própria SE ainda não foi lida (read_at null).
     *   - Admin: pode apagar QUALQUER msg, lida ou não. Cada delete por
     *     admin gera audit log pra trilha LGPD (motivo de exclusão fica
     *     registrado fora desse fluxo, na própria UI de admin).
     *
     * Após sucesso: dispara ChatMessageDeleted pra UI dos dois lados
     * trocar a bolha por placeholder "Mensagem apagada" em tempo real.
     */
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

        // Bypass de permissão: admin pode apagar qualquer msg, mesmo de
        // conversa em que não participa (LGPD/moderação). Não-admin tem
        // que ser participante E autor da msg.
        if (!$isAdmin) {
            $this->ensureParticipant($conversation);

            if (!$isAuthor) {
                return response()->json([
                    'message' => 'Você só pode apagar suas próprias mensagens.',
                ], 403);
            }

            // Autor + msg lida = bloqueado. Admin não tem essa restrição.
            if ($message->read_at !== null) {
                return response()->json([
                    'message' => 'Mensagem já foi lida e não pode ser apagada.',
                    'code'    => 'already_read',
                ], 422);
            }
        }

        // Auditoria de admin apagando msg alheia. Importante pra LGPD —
        // toda intervenção de moderação precisa de trilha. Não logamos
        // quando o autor apaga a própria msg (não é evento de moderação).
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
                // Auditoria não pode derrubar o request.
            }
        }

        $message->delete(); // SoftDeletes — seta deleted_at, não remove row

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

    /**
     * Constrói um trecho curto (~160 chars) centrado na primeira ocorrência
     * do termo. Se o body é mais curto, retorna inteiro. Usado pra UI
     * mostrar "contexto" do match sem truncar feio.
     */
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

        // 60 chars antes do match + 100 depois = ~160 chars centrado.
        $start  = max(0, $pos - 60);
        $prefix = $start > 0 ? '…' : '';
        $suffix = ($start + 160 < $len) ? '…' : '';
        return $prefix . mb_substr($body, $start, 160) . $suffix;
    }

    /**
     * Escapa caracteres especiais do LIKE (% e _) pra não virarem
     * coringa. Usado no search — sem isso, "50%" viraria "qualquer
     * coisa que começa com 50" no LIKE.
     */
    private function escapeLike(string $v): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
    }

    /**
     * Shape unificado: body + timestamps + anexos + pin.
     */
    private function buildMessagePayload(ChatMessage $m, bool $exposeRead = true): array
    {
        // Sprint 4.4 — reply/citação. Se a msg-pai foi deletada (reply_to_id
        // virou null via SET NULL), retornamos null; frontend exibe
        // "Mensagem indisponível" no lugar do bloco citado.
        $reply = null;
        if ($m->reply_to_id && $m->replyTo) {
            $parentBody = (string) ($m->replyTo->body ?? '');
            $reply = [
                'id'        => $m->replyTo->id,
                'sender_id' => $m->replyTo->sender_id,
                // Snippet curto pra caber no bloquinho (~120 chars). Body
                // inteiro fica escondido; a UX é clicar pra pular pra msg.
                'snippet'   => mb_strlen($parentBody) > 120
                    ? mb_substr($parentBody, 0, 120) . '…'
                    : $parentBody,
            ];
        } elseif ($m->reply_to_id) {
            // Referência órfã — a msg-pai existia mas foi deletada.
            $reply = ['id' => null, 'sender_id' => null, 'snippet' => null];
        }

        // Sprint 4.6 — Msgs apagadas vêm via withTrashed() na index. Quando
        // estão soft-deleted, body/anexos/reply ficam zerados no payload —
        // o frontend renderiza "Mensagem apagada" no lugar e não vaza
        // conteúdo retroativamente. Mantemos id/sender_id/created_at pra
        // preservar a posição na thread e poder identificar quem apagou.
        $isDeleted = $m->trashed();

        return [
            'id'                  => $m->id,
            'conversation_id'     => $m->conversation_id,
            'sender_id'           => $m->sender_id,
            'body'                => $isDeleted ? '' : $m->body,
            'created_at'          => $m->created_at,
            // Sprint 3.8c — timestamp exato de quando o destinatário leu
            // essa msg pela primeira vez. Null = ainda não lida. Frontend
            // usa pra tooltip "Lido às HH:MM" preciso.
            // Sprint 3.8d — zera se a regra recíproca foi desligada; assim
            // o frontend cai no branch "Enviada" independente do que tá
            // salvo no banco.
            'read_at'             => $exposeRead ? $m->read_at : null,
            // Sprint 4.6 — quando foi editada pela última vez. Frontend
            // mostra selo "(editada)" se não-null. Apagada não exibe selo.
            'edited_at'           => $isDeleted ? null : $m->edited_at,
            // Sprint 4.6 — quando foi apagada (soft delete). Frontend usa
            // pra trocar a bolha por "Mensagem apagada" italic cinza.
            'deleted_at'          => $m->deleted_at,
            // Sprint 4.4 — bloco citado quando é resposta. Não exposto se
            // a msg foi apagada (não vaza contexto da thread original).
            'reply_to'            => $isDeleted ? null : $reply,
            // Sprint 4.1 — pin de mensagens importantes. Apagada não fica
            // pinada (a UI esconde o ícone, mas zeramos por garantia).
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

    /**
     * Sprint 3.8d — decide se confirmação de leitura é exposta nesta
     * conversa. Regra recíproca: só mostra ✓✓ se os DOIS participantes
     * têm a flag `chat_read_receipts` ativa. Qualquer um desligando,
     * ninguém vê — igual WhatsApp.
     *
     * Defensivo: assume true quando um dos users não foi encontrado
     * (edge case de user deletado); nesse caso o read_at já tá gravado
     * historicamente então não faz sentido esconder retroativamente.
     */
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

    /**
     * Sprint 3.9a — versão relaxada pra endpoints de LEITURA. Passa pra:
     *   - Participante da conversa (regra normal)
     *   - Admin (pra auditoria LGPD; cada acesso é registrado em audit_logs)
     *
     * Não usar em endpoints de escrita (store/markRead/pin) — esses continuam
     * precisando de ensureParticipant pra admin não fingir ser participante.
     */
    private function ensureCanRead(ChatConversation $conversation): void
    {
        $user = Auth::user();
        if (!$user) abort(401);

        $me = (int) $user->id;
        $isParticipant = $conversation->user_a_id === $me || $conversation->user_b_id === $me;
        if ($isParticipant) return;

        $role = strtolower(trim((string) ($user->role ?? '')));
        if ($role === 'admin') {
            // Registro de acesso pra LGPD — uma linha por request de leitura
            // de conversa alheia. Não dedup por sessão pra manter trilha
            // granular. Pode virar ruído em auditorias longas, mas é barato.
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
                // Silencioso — auditoria não pode derrubar o request.
            }
            return;
        }

        abort(403, 'Você não faz parte dessa conversa.');
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
