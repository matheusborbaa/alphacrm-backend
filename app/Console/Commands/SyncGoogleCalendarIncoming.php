<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserGoogleCredential;
use App\Services\GoogleCalendarService;
use Illuminate\Console\Command;

// Pull incremental do Calendar pra cada user conectado. syncToken cuida pra só puxar diff.
// --reset zera o sync_token e força um full sync no próximo run (precisa quando o token expira).
class SyncGoogleCalendarIncoming extends Command
{
    protected $signature = 'google:sync-incoming
        {--user= : Limitar a um usuário específico}
        {--reset : Zera sync_token e força full sync}';

    protected $description = 'Pull incremental do Google Calendar pra cada usuário conectado';

    public function handle(GoogleCalendarService $service): int
    {
        if (!$service->isConfigured()) {
            $this->error('Google não configurado (GOOGLE_CLIENT_ID/SECRET no .env). Saindo.');
            return self::FAILURE;
        }

        $query = UserGoogleCredential::query();
        if ($this->option('user')) {
            $query->where('user_id', (int) $this->option('user'));
        }

        if ($this->option('reset')) {
            $query->update(['sync_token' => null]);
            $this->info('Sync tokens resetados. Próximo sync vai puxar tudo de novo.');
        }

        $creds = $query->get();
        if ($creds->isEmpty()) {
            $this->info('Nenhum usuário conectado.');
            return self::SUCCESS;
        }

        $this->info("Sincronizando {$creds->count()} usuário(s) com Google Calendar...");

        $totals = ['fetched' => 0, 'updated' => 0, 'cancelled' => 0, 'errors' => 0];

        foreach ($creds as $cred) {
            $user = User::find($cred->user_id);
            if (!$user) continue;

            $this->line(" • {$user->name} ({$cred->email})");
            try {
                $r = $service->pullChangesForUser($user);
                $totals['fetched']   += $r['fetched'];
                $totals['updated']   += $r['updated'];
                $totals['cancelled'] += $r['cancelled'];
                $this->line("   ↳ {$r['fetched']} fetched, {$r['updated']} atualizadas, {$r['cancelled']} canceladas");
            } catch (\Throwable $e) {
                $totals['errors']++;
                $this->error("   ↳ ERRO: " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("Total: {$totals['fetched']} eventos lidos, {$totals['updated']} atualizadas, {$totals['cancelled']} canceladas, {$totals['errors']} erros.");

        return self::SUCCESS;
    }
}
