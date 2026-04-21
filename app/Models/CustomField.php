<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo de campos customizados que podem ser exigidos em status/substatus.
 */
class CustomField extends Model
{
    protected $table = 'custom_fields';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'options',
        'mask',
        'active',
        'order',
    ];

    protected $casts = [
        'options' => 'array',
        'active'  => 'boolean',
        'order'   => 'integer',
    ];

    /**
     * Tipos válidos. Usar pra validação no controller.
     */
    public const TYPES = ['text', 'textarea', 'number', 'date', 'select', 'checkbox'];

    /**
     * Presets de máscara reconhecidos pelo frontend (core/masks.js).
     * Também aceita padrão livre ("000.000.000-00", "(00) 00000-0000" etc).
     */
    public const MASK_PRESETS = ['cpf', 'cnpj', 'telefone', 'celular', 'data', 'cep', 'moeda'];

    public function values(): HasMany
    {
        return $this->hasMany(LeadCustomFieldValue::class, 'custom_field_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(StatusRequiredField::class, 'custom_field_id');
    }
}
