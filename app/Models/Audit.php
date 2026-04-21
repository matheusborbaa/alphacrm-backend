<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Audit extends Model
{
    protected $fillable = [
        'event',
        'entity_type',
        'entity_id',
        'user_id',
        'old_values',
        'new_values',
        'source'
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];
}
