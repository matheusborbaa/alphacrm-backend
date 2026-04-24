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
 *   - Se require_task=true: não precisa de lead_column nem custom_field_id
 *     (é uma regra "precisa ter tarefa registrada")
 *   - Se require_task=false: exatamente 1 de {lead_column, custom_field_id}
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
        'require_task',
    ];

    protected $casts = [
        'required'     => 'boolean',
        'require_task' => 'boolean',
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

    /**
     * Atalho: é regra que exige tarefa registrada (true) em vez de campo?
     */
    public function isTaskRequirement(): bool
    {
        return (bool) $this->require_task;
    }
}
