<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * Appointment representa QUALQUER compromisso/tarefa/visita na agenda.
 *
 * O type discrimina a finalidade:
 *   - 'task'    → tarefa/follow-up (usa due_at, priority, reminder_at)
 *   - 'visit'   → visita ao empreendimento (starts_at/ends_at)
 *   - 'call'    → ligação agendada
 *   - 'meeting' → reunião interna
 *
 * Uma tarefa concluída tem completed_at preenchido; uma aberta não.
 * Use os scopes `tasks()`, `open()`, `overdue()`, `dueToday()` pra
 * filtrar de forma legível sem repetir whereNull/whereDate por aí.
 */
class Appointment extends Model
{
    /* ------------------------------------------------------------------
     * CONSTANTES
     * ------------------------------------------------------------------ */
    public const TYPE_TASK    = 'task';
    public const TYPE_VISIT   = 'visit';
    public const TYPE_CALL    = 'call';
    public const TYPE_MEETING = 'meeting';

    public const PRIORITY_LOW    = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH   = 'high';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_DONE      = 'done';
    public const STATUS_CANCELLED = 'cancelled';

    /* ------------------------------------------------------------------
     * MASS ASSIGNMENT + CASTS
     * ------------------------------------------------------------------ */
    protected $fillable = [
        'title',
        'lead_id',
        'user_id',
        'type',
        'description',
        'starts_at',
        'ends_at',
        'due_at',
        'completed_at',
        'completed_by',
        'priority',
        'reminder_at',
        'status',
        'scope',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'starts_at'    => 'datetime',
        'ends_at'      => 'datetime',
        'due_at'       => 'datetime',
        'completed_at' => 'datetime',
        'reminder_at'  => 'datetime',
    ];

    /* ------------------------------------------------------------------
     * RELACIONAMENTOS
     * ------------------------------------------------------------------ */

    /** Usuário responsável (quem vai cumprir a tarefa / fazer a visita). */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Lead ao qual o compromisso se refere (opcional — pode ser tarefa interna). */
    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    /** Quem criou o registro. Distinto do user_id (o dono). */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Quem marcou como concluída. Null se ainda aberta. */
    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /* ------------------------------------------------------------------
     * SCOPES — açúcar pras queries mais repetidas
     * ------------------------------------------------------------------ */

    /** Só tarefas (type='task'), descartando visitas/reuniões. */
    public function scopeTasks(Builder $q): Builder
    {
        return $q->where('type', self::TYPE_TASK);
    }

    /** Tarefas abertas (ainda não concluídas). */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('completed_at');
    }

    /** Atrasadas: prazo passado e ainda não concluídas. */
    public function scopeOverdue(Builder $q): Builder
    {
        return $q->whereNull('completed_at')
                 ->whereNotNull('due_at')
                 ->where('due_at', '<', now());
    }

    /** Vencem hoje (ainda abertas). */
    public function scopeDueToday(Builder $q): Builder
    {
        return $q->whereNull('completed_at')
                 ->whereDate('due_at', now()->toDateString());
    }

    /** Vencem depois de hoje (ainda abertas). */
    public function scopeUpcoming(Builder $q): Builder
    {
        return $q->whereNull('completed_at')
                 ->whereNotNull('due_at')
                 ->whereDate('due_at', '>', now()->toDateString());
    }

    /* ------------------------------------------------------------------
     * HELPERS
     * ------------------------------------------------------------------ */

    /** True se a tarefa está atrasada (prazo passou e ainda não concluiu). */
    public function isOverdue(): bool
    {
        return $this->due_at
            && $this->completed_at === null
            && $this->due_at->isPast();
    }

    /** True se tá marcada como concluída. */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
