<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LeadAssignmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Libera corretores cujo cooldown pós-lead já expirou.
 *
 * Fluxo:
 *   1. Acha users com cooldown_until <= now() (ainda seja pelo marker
 *      do cooldown, não apenas timestamp solto).
 *   2. Se estavam marcados como 'ocupado' pelo cooldown, voltam pra
 *      'disponivel' automaticamente. Se o admin já mudou pra 'offline'
 *      manualmente (quis sair antes de expirar), só zeramos o timestamp
 *      e mantemos o status atual.
 *   3. Pra cada corretor que voltou a 'disponivel', chama
 *      tryClaimNextOrphan pra pegar o lead mais antigo da fila.
 *
 * Agendado em routes/console.php pra rodar a cada 1 minuto.
 * Idempotente — rodar várias vezes é seguro.
 *
 * Executável manual:  php artisan leads:release-cooldowns
 */
class ReleaseCooldowns extends Command
{
    protected $signature = 'leads:release-cooldowns {--dry-run : Só lista o que seria liberado}';

    protected $description = 'Libera corretores cujo cooldown pós-lead já expirou e tenta pegar órfãos';

    public function handle(LeadAssignmentService $assigner): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Todos os que têm cooldown vencido. Incluímos quem está em 'ocupado'
        // (cooldown ativo que vamos virar disponivel) E quem está em 'offline'
        // (cooldown residual — só limpa o timestamp, não mexe no status).
        $candidates = User::whereNotNull('cooldown_until')
            ->where('cooldown_until', '<=', now())
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Nenhum cooldown expirado.');
            return Command::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Encontrados {$candidates->count()} corretores com cooldown expirado.");

        $releasedToAvailable = 0;
        $justCleared         = 0;
        $errors              = 0;

        foreach ($candidates as $user) {
            try {
                $currentStatus = strtolower((string) ($user->status_corretor ?? ''));

                $this->line(sprintf(
                    '  - user#%d (%s) status=%s cooldown_until=%s',
                    $user->id,
                    $user->name,
                    $currentStatus ?: '—',
                    optional($user->cooldown_until)->toDateTimeString() ?? '—'
                ));

                if ($dryRun) { continue; }

                if ($currentStatus === 'ocupado') {
                    // Saindo do cooldown → volta pra disponível.
                    $user->update([
                        'status_corretor' => 'disponivel',
                        'cooldown_until'  => null,
                    ]);
                    $releasedToAvailable++;

                    // Tenta pegar o lead órfão mais antigo.
                    $claimed = $assigner->tryClaimNextOrphan($user->fresh());
                    if ($claimed) {
                        $this->info("    ✓ lead órfão #{$claimed->id} atribuído");
                    }
                } else {
                    // Cooldown residual em offline/disponivel (caso raro — o
                    // corretor mudou manualmente). Só limpa o timestamp.
                    $user->update(['cooldown_until' => null]);
                    $justCleared++;
                }
            } catch (\Throwable $e) {
                $errors++;
                Log::error('ReleaseCooldowns falhou pra user ' . $user->id, [
                    'exception' => $e->getMessage(),
                ]);
                $this->error("    ✗ erro no user {$user->id}: " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("Concluído. Liberados pra disponivel: {$releasedToAvailable}. Timestamps limpos: {$justCleared}. Erros: {$errors}.");

        return $errors === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
