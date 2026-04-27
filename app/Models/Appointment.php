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


    public const MODALITY_PRESENCIAL = 'presencial';
    public const MODALITY_ONLINE     = 'online';

    public const CONFIRM_PENDING   = 'pending';
    public const CONFIRM_CONFIRMED = 'confirmed';
    public const CONFIRM_COMPLETED = 'completed';
    public const CONFIRM_NO_SHOW   = 'no_show';
    public const CONFIRM_CANCELLED = 'cancelled';

    protected $fillable = [
        'title',
        'lead_id',
        'user_id',
        'type',
        'task_kind',
        'modality',
        'description',
        'location',
        'attendee_email',
        'attendee_phone',
        'starts_at',
        'ends_at',
        'due_at',
        'completed_at',
        'completed_by',
        'priority',
        'reminder_at',
        'status',
        'confirmation_status',
        'confirmation_token',
        'lead_confirmed_at',
        'cancellation_reason',
        'meeting_url',
        'external_event_id',
        'external_event_etag',
        'last_synced_at',
        'last_sync_error',
        'reminder_sent_24h_at',
        'reminder_sent_1h_at',
        'scope',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'starts_at'             => 'datetime:Y-m-d H:i:s',
        'ends_at'               => 'datetime:Y-m-d H:i:s',
        'due_at'                => 'datetime:Y-m-d H:i:s',
        'completed_at'          => 'datetime:Y-m-d H:i:s',
        'reminder_at'           => 'datetime:Y-m-d H:i:s',
        'lead_confirmed_at'     => 'datetime',
        'last_synced_at'        => 'datetime',
        'reminder_sent_24h_at'  => 'datetime',
        'reminder_sent_1h_at'   => 'datetime',
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


    public function isVisit(): bool
    {
        return $this->type === self::TYPE_VISIT
            || $this->task_kind === self::KIND_VISITA
            || $this->task_kind === self::KIND_AGENDAMENTO;
    }


    protected static function booted(): void
    {
        static::creating(function (Appointment $appt) {
            if ($appt->isVisit() && empty($appt->confirmation_token)) {
                $appt->confirmation_token = self::generateUniqueToken();
            }
            if ($appt->isVisit() && empty($appt->confirmation_status)) {
                $appt->confirmation_status = self::CONFIRM_PENDING;
            }
        });
    }

    private static function generateUniqueToken(): string
    {
        do {
            $token = bin2hex(random_bytes(24));
        } while (self::where('confirmation_token', $token)->exists());
        return $token;
    }


    public function isVisitOnline(): bool
    {
        return $this->modality === self::MODALITY_ONLINE;
    }
}
