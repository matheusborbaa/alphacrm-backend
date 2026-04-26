<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomField extends Model
{
    protected $table = 'custom_fields';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'options',
        'mask',
        'is_sensitive',
        'active',
        'order',
    ];

    protected $casts = [
        'options'      => 'array',
        'active'       => 'boolean',
        'is_sensitive' => 'boolean',
        'order'        => 'integer',
    ];

    public const TYPES = ['text', 'textarea', 'number', 'date', 'select', 'checkbox', 'file'];

    public const FILE_DEFAULT_MAX_MB = 10;

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
