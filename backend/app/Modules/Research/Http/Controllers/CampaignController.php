<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Http\Paginated;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\Domain\CampaignSettings;
use SiteXPost\Modules\Research\Domain\ContentCalendarItem;
use SiteXPost\Modules\Research\Domain\ContentGap;
use SiteXPost\Modules\Research\Domain\ResearchRun;
use SiteXPost\Modules\Research\Domain\ResearchSchedule;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Http\Requests\StoreCampaignRequest;
use SiteXPost\Modules\Research\Http\Requests\UpdateCampaignSettingsRequest;
use SiteXPost\Modules\Research\Http\Requests\UpdateScheduleRequest;
use SiteXPost\Modules\Research\Http\Resources\CampaignResource;
use SiteXPost\Modules\Research\Http\Resources\CampaignSettingsResource;
use SiteXPost\Modules\Research\Http\Resources\ResearchRunResource;
use SiteXPost\Modules\Research\Support\QuotaManager;
use SiteXPost\Modules\Research\Support\ResearchSettings;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Workspace\Domain\Workspace;

final class CampaignController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', SeoCampaign::class);
        $query = SeoCampaign::query()->withCount(['keywords', 'ideas', 'clusters', 'competitors']);
        $status = $request->query('status');
        if (is_string($status) && in_array($status, ['active', 'paused', 'archived'], true)) {
            $query->where('status', $status);
        }
        $site = $request->query('site_id');
        if (is_string($site) && preg_match('/^[0-9a-z]{26}$/', $site) === 1) {
            $query->where('site_id', $site);
        }
        $sort = $request->query('sort');
        match ($sort) {
            'name' => $query->orderBy('name')->orderBy('id'),
            'last_run' => $query->orderByDesc('last_run_at')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        return Paginated::response($query, CampaignResource::class, $request, 25);
    }

    public function store(StoreCampaignRequest $request, Workspace $workspace, SiteDirectory $sites, ResearchSettings $settings): JsonResponse
    {
        Gate::authorize('create', SeoCampaign::class);
        $site = $sites->find($request->string('site_id')->toString());
        if ($site === null) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['site_id' => [__('validation.exists', ['attribute' => 'site_id'])]]);
        }
        $max = $settings->limitsFor($workspace->id)['max_campaigns'];
        if (SeoCampaign::query()->where('status', '!=', 'archived')->count() >= $max) {
            throw new ApiException(429, 'research_quota_exceeded', __('research.errors.campaign_limit', ['max' => $max]));
        }
        if (SeoCampaign::query()->where('site_id', $site['id'])->where('name', $request->string('name')->toString())->exists()) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['name' => [__('validation.unique', ['attribute' => 'name'])]]);
        }

        $campaign = DB::transaction(function () use ($request, $site): SeoCampaign {
            $c = new SeoCampaign;
            $c->forceFill([
                'site_id' => $site['id'],
                'name' => $request->string('name')->trim()->toString(),
                'industry' => $request->string('industry')->trim()->toString(),
                'market' => $request->string('market')->toString(),
                'language' => $request->string('language')->toString(),
                'products' => self::list($request->input('products')),
                'services' => self::list($request->input('services')),
                'audiences' => self::list($request->input('audiences')),
                'goals' => array_values(array_unique((array) $request->input('goals', ['traffic']))),
                'brand_terms' => self::list($request->input('brand_terms')),
                'status' => 'active',
                'created_by' => (string) $request->user()?->getAuthIdentifier(),
            ])->save();
            $s = CampaignSettings::defaultsFor($c);
            $s->save();
            $sched = new ResearchSchedule;
            $sched->forceFill(['campaign_id' => $c->id, 'frequency' => 'weekly', 'day_of_week' => 1, 'hour_utc' => 2, 'is_enabled' => false])->save();

            return $c;
        });
        Audit::record('research.campaign.created', $campaign, ['site_id' => $campaign->site_id]);

        return (new CampaignResource($campaign))->response()->setStatusCode(201);
    }

    public function show(Workspace $workspace, SeoCampaign $campaign): CampaignResource
    {
        Gate::authorize('view', $campaign);

        return new CampaignResource($campaign->loadCount(['keywords', 'ideas', 'clusters', 'competitors']));
    }

    public function update(StoreCampaignRequest $request, Workspace $workspace, SeoCampaign $campaign): CampaignResource
    {
        Gate::authorize('operate', $campaign);
        $data = [];
        foreach (['name', 'industry', 'market', 'language', 'status'] as $f) {
            if ($request->has($f)) {
                $data[$f] = $request->string($f)->trim()->toString();
            }
        }
        foreach (['products', 'services', 'audiences', 'brand_terms'] as $f) {
            if ($request->has($f)) {
                $data[$f] = self::list($request->input($f));
            }
        }
        if ($request->has('goals')) {
            $data['goals'] = array_values(array_unique((array) $request->input('goals')));
        }
        $campaign->forceFill($data)->save();
        Audit::record('research.campaign.updated', $campaign, ['fields' => array_keys($data)]);

        return new CampaignResource($campaign->loadCount(['keywords', 'ideas', 'clusters', 'competitors']));
    }

    public function destroy(Workspace $workspace, SeoCampaign $campaign): JsonResponse
    {
        Gate::authorize('manage', $campaign);
        if (ResearchRun::query()->where('campaign_id', $campaign->id)->whereIn('status', ResearchRun::ACTIVE)->exists()) {
            throw ApiException::conflict('research_in_progress', __('research.errors.in_progress'));
        }
        Audit::record('research.campaign.deleted', $campaign, ['name' => $campaign->name]);
        $campaign->delete();

        return new JsonResponse(null, 204);
    }

    public function settings(Workspace $workspace, SeoCampaign $campaign): CampaignSettingsResource
    {
        Gate::authorize('view', $campaign);

        return new CampaignSettingsResource($campaign->settingsOrDefault());
    }

    public function updateSettings(UpdateCampaignSettingsRequest $request, Workspace $workspace, SeoCampaign $campaign, ResearchSettings $settings): CampaignSettingsResource
    {
        Gate::authorize('operate', $campaign);
        $limits = $settings->limitsFor($workspace->id);
        if ($request->integer('ideas_per_run', 0) > $limits['max_ideas_per_run']) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['ideas_per_run' => [__('research.errors.ideas_limit', ['max' => $limits['max_ideas_per_run']])]]);
        }
        $s = $campaign->settingsOrDefault();
        $data = $request->validated();
        foreach (['scope_keywords', 'excluded_topics'] as $f) {
            if (array_key_exists($f, $data)) {
                $data[$f] = self::list($data[$f]);
            }
        }
        $s->forceFill($data)->save();
        Audit::record('research.campaign.settings_updated', $campaign, ['fields' => array_keys($data)]);

        return new CampaignSettingsResource($s);
    }

    public function schedule(Workspace $workspace, SeoCampaign $campaign): JsonResponse
    {
        Gate::authorize('view', $campaign);

        return new JsonResponse(['data' => self::scheduleData($campaign->schedule)]);
    }

    public function updateSchedule(UpdateScheduleRequest $request, Workspace $workspace, SeoCampaign $campaign): JsonResponse
    {
        Gate::authorize('operate', $campaign);
        $sched = $campaign->schedule ?? new ResearchSchedule;
        $sched->forceFill([
            'campaign_id' => $campaign->id,
            'is_enabled' => $request->boolean('is_enabled'),
            'frequency' => $request->string('frequency')->toString(),
            'day_of_week' => $request->input('day_of_week'),
            'day_of_month' => $request->input('day_of_month'),
            'hour_utc' => $request->integer('hour_utc'),
        ]);
        $sched->next_run_at = $sched->is_enabled ? $sched->computeNextRun() : null;
        $sched->save();
        Audit::record('research.schedule.updated', $campaign, ['enabled' => $sched->is_enabled, 'frequency' => $sched->frequency]);

        return new JsonResponse(['data' => self::scheduleData($sched)]);
    }

    public function overview(Request $request, Workspace $workspace, SeoCampaign $campaign, QuotaManager $quota): JsonResponse
    {
        Gate::authorize('view', $campaign);
        $last = ResearchRun::query()->where('campaign_id', $campaign->id)->latest('created_at')->first();
        $ideas = $campaign->ideas()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $intents = $campaign->keywords()->where('status', '!=', 'rejected')->selectRaw('intent, count(*) as c')->groupBy('intent')->pluck('c', 'intent');

        return new JsonResponse(['data' => [
            'campaign' => (new CampaignResource($campaign->loadCount(['keywords', 'ideas', 'clusters', 'competitors'])))->resolve($request),
            'last_run' => $last !== null ? (new ResearchRunResource($last))->resolve($request) : null,
            'ideas_by_status' => $ideas,
            'keywords_by_intent' => $intents,
            'open_gaps' => ContentGap::query()->where('campaign_id', $campaign->id)->where('status', 'open')->count(),
            'upcoming' => ContentCalendarItem::query()->where('campaign_id', $campaign->id)->whereIn('status', ['proposed', 'approved'])
                ->where('scheduled_for', '>=', now()->toDateString())->count(),
            'usage' => $quota->usage($workspace->id),
            'schedule' => self::scheduleData($campaign->schedule),
        ]]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function scheduleData(?ResearchSchedule $s): ?array
    {
        return $s === null ? null : [
            'is_enabled' => $s->is_enabled, 'frequency' => $s->frequency, 'day_of_week' => $s->day_of_week, 'day_of_month' => $s->day_of_month,
            'hour_utc' => $s->hour_utc, 'next_run_at' => $s->next_run_at?->toIso8601ZuluString(), 'last_run_at' => $s->last_run_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function list(mixed $value): array
    {
        $out = [];
        foreach ((array) $value as $v) {
            $v = Text::clean($v, 190);
            if ($v !== '' && ! in_array($v, $out, true)) {
                $out[] = $v;
            }
        }

        return $out;
    }
}
