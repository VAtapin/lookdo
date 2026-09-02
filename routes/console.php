<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('backup:create')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('backup:tenant --all')->dailyAt('03:15')->withoutOverlapping();
Schedule::command('backup:verify')->dailyAt('04:00')->withoutOverlapping();
Schedule::command('lookdo:reminders:send --limit=500')->everyMinute()->withoutOverlapping();
