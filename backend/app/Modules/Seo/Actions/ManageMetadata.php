<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Actions;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Media\Contracts\MediaLibrary;
use SiteXPost\Modules\Seo\Domain\SeoMetadata;
use SiteXPost\Modules\Seo\Support\MetadataGenerator;
use SiteXPost\Modules\Seo\Support\SiteSeoConfig;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;

/**
 * SEO Metadata Editor: generate (deterministic, keeps manual edits) and
 * update (optimistic lock). Dangerous changes — canonical to another host,
 * noindex — require the site's explicit policy.
 */
final class ManageMetadata
{
    public function __construct(
        private readonly ArticleCatalog $articles,
        private readonly SiteDirectory $sites,
        private readonly MediaLibrary $media,
        private readonly MetadataGenerator $generator,
    ) {}

    public function get(string $articleId): SeoMetadata
    {
        $article = $this->article($articleId);

        return SeoMetadata::query()->where('article_id', $article['id'])->first() ?? $this->generate($articleId, null, false);
    }

    public function generate(string $articleId, ?string $userId, bool $audit = true): SeoMetadata
    {
        $article = $this->article($articleId);
        $content = $this->articles->approvedContent($articleId) ?? $this->articles->currentContent($articleId);
        $row = SeoMetadata::query()->where('article_id', $article['id'])->first() ?? new SeoMetadata;
        $featured = null;
        foreach ($this->media->placements($article['id'], true) as $pl) {
            if ($pl['slot'] === 'featured' && $pl['asset'] !== null) {
                $featured = $pl['asset']['id'];
            }
        }
        $current = $row->exists ? $row->only([...MetadataGenerator::FIELDS, 'manual_fields']) : [];
        $values = $this->generator->generate($article, (string) ($content['title'] ?? $article['title']), (string) ($content['html'] ?? ''), $featured, $current);
        $row->forceFill([...$values, 'article_id' => $article['id'], 'site_id' => $article['site_id'], 'source' => ($row->manual_fields ?? []) === [] ? 'generated' : 'manual',
            'manual_fields' => $row->manual_fields ?? [], 'lock_version' => (int) $row->lock_version + 1, 'updated_by' => $userId])->save();
        if ($audit) {
            Audit::record('seo.metadata_generated', $row, ['article_id' => $article['id']]);
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $articleId, array $data, int $lockVersion, string $userId): SeoMetadata
    {
        $row = $this->get($articleId);
        if ($row->lock_version !== $lockVersion) {
            throw ApiException::conflict('stale_metadata', __('seo.errors.stale_metadata'));
        }
        $profile = $this->sites->publishingProfile($row->site_id);
        $policy = SiteSeoConfig::for($row->site_id);
        $fields = array_intersect_key($data, array_flip(MetadataGenerator::FIELDS));
        if (array_key_exists('slug', $fields) && $fields['slug'] !== null) {
            $fields['slug'] = MetadataGenerator::slug((string) $fields['slug']);
        }
        if (! empty($fields['canonical_url'])) {
            $host = strtolower((string) parse_url((string) $fields['canonical_url'], PHP_URL_HOST));
            if ($host !== ($profile['host'] ?? '') && ! $policy['allow_canonical_override']) {
                throw new ApiException(422, 'canonical_requires_policy', __('seo.errors.canonical_requires_policy'));
            }
        }
        if (($fields['robots_noindex'] ?? false) === true && ! $policy['allow_robots_override']) {
            throw new ApiException(422, 'robots_requires_policy', __('seo.errors.robots_requires_policy'));
        }
        if (array_key_exists('social_image_asset_id', $fields) && $fields['social_image_asset_id'] !== null && $this->media->asset((string) $fields['social_image_asset_id']) === null) {
            throw new ApiException(422, 'invalid_social_image', __('seo.errors.invalid_social_image'));
        }
        $changed = [];
        foreach ($fields as $k => $v) {
            if ($row->getAttribute($k) != $v) {
                $changed[] = $k;
            }
        }
        $manual = array_values(array_unique([...($row->manual_fields ?? []), ...$changed]));
        $row->forceFill([...$fields, 'manual_fields' => $manual, 'source' => 'manual', 'lock_version' => $row->lock_version + 1, 'updated_by' => $userId])->save();
        Audit::record('seo.metadata_updated', $row, ['fields' => $changed, 'canonical' => $fields['canonical_url'] ?? null, 'noindex' => $fields['robots_noindex'] ?? null]);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function article(string $id): array
    {
        return preg_match('/^[0-9a-z]{26}$/i', $id) === 1 ? ($this->articles->find($id) ?? throw ApiException::notFound()) : throw ApiException::notFound();
    }
}
