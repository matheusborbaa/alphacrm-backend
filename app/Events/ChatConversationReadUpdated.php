<?php

namespace App\Events;

use App\Models\ChatConversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 4.5 — dispara quando um participante avança seu cursor de
 * leitura (markRead). O OUTRO participante ouve e atualiza ✓✓ ao vivo,
 * sem esperar o próximo tick de polling.
 *
 * Canal único: private-conversation.{id}. O frontend filtra localmente
 * (só aplica quando `reader_id !== me.id`, já que a MINHA leitura não
 * é relevante pra MIM).
 *
 * Payload preserva a regra recíproca: o `last_read_message_id` vem
 * sempre (é cursor interno), mas o frontend só renderiza ✓✓ se a
 * própria resposta do GET /messages tiver exposto read_at/peer_read
 * (ou seja, quem desligou read receipts não vê independente do evento).
 */
class ChatConversationReadUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ChatConversation $conversation,
        public int $readerId,
        public int $lastReadMessageId,
        public ?string $lastReadAtIso,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('conversation.' . $this->conversation->id);
    }

    public function broadcastAs(): string
    {
        return 'read.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id'      => $this->conversation->id,
            'reader_id'            => $this->readerId,
            'last_read_message_id' => $this->lastReadMessageId,
            'last_read_at'         => $this->lastReadAtIso,
        ];
    }
}
