<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
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
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'active',
        'last_lead_assigned_at',
        'avatar',
        // Usado pelo rodízio: UserController@updateStatus grava aqui via
        // $user->update(['status_corretor' => ...]). Sem estar em $fillable,
        // Mass Assignment Protection do Eloquent IGNORA SILENCIOSAMENTE e
        // o DB nunca persiste a mudança (bug: select do corretor "voltava"
        // pra offline sempre que a home recarregava).
        'status_corretor',
        // Cooldown pós-lead: timestamp até quando o corretor fica "travado"
        // sem receber novos leads, mesmo que esteja 'disponivel'.
        'cooldown_until',
        // Sprint 3.8d — preferência pessoal de confirmação de leitura no chat.
        // true (default) = usuário expõe quando leu e vê quando foram lidas as dele.
        // false = desliga em AMBAS as direções (reciprocidade).
        'chat_read_receipts',
        // Sprint Seccionamento — hierarquia gestor→corretor + permissão por empreendimento.
        'parent_user_id',
        'empreendimento_access_mode', // 'all' | 'specific'
    ];

   
    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
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

    /* ================================================================
     * Sprint Seccionamento — hierarquia + permissão por empreendimento
     * ================================================================ */

    /**
     * Gestor responsável (corretor → gestor). Null pra admin/gestor "raiz".
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    /**
     * Subordinados diretos (gestor → corretores). Inverso de manager().
     */
    public function subordinates(): HasMany
    {
        return $this->hasMany(User::class, 'parent_user_id');
    }

    /**
     * Empreendimentos que o user pode atender. Lê do pivot user_empreendimentos.
     * NOTA: essa relação só é "verdadeira" quando empreendimento_access_mode='specific'.
     * Quando ='all', o user vê TODOS os empreendimentos (a relation não é
     * consultada). Use `accessibleEmpreendimentoIds()` pra abstrair isso.
     */
    public function empreendimentos(): BelongsToMany
    {
        return $this->belongsToMany(
            Empreendimento::class,
            'user_empreendimentos',
            'user_id',
            'empreendimento_id'
        )->withTimestamps();
    }

    /**
     * Decide se este user pode atender o empreendimento $id.
     *
     * Regras (em ordem):
     *   1. Admin → sempre true (full access, sem filtro)
     *   2. access_mode='all' → true (modelo dinâmico — pega tudo)
     *   3. access_mode='specific' → checa se id está na pivot
     *
     * Usado no LeadAssignmentService pra filtrar pool de candidatos e
     * em validações de UI (esconder ações em empreendimentos sem acesso).
     */
    public function canAccessEmpreendimento(int $empreendimentoId): bool
    {
        if (strtolower((string) ($this->role ?? '')) === 'admin') {
            return true;
        }
        if ($this->empreendimento_access_mode === 'all') {
            return true;
        }
        return $this->empreendimentos()
            ->where('empreendimentos.id', $empreendimentoId)
            ->exists();
    }

    /**
     * Lista de empreendimento_ids que este user pode atender. Retorna
     * collection de IDs (int) pra usar em whereIn() em queries.
     *
     * Quando admin ou access_mode='all' → retorna TODOS os ids da tabela
     * empreendimentos (resolução dinâmica do "todos").
     * Quando access_mode='specific' → retorna só os do pivot.
     *
     * Cuidado: pode ficar pesado em bases com muitos empreendimentos.
     * Pra checar UM empreendimento específico, prefira canAccessEmpreendimento().
     */
    public function accessibleEmpreendimentoIds(): \Illuminate\Support\Collection
    {
        $isAdmin = strtolower((string) ($this->role ?? '')) === 'admin';
        if ($isAdmin || $this->empreendimento_access_mode === 'all') {
            return Empreendimento::query()->pluck('id');
        }
        return $this->empreendimentos()->pluck('empreendimentos.id');
    }
}
