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

    // Subtipos dentro de type='task' — escolhidos pelo corretor ao criar
    // a tarefa. Permite que as regras de obrigatoriedade exijam
    // especificamente "ligação concluída" em vez de qualquer tarefa.
    //
    // Sprint 3.2c — expandido pros 7 tipos pedidos pela Marcia:
    // Ligação, WhatsApp, E-mail, Follow-up, Agendamento, Visita Presencial,
    // Reunião On-line. Mantemos 'anotacao' e 'generica' como fallbacks.
    public const KIND_LIGACAO     = 'ligacao';
    public const KIND_WHATSAPP    = 'whatsapp';
    public const KIND_EMAIL       = 'email';
    public const KIND_FOLLOWUP    = 'followup';
    public const KIND_AGENDAMENTO = 'agendamento';
    public const KIND_VISITA      = 'visita';       // Visita Presencial
    public const KIND_REUNIAO     = 'reuniao';      // Reunião On-line
    public const KIND_ANOTACAO    = 'anotacao';
    public const KIND_GENERICA    = 'generica';

    public const KINDS = [
        self::KIND_LIGACAO,
        self::KIND_WHATSAPP,
        self::KIND_EMAIL,
        self::KIND_FOLLOWUP,
        self::KIND_AGENDAMENTO,
        self::KIND_VISITA,
        self::KIND_REUNIAO,
        self::KIND_ANOTACAO,
        self::KIND_GENERICA,
    ];

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
        'task_kind',
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

    // Formato "Y-m-d H:i:s" sem `Z` força o frontend a interpretar as datas
    // como horário local (timezone do navegador/usuário) em vez de UTC.
    // Sem isso, o Laravel serializa `2026-04-23 14:00:00` como
    // `2026-04-23T14:00:00.000000Z` e o browser reinterpreta pra BRT,
    // mudando o dia e a hora que o corretor vê no card/calendário.
    protected $casts = [
        'starts_at'    => 'datetime:Y-m-d H:i:s',
        'ends_at'      => 'datetime:Y-m-d H:i:s',
        'due_at'       => 'datetime:Y-m-d H:i:s',
        'completed_at' => 'datetime:Y-m-d H:i:s',
        'reminder_at'  => 'datetime:Y-m-d H:i:s',
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

    /** Comentários da tarefa (mais recentes primeiro no feed). */
    public function comments()
    {
        return $this->hasMany(TaskComment::class, 'task_id')
                    ->with('user:id,name')
                    ->orderBy('created_at', 'asc');
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
