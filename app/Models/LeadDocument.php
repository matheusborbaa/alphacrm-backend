<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadDocument extends Model
{
    protected $fillable = [
        'lead_id',
        'uploader_user_id',
        'original_name',
        'storage_path',
        'mime_type',
        'size_bytes',
        'category',
        'description',
        'deletion_requested_by',
        'deletion_requested_at',
        'deletion_reason',
    ];

    protected $casts = [
        'deletion_requested_at' => 'datetime',
        'size_bytes'            => 'integer',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_user_id');
    }

    public function deletionRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deletion_requested_by');
    }

    /** True se o documento está aguardando aprovação de exclusão por admin. */
    public function isDeletionPending(): bool
    {
        return $this->deletion_requested_at !== null;
    }
}
