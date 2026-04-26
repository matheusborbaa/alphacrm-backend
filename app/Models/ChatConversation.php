<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function lastMessage(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id')->latest('created_at');
    }

    public function otherParticipant(int $currentUserId): ?User
    {
        if ($this->user_a_id === $currentUserId) {
            return $this->userB;
        }
        if ($this->user_b_id === $currentUserId) {
            return $this->userA;
        }

        return null;
    }

    public static function canonicalPair(int $userX, int $userY): array
    {
        return $userX < $userY ? [$userX, $userY] : [$userY, $userX];
    }
}
