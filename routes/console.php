<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('backup:create')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('backup:verify')->dailyAt('04:00')->withoutOverlapping();
Schedule::command('backup:projects')->hourly()->withoutOverlapping();
Schedule::command('backup:projects-prune')->dailyAt('05:00')->withoutOverlapping();
Schedule::command('privacy:prune')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('withdrawals:send-confirmations')->everyFiveMinutes()->withoutOverlapping();
