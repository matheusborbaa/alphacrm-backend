<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{


    // Sprint 3.5b — status estendido pra incluir pagamento parcial
    // (Pago parcialmente / Pendente / Pago). Usado na lista "Minhas Próximas
    // Comissões" da home pra renderizar as tarjas.
    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID    = 'paid';

    protected $fillable = [
        'lead_id',
        'user_id',
        'sale_value',
        'commission_percentage',
        'commission_value',
        'status',
        'paid_at',
        'expected_payment_date',
    ];
    protected $casts = [
        'paid_at'               => 'date',
        'expected_payment_date' => 'date',
    ];

    public function corretor()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
