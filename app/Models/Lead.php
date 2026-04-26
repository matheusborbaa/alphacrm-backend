<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use SoftDeletes;


    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        $role = strtolower(trim((string) ($user->role ?? '')));
        if (in_array($role, ['admin', 'gestor'], true)) {
            return $query;
        }

        return $query->where('assigned_user_id', $user->id);
    }

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

        'deleted_by_user_id',
        'deletion_reason',
    ];

    protected $casts = [
        'assigned_at'         => 'datetime',
        'sla_deadline_at'     => 'datetime',
        'last_interaction_at' => 'datetime',
        'status_changed_at'   => 'datetime',
        'value'               => 'decimal:2',
    ];

    /**
     * Normaliza um telefone pra forma canônica usada nas comparações de duplicidade.
     * - Mantém só dígitos
     * - Se vier com código de país (Brasil = 55) e o resultado tiver mais de 11 dígitos,
     *   pega os últimos 11 (DDD + número celular/fixo)
     * - Retorna null pra strings vazias
     */
    public static function normalizePhone(?string $value): ?string
    {
        if ($value === null) return null;
        $digits = preg_replace('/\D/', '', $value);
        if ($digits === '') return null;
        if (strlen($digits) > 11) {
            $digits = substr($digits, -11);
        }
        return $digits;
    }


    public function setPhoneAttribute($value): void
    {
        $this->attributes['phone'] = $value;
        $this->attributes['phone_normalized'] = self::normalizePhone($value);
    }

    public function setWhatsappAttribute($value): void
    {
        $this->attributes['whatsapp'] = $value;
        $this->attributes['whatsapp_normalized'] = self::normalizePhone($value);
    }

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

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

public function substatus()
{
    return $this->belongsTo(LeadSubstatus::class, 'lead_substatus_id');
}

    public function customFieldValues(): HasMany
    {
        return $this->hasMany(LeadCustomFieldValue::class);
    }

    public function customValue(string $slug): ?string
    {
        return $this->customFieldValues()
            ->whereHas('customField', fn($q) => $q->where('slug', $slug))
            ->value('value');
    }
}
