<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LeadAssignmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReleaseCooldowns extends Command
{
    protected $signature = 'leads:release-cooldowns {--dry-run : Só lista o que seria liberado}';

    protected $description = 'Libera corretores cujo cooldown ou pausa expirou e tenta pegar órfãos';

    public function handle(LeadAssignmentService $assigner): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $cooldownReleased = $this->releaseCooldowns($assigner, $dryRun);
        $pauseReleased    = $this->releasePauses($assigner, $dryRun);

        $this->newLine();
        $this->info("Concluído. Cooldowns liberados: {$cooldownReleased}. Pausas liberadas: {$pauseReleased}.");

        return Command::SUCCESS;
    }

    private function releaseCooldowns(LeadAssignmentService $assigner, bool $dryRun): int
    {
        $candidates = User::whereNotNull('cooldown_until')
            ->where('cooldown_until', '<=', now())
            ->get();

        if ($candidates->isEmpty()) {
            $this->line('Nenhum cooldown expirado.');
            return 0;
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Encontrados {$candidates->count()} corretores com cooldown expirado.");

        $released = 0;
        foreach ($candidates as $user) {
            try {
                $currentStatus = strtolower((string) ($user->status_corretor ?? ''));
                if ($dryRun) {
                    $this->line("  - user#{$user->id} ({$user->name}) cooldown expirado");
                    continue;
                }

                if ($currentStatus === 'ocupado') {
                    $user->update(['status_corretor' => 'disponivel', 'cooldown_until' => null]);
                    $released++;

                    $claimed = $assigner->tryClaimNextOrphan($user->fresh());
                    if ($claimed) $this->info("    ✓ user#{$user->id}: lead órfão #{$claimed->id} atribuído");
                } else {
                    $user->update(['cooldown_until' => null]);
                }
            } catch (\Throwable $e) {
                Log::error('ReleaseCooldowns cooldown falhou user ' . $user->id, ['err' => $e->getMessage()]);
            }
        }
        return $released;
    }

    private function releasePauses(LeadAssignmentService $assigner, bool $dryRun): int
    {
        $candidates = User::whereNotNull('paused_until')
            ->where('paused_until', '<=', now())
            ->get();

        if ($candidates->isEmpty()) {
            $this->line('Nenhuma pausa expirada.');
            return 0;
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Encontrados {$candidates->count()} corretores com pausa expirada.");

        $released = 0;
        foreach ($candidates as $user) {
            try {
                if ($dryRun) {
                    $this->line("  - user#{$user->id} ({$user->name}) pausa expirada (motivo: {$user->pause_reason})");
                    continue;
                }


                $update = ['paused_until' => null, 'pause_reason' => null];
                if (strcasecmp((string) $user->status_corretor, 'disponivel') === 0) {

                } else {

                    $update['status_corretor'] = 'disponivel';
                }

                $user->update($update);
                $released++;


                $claimed = $assigner->tryClaimNextOrphan($user->fresh());
                if ($claimed) $this->info("    ✓ user#{$user->id} retomou e pegou lead órfão #{$claimed->id}");
            } catch (\Throwable $e) {
                Log::error('ReleaseCooldowns pause falhou user ' . $user->id, ['err' => $e->getMessage()]);
            }
        }
        return $released;
    }
}
