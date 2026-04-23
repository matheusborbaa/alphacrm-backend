<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatConversationRead;
use App\Models\ChatMessage;
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
 * Autorização: `ensureParticipant()` barra qualquer request de usuário
 * que não faz parte dos 2 participantes da conversa. Retorna 403 com
 * mensagem genérica (não vaza que a conversa existe).
 */
class ChatMessageController extends Controller
{
    /**
     * Lista mensagens da conversa.
     *
     * Query params:
     *  - before_id (int, opcional): retorna mensagens com id < before_id.
     *    Usado pro scroll infinito retrocedendo no histórico.
     *  - after_id  (int, opcional): retorna mensagens com id > after_id.
     *    Usado pelo polling pra buscar só o delta desde a última mensagem
     *    que o cliente já tem.
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
            ->where('conversation_id', $conversationId);

        // Polling incremental: cliente pede "tudo depois do id X".
        if (!empty($data['after_id'])) {
            $query->where('id', '>', (int) $data['after_id']);
        }

        // Histórico: cliente pede "50 mensagens antes do id X".
        // Ordena desc pra pegar as mais recentes ANTES de X, depois inverte.
        if (!empty($data['before_id'])) {
            $query->where('id', '<', (int) $data['before_id']);
            $messages = $query->orderByDesc('id')->limit($limit)->get()->reverse()->values();
        } else {
            // Default / polling: ordem cronológica direto.
            // No polling (after_id) a tendência é pegar poucas msgs, mas
            // limitamos mesmo assim pra não estourar em caso de catchup.
            $messages = $query->orderBy('id')->limit($limit)->get();
        }

        // Shape consistente — frontend não quer saber de coluna crua.
        $payload = $messages->map(function (ChatMessage $m) {
            return [
                'id'              => $m->id,
                'conversation_id' => $m->conversation_id,
                'sender_id'       => $m->sender_id,
                'body'            => $m->body,
                'created_at'      => $m->created_at,
            ];
        });

        return response()->json([
            'messages' => $payload,
            'has_more' => $messages->count() === $limit,
        ]);
    }

    /**
     * Envia nova mensagem na conversa. Atomicamente:
     *   - cria o row em chat_messages
     *   - atualiza last_message_at da conversa (pra ordenação da sidebar)
     *
     * Limita o body a 5000 chars — mensagens de chat tipicamente são curtas,
     * e texto MUITO longo indica que o usuário deveria estar anexando um
     * documento em vez.
     */
    public function store(Request $request, int $conversationId): JsonResponse
    {
        $conversation = ChatConversation::findOrFail($conversationId);
        $this->ensureParticipant($conversation);

        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        $me = (int) Auth::id();
        $body = trim($data['body']);

        if ($body === '') {
            return response()->json(['message' => 'Mensagem vazia.'], 422);
        }

        $message = DB::transaction(function () use ($conversation, $me, $body) {
            $msg = ChatMessage::create([
                'conversation_id' => $conversation->id,
                'sender_id'       => $me,
                'body'            => $body,
            ]);

            // Bumpa a conversa pro topo da sidebar dos dois participantes.
            // Updates sem trigger de model events pra evitar overhead.
            ChatConversation::where('id', $conversation->id)
                ->update(['last_message_at' => $msg->created_at]);

            // Avança o last_read do próprio sender até essa msg — afinal,
            // quem manda leu tudo até ali. Evita que msgs do próprio user
            // fiquem contando como não-lidas pra ele mesmo (não contariam
            // pela regra sender_id != me, mas isso mantém o valor em dia).
            ChatConversationRead::updateOrCreate(
                ['user_id' => $me, 'conversation_id' => $conversation->id],
                ['last_read_message_id' => $msg->id, 'last_read_at' => now()]
            );

            return $msg;
        });

        return response()->json([
            'id'              => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_id'       => $message->sender_id,
            'body'            => $message->body,
            'created_at'      => $message->created_at,
        ], 201);
    }

    /**
     * Marca a conversa como lida pelo usuário logado, avançando o cursor
     * last_read_message_id até o valor passado (ou até o maior id atual,
     * se não vier no body).
     *
     * IMPORTANTE: é idempotente e monotônico — nunca regride o cursor.
     * Se o cliente enviar um id menor que o atual (ex: desordem de requests),
     * ignora a atualização.
     */
    public function markRead(Request $request, int $conversationId): JsonResponse
    {
        $conversation = ChatConversation::findOrFail($conversationId);
        $this->ensureParticipant($conversation);

        $data = $request->validate([
            'last_read_message_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $me = (int) Auth::id();

        // Se não informar id, usa o maior id atual da conversa.
        $targetId = $data['last_read_message_id'] ?? null;
        if ($targetId === null) {
            $targetId = ChatMessage::where('conversation_id', $conversationId)
                ->max('id') ?? 0;
        }

        $targetId = (int) $targetId;

        $read = ChatConversationRead::firstOrNew(
            ['user_id' => $me, 'conversation_id' => $conversationId]
        );

        // Monotônico: nunca regride.
        $current = (int) ($read->last_read_message_id ?? 0);
        if ($targetId > $current) {
            $read->last_read_message_id = $targetId;
            $read->last_read_at = now();
            $read->save();
        } elseif (!$read->exists) {
            // Primeiro registro com id 0 — ainda assim salvamos pra materializar.
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
     * Guarda: garante que o usuário logado é um dos 2 participantes.
     * Retorna 403 genérico (não vaza se a conversa existe ou não).
     */
    private function ensureParticipant(ChatConversation $conversation): void
    {
        $me = (int) Auth::id();
        if ($conversation->user_a_id !== $me && $conversation->user_b_id !== $me) {
            abort(403, 'Você não faz parte dessa conversa.');
        }
    }
}
