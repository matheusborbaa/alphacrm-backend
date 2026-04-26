<?php

namespace App\Observers;

use App\Models\Empreendimento;
use App\Services\MediaLibrarySync;

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

    public function deleting(Empreendimento $empreendimento): void
    {
        try {
            $this->sync->handleEmpreendimentoDeleted($empreendimento->id);
        } catch (\Throwable $e) {
            \Log::warning('Falha ao remover pasta da biblioteca do empreendimento', [
                'empreendimento_id' => $empreendimento->id,
                'error'             => $e->getMessage(),
            ]);

        }
    }
}
