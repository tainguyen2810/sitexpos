<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use SiteXPost\Modules\Publishing\Domain\PublishingJob;

/**
 * Publishing window, spacing and daily limits, evaluated in the WEBSITE's
 * timezone (reported by WordPress). Used for scheduling, bulk slots and
 * the quota gate at publish time.
 */
final class ScheduleRules
{
    /**
     * Problems with publishing at $at (UTC). Empty list = allowed.
     *
     * @param  array<string, mixed>  $policy  SitePolicy::for()
     * @param  array<string, mixed>  $limits  PublishingLimits::for()
     * @return list<string> error codes
     */
    public function problems(CarbonImmutable $at, string $timezone, string $siteId, ?string $campaignId, array $policy, array $limits, ?string $excludeJobId, bool $scheduling): array
    {
        $errors = [];
        $now = CarbonImmutable::now('UTC');
        if ($scheduling) {
            if ($at->lessThan($now->addMinutes(2))) {
                $errors[] = 'schedule_in_past';
            }
            $maxDays = min((int) $policy['max_schedule_days_ahead'], (int) $limits['max_schedule_days_ahead']);
            if ($at->greaterThan($now->addDays($maxDays))) {
                $errors[] = 'schedule_too_far';
            }
            $local = $at->setTimezone($timezone);
            $hour = (int) $local->format('G');
            if ($hour < (int) $policy['window_start_hour'] || $hour >= (int) $policy['window_end_hour']) {
                $errors[] = 'outside_window';
            }
            if (is_array($policy['window_days']) && $policy['window_days'] !== [] && ! in_array((int) $local->format('N'), $policy['window_days'], true)) {
                $errors[] = 'outside_window_days';
            }
            $gap = (int) $policy['min_minutes_between_posts'];
            if ($gap > 0 && $this->slots($siteId, $at->subMinutes($gap)->addSecond(), $at->addMinutes($gap)->subSecond(), $excludeJobId)->exists()) {
                $errors[] = 'too_close_to_other_post';
            }
        }

        $local = $at->setTimezone($timezone);
        $dayStart = $local->startOfDay()->setTimezone('UTC');
        $dayEnd = $local->endOfDay()->setTimezone('UTC');
        if ($this->slots($siteId, $dayStart, $dayEnd, $excludeJobId)->count() >= (int) $policy['max_posts_per_day']) {
            $errors[] = 'site_daily_limit';
        }
        if ($campaignId !== null && $this->slots($siteId, $dayStart, $dayEnd, $excludeJobId)->where('campaign_id', $campaignId)->count() >= (int) $policy['max_posts_per_campaign_per_day']) {
            $errors[] = 'campaign_daily_limit';
        }
        if ($this->slots(null, $at->subDay(), $at, $excludeJobId)->count() >= (int) $limits['max_publishes_per_day']) {
            $errors[] = 'workspace_daily_limit';
        }

        return $errors;
    }

    /**
     * First allowed slot at or after $from (15-minute steps), or null.
     *
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $limits
     */
    public function nextSlot(CarbonImmutable $from, string $timezone, string $siteId, ?string $campaignId, array $policy, array $limits): ?CarbonImmutable
    {
        $t = $from->setTimezone('UTC')->ceilMinutes(15);
        $end = CarbonImmutable::now('UTC')->addDays(min((int) $policy['max_schedule_days_ahead'], (int) $limits['max_schedule_days_ahead']));
        for ($i = 0; $i < 3000 && $t->lessThanOrEqualTo($end); $i++) {
            $p = $this->problems($t, $timezone, $siteId, $campaignId, $policy, $limits, null, true);
            if ($p === []) {
                return $t;
            }
            $local = $t->setTimezone($timezone);
            if (array_intersect($p, ['outside_window_days', 'site_daily_limit', 'campaign_daily_limit']) !== []
                || (in_array('outside_window', $p, true) && (int) $local->format('G') >= (int) $policy['window_end_hour'])) {
                $t = $local->addDay()->startOfDay()->setTime((int) $policy['window_start_hour'], 0)->setTimezone('UTC');
            } elseif (in_array('outside_window', $p, true)) {
                $t = $local->setTime((int) $policy['window_start_hour'], 0)->setTimezone('UTC');
            } else {
                $t = $t->addMinutes(15);
            }
        }

        return null;
    }

    /**
     * Jobs occupying a publishing slot between two instants (UTC).
     *
     * @return Builder<PublishingJob>
     */
    private function slots(?string $siteId, CarbonImmutable $from, CarbonImmutable $to, ?string $excludeJobId): Builder
    {
        $q = PublishingJob::query()->where('wp_target_status', 'publish')
            ->where(fn ($w) => $w->where(fn ($s) => $s->whereIn('status', [PublishingStatus::SCHEDULED, PublishingStatus::PUBLISHING])->whereBetween('scheduled_at', [$from, $to]))
                ->orWhere(fn ($p) => $p->where('status', PublishingStatus::PUBLISHED)->whereBetween('published_at', [$from, $to])));
        if ($siteId !== null) {
            $q->where('site_id', $siteId);
        }
        if ($excludeJobId !== null) {
            $q->where('id', '!=', $excludeJobId);
        }

        return $q;
    }
}
