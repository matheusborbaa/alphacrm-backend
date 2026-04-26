<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;

class MarkInactiveCorretoresOffline extends Command
{
    protected $signature   = 'corretores:mark-offline-inactive {--dry-run : Mostra quem seria marcado sem alterar}';
    protected $description = 'Marca corretores como offline quando não enviam heartbeat há X minutos (configurável)';

    public function handle(): int
    {
        $minutes = (int) Setting::get('corretor_auto_offline_minutes', 60);
        if ($minutes <= 0) {
            $this->info('Auto-offline desativado (corretor_auto_offline_minutes <= 0). Nada a fazer.');
            return self::SUCCESS;
        }

        $threshold = now()->subMinutes($minutes);

        $stale = User::query()
            ->where('status_corretor', 'disponivel')
            ->where(function ($q) use ($threshold) {
                $q->whereNull('last_seen_at')
                  ->orWhere('last_seen_at', '<', $threshold);
            })
            ->get(['id', 'name', 'email', 'last_seen_at']);

        if ($stale->isEmpty()) {
            $this->line('Nenhum corretor inativo encontrado.');
            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $this->info(($dry ? '[DRY-RUN] ' : '') . "Encontrados {$stale->count()} corretor(es) inativo(s) (threshold: {$minutes}min):");

        foreach ($stale as $u) {
            $last = $u->last_seen_at ? $u->last_seen_at->diffForHumans() : 'nunca';
            $this->line("  • #{$u->id} {$u->name} <{$u->email}> — última atividade: {$last}");
        }

        if ($dry) {
            return self::SUCCESS;
        }

        User::whereIn('id', $stale->pluck('id'))->update([
            'status_corretor' => 'offline',
        ]);

        $this->info("✓ {$stale->count()} corretor(es) marcado(s) como offline.");
        return self::SUCCESS;
    }
}
