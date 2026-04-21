<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpreendimentoFieldValue extends Model
{
    protected $table = 'empreendimento_field_values';

    protected $fillable = [
    'empreendimento_id',
    'field_definition_id',
    'value'
];

    public function field()
    {
        return $this->belongsTo(
            EmpreendimentoFieldDefinition::class,
            'field_definition_id'
        );
    }
public function definition()
{
    return $this->belongsTo(EmpreendimentoFieldDefinition::class, 'field_definition_id');
}
    public function empreendimento()
    {
        return $this->belongsTo(Empreendimento::class);
    }
}
