<?php

namespace App\Events;

use App\Models\ChatConversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

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
