<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpreendimentoImage extends Model
{
    protected $fillable = [
        'empreendimento_id',
        'image_path',
        'order',
    ];

    public function empreendimento()
    {
        return $this->belongsTo(Empreendimento::class);
    }
}
