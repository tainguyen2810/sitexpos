<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Domain\WebsiteKnowledge;
use SiteXPost\Modules\Research\Domain\WebsiteProfile;
use SiteXPost\Modules\Research\Http\Requests\KnowledgeRequest;
use SiteXPost\Modules\Research\Http\Resources\KnowledgeResource;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Website Knowledge Profile: what SiteX Post believes about the business.
 * Users confirm, correct, reject or add statements; AI never overwrites a
 * user's decision.
 */
final class WebsiteIntelligenceController
{
    public function show(Request $request, Workspace $workspace, SeoCampaign $campaign): JsonResponse
    {
        Gate::authorize('view', $campaign);
        $profile = WebsiteProfile::query()->where('site_id', $campaign->site_id)->first();
        $knowledge = $profile === null ? collect() : WebsiteKnowledge::query()->where('website_profile_id', $profile->id)
            ->orderBy('kind')->orderByDesc('confidence')->limit(500)->get();

        return new JsonResponse(['data' => [
            'profile' => $profile === null ? null : [
                'title' => $profile->title, 'tagline' => $profile->tagline, 'description' => $profile->description,
                'language' => $profile->language, 'region' => $profile->region, 'sitemap_url' => $profile->sitemap_url,
                'sitemap_url_count' => $profile->sitemap_url_count, 'stats' => $profile->stats ?? (object) [],
                'covered_topics' => $profile->covered_topics ?? [], 'status' => $profile->status,
                'last_error_code' => $profile->last_error_code,
                'last_crawled_at' => $profile->last_crawled_at?->toIso8601ZuluString(),
                'analyzed_at' => $profile->analyzed_at?->toIso8601ZuluString(),
            ],
            'knowledge' => KnowledgeResource::collection($knowledge)->resolve($request),
        ]]);
    }

    public function store(KnowledgeRequest $request, Workspace $workspace, SeoCampaign $campaign): JsonResponse
    {
        Gate::authorize('operate', $campaign);
        $profile = WebsiteProfile::query()->where('site_id', $campaign->site_id)->first();
        if ($profile === null) {
            $profile = new WebsiteProfile;
            $profile->forceFill(['site_id' => $campaign->site_id, 'status' => 'pending'])->save();
        }
        $kind = $request->string('kind')->toString();
        $value = Text::clean($request->input('value'), 1000);
        $fp = Text::fingerprint($kind, Text::fold($value));
        $row = WebsiteKnowledge::query()->where(['website_profile_id' => $profile->id, 'fingerprint' => $fp])->first() ?? new WebsiteKnowledge;
        $row->forceFill([
            'website_profile_id' => $profile->id, 'kind' => $kind, 'value' => $value, 'source' => 'user', 'confidence' => 100,
            'status' => 'confirmed', 'fingerprint' => $fp, 'edited_by' => (string) $request->user()?->getAuthIdentifier(), 'edited_at' => now(),
        ])->save();
        Audit::record('research.knowledge.added', $row, ['kind' => $kind]);

        return (new KnowledgeResource($row))->response()->setStatusCode(201);
    }

    public function update(KnowledgeRequest $request, Workspace $workspace, SeoCampaign $campaign, WebsiteKnowledge $knowledge): KnowledgeResource
    {
        Gate::authorize('operate', $campaign);
        $data = ['edited_by' => (string) $request->user()?->getAuthIdentifier(), 'edited_at' => now()];
        if ($request->has('value')) {
            $value = Text::clean($request->input('value'), 1000);
            $fp = Text::fingerprint($knowledge->kind, Text::fold($value));
            if ($fp !== $knowledge->fingerprint && WebsiteKnowledge::query()->where(['website_profile_id' => $knowledge->website_profile_id, 'fingerprint' => $fp])->exists()) {
                throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['value' => [__('validation.unique', ['attribute' => 'value'])]]);
            }
            $data += ['value' => $value, 'fingerprint' => $fp, 'source' => 'user', 'confidence' => 100, 'status' => 'confirmed'];
        }
        if ($request->has('status')) {
            $data['status'] = $request->string('status')->toString();
        }
        $knowledge->forceFill($data)->save();
        Audit::record('research.knowledge.updated', $knowledge, ['status' => $knowledge->status]);

        return new KnowledgeResource($knowledge);
    }

    public function destroy(Workspace $workspace, SeoCampaign $campaign, WebsiteKnowledge $knowledge): JsonResponse
    {
        Gate::authorize('operate', $campaign);
        // Keep the row as "rejected" so a later AI analysis does not re-suggest it.
        $knowledge->forceFill(['status' => 'rejected', 'edited_at' => now()])->save();
        Audit::record('research.knowledge.rejected', $knowledge);

        return new JsonResponse(null, 204);
    }
}
