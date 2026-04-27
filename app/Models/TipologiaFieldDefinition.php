<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipologiaFieldDefinition extends Model
{
    protected $table = 'tipologia_field_definitions';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'unit',
        'group',
        'icon',
        'options',
        'active',
        'required',
        'order',
    ];

    protected $casts = [
        'options'  => 'array',
        'active'   => 'boolean',
        'required' => 'boolean',
        'order'    => 'integer',
    ];

    public function values()
    {
        return $this->hasMany(TipologiaFieldValue::class, 'field_definition_id');
    }
}
