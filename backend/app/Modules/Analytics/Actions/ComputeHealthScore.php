<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Actions;

use Carbon\CarbonImmutable;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Analytics\Domain\SeoHealthScore;
use SiteXPost\Modules\Analytics\Domain\UrlMapping;
use SiteXPost\Modules\Analytics\Engine\HealthScorer;
use SiteXPost\Modules\Analytics\Support\AnalyticsConfig;
use SiteXPost\Modules\Analytics\Support\SiteDataset;
use SiteXPost\Modules\Analytics\Support\UrlNormalizer;
use SiteXPost\Modules\Seo\Contracts\SeoGate;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;

/**
 * SEO Health Score (SEO HEALTH SCORE). Every component comes from data the
 * system has (synced WordPress inventory, SiteX articles, SEO audits,
 * fact-check state, planner clusters). There is no site crawler yet, so
 * "technical signals" only uses SiteX SEO audits and says so.
 */
final class ComputeHealthScore
{
    public function __construct(
        private readonly SiteDataset $dataset,
        private readonly SiteDirectory $sites,
        private readonly ArticleCatalog $articles,
        private readonly SeoGate $seo,
        private readonly TenantContext $context,
    ) {}

    public function run(string $siteId, CarbonImmutable $date): SeoHealthScore
    {
        $components = [];
        $index = array_values(array_filter($this->sites->contentIndex($siteId, ['post', 'page'], 20000), fn ($i) => $i['status'] === 'publish'));
        if ($index !== []) {
            $fresh = count(array_filter($index, fn ($i) => $i['modified_at'] !== null && CarbonImmutable::parse($i['modified_at'])->greaterThan($date->subDays(365))));
            $components['content_freshness'] = ['value' => $fresh / count($index) * 100, 'detail' => "{$fresh}/".count($index).' bài/trang cập nhật trong 12 tháng (theo dữ liệu WordPress đã đồng bộ).'];
            $weeks = [];
            foreach ($this->sites->content($siteId, ['post'], 20000) as $p) {
                if ($p['published_at'] !== null && CarbonImmutable::parse($p['published_at'])->greaterThan($date->subWeeks(12))) {
                    $weeks[CarbonImmutable::parse($p['published_at'])->format('oW')] = true;
                }
            }
            $components['publishing_consistency'] = ['value' => min(12, count($weeks)) / 12 * 100, 'detail' => count($weeks).'/12 tuần gần nhất có bài mới.'];
        }

        $mapped = UrlMapping::query()->where('site_id', $siteId)->whereNotNull('article_id')->pluck('article_id', 'page_hash')->all();
        $managed = $this->dataset->managed($siteId, $mapped);
        if ($managed !== []) {
            $withMeta = array_filter($managed, fn ($a) => $a['metadata'] !== null && trim((string) $a['metadata']['seo_title']) !== '' && trim((string) $a['metadata']['meta_description']) !== '');
            $components['metadata_completeness'] = ['value' => count($withMeta) / count($managed) * 100, 'detail' => count($withMeta).'/'.count($managed).' bài SiteX đã xuất bản có đủ SEO title và meta description.'];
            $known = array_flip(array_map(fn ($i) => UrlNormalizer::normalize((string) $i['url']), array_filter($index, fn ($i) => $i['url'] !== null)));
            $host = UrlNormalizer::host((string) ($this->sites->find($siteId)['url'] ?? ''));
            $internal = 0;
            $broken = 0;
            foreach ($managed as $a) {
                foreach ($a['links_norm'] as $l) {
                    if (UrlNormalizer::host($l) === $host) {
                        $internal++;
                        if ($known !== [] && ! isset($known[$l]) && parse_url($l, PHP_URL_PATH) !== '/') {
                            $broken++;
                        }
                    }
                }
            }
            if ($known !== [] && $internal > 0) {
                $components['broken_links'] = ['value' => 100 - $broken / $internal * 100, 'detail' => "{$broken}/{$internal} liên kết nội bộ trong bài SiteX không khớp nội dung đã đồng bộ."];
            }
            if (count($managed) >= 3) {
                $linked = [];
                foreach ($managed as $a) {
                    foreach ($a['links_norm'] as $l) {
                        $linked[$l] = true;
                    }
                }
                $withInbound = count(array_filter($managed, fn ($a) => isset($linked[UrlNormalizer::normalize((string) $a['url'])])));
                $components['internal_linking'] = ['value' => $withInbound / count($managed) * 100, 'detail' => "{$withInbound}/".count($managed).' bài SiteX có ít nhất 1 liên kết nội bộ trỏ tới (chỉ tính nội dung SiteX quản lý).'];
            }
            $scores = [];
            $clean = 0;
            foreach ($managed as $a) {
                $audit = $this->seo->latestAudit($a['article_id']);
                if ($audit !== null) {
                    $scores[] = $audit['score'];
                }
                $art = $this->articles->find($a['article_id']);
                if ($art !== null && $art['unresolved_critical'] + $art['unresolved_high'] === 0) {
                    $clean++;
                }
            }
            if ($scores !== []) {
                $components['technical_signals'] = ['value' => array_sum($scores) / count($scores), 'detail' => 'Trung bình điểm SEO Audit của '.count($scores).' bài SiteX (chưa có crawler kỹ thuật toàn site).'];
            }
            $components['fact_check_issues'] = ['value' => $clean / count($managed) * 100, 'detail' => "{$clean}/".count($managed).' bài không còn vấn đề fact-check mức cao/nghiêm trọng.'];
        }
        $clusters = $this->dataset->clusters($siteId);
        $ideas = array_sum(array_column($clusters, 'ideas'));
        if ($ideas > 0) {
            $pub = array_sum(array_column($clusters, 'published'));
            $components['content_coverage'] = ['value' => $pub / $ideas * 100, 'detail' => "{$pub}/{$ideas} chủ đề đã lập kế hoạch có bài xuất bản."];
        }

        $weights = AnalyticsConfig::weights($this->context->workspaceId());
        $res = HealthScorer::score($components, $weights);
        $row = (SeoHealthScore::query()->where(['site_id' => $siteId, 'date' => $date->toDateString()])->first() ?? (new SeoHealthScore)->forceFill(['site_id' => $siteId, 'date' => $date->toDateString()]));
        $row->forceFill(['site_id' => $siteId, 'date' => $date->toDateString(), 'score' => $res['score'], 'components' => $res['components'], 'weights' => $weights])->save();

        return $row;
    }
}
