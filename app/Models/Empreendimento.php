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
    'location_city',
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
