<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
