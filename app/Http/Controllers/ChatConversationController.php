<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
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
                'userA:id,name,email',
                'userB:id,name,email',
                // carregamos só a última mensagem de cada conversa via
                // lastMessage() (latest), mas Eager load com hasMany
                // traz todas. Alternativa: subquery. Pra MVP, limitamos
                // manualmente depois no map().
                'lastMessage:id,conversation_id,sender_id,body,created_at',
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id') // tiebreaker pra conversas sem mensagem
            ->get();

        $result = $conversations->map(function (ChatConversation $c) use ($me) {
            $other = $c->otherParticipant($me);

            // lastMessage() retorna a coleção ordenada desc; pega o primeiro.
            $last = $c->lastMessage->first();

            return [
                'id'              => $c->id,
                'last_message_at' => $c->last_message_at,
                'other_user'      => $other ? [
                    'id'    => $other->id,
                    'name'  => $other->name,
                    'email' => $other->email,
                ] : null,
                'last_message'    => $last ? [
                    'id'         => $last->id,
                    'body'       => $last->body,
                    'sender_id'  => $last->sender_id,
                    'is_mine'    => $last->sender_id === $me,
                    'created_at' => $last->created_at,
                ] : null,
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
                'id'    => $otherUser->id,
                'name'  => $otherUser->name,
                'email' => $otherUser->email,
            ],
        ], $conversation->wasRecentlyCreated ? 201 : 200);
    }
}
