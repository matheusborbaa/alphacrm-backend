<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpreendimentoFieldDefinition extends Model
{
    protected $table = 'empreendimento_field_definitions';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'unit',
        'group',
        'icon',
        'active',
        'order',
    ];

    public function values()
    {
        return $this->hasMany(EmpreendimentoFieldValue::class, 'field_definition_id');
    }
}
