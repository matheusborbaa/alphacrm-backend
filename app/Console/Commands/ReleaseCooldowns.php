<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LeadAssignmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReleaseCooldowns extends Command
{
    protected $signature = 'leads:release-cooldowns {--dry-run : Só lista o que seria liberado}';

    protected $description = 'Libera corretores cujo cooldown pós-lead já expirou e tenta pegar órfãos';

    public function handle(LeadAssignmentService $assigner): int
    {
        $dryRun = (bool) $this->option('dry-run');

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

                    $user->update([
                        'status_corretor' => 'disponivel',
                        'cooldown_until'  => null,
                    ]);
                    $releasedToAvailable++;

                    $claimed = $assigner->tryClaimNextOrphan($user->fresh());
                    if ($claimed) {
                        $this->info("    ✓ lead órfão #{$claimed->id} atribuído");
                    }
                } else {

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
