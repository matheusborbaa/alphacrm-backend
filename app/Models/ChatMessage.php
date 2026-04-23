<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
