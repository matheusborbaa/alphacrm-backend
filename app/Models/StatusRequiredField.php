<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatusRequiredField extends Model
{
    protected $table = 'status_required_fields';

    protected $fillable = [
        'lead_status_id',
        'lead_substatus_id',
        'lead_column',
        'custom_field_id',
        'required',
        'require_task',
        'require_task_kind',
        'require_task_completed',
    ];

    protected $casts = [
        'required'               => 'boolean',
        'require_task'           => 'boolean',
        'require_task_completed' => 'boolean',
    ];

    public const ALLOWED_LEAD_COLUMNS = [
        'name',
        'phone',
        'email',
        'source_id',
        'assigned_user_id',
        'empreendimento_id',
        'channel',
        'campaign',
    ];

    public function status(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'lead_status_id');
    }

    public function substatus(): BelongsTo
    {
        return $this->belongsTo(LeadSubstatus::class, 'lead_substatus_id');
    }

    public function customField(): BelongsTo
    {
        return $this->belongsTo(CustomField::class);
    }

    public function isLeadColumn(): bool
    {
        return !empty($this->lead_column);
    }

    public function isTaskRequirement(): bool
    {
        return (bool) $this->require_task;
    }
}
