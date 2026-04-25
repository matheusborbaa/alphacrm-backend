<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 4.5 — evento disparado quando uma msg nova é criada.
 *
 * Usa ShouldBroadcastNow (sync, não precisa de queue:work) porque:
 *   - latência importa (queremos realtime de verdade);
 *   - payload é mínimo (só id da msg);
 *   - dispatch roda no fim da transaction do store, então já temos certeza
 *     que a msg tá persistida quando o peer for fazer fetch do payload.
 *
 * Dois canais são usados em dispatches separados pra cada ChatMessageSent:
 *   1. private-conversation.{id} — os DOIS participantes ouvem pra receber
 *      a msg em tempo real enquanto estão com essa conversa aberta.
 *   2. private-user.{peer_id}    — o OUTRO participante ouve num canal
 *      global pra atualizar o badge / lista de conversas mesmo sem estar
 *      com a conversa aberta.
 *
 * O payload intencionalmente NÃO inclui body/attachments da msg — o
 * frontend dispara pollActiveMessages() ao receber o evento pra pegar
 * o shape completo via GET /messages?after_id=X, preservando a regra
 * recíproca de read receipts e o filtro de auditoria já existentes.
 */
class ChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ChatMessage $message,
        public int $channelUserId, // id do canal private-user.{X} (broadcast global)
    ) {}

    /**
     * Dois channels: o da conversa (ambos ouvem) e o do peer (só ele
     * ouve num canal user global — pra badge/sidebar quando a conversa
     * não tá aberta).
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.' . $this->message->conversation_id),
            new PrivateChannel('user.' . $this->channelUserId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'message_id'      => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'sender_id'       => $this->message->sender_id,
            // created_at ajuda o frontend a ordenar sem fetch extra quando
            // quiser mostrar preview na sidebar antes do refresh completo.
            'created_at'      => $this->message->created_at?->toISOString(),
        ];
    }
}
