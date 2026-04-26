<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ChatMessage $message,
        public int $channelUserId,
    ) {}

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

        $body = (string) ($this->message->body ?? '');
        $preview = $body !== ''
            ? mb_strimwidth($body, 0, 80, '…')
            : '📎 Anexo';

        $senderName = optional($this->message->sender)->name ?? 'Alguém';
        $senderName = trim(explode(' ', $senderName)[0] ?? $senderName);

        return [
            'message_id'      => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'sender_id'       => $this->message->sender_id,

            'sender_name'     => $senderName,
            'preview'         => $preview,

            'created_at'      => $this->message->created_at?->toISOString(),
        ];
    }
}
