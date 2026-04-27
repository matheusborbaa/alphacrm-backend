<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\CheckLeadSlaJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new CheckLeadSlaJob())->everyMinute();

Schedule::command('docs:purge-expired')
    ->dailyAt('03:10')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('leads:release-cooldowns')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('corretores:mark-offline-inactive')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('servidor:check-capacity')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();


Schedule::command('google:sync-incoming')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();
