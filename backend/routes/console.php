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

// Phase 3 — AI Gateway & Queue
Schedule::command('sitex:workflows:recover')->everyMinute()->withoutOverlapping();
Schedule::command('sitex:ai:expire-reservations')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('sitex:ai:prune')->daily();
Schedule::command('sitex:workflows:prune')->daily();
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Phase 4 — Topic Discovery & SEO Planner
Schedule::command('sitex:research:schedule')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('sitex:research:sync')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('sitex:research:prune')->daily();
