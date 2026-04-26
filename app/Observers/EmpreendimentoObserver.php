<?php

namespace App\Observers;

use App\Models\Empreendimento;
use App\Services\MediaLibrarySync;

/**
 * Observa mudanças em Empreendimento pra manter a pasta-espelho da
 * Biblioteca de Mídia em sincronia.
 *
 * Cria pasta no `created`, renomeia no `updated` (quando name mudou),
 * deixa a pasta órfã no `deleted` (não apaga arquivos por segurança).
 *
 * Falhas são logadas mas NÃO propagam — sincronização da biblioteca
 * não pode quebrar o cadastro do empreendimento.
 */
class EmpreendimentoObserver
{
    public function __construct(private MediaLibrarySync $sync) {}

    public function created(Empreendimento $empreendimento): void
    {
        try {
            $this->sync->ensureFolderForEmpreendimento($empreendimento);
        } catch (\Throwable $e) {
            \Log::warning('Falha ao criar pasta da biblioteca pro empreendimento', [
                'empreendimento_id' => $empreendimento->id,
                'error'             => $e->getMessage(),
            ]);
        }
    }

    public function updated(Empreendimento $empreendimento): void
    {
        // Só faz sentido sincronizar se o nome mudou — evita writes inúteis
        // a cada update (mudança de preço, status, etc).
        if (!$empreendimento->wasChanged('name')) {
            return;
        }

        try {
            $this->sync->ensureFolderForEmpreendimento($empreendimento);
        } catch (\Throwable $e) {
            \Log::warning('Falha ao renomear pasta da biblioteca do empreendimento', [
                'empreendimento_id' => $empreendimento->id,
                'error'             => $e->getMessage(),
            ]);
        }
    }

    /**
     * `deleting` (e não `deleted`) é crítico: a FK nullOnDelete em
     * media_folders.empreendimento_id zera a relação assim que o
     * empreendimento sai do banco — perderíamos a referência. Aqui
     * pegamos a pasta enquanto o link ainda existe e a apagamos
     * (cascade derruba subpastas + media_files; o service também apaga
     * os arquivos físicos do disco).
     */
    public function deleting(Empreendimento $empreendimento): void
    {
        try {
            $this->sync->handleEmpreendimentoDeleted($empreendimento->id);
        } catch (\Throwable $e) {
            \Log::warning('Falha ao remover pasta da biblioteca do empreendimento', [
                'empreendimento_id' => $empreendimento->id,
                'error'             => $e->getMessage(),
            ]);
            // Não rethrow — exclusão da biblioteca não pode bloquear
            // exclusão do empreendimento (admin precisa ter controle).
        }
    }
}
