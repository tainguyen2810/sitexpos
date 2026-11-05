<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\Analysis\CalendarPlanner;
use SiteXPost\Modules\Research\Domain\ContentCalendarItem;
use SiteXPost\Modules\Research\Domain\ContentIdea;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Http\Requests\CalendarItemRequest;
use SiteXPost\Modules\Research\Http\Resources\CalendarItemResource;
use SiteXPost\Modules\Research\Support\ResearchSettings;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Content Calendar: month/week views, drag & drop (PATCH scheduled_for /
 * position), approve / pause / cancel, auto-plan. Planning only — nothing
 * here writes or publishes an article.
 */
final class CalendarController
{
    /**
     * Workspace-wide calendar (all campaigns, optional campaign/site filter).
     */
    public function workspace(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', SeoCampaign::class);
        [$from, $to] = self::range($request);
        $q = ContentCalendarItem::query()->with('idea')->whereBetween('scheduled_for', [$from, $to]);
        $campaign = $request->query('campaign_id');
        if (is_string($campaign) && preg_match('/^[0-9a-z]{26}$/', $campaign) === 1) {
            $q->where('campaign_id', $campaign);
        }
        $site = $request->query('site_id');
        if (is_string($site) && preg_match('/^[0-9a-z]{26}$/', $site) === 1) {
            $q->whereIn('campaign_id', SeoCampaign::query()->where('site_id', $site)->pluck('id'));
        }
        $cluster = $request->query('cluster_id');
        if (is_string($cluster) && preg_match('/^[0-9a-z]{26}$/', $cluster) === 1) {
            $q->where('cluster_id', $cluster);
        }
        $status = $request->query('status');
        if (is_string($status) && in_array($status, ['proposed', 'approved', 'paused', 'canceled', 'done'], true)) {
            $q->where('status', $status);
        } else {
            $q->where('status', '!=', 'canceled');
        }

        return CalendarItemResource::collection($q->orderBy('scheduled_for')->orderBy('position')->limit(1000)->get());
    }

    public function index(Request $request, Workspace $workspace, SeoCampaign $campaign): AnonymousResourceCollection
    {
        Gate::authorize('view', $campaign);
        $request->query->set('campaign_id', $campaign->id);

        return $this->workspace($request, $workspace);
    }

    public function store(CalendarItemRequest $request, Workspace $workspace, SeoCampaign $campaign, ResearchSettings $settings): JsonResponse
    {
        Gate::authorize('operate', $campaign);
        $idea = ContentIdea::query()->where('campaign_id', $campaign->id)->find($request->string('content_idea_id')->toString());
        if ($idea === null) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['content_idea_id' => [__('validation.exists', ['attribute' => 'content_idea_id'])]]);
        }
        if ($idea->status === 'rejected') {
            throw ApiException::conflict('invalid_state_transition', __('research.errors.idea_rejected'));
        }
        if (ContentCalendarItem::query()->where('content_idea_id', $idea->id)->exists()) {
            throw ApiException::conflict('already_scheduled', __('research.errors.already_scheduled'));
        }
        $date = Carbon::parse($request->string('scheduled_for')->toString());
        $this->assertMonthlyLimit($workspace, $settings, $date, null);

        $item = new ContentCalendarItem;
        $item->forceFill([
            'campaign_id' => $campaign->id, 'content_idea_id' => $idea->id, 'cluster_id' => $idea->cluster_id,
            'scheduled_for' => $date->toDateString(), 'position' => $request->integer('position'), 'status' => 'approved', 'source' => 'user',
            'notes' => $request->input('notes'), 'approved_by' => (string) $request->user()?->getAuthIdentifier(), 'approved_at' => now(),
        ])->save();
        if ($idea->status === 'suggested') {
            $idea->forceFill(['status' => 'approved', 'decided_by' => $item->approved_by, 'decided_at' => now()])->save();
        }
        Audit::record('research.calendar.scheduled', $item, ['date' => $item->scheduled_for?->toDateString()]);

        return (new CalendarItemResource($item->load('idea')))->response()->setStatusCode(201);
    }

    public function update(CalendarItemRequest $request, Workspace $workspace, SeoCampaign $campaign, ContentCalendarItem $calendarItem, ResearchSettings $settings): CalendarItemResource
    {
        Gate::authorize('operate', $campaign);
        $data = [];
        if ($request->has('scheduled_for')) {
            $date = Carbon::parse($request->string('scheduled_for')->toString());
            if ($date->format('Y-m') !== $calendarItem->scheduled_for?->format('Y-m')) {
                $this->assertMonthlyLimit($workspace, $settings, $date, $calendarItem->id);
            }
            $data['scheduled_for'] = $date->toDateString();
        }
        foreach (['position', 'notes'] as $f) {
            if ($request->has($f)) {
                $data[$f] = $request->input($f);
            }
        }
        if ($request->has('status')) {
            $data['status'] = $request->string('status')->toString();
            if ($data['status'] === 'approved') {
                $data += ['approved_by' => (string) $request->user()?->getAuthIdentifier(), 'approved_at' => now()];
                $idea = $calendarItem->idea;
                if ($idea !== null && $idea->status === 'suggested') {
                    $idea->forceFill(['status' => 'approved', 'decided_by' => $data['approved_by'], 'decided_at' => now()])->save();
                }
            }
        }
        $calendarItem->forceFill($data)->save();
        Audit::record('research.calendar.updated', $calendarItem, ['fields' => array_keys($data)]);

        return new CalendarItemResource($calendarItem->load('idea'));
    }

    public function destroy(Workspace $workspace, SeoCampaign $campaign, ContentCalendarItem $calendarItem): JsonResponse
    {
        Gate::authorize('operate', $campaign);
        Audit::record('research.calendar.removed', $calendarItem);
        $calendarItem->delete();

        return new JsonResponse(null, 204);
    }

    public function autoPlan(Request $request, Workspace $workspace, SeoCampaign $campaign, CalendarPlanner $planner): JsonResponse
    {
        Gate::authorize('operate', $campaign);
        $result = $planner->plan($campaign, $request->boolean('include_suggested'));
        Audit::record('research.calendar.auto_planned', $campaign, $result);

        return new JsonResponse(['data' => $result]);
    }

    private function assertMonthlyLimit(Workspace $workspace, ResearchSettings $settings, Carbon $date, ?string $ignoreId): void
    {
        $max = $settings->limitsFor($workspace->id)['max_calendar_items_per_month'];
        $count = ContentCalendarItem::query()->where('status', '!=', 'canceled')
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->whereBetween('scheduled_for', [$date->copy()->startOfMonth()->toDateString(), $date->copy()->endOfMonth()->toDateString()])->count();
        if ($count >= $max) {
            throw new ApiException(429, 'research_quota_exceeded', __('research.errors.calendar_limit', ['max' => $max]));
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function range(Request $request): array
    {
        $parse = static function (mixed $v, Carbon $default): Carbon {
            if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1) {
                try {
                    return Carbon::parse($v);
                } catch (\Throwable) {
                }
            }

            return $default;
        };
        $from = $parse($request->query('from'), now()->startOfMonth()->subWeek());
        $to = $parse($request->query('to'), now()->endOfMonth()->addWeek());
        if ($to->lt($from) || $from->diffInDays($to) > 400) {
            $to = $from->copy()->addDays(62);
        }

        return [$from->toDateString(), $to->toDateString()];
    }
}
