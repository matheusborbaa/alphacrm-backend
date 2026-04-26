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
 *
 * Vínculo opcional com empreendimento (`empreendimento_id`):
 *   - Quando setado, só é listada pra usuários que canAccessEmpreendimento()
 *   - Subpastas/arquivos dentro herdam o scope (resolvido via
 *     effectiveEmpreendimentoId() caminhando ancestrais)
 *
 * `is_system`:
 *   - Pasta gerenciada pelo backend (raiz EMPREENDIMENTOS, pasta de cada
 *     empreendimento). UI esconde botão "Excluir"; controller rejeita delete.
 *   - User só consegue criar/apagar conteúdo DENTRO de pastas system, não
 *     a pasta system em si.
 */
class MediaFolder extends Model
{
    protected $fillable = [
        'parent_id',
        'empreendimento_id',
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

    /**
     * Resolve o empreendimento_id "efetivo" desta pasta caminhando pra cima
     * na árvore até achar um ancestral com empreendimento_id setado. Retorna
     * null se nenhuma pasta da hierarquia tem vínculo.
     *
     * Use case: subpasta "Plantas" dentro de "/EMPREENDIMENTOS/Reserva Verde/"
     * herda o empreendimento_id de "Reserva Verde" — corretor sem acesso ao
     * empreendimento não vê nada dessa subárvore.
     *
     * Performance: caminhada O(profundidade). A biblioteca é tipicamente rasa
     * (3-4 níveis), então está OK. Se virar bottleneck, cacheamos o
     * effective_empreendimento_id direto na tabela via observer.
     */
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
}
