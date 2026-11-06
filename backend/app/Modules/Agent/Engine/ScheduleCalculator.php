<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Engine;

use Carbon\CarbonImmutable;

/**
 * Next run time of an automation schedule, computed in the SITE's timezone
 * (DST-safe: local wall-clock time, stored in UTC). Monthly runs use day
 * 1–28 so every month has the day.
 */
final class ScheduleCalculator
{
    public const KINDS = ['daily_monitoring' => 'daily', 'weekly_audit' => 'weekly', 'monthly_review' => 'monthly', 'content_refresh' => 'weekly', 'opportunity_discovery' => 'weekly'];

    public static function next(string $frequency, string $localTime, string $timezone, ?int $dayOfWeek, ?int $dayOfMonth, CarbonImmutable $after): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $localTime) + [1 => '0']);
        $local = $after->setTimezone($timezone);
        $candidate = $local->setTime($h, $m);
        for ($i = 0; $i < 400; $i++) {
            $ok = match ($frequency) {
                'weekly' => $candidate->isoWeekday() === ($dayOfWeek ?? 1),
                'monthly' => $candidate->day === min(28, max(1, $dayOfMonth ?? 1)),
                default => true,
            };
            if ($ok && $candidate->greaterThan($local)) {
                return $candidate->utc();
            }
            $candidate = $candidate->addDay()->setTime($h, $m);
        }

        return $local->addDay()->utc();
    }

    /**
     * @return list<string> next N run times (ISO-8601 UTC)
     */
    public static function preview(string $frequency, string $localTime, string $timezone, ?int $dayOfWeek, ?int $dayOfMonth, int $count = 5): array
    {
        $out = [];
        $t = CarbonImmutable::now('UTC');
        for ($i = 0; $i < $count; $i++) {
            $t = self::next($frequency, $localTime, $timezone, $dayOfWeek, $dayOfMonth, $t);
            $out[] = $t->toIso8601ZuluString();
        }

        return $out;
    }
}
