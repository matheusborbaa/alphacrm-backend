<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regra que diz: "quando o lead entrar nesse status (ou substatus),
 * esse campo (fixo ou custom) é obrigatório".
 *
 * Regras de integridade (garantidas no controller, não no banco):
 *   - Exatamente 1 de {lead_status_id, lead_substatus_id}
 *   - Exatamente 1 de {lead_column, custom_field_id}
 */
class StatusRequiredField extends Model
{
    protected $table = 'status_required_fields';

    protected $fillable = [
        'lead_status_id',
        'lead_substatus_id',
        'lead_column',
        'custom_field_id',
        'required',
    ];

    protected $casts = [
        'required' => 'boolean',
    ];

    /**
     * Colunas do lead que podem ser exigidas como obrigatórias.
     * Whitelist pra evitar que alguém exija coluna inexistente ou sensível.
     */
    public const ALLOWED_LEAD_COLUMNS = [
        'name',
        'phone',
        'email',
        'source_id',
        'assigned_user_id',
        'empreendimento_id',
        'channel',
        'campaign',
    ];

    public function status(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'lead_status_id');
    }

    public function substatus(): BelongsTo
    {
        return $this->belongsTo(LeadSubstatus::class, 'lead_substatus_id');
    }

    public function customField(): BelongsTo
    {
        return $this->belongsTo(CustomField::class);
    }

    /**
     * Atalho: é regra de coluna fixa (true) ou de custom field (false)?
     */
    public function isLeadColumn(): bool
    {
        return !empty($this->lead_column);
    }
}
