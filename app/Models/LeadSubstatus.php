<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\LeadStatus;

class LeadSubstatus extends Model
{
    protected $table = 'lead_substatus';

public function status()
{
    return $this->belongsTo(LeadStatus::class, 'lead_status_id');
}
}
