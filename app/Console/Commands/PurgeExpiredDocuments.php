<?php

namespace App\Console\Commands;

use App\Models\LeadDocument;
use App\Models\LeadHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Varre lead_documents buscando registros cuja janela de retenção expirou
 * (purge_at <= now() e deleted_at já setado) e faz o hard-delete:
 *   - apaga o arquivo físico do disco
 *   - remove a row do banco
 *   - grava uma LeadHistory 'document_purged' pra rastro
 *
 * Agendado no routes/console.php pra rodar diariamente (de madrugada).
 * Executável manualmente via:  php artisan docs:purge-expired
 *
 * Idempotente: roda várias vezes sem causar dano (só atua em registros
 * que ainda existem e estão com purge_at vencido).
 */
class PurgeExpiredDocuments extends Command
{
    protected $signature = 'docs:purge-expired {--dry-run : Só lista o que seria apagado}';

    protected $description = 'Expurga documentos cuja janela de retenção já expirou (hard delete do arquivo + row)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = LeadDocument::query()
            ->whereNotNull('deleted_at')
            ->whereNotNull('purge_at')
            ->where('purge_at', '<=', now());

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Nenhum documento com janela de retenção expirada.');
            return Command::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Encontrados {$total} documentos pra expurgar.");

        $disk     = Storage::disk('local');
        $okCount  = 0;
        $errCount = 0;

        // Chunk pra não carregar tudo em memória quando houver muita coisa.
        $query->chunkById(200, function ($docs) use (&$okCount, &$errCount, $disk, $dryRun) {
            foreach ($docs as $doc) {
                try {
                    $this->line(sprintf(
                        '  - doc#%d (lead %d) "%s" purge_at=%s',
                        $doc->id,
                        $doc->lead_id,
                        $doc->original_name,
                        optional($doc->purge_at)->toDateTimeString() ?? '—'
                    ));

                    if ($dryRun) { $okCount++; continue; }

                    // Tenta apagar o arquivo; se não encontrar, não é fatal
                    // (pode ter sido removido fora de banda). Usa o mesmo
                    // resolver que o controller pra compat com paths antigos.
                    $path = $this->resolveStoragePath($disk, $doc->storage_path);
                    if ($path !== null) {
                        $disk->delete($path);
                    }

                    // História antes do delete pra manter a FK de lead_id.
                    LeadHistory::create([
                        'lead_id'     => $doc->lead_id,
                        'user_id'     => null, // ação do sistema
                        'type'        => 'document_purged',
                        'description' => mb_substr(
                            $doc->original_name . ' (expurgo automático após retenção)',
                            0, 500
                        ),
                    ]);

                    $doc->delete();
                    $okCount++;
                } catch (\Throwable $e) {
                    $errCount++;
                    Log::error('PurgeExpiredDocuments falhou pra doc ' . $doc->id, [
                        'exception' => $e->getMessage(),
                    ]);
                    $this->error("    ✗ erro no doc {$doc->id}: " . $e->getMessage());
                }
            }
        });

        $this->newLine();
        $this->info("Concluído. Ok: {$okCount}. Erros: {$errCount}.");

        return $errCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /** Espelha o resolveStoragePath do controller pra compat com paths 'private/' antigos. */
    private function resolveStoragePath($disk, string $stored): ?string
    {
        if ($disk->exists($stored)) return $stored;
        if (str_starts_with($stored, 'private/')) {
            $fallback = substr($stored, 8);
            if ($disk->exists($fallback)) return $fallback;
        }
        return null;
    }
}
