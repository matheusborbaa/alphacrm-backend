<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\CheckLeadSlaJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Schedule::job(new CheckLeadSlaJob())->everyMinute();

// Expurgo diário dos documentos cuja janela de retenção expirou.
// Roda às 03:10 da manhã (horário de baixo tráfego).
Schedule::command('docs:purge-expired')
    ->dailyAt('03:10')
    ->onOneServer()
    ->withoutOverlapping();

// Cooldown pós-lead: libera corretores cujo timer expirou e já tenta
// pegar o lead órfão mais antigo. Roda a cada minuto — operação barata
// (só toca linhas com cooldown_until vencido).
Schedule::command('leads:release-cooldowns')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();
