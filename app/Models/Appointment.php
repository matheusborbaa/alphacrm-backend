<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
  

    protected $fillable = [
    'title',
    'lead_id',
    'type',
    'starts_at',
    'description',
    'ends_at',
    'status',
    'user_id',
    'scope',
    'created_at'
];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
