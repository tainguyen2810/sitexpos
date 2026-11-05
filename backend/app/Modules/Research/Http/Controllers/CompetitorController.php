<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Http\Paginated;
use SiteXPost\Kernel\Security\SsrfGuard;
use SiteXPost\Kernel\Security\SsrfViolation;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\Crawler\PublicFetcher;
use SiteXPost\Modules\Research\Domain\CompetitorFinding;
use SiteXPost\Modules\Research\Domain\CompetitorWebsite;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Http\Requests\CompetitorRequest;
use SiteXPost\Modules\Research\Http\Resources\CompetitorFindingResource;
use SiteXPost\Modules\Research\Http\Resources\CompetitorResource;
use SiteXPost\Modules\Research\Support\ResearchSettings;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Workspace\Domain\Workspace;

final class CompetitorController
{
    public function index(Workspace $workspace, SeoCampaign $campaign): AnonymousResourceCollection
    {
        Gate::authorize('view', $campaign);

        return CompetitorResource::collection($campaign->competitors()->withCount('findings')->orderBy('created_at')->get());
    }

    public function store(CompetitorRequest $request, Workspace $workspace, SeoCampaign $campaign, SsrfGuard $guard, ResearchSettings $settings, SiteDirectory $sites): JsonResponse
    {
        Gate::authorize('operate', $campaign);
        $parts = parse_url(trim($request->string('url')->toString()));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $origin = 'https://'.$host;
        try {
            $guard->assertSafe($origin.'/');
        } catch (SsrfViolation $e) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['url' => [__('research.errors.blocked_url', ['reason' => $e->getMessage()])]]);
        }
        $own = $sites->find($campaign->site_id);
        if ($own !== null && PublicFetcher::sameSite($own['url'], $origin)) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['url' => [__('research.errors.competitor_is_own_site')]]);
        }
        $hash = hash('sha256', (string) preg_replace('/^www\./', '', $host));
        if (CompetitorWebsite::query()->where('campaign_id', $campaign->id)->where('url_hash', $hash)->exists()) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['url' => [__('research.errors.competitor_exists')]]);
        }
        $max = $settings->limitsFor($workspace->id)['max_competitors_per_campaign'];
        if (CompetitorWebsite::query()->where('campaign_id', $campaign->id)->count() >= $max) {
            throw new ApiException(429, 'research_quota_exceeded', __('research.errors.competitor_limit', ['max' => $max]));
        }

        $c = new CompetitorWebsite;
        $c->forceFill([
            'campaign_id' => $campaign->id, 'name' => $request->filled('name') ? $request->string('name')->trim()->toString() : $host,
            'url' => $origin, 'url_hash' => $hash, 'status' => 'active', 'created_by' => (string) $request->user()?->getAuthIdentifier(),
        ])->save();
        Audit::record('research.competitor.added', $c, ['host' => $host]);

        return (new CompetitorResource($c))->response()->setStatusCode(201);
    }

    public function update(CompetitorRequest $request, Workspace $workspace, SeoCampaign $campaign, CompetitorWebsite $competitor): CompetitorResource
    {
        Gate::authorize('operate', $campaign);
        $competitor->forceFill($request->validated())->save();

        return new CompetitorResource($competitor);
    }

    public function destroy(Workspace $workspace, SeoCampaign $campaign, CompetitorWebsite $competitor): JsonResponse
    {
        Gate::authorize('operate', $campaign);
        Audit::record('research.competitor.removed', $competitor, ['url' => $competitor->url]);
        $competitor->delete();

        return new JsonResponse(null, 204);
    }

    public function findings(Request $request, Workspace $workspace, SeoCampaign $campaign, CompetitorWebsite $competitor): JsonResponse
    {
        Gate::authorize('view', $campaign);
        $q = CompetitorFinding::query()->where('competitor_website_id', $competitor->id);
        $kind = $request->query('kind');
        if (is_string($kind) && in_array($kind, ['page', 'category', 'question'], true)) {
            $q->where('kind', $kind);
        }

        return Paginated::response($q->orderByDesc('fetched_at')->orderByDesc('id'), CompetitorFindingResource::class, $request, 50);
    }
}
