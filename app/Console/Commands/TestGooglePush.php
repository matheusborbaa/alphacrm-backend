<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Console\Command;

/**
 * Diagnóstico — testa se uma appointment específica consegue ser pushada pro Google.
 *
 * Uso:
 *   php artisan google:test-push 42       (push da appointment id=42)
 *   php artisan google:test-push --last   (pega a última criada)
 */
class TestGooglePush extends Command
{
    protected $signature = 'google:test-push
        {id? : ID da appointment (ou use --last)}
        {--last : Usa a última appointment criada}';

    protected $description = 'Testa push manual de uma appointment pro Google Calendar (diagnóstico)';

    public function handle(GoogleCalendarService $service): int
    {

        $this->line('=== Configuração ===');
        $this->line('Pacote google/apiclient instalado: ' . ($service->isInstalled() ? 'SIM' : 'NÃO'));
        $this->line('Configurado (.env tem CLIENT_ID/SECRET/REDIRECT): ' . ($service->isConfigured() ? 'SIM' : 'NÃO'));

        if (!$service->isConfigured()) {
            $this->error('Configuração faltando. Verifique .env: GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI');
            return self::FAILURE;
        }

        $this->newLine();


        $appt = null;
        if ($this->option('last')) {
            $appt = Appointment::orderByDesc('id')->first();
        } elseif ($this->argument('id')) {
            $appt = Appointment::find((int) $this->argument('id'));
        } else {
            $this->error('Passe um ID ou use --last');
            return self::FAILURE;
        }

        if (!$appt) {
            $this->error('Appointment não encontrada.');
            return self::FAILURE;
        }

        $this->line('=== Appointment ===');
        $this->line("ID: {$appt->id}");
        $this->line("Title: {$appt->title}");
        $this->line("Type: {$appt->type}");
        $this->line("Task kind: {$appt->task_kind}");
        $this->line("isVisit(): " . ($appt->isVisit() ? 'TRUE' : 'FALSE'));
        $this->line("User ID: {$appt->user_id}");
        $this->line("Starts at: " . ($appt->starts_at ?: 'NULL'));
        $this->line("Due at: " . ($appt->due_at ?: 'NULL'));
        $this->line("Modality: " . ($appt->modality ?: 'NULL'));
        $this->line("External event ID: " . ($appt->external_event_id ?: 'NULL — ainda não foi pushada'));
        $this->newLine();

        if (!$appt->isVisit()) {
            $this->warn('isVisit() retornou false — não vai pushar. Verifique se task_kind é visita/agendamento/reuniao.');
            return self::SUCCESS;
        }

        $user = User::find($appt->user_id);
        if (!$user) {
            $this->error("User {$appt->user_id} não encontrado.");
            return self::FAILURE;
        }

        $this->line("Owner: {$user->name} ({$user->email})");
        $this->line("Conectado ao Google: " . ($service->isUserConnected($user) ? 'SIM' : 'NÃO'));

        if (!$service->isUserConnected($user)) {
            $this->error('User não tem credencial Google. Vai em /corretor.php → aba Perfil → Conectar Google.');
            return self::FAILURE;
        }

        $cred = $user->googleCredential;
        $this->line("Email Google: " . ($cred->email ?: '(não capturado)'));
        $this->line("Token expira em: " . ($cred->expires_at ?: 'sem expiração'));
        $this->line("Última sync: " . ($cred->last_synced_at ?: 'nunca'));
        if ($cred->last_sync_error) {
            $this->error("Último erro de sync: " . $cred->last_sync_error);
        }
        $this->newLine();


        $this->line('=== Tentando push... ===');
        try {
            $result = $service->pushAppointment($appt);
            if ($result === null) {
                $this->error('pushAppointment retornou NULL. Veja storage/logs/laravel.log pros detalhes.');
                return self::FAILURE;
            }
            $this->info('✓ Push OK!');
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
            $appt->refresh();
            $this->line("external_event_id agora: " . $appt->external_event_id);
            $this->line("meeting_url: " . ($appt->meeting_url ?: '(não veio)'));
        } catch (\Throwable $e) {
            $this->error('Exception: ' . $e->getMessage());
            $this->line($e->getTraceAsString());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
