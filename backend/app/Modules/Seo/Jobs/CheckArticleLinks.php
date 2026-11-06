<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Jobs;

use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Kernel\Tenancy\Jobs\TenantJob;
use SiteXPost\Modules\Research\Contracts\PublicWebFetcher;
use SiteXPost\Modules\Research\Crawler\FetchException;
use SiteXPost\Modules\Seo\Actions\RunSeoAudit;
use SiteXPost\Modules\Seo\Support\ArticleHtml;
use Throwable;

/**
 * Checks the external links of the publishable render through the SSRF-safe
 * public fetcher (Phase 4), then stores a new audit with the statuses.
 * Internal links are validated against the synced content index instead.
 */
final class CheckArticleLinks extends TenantJob
{
    public const MAX_LINKS = 30;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $articleId, public ?string $userId, public ?string $jobRunId = null)
    {
        parent::__construct();
        $this->onQueue('default');
    }

    public function handle(RunSeoAudit $audit, PublicWebFetcher $fetcher): void
    {
        $run = $this->jobRunId !== null ? JobRun::query()->find($this->jobRunId) : null;
        $run?->forceFill(['status' => 'running', 'started_at' => now(), 'progress' => 5])->save();
        try {
            $ctx = $audit->context($this->articleId);
            $host = (string) ($ctx['site']['host'] ?? '');
            /** @var ArticleHtml $html */
            $html = $ctx['html'];
            $urls = [];
            foreach ($html->links as $l) {
                if (preg_match('#^https?://#i', $l['href']) === 1 && strtolower((string) parse_url($l['href'], PHP_URL_HOST)) !== $host) {
                    $urls[$l['href']] = true;
                }
            }
            $status = [];
            foreach (array_slice(array_keys($urls), 0, self::MAX_LINKS) as $url) {
                try {
                    $status[$url] = $fetcher->get($url, 'website_crawl', false)->status;
                } catch (FetchException $e) {
                    $status[$url] = $e->httpStatus;
                }
            }
            $result = $audit->run($this->articleId, $this->userId, $status);
            $run?->forceFill(['status' => 'succeeded', 'progress' => 100, 'finished_at' => now(), 'step' => 'audit:'.$result->id])->save();
        } catch (Throwable $e) {
            $run?->forceFill(['status' => 'failed', 'finished_at' => now(), 'error_code' => 'server_error', 'error_message' => __('api.server_error')])->save();
            throw $e;
        }
    }
}
