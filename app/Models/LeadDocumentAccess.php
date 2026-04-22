<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log de acesso (download/preview) a um LeadDocument.
 *
 * Uma row por download. O registro é imutável: só INSERT.
 */
class LeadDocumentAccess extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'lead_document_id',
        'lead_id',
        'user_id',
        'action',
        'ip_address',
        'user_agent',
        'country',
        'country_code',
        'region',
        'city',
        'isp',
        'lat',
        'lon',
        'accessed_at',
    ];

    protected $casts = [
        'accessed_at' => 'datetime',
        'lat'         => 'decimal:6',
        'lon'         => 'decimal:6',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(LeadDocument::class, 'lead_document_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
