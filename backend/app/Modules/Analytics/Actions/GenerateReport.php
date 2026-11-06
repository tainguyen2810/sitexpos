<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use SiteXPost\Modules\Agent\Contracts\AgentActivity;
use SiteXPost\Modules\AiGateway\Contracts\AiSpendReport;
use SiteXPost\Modules\Analytics\Domain\ContentPerformanceSnapshot;
use SiteXPost\Modules\Analytics\Domain\ContentRefreshJob;
use SiteXPost\Modules\Analytics\Domain\SearchPerformanceDaily;
use SiteXPost\Modules\Analytics\Domain\SeoOpportunity;
use SiteXPost\Modules\Analytics\Domain\SeoRecommendation;
use SiteXPost\Modules\Analytics\Domain\SeoReport;
use SiteXPost\Modules\Analytics\Engine\Metrics;
use SiteXPost\Modules\Analytics\Engine\TrendClassifier;

/**
 * SEO report (weekly / monthly / custom). Every number comes from stored
 * data; sections without data say so instead of showing zeros as facts.
 */
final class GenerateReport
{
    public function __construct(private readonly AiSpendReport $ai, private readonly AgentActivity $agent) {}

    public function build(SeoReport $report): SeoReport
    {
        $from = CarbonImmutable::parse($report->period_start);
        $to = CarbonImmutable::parse($report->period_end);
        $days = $from->diffInDays($to) + 1;
        $prevFrom = $from->subDays($days);
        $prevTo = $from->subDay();
        $site = $report->site_id;
        $scope = fn ($q) => $site !== null ? $q->where('site_id', $site) : $q;

        $totals = fn (CarbonImmutable $a, CarbonImmutable $b) => Metrics::aggregate(
            $scope(SearchPerformanceDaily::query()->where('search_type', 'web')->whereBetween('date', [$a->toDateString(), $b->toDateString()]))
                ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(position * impressions) / NULLIF(SUM(impressions), 0) as position')
                ->toBase()->get()->map(fn ($r) => ['clicks' => (int) $r->clicks, 'impressions' => (int) $r->impressions, 'position' => $r->position !== null ? (float) $r->position : null])->all()
        );
        $current = $totals($from, $to);
        $previous = $totals($prevFrom, $prevTo);
        $hasData = $current['impressions'] > 0 || $previous['impressions'] > 0;

        $latest = $scope(ContentPerformanceSnapshot::query())->where('period_end', '<=', $to->toDateString())->max('period_end');
        $snap = fn (string $class) => $latest === null ? [] : $scope(ContentPerformanceSnapshot::query())->where('period_end', $latest)->where('classification', $class)
            ->orderByRaw($class === TrendClassifier::GROWING ? '(CAST(clicks AS SIGNED) - CAST(prev_clicks AS SIGNED)) DESC' : '(CAST(clicks AS SIGNED) - CAST(prev_clicks AS SIGNED)) ASC')->limit(10)
            ->get(['page_url', 'article_id', 'clicks', 'prev_clicks', 'impressions', 'position'])->toArray();

        $fromC = Carbon::parse($from->startOfDay());
        $toC = Carbon::parse($to->endOfDay());
        $payload = [
            'data_note' => 'Số liệu Search Console có độ trễ 2–3 ngày, có thể ẩn một phần truy vấn và không phải toàn bộ lượt tìm kiếm thực tế. Báo cáo không dự báo traffic và không cam kết thứ hạng.',
            'performance' => $hasData ? ['current' => $current, 'previous' => $previous,
                'clicks_change_pct' => Metrics::change($current['clicks'], $previous['clicks']), 'impressions_change_pct' => Metrics::change($current['impressions'], $previous['impressions'])] : null,
            'growing_pages' => $snap(TrendClassifier::GROWING),
            'declining_pages' => $snap(TrendClassifier::DECLINING),
            'opportunities' => $scope(SeoOpportunity::query())->where('status', 'open')->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
                ->limit(10)->get(['id', 'rule_code', 'target_label', 'severity', 'impact', 'recommended_action'])->toArray(),
            'completed_optimizations' => [
                'refreshes' => $scope(ContentRefreshJob::query())->whereIn('status', ['monitoring', 'completed'])->whereBetween('updated_at', [$fromC, $toC])->count(),
                'recommendations_applied' => $scope(SeoRecommendation::query())->where('status', 'applied')->whereBetween('applied_at', [$fromC, $toC])->count(),
            ],
            'agent' => $this->agent->summary($site, $fromC, $toC),
            'ai_costs' => $this->ai->forPeriod($fromC, $toC),
            'pending_approvals' => [
                'recommendations' => $scope(SeoRecommendation::query())->where('status', 'proposed')->count(),
                'refreshes' => $scope(ContentRefreshJob::query())->whereIn('status', ['awaiting_approval', 'awaiting_publish'])->count(),
                'agent_tasks' => $this->agent->pendingApprovals($site),
            ],
            'next_actions' => $scope(SeoRecommendation::query())->where('status', 'proposed')->orderByDesc('priority')->limit(5)->get(['id', 'type', 'target_label', 'priority', 'benefit_hypothesis'])->toArray(),
        ];
        $report->forceFill(['payload' => $payload, 'status' => 'ready'])->save();

        return $report;
    }

    /**
     * Flat CSV rows (UTF-8 with BOM added by the controller).
     *
     * @return list<list<string|int|float|null>>
     */
    public static function csv(SeoReport $report): array
    {
        $p = $report->payload ?? [];
        $rows = [['Báo cáo SEO', $report->period_type, $report->period_start, $report->period_end], []];
        $perf = $p['performance'] ?? null;
        $rows[] = ['Chỉ số', 'Kỳ này', 'Kỳ trước', 'Thay đổi %'];
        if ($perf === null) {
            $rows[] = ['Hiệu suất', 'Chưa có dữ liệu'];
        } else {
            $rows[] = ['Clicks', $perf['current']['clicks'], $perf['previous']['clicks'], $perf['clicks_change_pct']];
            $rows[] = ['Impressions', $perf['current']['impressions'], $perf['previous']['impressions'], $perf['impressions_change_pct']];
            $rows[] = ['CTR %', $perf['current']['ctr'], $perf['previous']['ctr'], null];
            $rows[] = ['Vị trí TB', $perf['current']['position'], $perf['previous']['position'], null];
        }
        foreach (['growing_pages' => 'Trang tăng trưởng', 'declining_pages' => 'Trang suy giảm'] as $k => $label) {
            $rows[] = [];
            $rows[] = [$label, 'URL', 'Clicks', 'Clicks kỳ trước', 'Impressions', 'Vị trí'];
            foreach ($p[$k] ?? [] as $r) {
                $rows[] = ['', $r['page_url'], $r['clicks'], $r['prev_clicks'], $r['impressions'], $r['position']];
            }
        }
        $rows[] = [];
        $rows[] = ['Cơ hội SEO', 'Luật', 'Đối tượng', 'Mức độ', 'Hành động đề xuất'];
        foreach ($p['opportunities'] ?? [] as $o) {
            $rows[] = ['', $o['rule_code'], $o['target_label'], $o['severity'], $o['recommended_action']];
        }
        $rows[] = [];
        $rows[] = ['Chi phí AI (USD)', round(((int) ($p['ai_costs']['cost_micro_usd'] ?? 0)) / 1_000_000, 4)];
        $rows[] = [];
        $rows[] = ['Ghi chú', (string) ($p['data_note'] ?? '')];

        return $rows;
    }
}
