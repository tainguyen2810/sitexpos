<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Support;

use SiteXPost\Modules\Research\Domain\ResearchRun;
use SiteXPost\Modules\Research\Domain\ResearchSource;
use SiteXPost\Modules\Research\Domain\SourceSnapshot;

/**
 * Records which source a run consulted (type, URL, status, time) and page
 * snapshots. Upserts by (run, type, label) / (source, url) → retry-safe.
 */
final class SourceRecorder
{
    /**
     * @param  array<string, mixed>  $attrs
     */
    public function source(ResearchRun $run, string $type, string $label, array $attrs = []): ResearchSource
    {
        $label = mb_substr($label, 0, 190);
        $row = ResearchSource::query()->where(['research_run_id' => $run->id, 'type' => $type, 'label' => $label])->first() ?? new ResearchSource;
        $row->forceFill([
            'campaign_id' => $run->campaign_id,
            'research_run_id' => $run->id,
            'type' => $type,
            'label' => $label,
            'status' => 'ok',
            'fetched_at' => now(),
            ...$attrs,
        ])->save();

        return $row;
    }

    /**
     * @param  array<string, mixed>  $page  HtmlExtractor output
     */
    public function snapshot(ResearchSource $source, string $url, int $status, array $page): SourceSnapshot
    {
        $hash = hash('sha256', $url);
        $snap = SourceSnapshot::query()->where(['research_source_id' => $source->id, 'url_hash' => $hash])->first() ?? new SourceSnapshot;
        $snap->forceFill([
            'research_source_id' => $source->id,
            'url' => mb_substr($url, 0, 1000),
            'url_hash' => $hash,
            'http_status' => $status,
            'title' => mb_substr((string) ($page['title'] ?? ''), 0, 500),
            'meta_description' => mb_substr((string) ($page['meta_description'] ?? ''), 0, 1000),
            'language' => $page['language'] ?? null,
            'headings' => array_slice($page['headings'] ?? [], 0, 60),
            'questions' => array_slice($page['questions'] ?? [], 0, 30),
            'internal_links' => array_slice($page['internal_links'] ?? [], 0, 100),
            'word_count' => (int) ($page['word_count'] ?? 0),
            'content_hash' => $page['content_hash'] ?? null,
            'fetched_at' => now(),
        ])->save();

        return $snap;
    }
}
