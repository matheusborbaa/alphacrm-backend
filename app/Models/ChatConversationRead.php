<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatConversationRead extends Model
{
    protected $table = 'chat_conversation_reads';

    protected $fillable = [
        'user_id',
        'conversation_id',
        'last_read_message_id',
        'last_read_at',
    ];

    protected $casts = [
        'last_read_message_id' => 'integer',
        'last_read_at'         => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }
}
