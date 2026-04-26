<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
