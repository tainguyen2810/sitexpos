<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Queries;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Seo\Actions\ManageMetadata;
use SiteXPost\Modules\Seo\Actions\RunSeoAudit;
use SiteXPost\Modules\Seo\Contracts\SeoGate;
use SiteXPost\Modules\Seo\Domain\SeoAuditRun;
use SiteXPost\Modules\Seo\Support\PublishableRenderer;
use SiteXPost\Modules\Seo\Support\SchemaBuilder;
use SiteXPost\Modules\Seo\Support\SiteSeoConfig;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;

final class SeoGateQuery implements SeoGate
{
    public function __construct(
        private readonly PublishableRenderer $renderer,
        private readonly ManageMetadata $metadata,
        private readonly RunSeoAudit $audit,
        private readonly SchemaBuilder $schema,
        private readonly SiteDirectory $sites,
    ) {}

    public function render(string $articleId, bool $forPublish): array
    {
        return $this->renderer->render($articleId, $forPublish);
    }

    public function metadata(string $articleId): array
    {
        return $this->metadata->get($articleId)->only(['id', 'seo_title', 'meta_description', 'slug', 'canonical_url', 'focus_keyword', 'secondary_keywords',
            'og_title', 'og_description', 'social_image_asset_id', 'robots_noindex', 'robots_nofollow', 'schema_types', 'manual_fields', 'lock_version']);
    }

    public function wordpressSeo(string $articleId): array
    {
        $m = $this->metadata->get($articleId);
        $policy = SiteSeoConfig::for($m->site_id);
        $seo = ['title' => (string) $m->seo_title, 'description' => (string) $m->meta_description, 'focus_keyword' => (string) $m->focus_keyword];
        // Only send canonical / noindex when set: an absent key leaves WordPress' own value untouched.
        if ((string) $m->canonical_url !== '') {
            $seo['canonical'] = (string) $m->canonical_url;
        }
        if ($m->robots_noindex && $policy['allow_robots_override']) {
            $seo['noindex'] = true;
        }

        return $seo;
    }

    public function schema(string $articleId, array $extra = []): array
    {
        $render = $this->renderer->render($articleId, false);
        $article = $render['article'];
        $profile = $this->sites->publishingProfile($article['site_id']) ?? throw ApiException::notFound();
        $meta = $this->metadata->get($articleId)->only(['meta_description', 'schema_types']);

        return $this->schema->build(SiteSeoConfig::for($article['site_id']), $profile, $article, $render['title'], $render['html'], $meta, $extra);
    }

    public function audit(string $articleId, ?string $userId): array
    {
        $run = $this->audit->run($articleId, $userId);

        return [...$this->shape($run), 'issues' => $run->issues()->get()->map(fn ($i) => $i->only(['rule', 'severity', 'message']))->all()];
    }

    public function latestAudit(string $articleId): ?array
    {
        $run = SeoAuditRun::query()->where('article_id', $articleId)->orderByDesc('created_at')->orderByDesc('id')->first();

        return $run === null ? null : $this->shape($run) + ['created_at' => $run->created_at?->toIso8601ZuluString()];
    }

    /**
     * @return array{id: string, score: int, blocked: int, errors: int, warnings: int, content_hash: string, links_checked: bool}
     */
    private function shape(SeoAuditRun $run): array
    {
        return ['id' => $run->id, 'score' => (int) $run->score, 'blocked' => (int) $run->blocked_count, 'errors' => (int) $run->error_count,
            'warnings' => (int) $run->warning_count, 'content_hash' => $run->content_hash, 'links_checked' => (bool) $run->links_checked];
    }
}
