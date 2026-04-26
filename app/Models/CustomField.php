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

    /**
     * Tipos válidos. Usar pra validação no controller.
     *
     * 'file' — upload de arquivo. Diferente dos outros, o `value` da
     * LeadCustomFieldValue guarda um JSON com {path, name, size, mime}
     * em vez de string simples. O arquivo fica em storage privado
     * (lead_custom_files/{lead_id}/{field_id}/...) e é servido via
     * LeadCustomFieldFileController. Não usa `options` nem `mask`;
     * limites opcionais (`file_max_mb`, `file_accept`) podem ser
     * armazenados em `options` como dict {max_mb, accept: ".pdf,.jpg"}.
     */
    public const TYPES = ['text', 'textarea', 'number', 'date', 'select', 'checkbox', 'file'];

    /**
     * Default máximo de upload (MB) quando o admin não definiu nenhum.
     * Bate com o post_max_size típico do PHP em hospedagem compartilhada.
     */
    public const FILE_DEFAULT_MAX_MB = 10;

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
