<?php

namespace App\Console\Commands;

use App\Models\Empreendimento;
use App\Services\MediaLibrarySync;
use Illuminate\Console\Command;

class SyncMediaLibraryEmpreendimentos extends Command
{
    protected $signature   = 'media:sync-empreendimentos {--dry-run : Mostra o que faria sem gravar}';
    protected $description = 'Cria/atualiza pastas-espelho na biblioteca de mídia pra empreendimentos existentes';

    public function handle(MediaLibrarySync $sync): int
    {
        $dry = (bool) $this->option('dry-run');
        $emps = Empreendimento::orderBy('name')->get();

        if ($emps->isEmpty()) {
            $this->info('Nenhum empreendimento cadastrado. Nada a fazer.');
            return self::SUCCESS;
        }

        if ($dry) {
            $this->warn('=== DRY-RUN: nada será gravado ===');
        }

        if (!$dry) {
            $sync->ensureRootFolder();
        } else {
            $this->line('• Garantiria pasta raiz "EMPREENDIMENTOS"');
        }

        $created = 0;
        $renamed = 0;
        $unchanged = 0;

        foreach ($emps as $emp) {
            $existing = \App\Models\MediaFolder::where('empreendimento_id', $emp->id)->first();

            if (!$existing) {
                $action = "CRIAR pasta «{$emp->name}» (empreendimento_id={$emp->id})";
                $created++;
            } elseif ($existing->name !== $emp->name) {
                $action = "RENOMEAR pasta {$existing->id}: «{$existing->name}» → «{$emp->name}»";
                $renamed++;
            } else {
                $unchanged++;
                continue;
            }

            $this->line('• ' . $action);
            if (!$dry) {
                $sync->ensureFolderForEmpreendimento($emp);
            }
        }

        $this->newLine();
        $this->info("Criadas: {$created} · Renomeadas: {$renamed} · Já em dia: {$unchanged}");
        return self::SUCCESS;
    }
}
