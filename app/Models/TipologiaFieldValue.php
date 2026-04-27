<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipologiaFieldValue extends Model
{
    protected $table = 'tipologia_field_values';

    protected $fillable = [
        'tipologia_id',
        'field_definition_id',
        'value',
    ];

    public function definition()
    {
        return $this->belongsTo(TipologiaFieldDefinition::class, 'field_definition_id');
    }

    public function field()
    {
        return $this->belongsTo(TipologiaFieldDefinition::class, 'field_definition_id');
    }

    public function tipologia()
    {
        return $this->belongsTo(EmpreendimentoTipologia::class, 'tipologia_id');
    }
}
