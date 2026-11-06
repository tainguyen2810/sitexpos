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

// Phase 5 — AI Writer & Fact-Check
Schedule::command('sitex:writer:sync')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('sitex:writer:autonomous')->dailyAt('02:30')->withoutOverlapping();

// Phase 6 — Media, SEO & WordPress Publisher
Schedule::command('sitex:publishing:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('sitex:publishing:auto')->hourly()->withoutOverlapping();

// Phase 7 — Billing, subscriptions, AI credits
Schedule::command('sitex:billing:lifecycle')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('sitex:billing:expire-credits')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('sitex:billing:aggregate-costs --days=2')->hourly()->withoutOverlapping();
Schedule::command('sitex:billing:verify-ledger')->dailyAt('03:15');

// Phase 8 — SEO Analytics & Autonomous SEO Agent
Schedule::command('sitex:analytics:sync')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('sitex:analytics:refresh-tick')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('sitex:analytics:prune')->dailyAt('03:40')->onOneServer();
Schedule::command('sitex:agent:schedule')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('sitex:agent:recover')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Phase 9 — operations: heartbeat, monitoring/alerts, backups
Schedule::command('sitex:system:heartbeat')->everyMinute();
Schedule::command('sitex:system:monitor')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('sitex:backup:run --scheduled --verify')->everyFifteenMinutes()->withoutOverlapping(120);
Schedule::command('sitex:backup:prune')->dailyAt('04:30');
