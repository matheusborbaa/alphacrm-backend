<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Lead;
use App\Models\EmpreendimentoImage;



class Empreendimento extends Model
{
   protected $fillable = [
    'name',
    'code',
    'locationcity',        // coluna real no banco (uma palavra)
    'neighborhood',
    'tipo',                // tipologia (apartamento/casa/terreno/comercial...)
    'finalidade',          // residencial/comercial/misto
    'status',              // status da obra (lancamento/em_obras/pronto_morar/entregue)
    'metragem',
    'initial_price',
    'active',
    'commission_percentage',
    'average_sale_value',
    'starts_at',
    'ends_at',
    'shortdescription',
    'description',
    'cover_image'
];

    protected $casts = [
        'active' => 'boolean',
        'starts_at' => 'date',
        'ends_at' => 'date',
        'metragem' => 'decimal:2',
        'initial_price' => 'decimal:2',
        'average_sale_value' => 'decimal:2',
        'commission_percentage' => 'decimal:2',
    ];

    public function leads()
{
    return $this->belongsToMany(
        Lead::class,
        'lead_empreendimentos',   // tabela pivot
        'empreendimento_id',     // FK do empreendimento na pivot
        'lead_id'                // FK do lead na pivot
    );
}
    public function images()
{
    return $this->hasMany(EmpreendimentoImage::class)
                ->orderBy('order');
}

public function fieldValues()
{
    return $this->hasMany(EmpreendimentoFieldValue::class);
}



}
