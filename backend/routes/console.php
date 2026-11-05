<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 2 — WordPress connector
Schedule::command('sitex:sites:health')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('sitex:sites:prune')->daily();
