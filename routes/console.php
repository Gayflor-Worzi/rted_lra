<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

// Statutory LRA tax season: bills unpaid after the July 1 deadline
// are swept and escalated every July 2nd at 06:00.
Schedule::command('lra:statutory-overdue')->cron('0 6 2 7 *');

// Workflow ladder: auto-issues 30-day reminders, 72-hour demands and final
// enforcement steps for delivered, unpaid bills each morning.
Schedule::command('tasks:advance')->dailyAt('01:00');
