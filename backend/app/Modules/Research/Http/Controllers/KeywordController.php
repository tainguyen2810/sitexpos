<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Http\Paginated;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\Domain\Keyword;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Http\Requests\BulkKeywordRequest;
use SiteXPost\Modules\Research\Http\Requests\KeywordRequest;
use SiteXPost\Modules\Research\Http\Resources\KeywordResource;
use SiteXPost\Modules\Research\Support\KeywordStore;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Keyword Explorer: filter by intent / source / status / cluster / score,
 * search, sort, cursor pagination.
 */
final class KeywordController
{
    public const SOURCES = ['campaign', 'wordpress', 'woocommerce', 'website_crawl', 'competitor', 'search_console', 'ai_expansion', 'dataforseo', 'user', 'demo'];

    public function index(Request $request, Workspace $workspace, SeoCampaign $campaign): JsonResponse
    {
        Gate::authorize('view', $campaign);
        $q = Keyword::query()->where('campaign_id', $campaign->id)->with('metrics');

        $intent = $request->query('intent');
        if (is_string($intent) && in_array($intent, ['informational', 'commercial', 'transactional', 'navigational'], true)) {
            $q->where('intent', $intent);
        }
        $status = $request->query('status');
        if (is_string($status) && in_array($status, ['suggested', 'approved', 'rejected'], true)) {
            $q->where('status', $status);
        } else {
            $q->where('status', '!=', 'rejected');
        }
        $source = $request->query('source');
        if (is_string($source) && in_array($source, self::SOURCES, true)) {
            $q->where('sources', 'like', '%"'.$source.'"%');
        }
        $cluster = $request->query('cluster_id');
        if ($cluster === 'none') {
            $q->whereNull('cluster_id');
        } elseif (is_string($cluster) && preg_match('/^[0-9a-z]{26}$/', $cluster) === 1) {
            $q->where('cluster_id', $cluster);
        }
        $topic = $request->query('topic');
        if (is_string($topic) && $topic !== '') {
            $q->where('topic_category', mb_substr($topic, 0, 120));
        }
        $min = $request->query('min_score');
        if (is_numeric($min)) {
            $q->where('opportunity_score', '>=', max(0, min(100, (int) $min)));
        }
        if ($request->query('has_metrics') === '1') {
            $q->whereHas('metrics');
        }
        $search = $request->query('q');
        if (is_string($search) && trim($search) !== '') {
            $q->where('keyword', 'like', '%'.addcslashes(mb_substr(trim($search), 0, 100), '%_\\').'%');
        }

        $dir = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        match ($request->query('sort')) {
            'keyword' => $q->orderBy('keyword', $dir)->orderBy('id', $dir),
            'created' => $q->orderBy('created_at', $dir)->orderBy('id', $dir),
            default => $q->orderBy('opportunity_score', $dir)->orderBy('id', $dir),
        };

        return Paginated::response($q, KeywordResource::class, $request, 50);
    }

    public function store(KeywordRequest $request, Workspace $workspace, SeoCampaign $campaign, KeywordStore $store): JsonResponse
    {
        Gate::authorize('operate', $campaign);
        $kw = $store->upsert($campaign, ['keyword' => $request->string('keyword')->toString(), 'source' => 'user', 'evidence' => ['type' => 'user']], null);
        if ($kw === null) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['keyword' => [__('validation.invalid', ['attribute' => 'keyword']) ?: 'invalid']]);
        }
        $kw->forceFill(['status' => 'approved'] + ($request->has('intent') ? ['intent' => $request->string('intent')->toString(), 'intent_source' => 'user'] : []))->save();
        Audit::record('research.keyword.added', $kw);

        return (new KeywordResource($kw->load('metrics')))->response()->setStatusCode(201);
    }

    public function update(KeywordRequest $request, Workspace $workspace, SeoCampaign $campaign, Keyword $keyword): KeywordResource
    {
        Gate::authorize('operate', $campaign);
        $data = [];
        if ($request->has('intent')) {
            $data += ['intent' => $request->string('intent')->toString(), 'intent_source' => 'user'];
        }
        if ($request->has('status')) {
            $data['status'] = $request->string('status')->toString();
        }
        if ($request->has('topic_category')) {
            $data['topic_category'] = $request->input('topic_category');
        }
        $keyword->forceFill($data)->save();
        Audit::record('research.keyword.updated', $keyword, ['fields' => array_keys($data)]);

        return new KeywordResource($keyword->load('metrics'));
    }

    public function bulk(BulkKeywordRequest $request, Workspace $workspace, SeoCampaign $campaign): JsonResponse
    {
        Gate::authorize('operate', $campaign);
        $n = Keyword::query()->where('campaign_id', $campaign->id)->whereIn('id', (array) $request->input('ids'))
            ->update(['status' => $request->string('status')->toString(), 'updated_at' => now()]);
        Audit::record('research.keyword.bulk_status', $campaign, ['count' => $n, 'status' => $request->string('status')->toString()]);

        return new JsonResponse(['data' => ['updated' => $n]]);
    }
}
