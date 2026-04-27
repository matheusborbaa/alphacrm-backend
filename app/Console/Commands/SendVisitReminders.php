<?php

namespace App\Console\Commands;

use App\Mail\VisitReminderMail;
use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Dispara lembretes 24h e 1h antes da visita. Roda a cada 5min (routes/console.php).
// Marca reminder_sent_*_at depois de enviar, então pode rodar várias vezes sem mandar email duplicado.
class SendVisitReminders extends Command
{
    protected $signature = 'visits:send-reminders
        {--dry : Mostra o que seria enviado sem disparar email}';

    protected $description = 'Envia lembretes de visita pro corretor (24h e 1h antes)';

    public function handle(): int
    {
        $now = now();
        $dry = $this->option('dry');

        $sent24 = $this->sendBatch(
            kind:    '24h',
            from:    $now->copy()->addHours(23),
            to:      $now->copy()->addHours(25),
            sentCol: 'reminder_sent_24h_at',
            dry:     $dry,
        );

        $sent1 = $this->sendBatch(
            kind:    '1h',
            from:    $now->copy()->addMinutes(30),
            to:      $now->copy()->addMinutes(90),
            sentCol: 'reminder_sent_1h_at',
            dry:     $dry,
        );

        $this->info("Total: {$sent24} lembrete(s) 24h + {$sent1} lembrete(s) 1h enviado(s)" . ($dry ? ' (DRY-RUN)' : ''));
        return self::SUCCESS;
    }

    private function sendBatch(string $kind, Carbon $from, Carbon $to, string $sentCol, bool $dry): int
    {
        $appts = Appointment::query()
            ->whereIn('confirmation_status', [Appointment::CONFIRM_PENDING, Appointment::CONFIRM_CONFIRMED])
            ->whereNull($sentCol)
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [$from, $to])
            ->whereNotNull('user_id')
            ->with('user', 'lead')
            ->get()
            ->filter(fn($a) => $a->isVisit());

        if ($appts->isEmpty()) {
            $this->line("Nenhuma visita na janela {$kind} ({$from->format('H:i')} → {$to->format('H:i')}).");
            return 0;
        }

        $sent = 0;
        foreach ($appts as $appt) {
            if (!$appt->user || !$appt->user->email) {
                $this->warn("  ↳ skip appt #{$appt->id}: corretor sem email");
                continue;
            }

            $this->line("  ↳ #{$appt->id} '{$appt->title}' → {$appt->user->email} ({$kind})");

            if ($dry) { $sent++; continue; }

            try {
                Mail::to($appt->user->email)
                    ->send(new VisitReminderMail($appt, $appt->user, $kind));
                $appt->update([$sentCol => $now ?? now()]);
                $sent++;
            } catch (\Throwable $e) {
                Log::error("[visits:reminders] falha ao enviar #{$appt->id} ({$kind}): " . $e->getMessage());
                $this->error("    ✗ falha: " . $e->getMessage());
            }
        }

        return $sent;
    }
}
