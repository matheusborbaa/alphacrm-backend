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
    'locationcity',
    'neighborhood',
    'tipo',
    'finalidade',
    'status',
    'metragem',
    'initial_price',
    'active',
    'commission_percentage',
    'average_sale_value',
    'starts_at',
    'ends_at',
    'shortdescription',
    'description',
    'cover_image',
    'book_path',
    'book_uploaded_at',
    'price_table_path',
    'price_table_uploaded_at',
];

    protected $casts = [
        'active' => 'boolean',
        'starts_at' => 'date',
        'ends_at' => 'date',
        'metragem' => 'decimal:2',
        'initial_price' => 'decimal:2',
        'average_sale_value' => 'decimal:2',
        'commission_percentage' => 'decimal:2',
        'book_uploaded_at'        => 'datetime:Y-m-d H:i:s',
        'price_table_uploaded_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function leads()
{
    return $this->belongsToMany(
        Lead::class,
        'lead_empreendimentos',
        'empreendimento_id',
        'lead_id'
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

    public function users()
    {
        return $this->belongsToMany(
            \App\Models\User::class,
            'user_empreendimentos',
            'empreendimento_id',
            'user_id'
        )->withTimestamps();
    }

}
