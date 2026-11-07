<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Retrieval;

use Carbon\Carbon;
use SiteXPost\Modules\Research\Contracts\PublicWebFetcher;
use SiteXPost\Modules\Research\Crawler\FetchException;
use SiteXPost\Modules\Writer\Domain\EvidenceSnapshot;
use SiteXPost\Modules\Writer\Domain\EvidenceSource;
use SiteXPost\Modules\Writer\Support\SourceFilter;
use SiteXPost\Modules\Writer\Support\WriterSettings;

/**
 * Retrieves one evidence source for real:
 * - SSRF guard on every URL and redirect hop + IP pinning, private networks
 *   blocked, robots.txt honoured, per-host rate limit, timeouts, size cap,
 *   content-type allow-list (all from the Phase 4 PublicFetcher);
 * - captcha / paywall / login walls are recorded as blocked, never bypassed;
 * - the text snapshot is stored with its SHA-256 so a claim can be
 *   re-verified later against exactly what was read;
 * - snapshots are cached PER WORKSPACE (no cross-tenant source sharing);
 * - the source filter applies to the URL and to the final URL after
 *   redirects (blocked → never used); preferred domains get a bonus.
 */
class SourceRetriever
{
    public function __construct(
        private readonly PublicWebFetcher $fetcher,
        private readonly PageExtractor $extractor,
        private readonly WriterSettings $settings,
        private readonly SourceFilter $filter,
    ) {}

    public static function hashUrl(string $url): string
    {
        return hash('sha256', strtolower(rtrim(trim($url), '/')));
    }

    /**
     * @param  list<string>  $brandTokens
     */
    public function retrieve(EvidenceSource $source, ?string $siteHost, array $brandTokens, ?string $declaredType = null): EvidenceSource
    {
        if ($this->filter->blocked($source->workspace_id, $source->url)) {
            return $this->block($source, null);
        }
        $hours = $this->settings->int('source_cache_hours');
        $cached = EvidenceSnapshot::query()->where('url_hash', $source->url_hash)->where('fetched_at', '>=', now()->subHours($hours))->latest('fetched_at')->first();

        if ($cached !== null) {
            return $this->apply($source, $cached, $siteHost, $brandTokens, $declaredType, true);
        }

        try {
            $result = $this->fetcher->get($source->url, 'evidence_fetch', true);
        } catch (FetchException $e) {
            $status = match ($e->errorCode) {
                'robots_disallowed' => 'blocked_robots',
                'blocked_url' => 'blocked_policy',
                'http_401', 'http_402', 'http_403' => 'blocked_access',
                default => 'failed',
            };
            $source->forceFill(['retrieval_status' => $status, 'error_code' => mb_substr($e->errorCode, 0, 64), 'http_status' => $e->httpStatus, 'retrieved_at' => now()])->save();

            return $source;
        }

        $page = $this->extractor->extract($result->body, $result->contentType, $result->url);
        if ($page['access_blocked'] !== null) {
            $source->forceFill(['retrieval_status' => 'blocked_access', 'error_code' => $page['access_blocked'], 'http_status' => $result->status, 'retrieved_at' => now(), 'final_url' => mb_substr($result->url, 0, 1000)])->save();

            return $source;
        }
        if (mb_strlen($page['text']) < 80) {
            $source->forceFill(['retrieval_status' => 'failed', 'error_code' => 'empty_content', 'http_status' => $result->status, 'retrieved_at' => now()])->save();

            return $source;
        }

        $snapshot = new EvidenceSnapshot;
        $snapshot->forceFill([
            'url' => mb_substr($result->url, 0, 1000),
            'url_hash' => $source->url_hash,
            'content_type' => mb_substr($result->contentType, 0, 100),
            'content_text' => $page['text'],
            'content_hash' => hash('sha256', $page['text']),
            'byte_size' => strlen($result->body),
            'meta' => array_diff_key($page, ['text' => true, 'access_blocked' => true]) + ['http_status' => $result->status],
            'fetched_at' => now(),
        ])->save();

        return $this->apply($source, $snapshot, $siteHost, $brandTokens, $declaredType, false);
    }

    /**
     * @param  list<string>  $brandTokens
     */
    private function apply(EvidenceSource $source, EvidenceSnapshot $snap, ?string $siteHost, array $brandTokens, ?string $declaredType, bool $fromCache): EvidenceSource
    {
        if ($this->filter->blocked($source->workspace_id, $snap->url)) {
            return $this->block($source, $snap->url);
        }
        $meta = $snap->meta ?? [];
        $type = SourceClassifier::type($source->url, $siteHost, $brandTokens, $declaredType);
        $injection = InjectionScanner::scan($snap->content_text);
        $rel = SourceClassifier::reliability($type, $source->url, [
            'published_at' => $meta['published_at'] ?? null, 'modified_at' => $meta['modified_at'] ?? null, 'author' => $meta['author'] ?? null,
            'text_length' => mb_strlen($snap->content_text), 'injection' => $injection,
        ], $this->settings->int('outdated_after_months'));
        if ($this->filter->preferred($source->workspace_id, $snap->url) || $this->filter->preferred($source->workspace_id, $source->url)) {
            $rel['score'] = min(100, $rel['score'] + SourceFilter::PREFER_BONUS);
            $rel['indicators'][] = 'preferred_source';
        }

        $source->forceFill([
            'final_url' => $snap->url,
            'title' => $meta['title'] ?? null,
            'publisher' => $meta['publisher'] ?? null,
            'author' => $meta['author'] ?? null,
            'published_at' => isset($meta['published_at']) ? Carbon::parse($meta['published_at']) : null,
            'modified_at' => isset($meta['modified_at']) ? Carbon::parse($meta['modified_at']) : null,
            'retrieved_at' => $snap->fetched_at,
            'source_type' => $type,
            'retrieval_status' => 'fetched',
            'http_status' => $meta['http_status'] ?? null,
            'error_code' => null,
            'reliability_score' => $rel['score'],
            'reliability' => ['indicators' => $rel['indicators'], 'injection_snippets' => $injection, 'from_cache' => $fromCache, 'rank' => SourceClassifier::rank($type)],
            'license_note' => $meta['license'] ?? null,
            'snapshot_id' => $snap->id,
        ])->save();

        return $source;
    }

    private function block(EvidenceSource $source, ?string $finalUrl): EvidenceSource
    {
        $source->forceFill(['retrieval_status' => 'blocked_policy', 'error_code' => 'source_blocked', 'retrieved_at' => now(),
            'final_url' => $finalUrl !== null ? mb_substr($finalUrl, 0, 1000) : $source->final_url])->save();

        return $source;
    }
}
