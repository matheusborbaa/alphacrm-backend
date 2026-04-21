<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMeta extends Model
{
    protected $table = 'user_metas';

    protected $fillable = [
        'user_id',
        'mes',
        'ano',
        'meta_leads',
        'meta_atendimentos',
        'meta_vendas',
    ];

    protected $casts = [
        'mes'               => 'integer',
        'ano'               => 'integer',
        'meta_leads'        => 'integer',
        'meta_atendimentos' => 'integer',
        'meta_vendas'       => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
