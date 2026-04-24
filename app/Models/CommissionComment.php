<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sprint 3.7a — Comentários por comissão.
 *
 * Corretor pode comentar pra discordar de valor; gestor pode responder.
 * Thread simples (sem aninhamento), ordenada por created_at.
 */
class CommissionComment extends Model
{
    protected $fillable = [
        'commission_id',
        'user_id',
        'body',
    ];

    public function commission()
    {
        return $this->belongsTo(Commission::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
