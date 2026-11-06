<?php

use Carbon\CarbonImmutable;
use SiteXPost\Modules\Billing\Accounting\Reports;
use SiteXPost\Modules\Billing\Credits\CreditConverter;
use SiteXPost\Modules\Billing\Domain\CreditPolicy;
use SiteXPost\Modules\Billing\Payments\PaymentOrders;
use SiteXPost\Modules\Billing\Payments\Reconciliation;
use SiteXPost\Modules\Billing\Payments\VietQr;
use SiteXPost\Modules\Billing\Subscriptions\Proration;
use SiteXPost\Modules\Billing\Subscriptions\SubscriptionStatus;
use SiteXPost\Modules\Billing\Support\CostCategory;

function convPolicy(int $perCredit, int $markup = 0, int $min = 0): CreditPolicy
{
    return (new CreditPolicy)->forceFill(['micro_usd_per_credit' => $perCredit, 'markup_bps' => $markup, 'min_charge_mc' => $min]);
}

describe('credit conversion (integer milli-credits, rounded up)', function () {
    it('converts provider cost with markup and never under-charges', function () {
        expect(CreditConverter::toCredits(1000, convPolicy(1000)))->toBe(1000)        // 1000 µUSD = 1 credit
            ->and(CreditConverter::toCredits(1000, convPolicy(1000, 5000)))->toBe(1500) // +50 %
            ->and(CreditConverter::toCredits(1, convPolicy(1000)))->toBe(1)             // rounds UP, not to 0
            ->and(CreditConverter::toCredits(1001, convPolicy(1000)))->toBe(1001)
            ->and(CreditConverter::toCredits(3, convPolicy(7)))->toBe(429);              // ceil(3000/7)
    });

    it('applies the minimum charge and charges nothing for zero cost', function () {
        expect(CreditConverter::toCredits(1, convPolicy(1000, 0, 50)))->toBe(50)
            ->and(CreditConverter::toCredits(0, convPolicy(1000, 0, 50)))->toBe(0);
    });
});

describe('proration', function () {
    it('charges the price difference for the remaining part of the period', function () {
        $start = CarbonImmutable::parse('2026-11-01T00:00:00Z');
        $end = $start->addDays(30);
        $q = Proration::upgrade(300_000, 600_000, $start, $end, $start->addDays(15));
        expect($q['charge_minor'])->toBe(150_000)->and($q['remaining_days'])->toBe(15)->and($q['credit_for_unused_minor'])->toBe(150_000);
    });

    it('never produces a negative charge and clamps outside the period', function () {
        $start = CarbonImmutable::parse('2026-11-01T00:00:00Z');
        $end = $start->addDays(30);
        expect(Proration::upgrade(600_000, 300_000, $start, $end, $start)['charge_minor'])->toBe(0)
            ->and(Proration::upgrade(100, 200, $start, $end, $end->addDay())['charge_minor'])->toBe(0)
            ->and(Proration::upgrade(100, 200, $start, $end, $start->subDay())['charge_minor'])->toBe(100);
    });
});

describe('VietQR payload', function () {
    it('computes CRC-16/CCITT-FALSE', function () {
        expect(VietQr::crc16('123456789'))->toBe('29B1');
    });

    it('builds a parseable EMVCo payload with amount, memo and a valid CRC', function () {
        $p = VietQr::payload('970436', '0123456789', 250000, 'SXPABCD2345');
        $tags = VietQr::parse($p);
        expect($tags['00'])->toBe('01')
            ->and($tags['53'])->toBe('704')
            ->and($tags['54'])->toBe('250000')
            ->and($tags['58'])->toBe('VN')
            ->and($tags['38'])->toContain('970436')->toContain('0123456789')
            ->and($tags['62'])->toContain('SXPABCD2345')
            ->and(substr($p, -4))->toBe(VietQr::crc16(substr($p, 0, -4)));
    });
});

describe('payment references', function () {
    it('generates unambiguous references and finds them in a noisy bank memo', function () {
        $ref = PaymentOrders::newReference();
        expect($ref)->toMatch('/^SXP[A-HJ-NP-Z2-9]{8}$/')
            ->and(Reconciliation::references('CT DEN:12345 '.strtolower($ref).' thanh toan'))->toBe([$ref])
            ->and(Reconciliation::references('SXP-'.substr($ref, 3, 4).' '.substr($ref, 7)))->toBe([$ref]) // bank apps add separators
            ->and(Reconciliation::references('chuyen tien'))->toBe([]);
    });
});

describe('subscription status machine', function () {
    it('only allows the documented transitions', function () {
        expect(SubscriptionStatus::Trialing->canTransitionTo(SubscriptionStatus::Active))->toBeTrue()
            ->and(SubscriptionStatus::Expired->canTransitionTo(SubscriptionStatus::Trialing))->toBeFalse()
            ->and(SubscriptionStatus::Expired->canTransitionTo(SubscriptionStatus::Active))->toBeTrue()
            ->and(SubscriptionStatus::Cancelled->canTransitionTo(SubscriptionStatus::PastDue))->toBeFalse()
            ->and(SubscriptionStatus::Paused->canTransitionTo(SubscriptionStatus::GracePeriod))->toBeFalse();
    });

    it('blocks new paid usage when paused, suspended or expired, and in grace unless allowed', function () {
        expect(SubscriptionStatus::Active->allowsPaidUsage(false))->toBeTrue()
            ->and(SubscriptionStatus::Cancelled->allowsPaidUsage(false))->toBeTrue()
            ->and(SubscriptionStatus::GracePeriod->allowsPaidUsage(false))->toBeFalse()
            ->and(SubscriptionStatus::GracePeriod->allowsPaidUsage(true))->toBeTrue()
            ->and(SubscriptionStatus::Suspended->allowsPaidUsage(true))->toBeFalse()
            ->and(SubscriptionStatus::Expired->isLive())->toBeFalse();
    });
});

describe('revenue recognition', function () {
    it('recognizes subscription revenue linearly and defers the rest', function () {
        $paid = CarbonImmutable::parse('2026-11-01T00:00:00Z');
        $end = $paid->addDays(30);
        // Report window = first 10 days of the 30-day service period.
        [$rec, $def] = Reports::recognize(300_000, $paid, $end, $paid, $paid, $paid->addDays(10));
        expect($rec)->toBe(100_000)->and($def)->toBe(200_000);
    });

    it('recognizes non-period revenue when it occurs and ignores future records', function () {
        $at = CarbonImmutable::parse('2026-11-05T00:00:00Z');
        expect(Reports::recognize(50_000, null, null, $at, $at->subDay(), $at->addDay()))->toBe([50_000, 0])
            ->and(Reports::recognize(50_000, null, null, $at, $at->subDays(3), $at->subDay()))->toBe([0, 0]);
    });
});

it('maps AI task keys to cost categories and required features', function () {
    expect(CostCategory::of('media.image'))->toBe('image')
        ->and(CostCategory::of('writer.claims'))->toBe('fact_check')
        ->and(CostCategory::of('research.discovery'))->toBe('research')
        ->and(CostCategory::of('writer.draft'))->toBe('writing')
        ->and(CostCategory::of('system.test'))->toBe('other')
        ->and(CostCategory::feature('system.test'))->toBeNull()
        ->and(CostCategory::feature('media.image'))->toBe('ai_images');
});
