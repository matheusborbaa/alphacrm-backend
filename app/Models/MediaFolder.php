<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pasta da biblioteca de mídia (área do corretor).
 *
 * Ver migration 2026_04_26_*_create_media_library_tables.php pro contexto.
 *
 * Hierárquica via `parent_id` (self-reference). NULL = raiz.
 * Cascade delete propaga: deletar pasta apaga subpastas + arquivos.
 */
class MediaFolder extends Model
{
    protected $fillable = [
        'parent_id',
        'name',
        'created_by',
        'description',
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

    /**
     * Resolve o caminho hierárquico da pasta (ex: "Materiais / Vídeos / Janeiro").
     * Útil pra breadcrumb na UI.
     */
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
}
