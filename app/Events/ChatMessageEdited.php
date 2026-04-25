<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 4.6 — Disparado quando o autor edita uma msg.
 *
 * Diferente do ChatMessageSent, esse só vai pro canal da CONVERSA —
 * não tem fluxo de "user channel" porque editar não gera notificação;
 * é só atualização visual de uma msg que ambos já viram (ou que pelo
 * menos o autor sabe que a outra pessoa pode ver).
 *
 * Payload inclui o body novo (diferente do ChatMessageSent que não
 * inclui body por design): aqui a UI precisa do texto pra atualizar
 * a bolha em tempo real, sem precisar de fetch extra.
 */
class ChatMessageEdited implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ChatMessage $message,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.' . $this->message->conversation_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.edited';
    }

    public function broadcastWith(): array
    {
        return [
            'message_id'      => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'body'            => (string) $this->message->body,
            'edited_at'       => $this->message->edited_at?->toISOString(),
        ];
    }
}
