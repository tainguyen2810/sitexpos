<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Analysis;

use Illuminate\Support\Carbon;
use SiteXPost\Modules\Research\Domain\ContentCalendarItem;
use SiteXPost\Modules\Research\Domain\ContentIdea;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Support\ResearchSettings;

/**
 * Proposes publication dates. Respects posts/week and weekdays of the
 * campaign, the monthly calendar limit of the workspace and existing items.
 * Pillar pages go first in each cluster. Items are created as `proposed`
 * (or `approved` when the idea was approved by a person) — the calendar
 * never creates or publishes an article by itself (Phase 5, opt-in).
 */
final class CalendarPlanner
{
    public function __construct(private readonly ResearchSettings $settings) {}

    /**
     * @return array{scheduled: int, skipped_limit: int}
     */
    public function plan(SeoCampaign $campaign, bool $includeSuggested, ?Carbon $from = null): array
    {
        $settings = $campaign->settingsOrDefault();
        $perWeek = max(1, min(21, $settings->posts_per_week));
        $weekdays = array_values(array_unique(array_filter(array_map('intval', $settings->publish_weekdays ?: [1, 3, 5]), fn ($d) => $d >= 0 && $d <= 6)));
        if ($weekdays === []) {
            $weekdays = [1, 3, 5];
        }
        $monthly = $this->settings->limitsFor($campaign->workspace_id)['max_calendar_items_per_month'];

        $query = ContentIdea::query()->where('campaign_id', $campaign->id)->whereDoesntHave('calendarItem')
            ->whereIn('status', $includeSuggested ? ['approved', 'briefed', 'suggested'] : ['approved', 'briefed']);
        if ($includeSuggested) {
            $query->where(fn ($q) => $q->whereIn('status', ['approved', 'briefed'])->orWhere('opportunity_score', '>=', $settings->notify_min_score));
        }
        $ideas = $query->get()->sortBy([
            fn ($a, $b) => (int) in_array($b->status, ['approved', 'briefed'], true) <=> (int) in_array($a->status, ['approved', 'briefed'], true),
            fn ($a, $b) => (int) $b->is_pillar <=> (int) $a->is_pillar,
            fn ($a, $b) => ($b->opportunity_score ?? 0) <=> ($a->opportunity_score ?? 0),
        ])->values();

        $day = ($from ?? now())->copy()->startOfDay()->addDay();
        $last = ContentCalendarItem::query()->where('campaign_id', $campaign->id)->whereNotIn('status', ['canceled'])->max('scheduled_for');
        if ($last !== null && Carbon::parse($last)->gte($day)) {
            $day = Carbon::parse($last)->startOfDay()->addDay();
        }

        $scheduled = 0;
        $skipped = 0;
        $guard = 0;
        foreach ($ideas as $idea) {
            // find the next allowed slot
            while ($guard++ < 800) {
                $weekStart = $day->copy()->startOfWeek();
                $inWeek = ContentCalendarItem::query()->where('campaign_id', $campaign->id)->whereNotIn('status', ['canceled'])
                    ->whereBetween('scheduled_for', [$weekStart->toDateString(), $weekStart->copy()->endOfWeek()->toDateString()])->count();
                $onDay = ContentCalendarItem::query()->where('campaign_id', $campaign->id)->whereNotIn('status', ['canceled'])->whereDate('scheduled_for', $day->toDateString())->exists();
                if (in_array($day->dayOfWeek, $weekdays, true) && $inWeek < $perWeek && ! $onDay) {
                    break;
                }
                $day->addDay();
            }
            $inMonth = ContentCalendarItem::query()->whereNotIn('status', ['canceled'])
                ->whereBetween('scheduled_for', [$day->copy()->startOfMonth()->toDateString(), $day->copy()->endOfMonth()->toDateString()])->count();
            if ($inMonth >= $monthly) {
                $skipped++;

                continue;
            }

            $item = new ContentCalendarItem;
            $item->forceFill([
                'campaign_id' => $campaign->id,
                'content_idea_id' => $idea->id,
                'cluster_id' => $idea->cluster_id,
                'scheduled_for' => $day->toDateString(),
                'position' => 0,
                'status' => in_array($idea->status, ['approved', 'briefed'], true) ? 'approved' : 'proposed',
                'source' => 'auto',
            ])->save();
            $scheduled++;
            $day->addDay();
        }

        return ['scheduled' => $scheduled, 'skipped_limit' => $skipped];
    }
}
