<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_PARTIAL   = 'partial';
    public const STATUS_PAID      = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_PARTIAL,
        self::STATUS_PAID,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'lead_id',
        'user_id',
        'sale_value',
        'commission_percentage',
        'commission_value',
        'status',
        'paid_at',
        'expected_payment_date',
        'approved_at',
        'approved_by',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'payment_receipt_path',
        'notes',
    ];

    protected $casts = [
        'paid_at'               => 'date',
        'expected_payment_date' => 'date',
        'approved_at'           => 'datetime',
        'cancelled_at'          => 'datetime',
        'sale_value'            => 'decimal:2',
        'commission_value'      => 'decimal:2',
        'commission_percentage' => 'decimal:2',
    ];

    public function corretor()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function comments()
    {
        return $this->hasMany(CommissionComment::class)->orderBy('created_at');
    }

    public function isLive(): bool
    {
        return !in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_CANCELLED,
        ], true);
    }

    public function outstandingValue(): float
    {
        if (in_array($this->status, [self::STATUS_PAID, self::STATUS_CANCELLED], true)) {
            return 0;
        }
        return (float) $this->commission_value;
    }
}
