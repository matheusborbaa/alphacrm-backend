<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Estado de um documento:
 *
 *   1. Ativo       : deletion_requested_at == null && deleted_at == null
 *   2. Pendente    : deletion_requested_at != null && deleted_at == null
 *                    (corretor solicitou exclusão, aguarda admin aprovar)
 *   3. Aguardando  : deleted_at != null && purge_at > now()
 *      expurgo       (admin aprovou -> soft delete; admin ainda pode restaurar)
 *   4. Apagado     : row removida pelo job PurgeExpiredDocuments
 *
 * O controller + job gerenciam as transições; esse model só expõe helpers.
 */
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
        'deleted_at',
        'purge_at',
    ];

    protected $casts = [
        'deletion_requested_at' => 'datetime',
        'deleted_at'            => 'datetime',
        'purge_at'              => 'datetime',
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

    public function accesses(): HasMany
    {
        return $this->hasMany(LeadDocumentAccess::class);
    }

    /** True se está aguardando aprovação de exclusão por admin. */
    public function isDeletionPending(): bool
    {
        return $this->deletion_requested_at !== null && $this->deleted_at === null;
    }

    /** True se já foi "soft-deletado" mas ainda não foi expurgado. */
    public function isPendingPurge(): bool
    {
        return $this->deleted_at !== null
            && ($this->purge_at === null || $this->purge_at->isFuture());
    }

    /** Quantos dias faltam até o purge (null se não aplicável). */
    public function daysUntilPurge(): ?int
    {
        if (!$this->isPendingPurge() || $this->purge_at === null) return null;
        return max(0, (int) now()->diffInDays($this->purge_at, false));
    }
}
