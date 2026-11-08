<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Pipeline;

use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleWorkflowRun;
use SiteXPost\Modules\Writer\Domain\EditorialApproval;
use SiteXPost\Modules\Writer\Enums\ArticleStatus;
use SiteXPost\Modules\Writer\Support\ArticleStateMachine;
use SiteXPost\Modules\Writer\Support\WriterSettings;

/**
 * Optional auto-approval (FACT-CHECK-POLICY.md §6). Runs when a draft or
 * recheck workflow finishes. It approves only when:
 * - Super Admin enabled `auto_approve_enabled` (platform or workspace) and
 *   the article was created with approval_mode = auto;
 * - the normal ApprovalGate passes WITHOUT a high-risk acknowledgement;
 * - no CRITICAL or HIGH claim is unresolved and none is CONTRADICTED/OUTDATED;
 * - the quality score reaches max(auto_approve_min_quality, min policy score);
 * - the duplicate check recommends creating a new article.
 * Otherwise the article simply stays in NEEDS_HUMAN_REVIEW. An auto approval
 * is invalidated by any later edit exactly like a human one. It never
 * publishes anything.
 */
final class AutoApprover
{
    public function __construct(
        private readonly ApprovalGate $gate,
        private readonly WriterSettings $settings,
        private readonly ArticleStateMachine $machine,
    ) {}

    public static function assertAllowed(WriterSettings $settings, string $workspaceId, mixed $mode, string $field): void
    {
        if ($mode === 'auto' && ! (bool) $settings->get('auto_approve_enabled', $workspaceId)) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), [$field => [__('writer.errors.auto_approve_disabled')]]);
        }
    }

    /**
     * @return array{eligible: bool, reasons: list<string>, min_quality: int}
     */
    public function check(Article $a, ?string $currentRunId = null): array
    {
        $reasons = [];
        $min = max($this->settings->int('auto_approve_min_quality', $a->workspace_id), $this->settings->int('min_quality_score_for_approval', $a->workspace_id));
        if ($a->setting('approval_mode') !== 'auto') {
            $reasons[] = 'manual_mode';
        }
        if (! (bool) $this->settings->get('auto_approve_enabled', $a->workspace_id)) {
            $reasons[] = 'disabled_by_policy';
        }
        $result = $this->gate->evaluate($a, false);
        foreach ($result['blocking'] as $b) {
            if ($b['code'] === 'workflow_running' && ! $this->otherRunActive($a, $currentRunId)) {
                continue;
            }
            $reasons[] = $b['code'];
        }
        $stats = $result['stats'];
        // Fact-check is only a warning for a human approver; auto-approval stays strict.
        if (($stats['unresolved_critical'] ?? 0) > 0 && ! in_array('critical_unresolved', $reasons, true)) {
            $reasons[] = 'critical_unresolved';
        }
        if (($stats['unresolved_high'] ?? 0) > 0 && ! in_array('high_unresolved', $reasons, true)) {
            $reasons[] = 'high_unresolved';
        }
        if (($stats['unchecked_sections'] ?? []) !== []) {
            $reasons[] = 'sections_not_checked';
        }
        if (($stats['by_status']['CONTRADICTED'] ?? 0) + ($stats['by_status']['OUTDATED'] ?? 0) > 0) {
            $reasons[] = 'contradicted_or_outdated_claims';
        }
        if (($stats['quality_score'] ?? null) === null || $stats['quality_score'] < $min) {
            $reasons[] = 'quality_below_auto_threshold';
        }
        $rec = $a->duplicate_check['recommendation'] ?? null;
        if ($rec !== null && $rec !== 'create_new') {
            $reasons[] = 'duplicate_risk';
        }

        return ['eligible' => $reasons === [], 'reasons' => array_values(array_unique($reasons)), 'min_quality' => $min];
    }

    /**
     * Approves the current version when every condition holds. Returns the
     * approval, or null when the article is left for a human.
     */
    public function attempt(Article $a, ?string $currentRunId = null): ?EditorialApproval
    {
        if ($a->setting('approval_mode') !== 'auto' || $a->status !== ArticleStatus::NeedsHumanReview || $a->current_version_id === null) {
            return null;
        }

        return DB::transaction(function () use ($a, $currentRunId): ?EditorialApproval {
            /** @var Article $locked */
            $locked = Article::query()->whereKey($a->id)->lockForUpdate()->firstOrFail();
            $check = $this->check($locked, $currentRunId);
            if (! $check['eligible']) {
                $locked->forceFill(['settings' => array_merge($locked->settings ?? [], ['auto_approve_result' => ['approved' => false, 'reasons' => $check['reasons'], 'at' => now()->toIso8601ZuluString()]])])->save();
                Audit::record('writer.auto_approve_skipped', $locked, ['reasons' => $check['reasons']]);
                $a->refresh();

                return null;
            }
            $versionId = (string) $locked->current_version_id;
            if (! $this->machine->transition($locked, ArticleStatus::Approved, [
                '__approval_gate' => true, 'approved_version_id' => $versionId, 'approved_by' => null, 'approved_at' => now(), 'blocked_reason' => null,
            ])) {
                return null;
            }
            $locked->refresh();
            $locked->forceFill(['settings' => array_merge($locked->settings ?? [], ['auto_approve_result' => ['approved' => true, 'reasons' => [], 'at' => now()->toIso8601ZuluString()]])])->save();
            $stats = $this->gate->evaluate($locked, false)['stats'];
            $ap = new EditorialApproval;
            $ap->forceFill([
                'workspace_id' => $locked->workspace_id, 'article_id' => $locked->id, 'article_version_id' => $versionId, 'decision' => 'approved',
                'note' => 'Tự duyệt: đạt mọi điều kiện chất lượng và chính sách.', 'user_id' => null,
                'checks' => ['auto' => true, 'min_quality' => $check['min_quality'], 'stats' => $stats],
            ])->save();
            Audit::record('writer.article_auto_approved', $locked, ['version_id' => $versionId, 'quality' => $stats['quality_score'] ?? null]);
            $a->refresh();

            return $ap;
        });
    }

    private function otherRunActive(Article $a, ?string $currentRunId): bool
    {
        $ids = ArticleWorkflowRun::query()->where('article_id', $a->id)->pluck('workflow_run_id')->reject(fn ($id) => $id === $currentRunId);

        // A previous stage parked on its hand-off step (it started this one) is not concurrent work.
        return $ids->isNotEmpty() && WorkflowRun::query()->whereIn('id', $ids)->whereIn('status', ['pending', 'running', 'retry_scheduled'])
            ->where(fn ($q) => $q->whereNull('current_step')->orWhere('current_step', '!=', 'handoff'))->exists();
    }
}
