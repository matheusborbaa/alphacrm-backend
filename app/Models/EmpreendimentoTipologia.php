<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmpreendimentoTipologia extends Model
{
    protected $table = 'empreendimento_tipologias';

    protected $fillable = [
        'empreendimento_id',
        'name',
        'bedrooms',
        'suites',
        'area_min_m2',
        'area_max_m2',
        'price_from',
        'order',
    ];

    protected $casts = [
        'bedrooms'    => 'integer',
        'suites'      => 'integer',
        'area_min_m2' => 'decimal:2',
        'area_max_m2' => 'decimal:2',
        'price_from'  => 'decimal:2',
        'order'       => 'integer',
    ];

    public function empreendimento(): BelongsTo
    {
        return $this->belongsTo(Empreendimento::class);
    }

    public function fieldValues()
    {
        return $this->hasMany(TipologiaFieldValue::class, 'tipologia_id');
    }
}
