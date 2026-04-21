<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;


class LeadStatus extends Model
{
     protected $table = 'lead_status';

    protected $fillable = [
        'name',
        'order',
    ];

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'status_id');
    }

    public function substatus()
    {
        return $this->hasMany(LeadSubstatus::class)->orderBy('order');
    }
}
