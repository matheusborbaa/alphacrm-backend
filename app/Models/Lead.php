<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;


class Lead extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'whatsapp',
        'email',
        'source_id',
        'status_id',
        'lead_substatus_id',
        'assigned_user_id',
        'empreendimento_id',
        'city_of_interest',
        'region_of_interest',
        'assigned_at',
        'sla_deadline_at',
        'sla_status',
        'manychat_id',
        'channel',
        'campaign',
        'temperature',
        'value',
        'last_interaction_at',
        'status_changed_at',
    ];

    protected $casts = [
        'assigned_at'         => 'datetime',
        'sla_deadline_at'     => 'datetime',
        'last_interaction_at' => 'datetime',
        'status_changed_at'   => 'datetime',
        'value'               => 'decimal:2',
    ];

    public function corretor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    public function empreendimentos()
{
    return $this->belongsToMany(
        Empreendimento::class,
        'lead_empreendimentos',
        'lead_id',
        'empreendimento_id'
    );
}
public function histories()
{
    return $this->hasMany(LeadHistory::class);
}
    public function empreendimento()
{
    return $this->belongsTo(Empreendimento::class);
}

    public function interactions(): HasMany
    {
        return $this->hasMany(LeadInteraction::class);
    }



    // colocado por ultimo
public function substatus()
{
    return $this->belongsTo(LeadSubstatus::class, 'lead_substatus_id');
}

    /**
     * Valores dos campos customizados desse lead.
     */
    public function customFieldValues(): HasMany
    {
        return $this->hasMany(LeadCustomFieldValue::class);
    }

    /**
     * Atalho: pega o valor de um campo customizado pelo slug.
     */
    public function customValue(string $slug): ?string
    {
        return $this->customFieldValues()
            ->whereHas('customField', fn($q) => $q->where('slug', $slug))
            ->value('value');
    }
}
