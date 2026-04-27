<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Appointment extends Model
{

    public const TYPE_TASK    = 'task';
    public const TYPE_VISIT   = 'visit';
    public const TYPE_CALL    = 'call';
    public const TYPE_MEETING = 'meeting';

    public const KIND_LIGACAO     = 'ligacao';
    public const KIND_WHATSAPP    = 'whatsapp';
    public const KIND_EMAIL       = 'email';
    public const KIND_FOLLOWUP    = 'followup';
    public const KIND_AGENDAMENTO = 'agendamento';
    public const KIND_VISITA      = 'visita';
    public const KIND_REUNIAO     = 'reuniao';
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

    /**
     * Slugs válidos pra task_kind: combina os fixos hardcoded acima
     * (compat com leads/seeds antigos) com os kinds ATIVOS criados
     * dinamicamente pelo admin via TaskKind.
     */
    public static function validKindSlugs(): array
    {
        try {
            $dynamic = \App\Models\TaskKind::activeSlugs();
        } catch (\Throwable $e) {
            $dynamic = [];
        }
        return array_values(array_unique(array_merge(self::KINDS, $dynamic)));
    }

    public const PRIORITY_LOW    = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH   = 'high';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_DONE      = 'done';
    public const STATUS_CANCELLED = 'cancelled';

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

    protected $casts = [
        'starts_at'    => 'datetime:Y-m-d H:i:s',
        'ends_at'      => 'datetime:Y-m-d H:i:s',
        'due_at'       => 'datetime:Y-m-d H:i:s',
        'completed_at' => 'datetime:Y-m-d H:i:s',
        'reminder_at'  => 'datetime:Y-m-d H:i:s',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Filtra appointments cujo lead esteja visível: ou tarefa interna (sem lead),
     * ou lead que NÃO está soft-deleted. Usar em listagens globais que cruzam
     * tarefas/agendamentos de vários leads.
     */
    public function scopeWithVisibleLead(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('lead_id')
              ->orWhereHas('lead');
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function comments()
    {
        return $this->hasMany(TaskComment::class, 'task_id')
                    ->with('user:id,name')
                    ->orderBy('created_at', 'asc');
    }

    public function scopeTasks(Builder $q): Builder
    {
        return $q->where('type', self::TYPE_TASK);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('completed_at');
    }

    public function scopeOverdue(Builder $q): Builder
    {
        return $q->whereNull('completed_at')
                 ->whereNotNull('due_at')
                 ->where('due_at', '<', now());
    }

    public function scopeDueToday(Builder $q): Builder
    {
        return $q->whereNull('completed_at')
                 ->whereDate('due_at', now()->toDateString());
    }

    public function scopeUpcoming(Builder $q): Builder
    {
        return $q->whereNull('completed_at')
                 ->whereNotNull('due_at')
                 ->whereDate('due_at', '>', now()->toDateString());
    }

    public function isOverdue(): bool
    {
        return $this->due_at
            && $this->completed_at === null
            && $this->due_at->isPast();
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
