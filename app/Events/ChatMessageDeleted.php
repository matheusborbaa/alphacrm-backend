<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 4.6 — Disparado quando o autor (ou um admin) apaga uma msg.
 *
 * Como ChatMessageEdited, vai só pro canal da conversa — não notifica
 * em canal de user porque "remoção" não é um aviso ativo, só atualização
 * visual da thread pra mostrar "Mensagem apagada" em vez do conteúdo.
 *
 * Payload mínimo: o frontend já tem a msg renderizada e só precisa do
 * id + flag de deleted_at pra trocar por placeholder. Body NÃO vai
 * embora — quem deletou pode ter feito por motivo (PII, erro etc) e
 * não devemos broadcastar de novo o conteúdo apagado.
 */
class ChatMessageDeleted implements ShouldBroadcastNow
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
        return 'message.deleted';
    }

    public function broadcastWith(): array
    {
        return [
            'message_id'      => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'deleted_at'      => $this->message->deleted_at?->toISOString(),
        ];
    }
}
