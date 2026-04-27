<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpreendimentoImage extends Model
{
    public const CATEGORY_IMAGENS  = 'imagens';
    public const CATEGORY_PLANTAS  = 'plantas';
    public const CATEGORY_DECORADO = 'decorado';
    public const CATEGORY_ARTES    = 'artes';

    public const CATEGORIES = [
        self::CATEGORY_IMAGENS,
        self::CATEGORY_PLANTAS,
        self::CATEGORY_DECORADO,
        self::CATEGORY_ARTES,
    ];

    protected $fillable = [
        'empreendimento_id',
        'image_path',
        'order',
        'category',
        'is_cover',
    ];

    protected $casts = [
        'is_cover' => 'boolean',
    ];

    public function empreendimento()
    {
        return $this->belongsTo(Empreendimento::class);
    }
}
