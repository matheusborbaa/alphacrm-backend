<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{


    protected $fillable = [
        'lead_id',
        'user_id',
        'sale_value',
        'commission_percentage',
        'commission_value',
        'status',
        'paid_at'
    ];
    protected $casts = [
        'paid_at' => 'date'
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
