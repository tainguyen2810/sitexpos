<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Actions;

use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Media\Support\InfographicRenderer;
use SiteXPost\Modules\Media\Support\MediaPipeline;
use SiteXPost\Modules\Media\Support\MediaRejected;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;

/**
 * Infographic from structured data the customer supplies (with sources or
 * the explicit "customer provided" confirmation), rendered deterministically
 * — never by an image model — and stored like any other asset.
 */
final class CreateInfographic
{
    public function __construct(
        private readonly InfographicRenderer $renderer,
        private readonly MediaPipeline $pipeline,
        private readonly SiteDirectory $sites,
    ) {}

    /**
     * @param  array<string, mixed>  $spec
     *
     * @throws MediaRejected
     */
    public function handle(array $spec, ?string $userId): MediaAsset
    {
        if ($this->sites->find((string) $spec['site_id']) === null) {
            throw new MediaRejected('site_not_found');
        }
        $png = $this->renderer->render($spec);
        $asset = new MediaAsset;
        $asset->forceFill([
            'site_id' => $spec['site_id'], 'source_type' => 'infographic', 'original_filename' => 'infographic-'.substr(hash('sha256', $png), 0, 10).'.png',
            'license' => 'owned', 'attribution' => mb_substr(implode('; ', array_map(fn ($s) => (string) $s['label'], $spec['sources'] ?? [])), 0, 500) ?: null,
            'title' => mb_substr((string) $spec['title'], 0, 200), 'alt_text' => mb_substr('Infographic: '.$spec['title'], 0, 125),
            'status' => 'processing', 'created_by' => $userId,
            'metadata' => ['infographic' => array_intersect_key($spec, array_flip(['type', 'title', 'columns', 'rows', 'items', 'unit', 'sources', 'customer_provided', 'brand_color'])),
                'section_heading' => $spec['title'], 'article_id' => $spec['article_id'] ?? null],
        ])->save();
        $this->pipeline->ingest($asset, $png, $asset->original_filename);
        Audit::record('media.infographic_created', $asset, ['type' => $spec['type'], 'sources' => count($spec['sources'] ?? []), 'customer_provided' => (bool) ($spec['customer_provided'] ?? false)]);

        return $asset->refresh();
    }
}
