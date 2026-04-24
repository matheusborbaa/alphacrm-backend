<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sprint 3.7a — Módulo financeiro (base).
 *
 * Cada entry é imutável (append-only). Direção `in` = entrada; `out` = saída.
 * Usado hoje pra registrar:
 *   - Venda confirmada     → direction=in,  category=sale,       ref=Commission
 *   - Comissão paga        → direction=out, category=commission, ref=Commission
 *
 * No futuro receberá fontes de outros módulos (RH, fornecedores, etc)
 * via polimorfismo em (reference_type, reference_id).
 */
class FinanceEntry extends Model
{
    public const DIRECTION_IN  = 'in';
    public const DIRECTION_OUT = 'out';

    public const CATEGORY_SALE       = 'sale';
    public const CATEGORY_COMMISSION = 'commission';
    public const CATEGORY_REFUND     = 'refund';
    public const CATEGORY_OTHER      = 'other';

    protected $fillable = [
        'direction',
        'category',
        'amount',
        'entry_date',
        'reference_type',
        'reference_id',
        'created_by',
        'description',
        'notes',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'entry_date' => 'date',
    ];

    /**
     * Escopo polimórfico pra buscar entries vindas de uma Commission
     * (ou de qualquer Model no futuro).
     */
    public function scopeFor($q, $model)
    {
        return $q->where('reference_type', get_class($model))
                 ->where('reference_id',   $model->getKey());
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
