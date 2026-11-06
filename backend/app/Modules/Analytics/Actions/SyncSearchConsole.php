<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Analytics\Connectors\SearchConsoleConnector;
use SiteXPost\Modules\Analytics\Domain\AnalyticsProperty;
use SiteXPost\Modules\Analytics\Domain\AnalyticsSyncRun;
use SiteXPost\Modules\Analytics\Domain\UrlMapping;
use SiteXPost\Modules\Analytics\Support\AnalyticsConfig;
use SiteXPost\Modules\Analytics\Support\UrlMapper;
use SiteXPost\Modules\Analytics\Support\UrlNormalizer;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\SearchConsole\SearchConsoleException;

/**
 * Search Console ingestion (GSC-INTEGRATION.md §4).
 *
 * - Incremental: from the checkpoint (last complete day) minus a 2-day
 *   overlap (late rows) up to today − lag (GSC data is not real-time).
 * - Backfill: back to `backfill_days`, never beyond Google's 16 months.
 * - At most `max_days_per_run` days per run; the checkpoint lets the next
 *   run continue (a restarted worker resumes, it never starts over).
 * - Pagination with startRow / 25 000 rows; idempotent upserts on natural
 *   keys (deduplication); rate-limit / quota → retry with backoff.
 * - GSC hides anonymised queries, so query totals are lower than page /
 *   site totals; that is shown in the UI, never "fixed".
 */
final class SyncSearchConsole
{
    public const BACKOFF_MINUTES = [5, 15, 60, 180];

    public function __construct(
        private readonly SearchConsoleConnector $gsc,
        private readonly UrlMapper $mapper,
        private readonly TenantContext $context,
    ) {}

    public function run(AnalyticsSyncRun $run): AnalyticsSyncRun
    {
        if (in_array($run->status, ['completed', 'skipped'], true)) {
            return $run;
        }
        $run->forceFill(['status' => 'running', 'started_at' => $run->started_at ?? now(), 'attempts' => $run->attempts + 1, 'error_code' => null, 'error_message' => null])->save();
        $property = (AnalyticsProperty::query()->where(['site_id' => $run->site_id, 'provider' => 'gsc'])->first() ?? (new AnalyticsProperty)->forceFill(['site_id' => $run->site_id, 'provider' => 'gsc']));
        if (! $property->exists) {
            $property->forceFill(['site_id' => $run->site_id, 'provider' => 'gsc', 'status' => 'pending'])->save();
        }

        try {
            $propertyUrl = $this->gsc->verify($run->site_id);
            $property->forceFill(['property_ref' => $propertyUrl, 'status' => 'active'])->save();
            [$start, $end] = $this->range($run, $property);
            if ($start->greaterThan($end)) {
                return $this->finish($run, $property, 'completed', 0);
            }
            $run->forceFill(['range_start' => $run->range_start ?? $start->toDateString(), 'range_end' => $end->toDateString()])->save();

            $articleByHash = $this->mapper->refresh($run->site_id);
            $types = (array) config('sitex.analytics.search_types', ['web']);
            $rows = $run->rows_upserted;
            for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
                foreach ($types as $type) {
                    $rows += $this->syncDay($run, (string) $type, $day, $articleByHash);
                }
                $run->forceFill(['checkpoint_date' => $day->toDateString(), 'rows_upserted' => $rows, 'progress' => ['day' => $day->toDateString()] + ($run->progress ?? [])])->save();
                if ($property->last_complete_date === null || $day->greaterThan(CarbonImmutable::parse($property->last_complete_date))) {
                    $property->forceFill(['last_complete_date' => $day->toDateString()])->save();
                }
                if ($property->first_date === null || $day->lessThan(CarbonImmutable::parse($property->first_date))) {
                    $property->forceFill(['first_date' => $day->toDateString()])->save();
                }
            }
            foreach ($types as $type) {
                $rows += $this->syncQueryPages($run, (string) $type, $end);
            }

            return $this->finish($run, $property, 'completed', $rows);
        } catch (SearchConsoleException $e) {
            return $this->fail($run, $property, $e);
        }
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(AnalyticsSyncRun $run, AnalyticsProperty $property): array
    {
        $end = CarbonImmutable::now('UTC')->startOfDay()->subDays((int) config('sitex.analytics.lag_days', 3));
        $oldest = CarbonImmutable::now('UTC')->startOfDay()->subMonths(16)->addDay();
        $backfill = $end->subDays(AnalyticsConfig::backfillDays($this->context->workspaceId()) - 1);
        $start = match (true) {
            $run->checkpoint_date !== null => CarbonImmutable::parse($run->checkpoint_date)->addDay(), // resume this run
            $run->kind === 'backfill' => $backfill,
            $property->last_complete_date !== null => CarbonImmutable::parse($property->last_complete_date)->subDays(2),
            default => $backfill,
        };
        if ($run->range_end !== null) {
            $end = $end->min(CarbonImmutable::parse($run->range_end)); // resumed run keeps its planned range
        } elseif ($run->kind === 'backfill' && $property->first_date !== null) {
            // Only fill what is missing before the earliest synced day.
            $end = $end->min(CarbonImmutable::parse($property->first_date)->subDay());
        }
        $start = $start->max($oldest);
        $maxDays = max(1, (int) config('sitex.analytics.max_days_per_run', 31));
        if ($start->diffInDays($end) + 1 > $maxDays) {
            $end = $start->addDays($maxDays - 1);
        }

        return [$start, $end];
    }

    /**
     * @param  array<string, string>  $articleByHash
     */
    private function syncDay(AnalyticsSyncRun $run, string $type, CarbonImmutable $day, array $articleByHash): int
    {
        $d = $day->toDateString();
        $ws = $this->context->workspaceId();
        $now = now();
        $count = 0;

        $count += $this->paged($run, ['startDate' => $d, 'endDate' => $d, 'dimensions' => ['device', 'country'], 'type' => $type], function (array $rows) use ($run, $ws, $d, $type, $now): int {
            $data = [];
            foreach ($rows as $r) {
                $data[] = ['id' => self::id(), 'workspace_id' => $ws, 'site_id' => $run->site_id, 'date' => $d, 'search_type' => $type,
                    'device' => mb_substr(strtolower($r['keys'][0] ?? 'unknown'), 0, 8), 'country' => mb_substr(strtolower($r['keys'][1] ?? 'zzz'), 0, 3),
                    'clicks' => $r['clicks'], 'impressions' => $r['impressions'], 'position' => round($r['position'], 2), 'is_demo' => false, 'synced_at' => $now];
            }
            DB::table('search_performance_daily')->upsert($data, ['site_id', 'date', 'search_type', 'device', 'country'], ['clicks', 'impressions', 'position', 'synced_at']);

            return count($data);
        });

        $count += $this->paged($run, ['startDate' => $d, 'endDate' => $d, 'dimensions' => ['query'], 'type' => $type], function (array $rows) use ($run, $ws, $d, $type, $now): int {
            $data = [];
            foreach ($rows as $r) {
                $q = mb_substr((string) ($r['keys'][0] ?? ''), 0, 500);
                if ($q === '') {
                    continue;
                }
                $data[UrlNormalizer::queryHash($q)] = ['id' => self::id(), 'workspace_id' => $ws, 'site_id' => $run->site_id, 'date' => $d, 'search_type' => $type,
                    'query_hash' => UrlNormalizer::queryHash($q), 'query' => $q, 'clicks' => $r['clicks'], 'impressions' => $r['impressions'],
                    'position' => round($r['position'], 2), 'is_demo' => false, 'synced_at' => $now];
            }
            foreach (array_chunk(array_values($data), 1000) as $chunk) {
                DB::table('search_query_metrics')->upsert($chunk, ['site_id', 'date', 'search_type', 'query_hash'], ['query', 'clicks', 'impressions', 'position', 'synced_at']);
            }

            return count($data);
        });

        $count += $this->paged($run, ['startDate' => $d, 'endDate' => $d, 'dimensions' => ['page'], 'type' => $type], function (array $rows) use ($run, $ws, $d, $type, $now, $articleByHash): int {
            $data = [];
            foreach ($rows as $r) {
                $url = (string) ($r['keys'][0] ?? '');
                if ($url === '') {
                    continue;
                }
                $hash = UrlNormalizer::hash($url);
                $data[$hash] = ['id' => self::id(), 'workspace_id' => $ws, 'site_id' => $run->site_id, 'date' => $d, 'search_type' => $type,
                    'page_hash' => $hash, 'page_url' => mb_substr(UrlNormalizer::normalize($url), 0, 1000), 'article_id' => $articleByHash[$hash] ?? null,
                    'clicks' => $r['clicks'], 'impressions' => $r['impressions'], 'position' => round($r['position'], 2), 'is_demo' => false, 'synced_at' => $now];
                if (! isset($articleByHash[$hash])) {
                    $this->rememberUnmapped($run->site_id, $hash, $url);
                }
            }
            foreach (array_chunk(array_values($data), 1000) as $chunk) {
                DB::table('search_page_metrics')->upsert($chunk, ['site_id', 'date', 'search_type', 'page_hash'], ['page_url', 'article_id', 'clicks', 'impressions', 'position', 'synced_at']);
            }

            return count($data);
        });

        return $count;
    }

    private function syncQueryPages(AnalyticsSyncRun $run, string $type, CarbonImmutable $end): int
    {
        $window = (int) config('sitex.analytics.window_days', 28);
        $start = $end->subDays($window - 1);
        $ws = $this->context->workspaceId();

        return $this->paged($run, ['startDate' => $start->toDateString(), 'endDate' => $end->toDateString(), 'dimensions' => ['query', 'page'], 'type' => $type, 'max_pages' => 2],
            function (array $rows) use ($run, $ws, $start, $end, $type): int {
                $data = [];
                foreach ($rows as $r) {
                    $q = mb_substr((string) ($r['keys'][0] ?? ''), 0, 500);
                    $url = (string) ($r['keys'][1] ?? '');
                    if ($q === '' || $url === '') {
                        continue;
                    }
                    $qh = UrlNormalizer::queryHash($q);
                    $ph = UrlNormalizer::hash($url);
                    $data[$qh.$ph] = ['id' => self::id(), 'workspace_id' => $ws, 'site_id' => $run->site_id, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
                        'search_type' => $type, 'query_hash' => $qh, 'query' => $q, 'page_hash' => $ph, 'page_url' => mb_substr(UrlNormalizer::normalize($url), 0, 1000),
                        'clicks' => $r['clicks'], 'impressions' => $r['impressions'], 'position' => round($r['position'], 2), 'is_demo' => false];
                }
                foreach (array_chunk(array_values($data), 1000) as $chunk) {
                    DB::table('search_query_page_metrics')->upsert($chunk, ['site_id', 'period_end', 'search_type', 'query_hash', 'page_hash'], ['query', 'page_url', 'clicks', 'impressions', 'position']);
                }

                return count($data);
            });
    }

    /**
     * @param  array<string, mixed>  $req
     * @param  callable(list<array{keys: list<string>, clicks: int, impressions: int, ctr: float, position: float}>): int  $store
     */
    private function paged(AnalyticsSyncRun $run, array $req, callable $store): int
    {
        $limit = (int) config('sitex.analytics.page_size', 25000);
        $maxPages = (int) ($req['max_pages'] ?? config('sitex.analytics.max_pages_per_request', 10));
        $total = 0;
        for ($page = 0; $page < $maxPages; $page++) {
            $rows = $this->gsc->query($run->site_id, ['startDate' => $req['startDate'], 'endDate' => $req['endDate'], 'dimensions' => $req['dimensions'],
                'type' => $req['type'], 'rowLimit' => $limit, 'startRow' => $page * $limit, 'dataState' => 'final']);
            $run->increment('api_requests');
            if ($rows === []) {
                break;
            }
            $total += $store($rows);
            if (count($rows) < $limit) {
                break;
            }
        }

        return $total;
    }

    private function rememberUnmapped(string $siteId, string $hash, string $url): void
    {
        if (! UrlMapping::query()->where('site_id', $siteId)->where('page_hash', $hash)->exists()) {
            $m = new UrlMapping;
            $m->forceFill(['site_id' => $siteId, 'page_hash' => $hash, 'page_url' => mb_substr(UrlNormalizer::normalize($url), 0, 1000), 'source' => 'gsc', 'is_current' => true])->save();
        }
    }

    private function finish(AnalyticsSyncRun $run, AnalyticsProperty $property, string $status, int $rows): AnalyticsSyncRun
    {
        $run->forceFill(['status' => $status, 'rows_upserted' => $rows, 'finished_at' => now(), 'next_retry_at' => null])->save();
        $property->forceFill(['last_sync_at' => now(), 'last_sync_status' => $status, 'last_error_code' => null, 'consecutive_failures' => 0, 'status' => 'active'])->save();
        $this->gsc->markSynced($run->site_id, null);

        return $run;
    }

    private function fail(AnalyticsSyncRun $run, AnalyticsProperty $property, SearchConsoleException $e): AnalyticsSyncRun
    {
        $retry = $e->retryable && $run->attempts <= count(self::BACKOFF_MINUTES);
        $progressed = $run->checkpoint_date !== null;
        $run->forceFill([
            'status' => $progressed ? 'partial' : 'failed', 'error_code' => $e->errorCode, 'error_message' => mb_substr($e->getMessage(), 0, 500),
            'next_retry_at' => $retry ? now()->addMinutes(self::BACKOFF_MINUTES[min($run->attempts - 1, count(self::BACKOFF_MINUTES) - 1)]) : null,
            'finished_at' => $retry ? null : now(),
        ])->save();
        $property->forceFill(['last_sync_at' => now(), 'last_sync_status' => 'failed', 'last_error_code' => $e->errorCode,
            'consecutive_failures' => $property->consecutive_failures + 1, 'status' => $e->retryable ? $property->status : 'error'])->save();
        $this->gsc->markSynced($run->site_id, $e->errorCode);
        if (! $e->retryable) {
            Audit::record('analytics.sync_failed', $run, ['error_code' => $e->errorCode]);
        }

        return $run;
    }

    private static function id(): string
    {
        return strtolower((string) Str::ulid());
    }
}
