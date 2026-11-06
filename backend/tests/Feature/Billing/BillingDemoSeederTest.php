<?php

use Database\Seeders\BillingDemoSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use SiteXPost\Modules\Billing\Credits\CreditLedger;

it('seeds labelled billing sample data through the real services, idempotently', function () {
    Notification::fake();
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(DB::table('plans')->where('is_demo', true)->count())->toBe(4)
        ->and(DB::table('plans')->where('is_demo', false)->count())->toBe(0)
        ->and(DB::table('plans')->pluck('name')->every(fn ($n) => str_starts_with($n, '[MẪU]')))->toBeTrue()
        ->and(DB::table('subscriptions')->count())->toBe(50)
        ->and(DB::table('payment_orders')->count())->toBe(100)
        ->and(DB::table('credit_ledger_entries')->count())->toBeGreaterThanOrEqual(200)
        ->and(DB::table('cost_records')->where('provider', 'demo')->count())->toBe(30)
        ->and(DB::table('refunds')->count())->toBe(10)
        ->and(DB::table('refunds')->where('status', 'succeeded')->count())->toBe(8)
        ->and(app(CreditLedger::class)->verify())->toBe([]);
});

it('refuses to seed billing demo data in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app()->call([app(BillingDemoSeeder::class), 'run']))->toThrow(RuntimeException::class);
});
