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

    public function values(): HasMany
    {
        return $this->hasMany(LeadCustomFieldValue::class, 'custom_field_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(StatusRequiredField::class, 'custom_field_id');
    }
}
