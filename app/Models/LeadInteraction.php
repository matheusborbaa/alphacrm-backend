<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Models\Lead;
use App\Models\Appointment;

class LeadInteraction extends Model
{
      protected $fillable = [
        'lead_id',
        'user_id',
        'type',
        'note',
        'appointment_id', // 👈 TEM QUE ESTAR AQUI

    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function appointment()
{
    return $this->belongsTo(Appointment::class);
}
}
