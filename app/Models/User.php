<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{

    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'active',
        'last_lead_assigned_at',
        'avatar',

        'status_corretor',

        'cooldown_until',

        'chat_read_receipts',

        'parent_user_id',
        'empreendimento_access_mode',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_lead_assigned_at' => 'datetime',
            'cooldown_until' => 'datetime',
            'chat_read_receipts' => 'boolean',
        ];
    }

	 public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_user_id');
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(LeadInteraction::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(User::class, 'parent_user_id');
    }

    public function empreendimentos(): BelongsToMany
    {
        return $this->belongsToMany(
            Empreendimento::class,
            'user_empreendimentos',
            'user_id',
            'empreendimento_id'
        )->withTimestamps();
    }

    public function effectiveRole(): string
    {
        $col = strtolower(trim((string) ($this->role ?? '')));
        $spatieName = '';
        $spatieType = '';
        try {
            $first = $this->roles()->first();
            if ($first) {
                $spatieName = strtolower(trim((string) $first->name));

                if (isset($first->type) && $first->type) {
                    $spatieType = strtolower(trim((string) $first->type));
                }
            }
        } catch (\Throwable $e) {

        }

        foreach (['admin', 'gestor', 'corretor'] as $candidate) {
            if ($spatieType === $candidate
                || $col === $candidate
                || $spatieName === $candidate) {
                return $candidate;
            }
        }
        return $col ?: $spatieName;
    }

    public function canAccessEmpreendimento(int $empreendimentoId): bool
    {
        if ($this->effectiveRole() === 'admin') {
            return true;
        }
        if ($this->empreendimento_access_mode === 'all') {
            return true;
        }
        return $this->empreendimentos()
            ->where('empreendimentos.id', $empreendimentoId)
            ->exists();
    }

    public function accessibleEmpreendimentoIds(): \Illuminate\Support\Collection
    {
        if ($this->effectiveRole() === 'admin' || $this->empreendimento_access_mode === 'all') {
            return Empreendimento::query()->pluck('id');
        }
        return $this->empreendimentos()->pluck('empreendimentos.id');
    }

    public function descendantIds(int $maxDepth = 10): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];
        $depth = 0;

        while (!empty($frontier) && $depth < $maxDepth) {
            $next = static::query()
                ->whereIn('parent_user_id', $frontier)
                ->pluck('id')
                ->all();

            $next = array_values(array_diff($next, $ids));
            if (empty($next)) break;

            $ids = array_merge($ids, $next);
            $frontier = $next;
            $depth++;
        }

        return $ids;
    }

    public function accessibleUserIds(): ?array
    {
        $role = $this->effectiveRole();
        if ($role === 'admin') return null;
        if ($role === 'gestor') return $this->descendantIds();
        return [$this->id];
    }
}
