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

    /**
     * Sprint Seccionamento — users com permissão "specific" pra esse
     * empreendimento. Inversa de User::empreendimentos(). Só lista users
     * com pivot row; users com access_mode='all' NÃO aparecem aqui (eles
     * não têm registro na pivot — modelo dinâmico). Pra obter TODOS os
     * users que podem atender, use a query no LeadAssignmentService.
     */
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
