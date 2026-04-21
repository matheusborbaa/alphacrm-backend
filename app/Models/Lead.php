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
        'email',
        'source_id',
        'status_id',
        'assigned_user_id',
        'empreendimento_id',
        'assigned_at',
        'sla_deadline_at',
        'sla_status',
        'manychat_id',
        'channel',
        'campaign',
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
}
