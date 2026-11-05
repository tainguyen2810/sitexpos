<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\Paginated;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Domain\SiteActivityLog;
use SiteXPost\Modules\Sites\Domain\SiteContentItem;
use SiteXPost\Modules\Sites\Http\Resources\SiteActivityLogResource;
use SiteXPost\Modules\Sites\Http\Resources\SiteContentItemResource;
use SiteXPost\Modules\Sites\Http\Resources\SiteHealthCheckResource;
use SiteXPost\Modules\Sites\Http\Resources\SiteTaxonomyResource;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Read-only views of synced data, health history and connector logs.
 */
final class SiteDataController
{
    public function taxonomies(Request $request, Workspace $workspace, Site $site): AnonymousResourceCollection
    {
        Gate::authorize('view', $site);

        $query = $site->taxonomies()->orderBy('taxonomy')->orderBy('name');
        $taxonomy = $request->input('filter.taxonomy');
        if (is_string($taxonomy) && in_array($taxonomy, ['category', 'post_tag', 'product_cat', 'product_tag'], true)) {
            $query->where('taxonomy', $taxonomy);
        }

        return SiteTaxonomyResource::collection($query->limit(1000)->get());
    }

    public function content(Request $request, Workspace $workspace, Site $site): JsonResponse
    {
        Gate::authorize('view', $site);

        $query = SiteContentItem::query()->where('site_id', $site->id);
        $type = $request->input('filter.post_type');
        if (is_string($type) && in_array($type, ['post', 'page', 'product'], true)) {
            $query->where('post_type', $type);
        }
        $q = $request->query('q');
        if (is_string($q) && trim($q) !== '') {
            $query->where('title', 'like', '%'.addcslashes(mb_substr(trim($q), 0, 100), '%_\\').'%');
        }

        return Paginated::response($query->orderByDesc('modified_at')->orderByDesc('id'), SiteContentItemResource::class, $request);
    }

    public function logs(Request $request, Workspace $workspace, Site $site): JsonResponse
    {
        Gate::authorize('view', $site);

        $query = SiteActivityLog::query()->where('site_id', $site->id);
        $level = $request->input('filter.level');
        if (is_string($level) && in_array($level, ['info', 'warning', 'error'], true)) {
            $query->where('level', $level);
        }

        return Paginated::response($query->orderByDesc('id'), SiteActivityLogResource::class, $request, 50);
    }

    public function healthChecks(Workspace $workspace, Site $site): AnonymousResourceCollection
    {
        Gate::authorize('view', $site);

        return SiteHealthCheckResource::collection($site->healthChecks()->orderByDesc('checked_at')->limit(50)->get());
    }
}
