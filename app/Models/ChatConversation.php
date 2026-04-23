<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversa 1-a-1 entre dois usuários.
 *
 * Invariante: user_a_id < user_b_id sempre (ordem canônica). Isso é
 * garantido no ChatConversationController@findOrCreate, NÃO no DB —
 * o unique index na tupla é o guardrail final.
 *
 * Pra pegar "o outro participante" de uma conversa da perspectiva do
 * usuário logado, use o helper $conversation->otherParticipant($me).
 */
class ChatConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_a_id',
        'user_b_id',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function userA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_a_id');
    }

    public function userB(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_b_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    /**
     * Última mensagem — útil pra lista de conversas.
     */
    public function lastMessage(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id')->latest('created_at');
    }

    /**
     * Dado o usuário logado, retorna o User do OUTRO lado da conversa.
     * Retorna null se a conversa tiver só um participante (caso o outro
     * user tenha sido deletado e o FK nullOnDelete tenha zerado).
     */
    public function otherParticipant(int $currentUserId): ?User
    {
        if ($this->user_a_id === $currentUserId) {
            return $this->userB;
        }
        if ($this->user_b_id === $currentUserId) {
            return $this->userA;
        }
        // User logado nem faz parte dessa conversa — ChatConversationController
        // deve ter barrado antes. Se chegou aqui, é bug de autorização.
        return null;
    }

    /**
     * Helper pra ordem canônica: (min, max) do par.
     * Usado no findOrCreate pra deduplicar conversa independente de
     * quem chamou primeiro.
     */
    public static function canonicalPair(int $userX, int $userY): array
    {
        return $userX < $userY ? [$userX, $userY] : [$userY, $userX];
    }
}
