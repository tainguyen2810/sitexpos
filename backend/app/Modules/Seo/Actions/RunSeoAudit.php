<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Actions;

use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Seo\Audit\AuditIssue;
use SiteXPost\Modules\Seo\Audit\SeoRules;
use SiteXPost\Modules\Seo\Domain\SeoAuditIssue;
use SiteXPost\Modules\Seo\Domain\SeoAuditRun;
use SiteXPost\Modules\Seo\Domain\SeoLinkSuggestion;
use SiteXPost\Modules\Seo\Support\ArticleHtml;
use SiteXPost\Modules\Seo\Support\PublishableRenderer;
use SiteXPost\Modules\Seo\Support\SchemaBuilder;
use SiteXPost\Modules\Seo\Support\SiteSeoConfig;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;

/**
 * Explainable SEO audit of the exact HTML that would be published
 * (content + metadata + accepted images + accepted links + schema).
 * Synchronous and local; checking external links needs outbound requests
 * and runs in the queue (CheckArticleLinks), which then calls run() again
 * with the measured statuses.
 */
final class RunSeoAudit
{
    public function __construct(
        private readonly PublishableRenderer $renderer,
        private readonly ManageMetadata $metadata,
        private readonly SiteDirectory $sites,
        private readonly SchemaBuilder $schema,
        private readonly SeoRules $rules,
    ) {}

    /**
     * @param  array<string, int|null>|null  $linkStatus  url => HTTP status (null = unreachable); null = not checked
     */
    public function run(string $articleId, ?string $userId, ?array $linkStatus = null): SeoAuditRun
    {
        $ctx = $this->context($articleId, $linkStatus);
        $issues = $this->rules->run($ctx);
        $counts = ['PASS' => 0, 'WARNING' => 0, 'ERROR' => 0, 'BLOCKED' => 0];
        foreach ($issues as $i) {
            $counts[$i->severity]++;
        }

        return DB::transaction(function () use ($ctx, $issues, $counts, $userId, $linkStatus): SeoAuditRun {
            $run = new SeoAuditRun;
            $run->forceFill([
                'article_id' => $ctx['article']['id'], 'site_id' => $ctx['article']['site_id'], 'article_version_id' => $ctx['version_id'],
                'content_hash' => $ctx['hash'], 'rules_version' => SeoRules::VERSION, 'status' => 'completed', 'score' => SeoRules::score($issues),
                'pass_count' => $counts['PASS'], 'warning_count' => $counts['WARNING'], 'error_count' => $counts['ERROR'], 'blocked_count' => $counts['BLOCKED'],
                'links_checked' => $linkStatus !== null,
                'summary' => ['approved' => $ctx['approved'], 'words' => $ctx['html']->words, 'images' => count($ctx['images']), 'links' => count($ctx['html']->links),
                    'schema_types' => $ctx['schema']['types'], 'stale_links' => count($ctx['stale_links']), 'disclaimer' => 'checklist_not_ranking_guarantee'],
                'created_by' => $userId,
            ])->save();
            foreach ($issues as $i) {
                /** @var AuditIssue $i */
                (new SeoAuditIssue)->forceFill(['seo_audit_run_id' => $run->id, 'rule' => $i->rule, 'category' => $i->category, 'severity' => $i->severity,
                    'weight' => $i->weight, 'points' => $i->points(), 'message' => mb_substr($i->message, 0, 500), 'explanation' => mb_substr($i->explanation, 0, 1000),
                    'fix' => $i->fix !== null ? mb_substr($i->fix, 0, 500) : null, 'context' => $i->context])->save();
            }

            return $run;
        });
    }

    /**
     * @param  array<string, int|null>|null  $linkStatus
     * @return array<string, mixed>
     */
    public function context(string $articleId, ?array $linkStatus = null): array
    {
        $render = $this->renderer->render($articleId, false);
        $article = $render['article'];
        $meta = $this->metadata->get($articleId);
        $profile = $this->sites->publishingProfile($article['site_id']) ?? throw ApiException::notFound();
        $policy = SiteSeoConfig::for($article['site_id']);
        $own = $this->sites->publication($article['site_id'], 'article', $article['id']);
        $metaArr = $meta->only(['seo_title', 'meta_description', 'slug', 'canonical_url', 'focus_keyword', 'robots_noindex', 'schema_types', 'og_title', 'og_description']);
        $schema = $this->schema->build($policy, $profile, $article, $render['title'], $render['html'], $metaArr, ['url' => $own['url'] ?? null]);
        $hash = hash('sha256', $render['hash'].'|'.json_encode($metaArr).'|'.json_encode($policy).'|'.SeoRules::VERSION);

        return [
            'title' => $render['title'], 'html' => ArticleHtml::parse($render['html']), 'meta' => $metaArr, 'article' => $article,
            'site' => $profile, 'policy' => $policy, 'inventory' => $this->sites->contentIndex($article['site_id']), 'own_wp_post_id' => $own['wp_post_id'] ?? null,
            'images' => $render['images'], 'featured' => $render['featured'], 'link_status' => $linkStatus, 'schema' => $schema, 'sources' => $render['sources'],
            'suggestions' => SeoLinkSuggestion::query()->where('article_id', $article['id'])->where('status', 'suggested')->count(),
            'version_id' => $render['version_id'], 'approved' => $render['approved'], 'hash' => $hash, 'stale_links' => $render['stale_links'], 'render_html' => $render['html'],
        ];
    }
}
