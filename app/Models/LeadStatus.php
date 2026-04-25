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
        'color_hex',
        // Sprint H1.4 — etapa "terminal" não aparece no desenho do funil
        // (Vendido, Perdido, Descartado…). Continua sendo etapa válida
        // pra mover leads, só não conta na visualização gráfica do funil.
        'is_terminal',
    ];

    protected $casts = [
        'is_terminal' => 'boolean',
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
