<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma mensagem dentro de uma conversa.
 *
 * body é plain text (o frontend escapa HTML na renderização). Não
 * guardamos HTML renderizado — isso facilita moderação futura e evita
 * classe de XSS.
 *
 * sender_id pode virar null se o usuário for deletado. Frontend mostra
 * "(usuário removido)" nesse caso.
 */
class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'body',
        'read_at',
        'reply_to_id',
        'is_pinned',
        'pinned_at',
        'pinned_by_user_id',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'pinned_at' => 'datetime',
        // Sprint 3.8c — timestamp exato de leitura por msg (ver migration
        // add_read_at_to_chat_messages). Null = ainda não foi lida.
        'read_at'   => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Quem pinou essa mensagem. Nullable quando is_pinned=false ou quando
     * o user que pinou foi deletado (nullOnDelete).
     */
    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by_user_id');
    }

    /**
     * Anexos da mensagem (0..N). Carregado sob demanda nos endpoints de
     * index/store do ChatMessageController.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ChatMessageAttachment::class, 'message_id')
            ->orderBy('id');
    }

    /**
     * Sprint 4.4 — mensagem-pai citada (quando esta é uma resposta).
     * Nullable: quando a citada é deletada o FK vai pra null (SET NULL).
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'reply_to_id');
    }
}
