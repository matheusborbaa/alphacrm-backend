<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaFolder extends Model
{
    protected $fillable = [
        'parent_id',
        'empreendimento_id',
        'lead_id',
        'name',
        'created_by',
        'description',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MediaFolder::class, 'parent_id')->orderBy('name');
    }

    public function files(): HasMany
    {
        return $this->hasMany(MediaFile::class, 'folder_id')->orderBy('name');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function empreendimento(): BelongsTo
    {
        return $this->belongsTo(Empreendimento::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function fullPath(): string
    {
        $parts = [$this->name];
        $cur = $this->parent;
        while ($cur) {
            array_unshift($parts, $cur->name);
            $cur = $cur->parent;
        }
        return implode(' / ', $parts);
    }

    public function effectiveEmpreendimentoId(): ?int
    {
        if ($this->empreendimento_id) {
            return (int) $this->empreendimento_id;
        }
        $cur = $this->parent;
        while ($cur) {
            if ($cur->empreendimento_id) {
                return (int) $cur->empreendimento_id;
            }
            $cur = $cur->parent;
        }
        return null;
    }

    public function effectiveLeadId(): ?int
    {
        if ($this->lead_id) {
            return (int) $this->lead_id;
        }
        $cur = $this->parent;
        while ($cur) {
            if ($cur->lead_id) {
                return (int) $cur->lead_id;
            }
            $cur = $cur->parent;
        }
        return null;
    }
}
