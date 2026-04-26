<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\MediaFolder;
use App\Services\MediaLibrarySync;
use Illuminate\Console\Command;

/**
 * Backfill: cria pasta-espelho na biblioteca pra leads que já existiam
 * antes da feature subir. Idempotente — pode rodar quantas vezes quiser,
 * só cria o que tá faltando e renomeia o que mudou.
 *
 * Uso:
 *   php artisan media:sync-leads
 *   php artisan media:sync-leads --dry-run
 *
 * IMPORTANTE: bases com muitos leads (10k+) podem demorar. Roda em chunks
 * de 200 pra não estourar memória.
 */
class SyncMediaLibraryLeads extends Command
{
    protected $signature   = 'media:sync-leads {--dry-run : Mostra o que faria sem gravar}';
    protected $description = 'Cria/atualiza pastas-espelho na biblioteca de mídia pra leads existentes';

    public function handle(MediaLibrarySync $sync): int
    {
        $dry = (bool) $this->option('dry-run');
        $total = Lead::count();

        if ($total === 0) {
            $this->info('Nenhum lead cadastrado. Nada a fazer.');
            return self::SUCCESS;
        }

        $this->info(($dry ? '[DRY-RUN] ' : '') . "Processando {$total} lead(s)...");

        if (!$dry) {
            $sync->ensureLeadsRootFolder();
        } else {
            $this->line('• Garantiria pasta raiz "LEADS"');
        }

        $created = 0;
        $renamed = 0;
        $unchanged = 0;

        Lead::orderBy('id')->chunkById(200, function ($leads) use ($sync, $dry, &$created, &$renamed, &$unchanged) {
            foreach ($leads as $lead) {
                $existing = MediaFolder::where('lead_id', $lead->id)->first();
                $desiredName = trim((string) $lead->name) . ' (#' . $lead->id . ')';

                if (!$existing) {
                    if ($dry) {
                        $this->line("  • CRIAR: {$desiredName}");
                    } else {
                        $sync->ensureFolderForLead($lead);
                    }
                    $created++;
                } elseif ($existing->name !== $desiredName) {
                    if ($dry) {
                        $this->line("  • RENOMEAR pasta {$existing->id}: «{$existing->name}» → «{$desiredName}»");
                    } else {
                        $sync->ensureFolderForLead($lead);
                    }
                    $renamed++;
                } else {
                    $unchanged++;
                }
            }
        });

        $this->newLine();
        $this->info("Criadas: {$created} · Renomeadas: {$renamed} · Já em dia: {$unchanged}");
        return self::SUCCESS;
    }
}
