<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;


class Lead extends Model
{
    /* -------------------------------------------------------------
     * ACL helpers
     *
     * Regra única de visibilidade de lead:
     *   - admin/gestor  → vê TODOS
     *   - corretor      → vê apenas onde assigned_user_id = user.id
     *
     * Centralizado aqui pra evitar divergência entre controllers
     * (Chat, Kanban, Reports etc). Se amanhã surgir "equipe" ou
     * "grupo de leads", troca aqui e o resto herda.
     * ------------------------------------------------------------- */

    /**
     * Scope: aplica filtro "leads visíveis por $user".
     * Uso: Lead::query()->visibleTo($user)->get().
     * Se $user for null, retorna nenhum lead (fail-safe).
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        $role = strtolower(trim((string) ($user->role ?? '')));
        if (in_array($role, ['admin', 'gestor'], true)) {
            return $query; // sem restrição
        }

        // corretor (ou qualquer outro role default): só os próprios
        return $query->where('assigned_user_id', $user->id);
    }

    /**
     * Helper booleano: a instância atual pode ser vista por $user?
     * Útil em validações pontuais (ex: anexar lead no chat).
     */
    public function isVisibleTo(?User $user): bool
    {
        if (!$user) return false;
        $role = strtolower(trim((string) ($user->role ?? '')));
        if (in_array($role, ['admin', 'gestor'], true)) return true;
        return (int) $this->assigned_user_id === (int) $user->id;
    }

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
