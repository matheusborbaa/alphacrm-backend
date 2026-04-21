<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadEmpreendimento extends Model
{
    protected $table = 'lead_empreendimentos';

    protected $fillable = [
        'lead_id',
        'empreendimento_id',
        'priority',
    ];
}
