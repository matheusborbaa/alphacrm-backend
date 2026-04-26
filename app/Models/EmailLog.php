<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    protected $fillable = [
        'to_email',
        'to_name',
        'from_email',
        'from_name',
        'subject',
        'mail_class',
        'type',
        'status',
        'error_message',
        'triggered_by_user_id',
        'related_user_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const TYPE_WELCOME = 'welcome';
    public const TYPE_RESET   = 'reset';
    public const TYPE_INVITE  = 'invite';
    public const TYPE_TEST    = 'test';
    public const TYPE_OTHER   = 'other';

    public const STATUS_SENT   = 'sent';
    public const STATUS_FAILED = 'failed';

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function relatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'related_user_id');
    }
}
