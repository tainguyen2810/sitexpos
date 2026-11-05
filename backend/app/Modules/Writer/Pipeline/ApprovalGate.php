<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Pipeline;

use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleWorkflowRun;
use SiteXPost\Modules\Writer\Domain\QualityReport;
use SiteXPost\Modules\Writer\Enums\ArticleStatus;
use SiteXPost\Modules\Writer\Support\WriterSettings;

/**
 * Conditions for a human to approve a version (FACT-CHECK-POLICY.md §6).
 * Blocking: unresolved CRITICAL claims, sections not fact-checked since
 * their last edit, a running workflow, quality below the policy minimum,
 * duplicate recommendation "reject". HIGH unresolved claims need an
 * explicit acknowledgement. AI scores alone never approve anything.
 */
class ApprovalGate
{
    public function __construct(private readonly WriterSettings $settings) {}

    /**
     * @return array{ok: bool, blocking: list<array{code: string, message: string}>, warnings: list<array{code: string, message: string}>, stats: array<string, mixed>}
     */
    public function evaluate(Article $a, bool $ackHighRisk = false): array
    {
        $blocking = [];
        $warnings = [];
        $stats = ClaimStats::compute($a);

        if (! in_array($a->status, [ArticleStatus::NeedsHumanReview, ArticleStatus::QualityReview], true)) {
            $blocking[] = ['code' => 'wrong_status', 'message' => 'Bài viết chưa ở trạng thái chờ duyệt.'];
        }
        if ($a->current_version_id === null) {
            $blocking[] = ['code' => 'no_content', 'message' => 'Chưa có nội dung.'];
        }
        if ($this->busy($a)) {
            $blocking[] = ['code' => 'workflow_running', 'message' => 'Đang có tác vụ AI chạy trên bài viết.'];
        }
        if ($stats['unresolved_critical'] > 0) {
            $blocking[] = ['code' => 'critical_unresolved', 'message' => "Còn {$stats['unresolved_critical']} khẳng định CRITICAL chưa được xác minh."];
        }
        if ($stats['unchecked_sections'] !== []) {
            $blocking[] = ['code' => 'sections_not_checked', 'message' => 'Có phần đã sửa nhưng chưa kiểm chứng lại: '.implode(', ', $stats['unchecked_sections'])];
        }
        $report = QualityReport::query()->where('article_version_id', $a->current_version_id)->latest()->first();
        $min = $this->settings->int('min_quality_score_for_approval', $a->workspace_id);
        if ($report === null) {
            $blocking[] = ['code' => 'quality_not_audited', 'message' => 'Chưa chạy đánh giá chất lượng cho phiên bản hiện tại.'];
        } elseif ($report->score < $min) {
            $blocking[] = ['code' => 'quality_below_policy', 'message' => "Điểm chất lượng {$report->score} thấp hơn mức tối thiểu {$min}."];
        }
        if (($a->duplicate_check['recommendation'] ?? null) === 'reject' && $a->duplicate_decision !== 'create_new') {
            $blocking[] = ['code' => 'duplicate_reject', 'message' => 'Kiểm tra trùng lặp đề xuất loại bài này; hãy chọn quyết định trùng lặp trước.'];
        }
        if ($stats['unresolved_high'] > 0) {
            $item = ['code' => 'high_unresolved', 'message' => "Còn {$stats['unresolved_high']} khẳng định HIGH chưa được xác minh."];
            if ((bool) $this->settings->get('require_ack_for_high_risk', $a->workspace_id) && ! $ackHighRisk) {
                $blocking[] = $item + ['requires_ack' => true];
            } else {
                $warnings[] = $item;
            }
        }
        if (($stats['by_status']['INSUFFICIENT_EVIDENCE'] ?? 0) + ($stats['by_status']['PENDING'] ?? 0) > 0) {
            $warnings[] = ['code' => 'unsupported_claims', 'message' => 'Có khẳng định chưa có bằng chứng.'];
        }

        return ['ok' => $blocking === [], 'blocking' => $blocking, 'warnings' => $warnings, 'stats' => $stats + ['quality_score' => $report?->score, 'min_quality' => $min]];
    }

    public function busy(Article $a): bool
    {
        $ids = ArticleWorkflowRun::query()->where('article_id', $a->id)->pluck('workflow_run_id');

        return $ids->isNotEmpty() && WorkflowRun::query()->whereIn('id', $ids)->whereIn('status', ['pending', 'running', 'retry_scheduled'])->exists();
    }
}
