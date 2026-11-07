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
        $status = $request->input('filter.status');
        if (is_string($status) && in_array($status, ['publish', 'draft', 'pending', 'private', 'future'], true)) {
            $query->where('status', $status);
        }
        // v1.0.9 "learn style from a category / an author": WordPress term id and author id.
        $term = $request->input('filter.term');
        if (is_scalar($term) && ctype_digit((string) $term) && (int) $term > 0) {
            $query->whereJsonContains('term_ids', (int) $term);
        }
        $author = $request->input('filter.author');
        if (is_scalar($author) && ctype_digit((string) $author) && (int) $author > 0) {
            $query->where('author_wp_id', (int) $author);
        }
        $q = $request->query('q');
        if (is_string($q) && trim($q) !== '') {
            $query->where('title', 'like', '%'.addcslashes(mb_substr(trim($q), 0, 100), '%_\\').'%');
        }

        return Paginated::response($query->orderByDesc('modified_at')->orderByDesc('id'), SiteContentItemResource::class, $request);
    }

    /**
     * Authors of the site's published posts with their post count (from the synced index).
     */
    public function authors(Workspace $workspace, Site $site): JsonResponse
    {
        Gate::authorize('view', $site);

        $rows = SiteContentItem::query()->where('site_id', $site->id)->where('post_type', 'post')->where('status', 'publish')
            ->whereNotNull('author_wp_id')
            ->selectRaw('author_wp_id, MAX(author_name) as author_name, COUNT(*) as posts')
            ->groupBy('author_wp_id')->orderByDesc('posts')->limit(200)->get();

        return new JsonResponse(['data' => $rows->map(fn (SiteContentItem $r): array => [
            'wp_author_id' => (int) $r->author_wp_id,
            'name' => (string) $r->author_name,
            'posts' => (int) $r->getAttribute('posts'),
        ])->values()->all()]);
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
