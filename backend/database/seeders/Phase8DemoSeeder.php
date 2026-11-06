<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Agent\Domain\AgentActionLog;
use SiteXPost\Modules\Agent\Domain\AgentConfig;
use SiteXPost\Modules\Agent\Domain\AgentMemory;
use SiteXPost\Modules\Agent\Domain\AgentRun;
use SiteXPost\Modules\Agent\Domain\AgentTask;
use SiteXPost\Modules\Agent\Domain\AutomationSchedule;
use SiteXPost\Modules\Agent\Engine\ScheduleCalculator;
use SiteXPost\Modules\Analytics\Actions\AnalyzeSite;
use SiteXPost\Modules\Analytics\Actions\ComputeHealthScore;
use SiteXPost\Modules\Analytics\Actions\GenerateReport;
use SiteXPost\Modules\Analytics\Actions\TrackExperiments;
use SiteXPost\Modules\Analytics\Domain\AnalyticsProperty;
use SiteXPost\Modules\Analytics\Domain\AnalyticsSyncRun;
use SiteXPost\Modules\Analytics\Domain\ContentRefreshJob;
use SiteXPost\Modules\Analytics\Domain\SeoRecommendation;
use SiteXPost\Modules\Analytics\Domain\SeoReport;
use SiteXPost\Modules\Analytics\Support\UrlMapper;
use SiteXPost\Modules\Analytics\Support\UrlNormalizer;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Publishing\Domain\WordPressPostMapping;
use SiteXPost\Modules\Seo\Actions\ManageMetadata;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Enums\SiteStatus;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Domain\Article;

/**
 * DEVELOPMENT ONLY — Phase 8 sample data for "Cửa hàng A (Demo)".
 *
 * Search Console numbers here are SYNTHETIC: every metric row, snapshot,
 * opportunity, run and report is stored with is_demo = true, the sites are
 * named "(DEMO)" on the .test domain and the property is "sc-domain:…test".
 * They exist only so the screens can be reviewed; nothing is presented as
 * real Google data and nothing is sent to Google, WordPress or an AI provider.
 *
 * Classification, decay, opportunities, recommendations, health scores,
 * experiment outcomes and reports are produced by the real Phase 8 engine
 * from those rows (no hand-written verdicts). Agent runs / tasks / logs are
 * written directly as a labelled history.
 *
 * Volumes: 3 sites, 12 months of daily metrics, 100 articles, 500 queries,
 * ≥50 opportunities, ≥30 recommendations, 20 agent runs, 10 refresh jobs,
 * 5 experiments, 6 monthly reports. Refuses to run in production.
 */
class Phase8DemoSeeder extends Seeder
{
    private const DAYS = 365;

    private const SITES = [
        ['url' => ResearchDemoSeeder::SITE_URL, 'name' => 'TechZone (DEMO)', 'articles' => 34,
            'topics' => ['laptop gaming', 'card màn hình', 'ổ cứng ssd', 'bàn phím cơ', 'chuột không dây', 'màn hình 4k', 'tai nghe bluetooth', 'ram ddr5', 'nguồn máy tính', 'tản nhiệt nước', 'webcam', 'router wifi 6']],
        ['url' => 'https://giadung.demo.sitexpost.test', 'name' => 'Gia Dụng Xanh (DEMO)', 'articles' => 33,
            'topics' => ['nồi chiên không dầu', 'máy lọc không khí', 'robot hút bụi', 'máy lọc nước', 'bếp từ', 'máy rửa bát', 'quạt điều hòa', 'nồi cơm điện', 'máy xay sinh tố', 'lò vi sóng', 'máy sấy tóc']],
        ['url' => 'https://dulich.demo.sitexpost.test', 'name' => 'Du Lịch Việt (DEMO)', 'articles' => 33,
            'topics' => ['du lịch đà lạt', 'du lịch phú quốc', 'vé máy bay giá rẻ', 'khách sạn hội an', 'tour hạ long', 'du lịch sapa', 'kinh nghiệm nha trang', 'homestay vũng tàu', 'visa nhật bản', 'du lịch côn đảo', 'lịch trình huế']],
    ];

    private const ANGLES = ['hướng dẫn chọn', 'so sánh', 'kinh nghiệm', 'top 10', 'đánh giá', 'cách dùng', 'bảng giá', 'lỗi thường gặp'];

    private const QUERY_SUFFIX = ['', ' giá rẻ', ' tốt nhất', ' 2026', ' review'];

    public function run(TenantContext $context): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Phase8DemoSeeder is disabled in production.');
        }
        $workspace = Workspace::query()->where('name', 'Cửa hàng A (Demo)')->where('is_demo', true)->first();
        if ($workspace === null) {
            return;
        }
        $ownerId = User::query()->where('email', 'owner@'.DemoSeeder::DOMAIN)->value('id');
        $context->runAs($workspace, null, function () use ($workspace, $ownerId): void {
            if (AnalyticsProperty::query()->where('is_demo', true)->exists()) {
                return; // idempotent
            }
            mt_srand(8008);
            $end = CarbonImmutable::now('UTC')->subDays(3)->startOfDay();
            $sites = [];
            foreach (self::SITES as $si => $def) {
                $site = $this->site($def);
                $articles = $this->articles($site, $def, $si, $end);
                $this->metrics($workspace, $site, $def, $articles, $end, $si);
                $this->property($site, $end, $si);
                app(UrlMapper::class)->refresh($site->id);
                app(AnalyzeSite::class)->run($site->id);
                for ($m = 11; $m >= 1; $m--) {
                    app(ComputeHealthScore::class)->run($site->id, $end->subMonths($m)->endOfMonth()->startOfDay());
                }
                $sites[] = ['site' => $site, 'articles' => $articles];
            }
            $siteIds = array_map(fn ($s) => $s['site']->id, $sites);
            $this->label($siteIds);
            $this->decisions($siteIds, $ownerId);
            $this->refreshJobs($sites, $ownerId);
            $this->experiments($sites[0], $ownerId, $end);
            $this->agent($sites, $ownerId);
            $this->reports($sites[0]['site'], $ownerId, $end);
            $this->label($siteIds);
        });
    }

    /** @param array<string, mixed> $def */
    private function site(array $def): Site
    {
        $site = Site::query()->where('url_hash', Site::hashUrl($def['url']))->first() ?? new Site;
        $site->fill(['name' => $site->exists ? $site->name : $def['name']]);
        // Never paired: no health checks, no publishing, no calls to the fake domain.
        $site->forceFill(['url' => $def['url'], 'url_hash' => Site::hashUrl($def['url']), 'status' => $site->exists ? $site->status : SiteStatus::PendingPairing])->save();

        return $site;
    }

    /**
     * Published demo articles with a current WordPress mapping on the .test domain.
     *
     * @param  array<string, mixed>  $def
     * @return list<array{id: string, url: string, title: string, keyword: string, scenario: string}>
     */
    private function articles(Site $site, array $def, int $si, CarbonImmutable $end): array
    {
        $scenarios = ['declining', 'declining', 'growing', 'growing', 'low_ctr', 'near_top', 'recent', 'sparse', 'stable', 'stable'];
        $host = (string) parse_url($def['url'], PHP_URL_HOST);
        $out = [];
        for ($j = 0; $j < $def['articles']; $j++) {
            $topic = $def['topics'][$j % count($def['topics'])];
            $angle = self::ANGLES[intdiv($j, count($def['topics'])) % count(self::ANGLES)];
            $keyword = $angle.' '.$topic;
            $slug = Str::slug(Str::ascii($keyword));
            $url = rtrim($def['url'], '/').'/'.$slug.'/';
            $scenario = $scenarios[$j % 10];
            $a = new Article;
            $a->forceFill(['site_id' => $site->id, 'mode' => 'manual', 'title' => '[DEMO] '.Str::ucfirst($keyword), 'status' => 'APPROVED', 'primary_keyword' => $keyword,
                'secondary_keywords' => [], 'search_intent' => 'informational', 'content_type' => 'blog_article', 'settings' => ['target_words' => 1200, 'demo' => true],
                'review_mode' => 'step_by_step', 'budget_limit_micro_usd' => 1_000_000, 'spent_micro_usd' => 0, 'is_demo' => true, 'fingerprint' => sha1('p8demo'.$si.'-'.$j)])->save();
            $published = $scenario === 'recent' ? $end->subDays(20) : $end->subDays(120 + ($j * 7) % 200);
            (new WordPressPostMapping)->forceFill(['site_id' => $site->id, 'article_id' => $a->id, 'external_content_id' => 'sxa-'.$a->id, 'idempotency_key' => 'p8demo-'.$a->id,
                'is_current' => true, 'wp_post_id' => 8000 + $si * 100 + $j, 'wp_status' => 'publish', 'url' => $url, 'slug' => $slug, 'site_host' => $host,
                'last_published_at' => $published])->save();
            if ($j % 7 !== 3) { // most demo articles have SEO metadata; the rest show up as missing_metadata
                app(ManageMetadata::class)->get($a->id)->forceFill(['seo_title' => Str::ucfirst($keyword).' (DEMO)',
                    'meta_description' => '[DEMO] '.Str::ucfirst($keyword).': nội dung minh họa cho giao diện Analytics, không phải dữ liệu thật.'])->save();
            }
            $out[] = ['id' => $a->id, 'url' => $url, 'title' => (string) $a->title, 'keyword' => $keyword, 'scenario' => $scenario];
        }

        return $out;
    }

    /**
     * Synthetic daily Search Console rows (DEMO). 5 queries per article → 500 queries.
     *
     * @param  array<string, mixed>  $def
     * @param  list<array{id: string, url: string, title: string, keyword: string, scenario: string}>  $articles
     */
    private function metrics(Workspace $ws, Site $site, array $def, array $articles, CarbonImmutable $end, int $si): void
    {
        $now = now();
        $base = ['workspace_id' => $ws->id, 'site_id' => $site->id, 'search_type' => 'web', 'is_demo' => true, 'synced_at' => $now];
        $pages = [];
        foreach ($articles as $a) {
            $pages[] = $a + ['clicks' => mt_rand(4, 40), 'ctr' => mt_rand(20, 60) / 1000, 'position' => mt_rand(30, 140) / 10];
        }
        foreach (['', 'danh-muc/khuyen-mai/', 'lien-he/'] as $k => $path) { // a few pages that are not SiteX articles (left unmapped)
            $pages[] = ['id' => null, 'url' => rtrim($def['url'], '/').'/'.$path, 'keyword' => $k === 0 ? Str::lower($def['name']) : ($k === 1 ? 'khuyến mãi '.$def['topics'][0] : 'liên hệ '.Str::lower($def['name'])),
                'scenario' => 'stable', 'clicks' => mt_rand(20, 80), 'ctr' => 0.08, 'position' => 2.2];
        }
        $weights = [0.4, 0.22, 0.12, 0.07, 0.04]; // sum < 1: Google hides anonymised queries
        $win = []; // [pageIdx][window][qIdx] => [c, i, pos*i]
        /** @var array<string, list<array<string, mixed>>> $buf */
        $buf = ['search_performance_daily' => [], 'search_page_metrics' => [], 'search_query_metrics' => []];
        $flush = function (bool $force = false) use (&$buf): void {
            foreach ($buf as $table => $rows) {
                if ($rows !== [] && ($force || count($rows) >= 500)) {
                    DB::table($table)->insert($rows);
                    $buf[$table] = [];
                }
            }
        };
        for ($i = self::DAYS - 1; $i >= 0; $i--) {
            $day = $end->subDays($i);
            $date = $day->toDateString();
            $season = 1 + 0.12 * sin(2 * M_PI * ($day->dayOfYear + 40 * $si) / 365);
            $weekday = $day->isWeekend() ? 0.82 : 1.0;
            $dayC = 0;
            $dayI = 0;
            $dayPI = 0.0;
            foreach ($pages as $pi => $p) {
                [$cm, $im, $pos] = $this->scenario($p['scenario'], $i, $p['position']);
                if ($im <= 0) {
                    continue;
                }
                $noise = mt_rand(88, 112) / 100;
                $clicks = max(0, (int) round($p['clicks'] * $cm * $season * $weekday * $noise));
                $impr = max($clicks + 1, (int) round($p['clicks'] / $p['ctr'] * $im * $season * $weekday * $noise));
                if ($p['scenario'] === 'low_ctr') {
                    $clicks = (int) floor($impr * 0.004);
                }
                if ($p['scenario'] === 'sparse') {
                    if ($i % 9 !== 0) {
                        continue;
                    }
                    $clicks = min($clicks, 1);
                    $impr = min($impr, 12);
                }
                $hash = UrlNormalizer::hash($p['url']);
                $buf['search_page_metrics'][] = $base + ['id' => strtolower((string) Str::ulid()), 'date' => $date, 'page_hash' => $hash, 'page_url' => UrlNormalizer::normalize($p['url']),
                    'article_id' => $p['id'], 'clicks' => $clicks, 'impressions' => $impr, 'position' => $pos];
                $dayC += $clicks;
                $dayI += $impr;
                $dayPI += $pos * $impr;
                $window = $i < 28 ? 'recent' : ($i < 56 ? 'previous' : null);
                foreach ($weights as $qi => $w) {
                    if ($qi === 4 && in_array($p['scenario'], ['growing', 'near_top'], true) && $i >= 25) {
                        continue; // a query that only appeared recently (new_query)
                    }
                    if ($p['id'] === null && $qi > 1) {
                        continue;
                    }
                    $qc = (int) round($clicks * $w);
                    $qim = (int) round($impr * $w);
                    if ($qim === 0) {
                        continue;
                    }
                    $q = $p['keyword'].self::QUERY_SUFFIX[$qi];
                    $qpos = round(min(100, $pos + $qi * 0.8), 2);
                    $buf['search_query_metrics'][] = $base + ['id' => strtolower((string) Str::ulid()), 'date' => $date, 'query_hash' => UrlNormalizer::queryHash($q), 'query' => $q,
                        'clicks' => $qc, 'impressions' => $qim, 'position' => $qpos];
                    if ($window !== null) {
                        $cell = $win[$pi][$window][$qi] ?? [0, 0, 0.0];
                        $win[$pi][$window][$qi] = [$cell[0] + $qc, $cell[1] + $qim, $cell[2] + $qpos * $qim];
                    }
                }
            }
            $split = [['mobile', 'vnm', 0.56], ['desktop', 'vnm', 0.3], ['tablet', 'vnm', 0.04], ['mobile', 'usa', 0.06], ['desktop', 'usa', 0.04]];
            foreach ($split as [$device, $country, $share]) {
                $buf['search_performance_daily'][] = $base + ['id' => strtolower((string) Str::ulid()), 'date' => $date, 'device' => $device, 'country' => $country,
                    'clicks' => (int) round($dayC * $share), 'impressions' => (int) round($dayI * $share), 'position' => $dayI > 0 ? round($dayPI / $dayI + ($device === 'mobile' ? 0.3 : 0), 2) : 0];
            }
            $flush();
        }
        $flush(true);

        // Window aggregates (query × page) for the two latest windows, as the sync writes them.
        $periods = ['recent' => [$end->subDays(27), $end], 'previous' => [$end->subDays(55), $end->subDays(28)]];
        $rows = [];
        foreach ($win as $pi => $byWindow) {
            $p = $pages[$pi];
            foreach ($byWindow as $window => $cells) {
                foreach ($cells as $qi => [$c, $im, $pim]) {
                    $q = $p['keyword'].self::QUERY_SUFFIX[$qi];
                    $rows[] = $this->qp($ws, $site, $periods[$window], $q, $p['url'], $c, $im, $im > 0 ? round($pim / $im, 2) : 0);
                    // Two articles competing for the same head query (cannibalization) on declining pages.
                    if ($qi === 0 && $p['scenario'] === 'declining' && isset($pages[$pi + 2]) && $pages[$pi + 2]['id'] !== null) {
                        $rows[] = $this->qp($ws, $site, $periods[$window], $q, $pages[$pi + 2]['url'], (int) round($c * 0.6), (int) round($im * 0.8), $im > 0 ? round($pim / $im + 2.5, 2) : 0);
                    }
                }
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('search_query_page_metrics')->insert($chunk);
        }
    }

    /**
     * Multipliers (clicks, impressions) and position for a scenario on day i (0 = latest complete day).
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private function scenario(string $scenario, int $i, float $position): array
    {
        return match ($scenario) {
            'declining' => $i < 28 ? [0.5, 0.75, $position + 2.4] : [1.0, 1.0, $position],
            'growing' => $i < 28 ? [1.7, 1.45, max(1.5, $position - 2.0)] : ($i < 56 ? [1.0, 1.0, $position] : [0.85, 0.9, $position + 0.6]),
            'low_ctr' => [1.0, 4.0, 3.6],
            'near_top' => $i < 28 ? [1.15, 1.2, 11.5] : [1.0, 1.0, 15.8],
            'recent' => $i < 20 ? [1.0, 1.0, $position] : [0.0, 0.0, $position],
            default => [1.0, 1.0, $position],
        };
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $period
     * @return array<string, mixed>
     */
    private function qp(Workspace $ws, Site $site, array $period, string $q, string $url, int $c, int $im, float $pos): array
    {
        return ['id' => strtolower((string) Str::ulid()), 'workspace_id' => $ws->id, 'site_id' => $site->id, 'period_start' => $period[0]->toDateString(), 'period_end' => $period[1]->toDateString(),
            'search_type' => 'web', 'query_hash' => UrlNormalizer::queryHash($q), 'query' => $q, 'page_hash' => UrlNormalizer::hash($url), 'page_url' => UrlNormalizer::normalize($url),
            'clicks' => $c, 'impressions' => $im, 'position' => $pos, 'is_demo' => true];
    }

    private function property(Site $site, CarbonImmutable $end, int $si): void
    {
        $host = (string) parse_url($site->url, PHP_URL_HOST);
        (new AnalyticsProperty)->forceFill(['site_id' => $site->id, 'provider' => 'gsc', 'property_ref' => 'sc-domain:'.$host, 'status' => 'active',
            'first_date' => $end->subDays(self::DAYS - 1)->toDateString(), 'last_complete_date' => $end->toDateString(), 'last_sync_at' => now()->subHours(5),
            'last_sync_status' => $si === 2 ? 'partial' : 'completed', 'last_error_code' => $si === 2 ? 'quota_exceeded' : null, 'consecutive_failures' => $si === 2 ? 1 : 0, 'is_demo' => true])->save();
        $runs = [['backfill', 'completed', self::DAYS, 1, 40], ['incremental', 'completed', 4, 0, 6], ['incremental', 'completed', 4, 0, 4], ['incremental', $si === 2 ? 'partial' : 'completed', 4, 0, 1]];
        foreach ($runs as $k => [$kind, $status, $days, $startAgo, $hoursAgo]) {
            $finished = now()->subHours($hoursAgo * 24 / max(1, $k + 1));
            (new AnalyticsSyncRun)->forceFill(['site_id' => $site->id, 'provider' => 'gsc', 'kind' => $kind, 'status' => $status,
                'range_start' => $end->subDays($days - 1 + $startAgo)->toDateString(), 'range_end' => $end->toDateString(), 'checkpoint_date' => $end->toDateString(),
                'rows_upserted' => $kind === 'backfill' ? 45_000 : 1_200, 'api_requests' => $kind === 'backfill' ? 1_460 : 16, 'attempts' => $status === 'partial' ? 2 : 1,
                'error_code' => $status === 'partial' ? 'quota_exceeded' : null, 'error_message' => $status === 'partial' ? '[DEMO] Search Console API quota exceeded.' : null,
                'next_retry_at' => $status === 'partial' ? now()->addMinutes(40) : null, 'started_at' => $finished->subMinutes(3), 'finished_at' => $finished, 'is_demo' => true])->save();
        }
    }

    /** @param list<string> $siteIds */
    private function label(array $siteIds): void
    {
        foreach (['content_performance_snapshots', 'seo_opportunities', 'content_decay_events', 'seo_recommendations', 'seo_health_scores', 'seo_experiments', 'experiment_observations_by_exp'] as $table) {
            if ($table === 'experiment_observations_by_exp') {
                $ids = DB::table('seo_experiments')->whereIn('site_id', $siteIds)->pluck('id');
                DB::table('experiment_observations')->whereIn('experiment_id', $ids)->update(['is_demo' => true]);

                continue;
            }
            DB::table($table)->whereIn('site_id', $siteIds)->update(['is_demo' => true]);
        }
    }

    /**
     * A few human decisions so every status has an example.
     *
     * @param  list<string>  $siteIds
     */
    private function decisions(array $siteIds, ?string $ownerId): void
    {
        $recs = SeoRecommendation::query()->whereIn('site_id', $siteIds)->orderBy('created_at')->orderBy('id')->get();
        foreach ($recs as $k => $r) {
            $status = match ($k % 10) {
                1 => 'approved', 3 => 'applied', 5 => 'rejected', 7 => 'dismissed', default => null
            };
            if ($status !== null) {
                $r->forceFill(['status' => $status, 'decided_by' => $ownerId, 'decided_at' => now()->subDays($k % 20), 'decision_note' => '[DEMO] Quyết định minh họa.',
                    'applied_at' => $status === 'applied' ? now()->subDays($k % 20) : null])->save();
            }
        }
        DB::table('seo_opportunities')->whereIn('site_id', $siteIds)->orderBy('id')->limit(6)->update(['status' => 'accepted', 'reviewed_by' => $ownerId, 'reviewed_at' => now()->subDays(2)]);
        DB::table('content_decay_events')->whereIn('site_id', $siteIds)->whereNotNull('open_key')->orderBy('id')->limit(2)
            ->update(['status' => 'CONFIRMED_ISSUE', 'decided_by' => $ownerId, 'decided_at' => now()->subDay(), 'decision_note' => '[DEMO] Đã xem trang, nội dung lỗi thời.']);
    }

    /** @param list<array{site: Site, articles: list<array<string, string>>}> $sites */
    private function refreshJobs(array $sites, ?string $ownerId): void
    {
        $statuses = ['brief_ready', 'drafting', 'fact_checking', 'awaiting_approval', 'awaiting_approval', 'awaiting_publish', 'monitoring', 'monitoring', 'conflict', 'cancelled'];
        $k = 0;
        foreach ($statuses as $n => $status) {
            $s = $sites[$n % 3];
            $declining = array_values(array_filter($s['articles'], fn ($a) => in_array($a['scenario'], ['declining', 'stable'], true)));
            $a = $declining[intdiv($n, 3) % count($declining)];
            $terminal = in_array($status, ['conflict', 'cancelled', 'monitoring'], true);
            $sections = [['heading' => 'Giới thiệu', 'changed' => true, 'before' => '[DEMO] Đoạn mở đầu cũ.', 'after' => '[DEMO] Đoạn mở đầu đã cập nhật.'],
                ['heading' => 'Bảng so sánh', 'changed' => true, 'before' => '[DEMO] Bảng năm 2024.', 'after' => '[DEMO] Bảng cập nhật theo thông tin đã kiểm chứng.'],
                ['heading' => 'Câu hỏi thường gặp', 'changed' => false, 'before' => '[DEMO] FAQ.', 'after' => '[DEMO] FAQ.']];
            $hasDraft = ! in_array($status, ['brief_ready', 'drafting'], true);
            (new ContentRefreshJob)->forceFill(['site_id' => $s['site']->id, 'article_id' => $a['id'], 'status' => $status, 'active_key' => $terminal ? null : $a['id'],
                'wp_check' => ['reachable' => true, 'changed' => $status === 'conflict', 'conflicts' => $status === 'conflict' ? ['manual_edit'] : [], 'demo' => true],
                'analysis' => ['clicks_change_pct' => -42.5, 'note' => '[DEMO] Lượt nhấp giảm so với kỳ trước.'],
                'brief' => ['goal' => 'refresh_content', 'target_sections' => [['key' => 'intro', 'heading' => 'Giới thiệu', 'action' => 'regenerate_section', 'reason' => '[DEMO] Thông tin đã cũ.'],
                    ['key' => 'table', 'heading' => 'Bảng so sánh', 'action' => 'expand', 'reason' => '[DEMO] Thiếu mẫu mới.']],
                    'questions_to_research' => ['[DEMO] Thông số mới nhất do nhà sản xuất công bố?'], 'keep' => ['Câu hỏi thường gặp'], 'limits' => ['Không bịa số liệu', 'Không cam kết thứ hạng']],
                'diff' => $hasDraft ? ['sections' => $sections, 'changed' => 2] : null,
                'fact_check' => $hasDraft && $status !== 'fact_checking' ? ['article_status' => 'passed', 'unresolved_critical' => 0, 'unresolved_high' => 0, 'checked_at' => now()->subDays(2)->toIso8601ZuluString()] : null,
                'seo_audit' => in_array($status, ['awaiting_publish', 'monitoring'], true) ? ['score' => 86, 'errors' => 0, 'warnings' => 2] : null,
                'estimated_cost_micro_usd' => 180_000 + $k * 10_000, 'error_code' => $status === 'conflict' ? 'wordpress_changed' : null,
                'error_message' => $status === 'conflict' ? 'manual_edit' : null, 'requested_by' => $ownerId,
                'approved_by' => in_array($status, ['awaiting_publish', 'monitoring'], true) ? $ownerId : null,
                'approved_at' => in_array($status, ['awaiting_publish', 'monitoring'], true) ? now()->subDays(3) : null, 'is_demo' => true])->save();
            $k++;
        }
    }

    /** @param array{site: Site, articles: list<array<string, string>>} $s */
    private function experiments(array $s, ?string $ownerId, CarbonImmutable $end): void
    {
        $track = app(TrackExperiments::class);
        $plan = [['seo_title', 120], ['meta_description', 95], ['internal_links', 70], ['content_refresh', 45], ['new_sections', 10]];
        foreach ($plan as $n => [$type, $daysAgo]) {
            $a = $s['articles'][$n * 3 + 2];
            $track->start($s['site']->id, $a['id'], $a['url'], $type, 'p8demo-exp-'.$n, $end->subDays($daysAgo), $ownerId);
        }
        DB::table('seo_experiments')->where('site_id', $s['site']->id)->update(['is_demo' => true, 'external_factors' => '[DEMO] Có thể chịu ảnh hưởng mùa vụ và cập nhật thuật toán; đây không phải A/B test.']);
        $track->run($s['site']->id);
    }

    /** @param list<array{site: Site, articles: list<array<string, string>>}> $sites */
    private function agent(array $sites, ?string $ownerId): void
    {
        $tz = 'Asia/Ho_Chi_Minh';
        foreach ($sites as $n => $s) {
            (new AgentConfig)->forceFill(['site_id' => $s['site']->id, 'enabled' => $n < 2, 'autonomy_level' => $n === 1 ? 2 : 1, 'timezone' => $tz,
                'principal_user_id' => $ownerId, 'updated_by' => $ownerId, 'approval_requirements' => $n === 1 ? ['create_proposal' => true] : [], 'is_demo' => true])->save();
            foreach ([['daily_monitoring', 'daily', null, null, '07:00'], ['weekly_audit', 'weekly', 1, null, '08:00'], ['monthly_review', 'monthly', null, 1, '09:00']] as [$kind, $freq, $dow, $dom, $time]) {
                (new AutomationSchedule)->forceFill(['site_id' => $s['site']->id, 'kind' => $kind, 'frequency' => $freq, 'day_of_week' => $dow, 'day_of_month' => $dom, 'local_time' => $time,
                    'timezone' => $tz, 'status' => $n === 2 ? 'paused' : 'active', 'next_run_at' => ScheduleCalculator::next($freq, $time, $tz, $dow, $dom, CarbonImmutable::now('UTC')),
                    'last_run_at' => now()->subDay(), 'last_status' => 'started', 'consecutive_failures' => 0, 'created_by' => $ownerId, 'is_demo' => true])->save();
            }
        }
        // 20 runs: 14 completed, 2 failed, 2 cancelled, 1 awaiting approval, 1 paused (one active run per site at most).
        $statuses = array_merge(array_fill(0, 14, 'completed'), ['failed', 'failed', 'cancelled', 'cancelled', 'awaiting_approval', 'paused']);
        $kinds = ['daily_monitoring', 'weekly_audit', 'monthly_review', 'opportunity_discovery', 'content_refresh'];
        foreach ($statuses as $r => $status) {
            $si = $status === 'paused' ? 1 : ($status === 'awaiting_approval' ? 0 : $r % 2);
            $s = $sites[$si];
            $level = $si === 1 ? 2 : 1;
            $started = now()->subDays(40 - $r * 2)->setTime(0, 7);
            $kind = $kinds[$r % count($kinds)];
            $run = new AgentRun;
            $run->forceFill(['site_id' => $s['site']->id, 'trigger' => $r % 3 === 0 ? 'manual' : 'schedule', 'kind' => $kind, 'status' => $status,
                'phase' => $status === 'paused' ? 'execute' : 'done', 'active_key' => $status === 'paused' ? $s['site']->id : null, 'autonomy_level' => $level,
                'policy_snapshot' => ['autonomy_level' => $level, 'demo' => true], 'max_tasks' => 10, 'estimated_cost_micro_usd' => 120_000, 'actual_cost_micro_usd' => $status === 'completed' ? 64_000 + $r * 1_000 : 12_000,
                'observations' => ['clicks_change_pct' => -8.4 + $r, 'open_opportunities' => 10 + $r, 'note' => '[DEMO]'],
                'plan' => ['tasks' => ['read_analytics', 'analyze_site', 'create_proposal']],
                'summary' => in_array($status, ['completed', 'awaiting_approval'], true) ? ['tasks' => 3, 'succeeded' => $status === 'completed' ? 3 : 2, 'awaiting_approval' => $status === 'awaiting_approval' ? 1 : 0, 'note' => '[DEMO]'] : null,
                'error_code' => match ($status) {
                    'failed' => 'run_timeout', 'cancelled' => 'cancelled_by_user', default => null
                },
                'error_message' => $status === 'failed' ? '[DEMO] Lượt chạy quá thời gian.' : null, 'scheduled_for' => $started, 'started_at' => $started,
                'finished_at' => in_array($status, ['paused', 'awaiting_approval'], true) ? null : $started->addMinutes(6), 'deadline_at' => $started->addHour(),
                'created_by' => $r % 3 === 0 ? $ownerId : null, 'cancelled_by' => $status === 'cancelled' ? $ownerId : null, 'idempotency_key' => 'p8demo-run-'.$r, 'is_demo' => true])->save();
            $tasks = [['read_analytics', 'allow', ['policy_allows'], 'succeeded'], ['analyze_site', 'allow', ['policy_allows'], 'succeeded']];
            $tasks[] = match ($status) {
                'awaiting_approval' => ['create_refresh_brief', 'require_approval', ['autonomy_level_1_requires_approval'], 'awaiting_approval'],
                'paused' => ['create_refresh_brief', 'allow', ['policy_allows'], 'queued'],
                'failed' => ['run_seo_audit', 'allow', ['policy_allows'], 'failed'],
                'cancelled' => ['create_proposal', 'allow', ['policy_allows'], 'cancelled'],
                default => $r % 4 === 0 ? ['publish_via_workflow', 'deny', ['publish_requires_level_3'], 'blocked'] : ['create_proposal', 'allow', ['policy_allows'], 'succeeded'],
            };
            foreach ($tasks as $seq => [$tool, $decision, $reasons, $tstatus]) {
                $task = new AgentTask;
                $task->forceFill(['site_id' => $s['site']->id, 'agent_run_id' => $run->id, 'sequence' => $seq + 1, 'tool' => $tool, 'status' => $tstatus, 'decision' => $decision,
                    'decision_reasons' => $reasons, 'input' => ['site_id' => $s['site']->id, 'article_id' => $tool === 'create_refresh_brief' ? $s['articles'][$r % 10]['id'] : null],
                    'policy_snapshot' => ['autonomy_level' => $level], 'estimated_cost_micro_usd' => $tool === 'create_refresh_brief' ? 40_000 : 0,
                    'output' => $tstatus === 'succeeded' ? ['note' => '[DEMO] Kết quả minh họa.'] : null,
                    'error_code' => $tstatus === 'failed' ? 'timeout' : null, 'error_message' => $tstatus === 'failed' ? '[DEMO] Quá thời gian xử lý.' : null,
                    'attempts' => $tstatus === 'failed' ? 3 : ($tstatus === 'succeeded' ? 1 : 0), 'idempotency_key' => 'p8demo-run-'.$r.'-'.$seq,
                    'started_at' => $tstatus === 'succeeded' ? $started->addMinutes($seq + 1) : null, 'finished_at' => $tstatus === 'succeeded' ? $started->addMinutes($seq + 2) : null, 'is_demo' => true])->save();
                $this->log($s['site']->id, $run->id, $task->id, 'task.'.$tstatus, $tstatus === 'failed' ? 'error' : ($decision === 'deny' ? 'warning' : 'info'),
                    '[DEMO] '.$tool.' → '.$tstatus, 'agent', null, $started->addMinutes($seq + 2));
            }
            $this->log($s['site']->id, $run->id, null, 'run.'.$status, $status === 'failed' ? 'error' : 'info', '[DEMO] Lượt chạy '.$kind.' → '.$status,
                $status === 'cancelled' ? 'user' : 'system', $status === 'cancelled' ? $ownerId : null, $started->addMinutes(7));
        }
        $memories = [['observation', 'Lượt nhấp nhóm bài so sánh giảm vào cuối tuần.', false, 40], ['user_feedback', 'Chủ site không muốn đổi tiêu đề bài bảng giá.', true, 90],
            ['experiment_outcome', 'Viết lại meta description cho bài hướng dẫn chọn: CTR cải thiện trong 28 ngày quan sát (không phải A/B test).', true, 70],
            ['recommendation_outcome', 'Đề xuất gộp hai bài trùng truy vấn đã bị từ chối.', true, 80], ['observation', 'Truy vấn mới xuất hiện quanh chủ đề 2026.', false, 35]];
        foreach ($sites as $n => $s) {
            foreach ($memories as $m => [$kind, $text, $verified, $conf]) {
                if (($n + $m) % 3 === 2) {
                    continue;
                }
                (new AgentMemory)->forceFill(['site_id' => $s['site']->id, 'kind' => $kind, 'content' => '[DEMO] '.$text, 'verified' => $verified, 'confidence' => $conf,
                    'evidence' => ['demo' => true], 'expires_at' => now()->addDays($kind === 'observation' ? 30 : 180), 'is_demo' => true])->save();
            }
        }
    }

    private function log(string $siteId, string $runId, ?string $taskId, string $event, string $level, string $message, string $actor, ?string $actorId, mixed $at): void
    {
        (new AgentActionLog)->forceFill(['site_id' => $siteId, 'agent_run_id' => $runId, 'agent_task_id' => $taskId, 'event' => $event, 'level' => $level, 'message' => $message,
            'context' => ['demo' => true], 'actor_type' => $actor, 'actor_id' => $actorId, 'is_demo' => true, 'created_at' => $at])->save();
    }

    private function reports(Site $site, ?string $ownerId, CarbonImmutable $end): void
    {
        $builder = app(GenerateReport::class);
        for ($m = 6; $m >= 1; $m--) {
            $month = $end->subMonthsNoOverflow($m);
            $report = new SeoReport;
            $report->forceFill(['site_id' => $site->id, 'period_type' => 'monthly', 'period_start' => $month->startOfMonth()->toDateString(), 'period_end' => $month->endOfMonth()->toDateString(),
                'status' => 'queued', 'generated_by' => $ownerId, 'is_demo' => true])->save();
            $builder->build($report);
        }
    }
}
