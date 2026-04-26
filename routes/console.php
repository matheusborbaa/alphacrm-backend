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

// Sprint Auto-Offline — força offline corretores que ficaram com status
// 'disponivel' mas pararam de mandar heartbeat (provavelmente fecharam
// o navegador). Threshold é configurável em Configurações → Geral
// (corretor_auto_offline_minutes). Setar 0 desativa o feature e este
// comando vira noop. Roda a cada 5 min — barato, só faz UPDATE em users
// expirados. Resolve "tem gente disponível há dias com sistema fechado".
Schedule::command('corretores:mark-offline-inactive')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// Monitoramento automático de capacidade do servidor (disco/RAM). Roda
// de hora em hora — o comando tem dedup interno (24h entre lembretes
// pra uma mesma métrica em estado crítico sustentado), então rodar
// hourly não spamma mas também não deixa o admin descobrir tarde.
// Thresholds e toggle vivem na tabela `settings` e são editáveis pela
// UI — admin pode afrouxar ou apertar sem deploy.
Schedule::command('servidor:check-capacity')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();
