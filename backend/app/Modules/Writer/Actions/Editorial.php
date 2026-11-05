<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Actions;

use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleClaim;
use SiteXPost\Modules\Writer\Domain\ArticleOutline;
use SiteXPost\Modules\Writer\Domain\ArticleRevision;
use SiteXPost\Modules\Writer\Domain\ArticleVersion;
use SiteXPost\Modules\Writer\Domain\ClaimEvidence;
use SiteXPost\Modules\Writer\Domain\ContentBrief;
use SiteXPost\Modules\Writer\Domain\EditorialApproval;
use SiteXPost\Modules\Writer\Domain\EvidenceItem;
use SiteXPost\Modules\Writer\Domain\FactCheckResult;
use SiteXPost\Modules\Writer\Domain\QualityReport;
use SiteXPost\Modules\Writer\Domain\ResearchDossier;
use SiteXPost\Modules\Writer\Enums\ArticleStatus;
use SiteXPost\Modules\Writer\Enums\Verdict;
use SiteXPost\Modules\Writer\Pipeline\ApprovalGate;
use SiteXPost\Modules\Writer\Pipeline\ArticleContext;
use SiteXPost\Modules\Writer\Pipeline\BriefBuilder;
use SiteXPost\Modules\Writer\Pipeline\ClaimStats;
use SiteXPost\Modules\Writer\Pipeline\OutlineBuilder;
use SiteXPost\Modules\Writer\Pipeline\QualityAuditor;
use SiteXPost\Modules\Writer\Pipeline\VersionManager;
use SiteXPost\Modules\Writer\Support\ArticleDocument;
use SiteXPost\Modules\Writer\Support\ArticleStateMachine;
use SiteXPost\Modules\Writer\Support\HtmlSanitizer;
use SiteXPost\Modules\Writer\Workflows\RewriteWorkflow;

/**
 * Human actions on an article: approve brief / outline, edit + autosave,
 * restore, apply / discard AI proposals, manual claim verification,
 * duplicate decision, approve / reject. Every write is checked against the
 * article lock and the running workflows so a human edit can never race an
 * AI step, and every content change goes through VersionManager (which
 * invalidates approvals and fact-checks of the changed sections).
 */
class Editorial
{
    public function __construct(
        private readonly VersionManager $versions,
        private readonly ApprovalGate $gate,
        private readonly ArticleStateMachine $machine,
        private readonly ArticleWorkflows $workflows,
        private readonly ArticleContext $context,
        private readonly QualityAuditor $quality,
    ) {}

    // ---------------------------------------------------------------- brief / outline

    /**
     * @param  array<string, mixed>|null  $edits  edited brief data (validated like AI output: facts need evidence refs)
     */
    public function approveBrief(Article $a, ?array $edits, string $userId): ContentBrief
    {
        $this->assertIdle($a);
        if (! in_array($a->status, [ArticleStatus::BriefReady, ArticleStatus::ResearchComplete], true)) {
            throw ApiException::conflict('invalid_state_transition', __('writer.errors.brief_not_ready'));
        }
        $brief = ContentBrief::query()->where('article_id', $a->id)->orderByDesc('version')->first()
            ?? throw ApiException::conflict('brief_missing', __('writer.errors.brief_not_ready'));

        $brief = DB::transaction(function () use ($a, $brief, $edits, $userId): ContentBrief {
            if ($edits !== null) {
                $links = array_column($this->context->linkCandidates($a, 30), 'url');
                [$data, $warnings] = BriefBuilder::validate($a, $edits + $brief->data, $brief->research_dossier_id, $links);
                $copy = new ContentBrief;
                $copy->forceFill([
                    'article_id' => $a->id, 'research_dossier_id' => $brief->research_dossier_id, 'version' => $brief->version + 1,
                    'status' => 'approved', 'data' => $data, 'warnings' => $warnings, 'generated_by_model' => $brief->generated_by_model,
                    'edited_by' => $userId, 'approved_by' => $userId, 'approved_at' => now(),
                ])->save();
                ContentBrief::query()->where('article_id', $a->id)->where('id', '!=', $copy->id)->where('status', 'approved')->update(['status' => 'superseded']);

                return $copy;
            }
            $brief->forceFill(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now()])->save();

            return $brief;
        });
        Audit::record('writer.brief_approved', $a, ['brief_version' => $brief->version, 'edited' => $edits !== null]);
        if ($a->status === ArticleStatus::ResearchComplete) {
            $this->machine->transition($a, ArticleStatus::BriefReady);
        }
        $this->workflows->start($a->refresh(), 'outline', $userId, 'brief:'.$brief->id);

        return $brief;
    }

    /**
     * @param  list<array<string, mixed>>|null  $sections  edited outline sections
     */
    public function approveOutline(Article $a, ?array $sections, string $userId): ArticleOutline
    {
        $this->assertIdle($a);
        if ($a->status !== ArticleStatus::Outlining) {
            throw ApiException::conflict('invalid_state_transition', __('writer.errors.outline_not_ready'));
        }
        $outline = ArticleOutline::query()->where('article_id', $a->id)->orderByDesc('version')->first()
            ?? throw ApiException::conflict('outline_missing', __('writer.errors.outline_not_ready'));
        if ($outline->status === 'approved') {
            throw ApiException::conflict('already_approved', __('writer.errors.outline_already_approved'));
        }
        if ($sections !== null) {
            $dossierId = ContentBrief::query()->whereKey($outline->content_brief_id)->value('research_dossier_id');
            $links = array_column($this->context->linkCandidates($a, 30), 'url');
            [$normalized, $warnings] = OutlineBuilder::normalize($a, $sections, $dossierId, $links);
            if ($normalized === []) {
                throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['sections' => [__('writer.errors.outline_empty')]]);
            }
            $copy = new ArticleOutline;
            $copy->forceFill([
                'article_id' => $a->id, 'content_brief_id' => $outline->content_brief_id, 'version' => $outline->version + 1,
                'status' => 'approved', 'sections' => $normalized, 'warnings' => $warnings, 'generated_by_model' => $outline->generated_by_model,
                'approved_by' => $userId, 'approved_at' => now(),
            ])->save();
            $outline = $copy;
        } else {
            $outline->forceFill(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now()])->save();
        }
        Audit::record('writer.outline_approved', $a, ['outline_version' => $outline->version, 'edited' => $sections !== null]);
        $this->workflows->start($a->refresh(), 'draft', $userId, 'outline:'.$outline->id);

        return $outline;
    }

    // ---------------------------------------------------------------- content editing

    /**
     * Save the editor content as a new version. `$baseVersionId` is the
     * version the editor started from; a different current version means
     * someone (or an AI step) saved in between → 409, nothing is lost.
     */
    public function save(Article $a, string $html, string $baseVersionId, string $userId, ?string $summary = null): ArticleVersion
    {
        $this->assertEditable($a);
        if ($a->current_version_id !== $baseVersionId) {
            throw ApiException::conflict('version_conflict', __('writer.errors.version_conflict'));
        }
        $current = ArticleVersion::query()->findOrFail($baseVersionId);
        $clean = $this->versions->sanitize($a, $html);
        if (hash('sha256', $clean['html']) === $current->content_hash) {
            return $current;
        }
        if (trim(HtmlSanitizer::text($clean['html'])) === '') {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['content_html' => [__('writer.errors.empty_content')]]);
        }
        $v = $this->versions->create($a, $clean['html'], 'human', $userId, null, $summary, alreadySanitized: true);
        Audit::record('writer.version_saved', $a, ['version' => $v->number, 'violations' => array_slice($clean['violations'], 0, 20)]);

        return $v;
    }

    /**
     * Autosave a draft of the editor content without creating a version.
     * Optimistic lock on `lock_version`.
     *
     * @return array{lock_version: int, autosaved_at: string}
     */
    public function autosave(Article $a, string $html, string $baseVersionId, int $lockVersion): array
    {
        $this->assertEditable($a);
        if (mb_strlen($html) > 600_000) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['content_html' => [__('writer.errors.content_too_large')]]);
        }
        $clean = $this->versions->sanitize($a, $html);
        $now = now();
        $n = Article::query()->whereKey($a->id)->where('lock_version', $lockVersion)->where('current_version_id', $baseVersionId)
            ->update(['autosave_html' => $clean['html'], 'autosave_base_version_id' => $baseVersionId, 'autosaved_at' => $now, 'lock_version' => $lockVersion + 1]);
        if ($n === 0) {
            throw ApiException::conflict('version_conflict', __('writer.errors.version_conflict'));
        }

        return ['lock_version' => $lockVersion + 1, 'autosaved_at' => $now->toIso8601ZuluString()];
    }

    public function restore(Article $a, ArticleVersion $from, string $userId): ArticleVersion
    {
        $this->assertEditable($a);
        if ($from->id === $a->current_version_id) {
            throw ApiException::conflict('already_current', __('writer.errors.already_current'));
        }
        // Restored content is re-sanitized against today's evidence map (a citation whose evidence no longer exists is dropped).
        $v = $this->versions->create($a, $from->content_html, 'restore', $userId, null, 'Khôi phục từ phiên bản '.$from->number);
        Audit::record('writer.version_restored', $a, ['from' => $from->number, 'version' => $v->number]);

        return $v;
    }

    // ---------------------------------------------------------------- AI rewrite proposals

    /**
     * @param  array{action: string, section_key: string, selection?: string|null, tone?: string|null}  $data
     */
    public function requestRewrite(Article $a, array $data, string $userId, ?string $key): ArticleRevision
    {
        $this->assertEditable($a);
        if (! isset(RewriteWorkflow::ACTIONS[$data['action']])) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['action' => [__('writer.errors.invalid_action')]]);
        }
        $version = ArticleVersion::query()->findOrFail((string) $a->current_version_id);
        $section = collect(ArticleDocument::split($version->content_html, $version->section_hashes ?? []))->firstWhere('key', $data['section_key']);
        if ($section === null) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['section_key' => [__('writer.errors.section_not_found')]]);
        }
        $selection = trim((string) ($data['selection'] ?? ''));
        if ($selection !== '' && ! str_contains(ArticleDocument::norm($section['text']), ArticleDocument::norm($selection))) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['selection' => [__('writer.errors.selection_not_in_section')]]);
        }
        if (ArticleRevision::query()->where('article_id', $a->id)->where('kind', 'editor')->where('status', 'queued')->exists()) {
            throw ApiException::conflict('workflow_active', __('writer.errors.workflow_active'));
        }
        $rev = new ArticleRevision;
        $rev->forceFill([
            'article_id' => $a->id, 'kind' => 'editor', 'action' => $data['action'], 'round' => 0, 'status' => 'queued',
            'section_key' => $section['key'], 'from_version_id' => $version->id,
            'request' => ['selection' => mb_substr($selection, 0, 4000), 'tone' => isset($data['tone']) ? mb_substr((string) $data['tone'], 0, 100) : null],
            'created_by' => $userId,
        ])->save();
        try {
            $run = $this->workflows->start($a, 'rewrite', $userId, $key, ['revision_id' => $rev->id]);
        } catch (\Throwable $e) {
            $rev->forceFill(['status' => 'failed', 'error_code' => $e instanceof ApiException ? $e->errorCode : 'start_failed'])->save();
            throw $e;
        }
        $rev->forceFill(['workflow_run_id' => $run->id])->save();
        Audit::record('writer.rewrite_requested', $a, ['action' => $data['action'], 'section' => $section['key']]);

        return $rev;
    }

    public function applyRevision(Article $a, ArticleRevision $rev, string $userId): ArticleVersion
    {
        $this->assertEditable($a);
        if ($rev->kind !== 'editor' || $rev->status !== 'proposed' || $rev->proposed_html === null) {
            throw ApiException::conflict('invalid_state_transition', __('writer.errors.revision_not_proposed'));
        }
        $current = ArticleVersion::query()->findOrFail((string) $a->current_version_id);
        $from = ArticleVersion::query()->findOrFail((string) $rev->from_version_id);
        $sections = ArticleDocument::split($current->content_html, $current->section_hashes ?? []);
        $idx = collect($sections)->search(fn ($s) => $s['key'] === $rev->section_key);
        $orig = collect(ArticleDocument::split($from->content_html, $from->section_hashes ?? []))->firstWhere('key', $rev->section_key);
        if ($idx === false || $orig === null || $sections[$idx]['hash'] !== $orig['hash']) {
            // The section changed after the proposal was made: applying would overwrite newer text.
            $rev->forceFill(['status' => 'stale'])->save();
            throw ApiException::conflict('revision_stale', __('writer.errors.revision_stale'));
        }
        $sections[$idx]['html'] = (string) $rev->proposed_html;
        $html = ArticleDocument::assemble($current->title, $sections);
        $v = $this->versions->create($a, $html, 'ai_rewrite', $userId, $rev->model, 'AI: '.$rev->action.' ('.$rev->section_key.')');
        $rev->forceFill(['status' => 'applied', 'to_version_id' => $v->id])->save();
        Audit::record('writer.rewrite_applied', $a, ['revision_id' => $rev->id, 'version' => $v->number]);

        return $v;
    }

    public function discardRevision(Article $a, ArticleRevision $rev): void
    {
        if (! in_array($rev->status, ['proposed', 'failed', 'stale'], true)) {
            throw ApiException::conflict('invalid_state_transition', __('writer.errors.revision_not_proposed'));
        }
        $rev->forceFill(['status' => 'discarded'])->save();
        Audit::record('writer.rewrite_discarded', $a, ['revision_id' => $rev->id]);
    }

    // ---------------------------------------------------------------- claims

    /**
     * Manual verification by a human reviewer. VERIFIED still needs
     * evidence: an evidence item of this article, or a reference URL the
     * reviewer checked (stored as reviewer-provided, never fetched or
     * presented as system-verified). A note is always required.
     *
     * @param  list<string>  $evidenceIds
     */
    public function verifyClaim(Article $a, ArticleClaim $claim, Verdict $verdict, string $note, array $evidenceIds, ?string $referenceUrl, string $userId): FactCheckResult
    {
        if ($verdict === Verdict::Pending) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['verdict' => [__('writer.errors.invalid_verdict')]]);
        }
        if ($claim->article_version_id !== $a->current_version_id || $claim->is_stale) {
            throw ApiException::conflict('claim_stale', __('writer.errors.claim_stale'));
        }
        $this->assertIdle($a);
        $dossierIds = ResearchDossier::query()->where('article_id', $a->id)->pluck('id');
        $items = EvidenceItem::query()->with('source')->whereIn('research_dossier_id', $dossierIds)->whereIn('id', $evidenceIds)->get();
        if ($items->count() !== count(array_unique($evidenceIds))) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['evidence_ids' => [__('writer.errors.evidence_not_found')]]);
        }
        if ($referenceUrl !== null && ! HtmlSanitizer::safeHref($referenceUrl)) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['reference_url' => [__('writer.errors.invalid_url')]]);
        }
        if (in_array($verdict, [Verdict::Verified, Verdict::Contradicted], true) && $items->isEmpty() && $referenceUrl === null) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['evidence_ids' => [__('writer.errors.manual_needs_evidence')]]);
        }

        $shape = static fn (EvidenceItem $e): array => ['evidence_id' => $e->id, 'ref' => $e->ref, 'url' => $e->source?->final_url ?? $e->source?->url,
            'title' => $e->source?->title, 'excerpt' => mb_substr($e->excerpt, 0, 1000), 'source_type' => $e->source?->source_type,
            'reliability' => $e->source?->reliability_score, 'date' => ($e->source?->modified_at ?? $e->source?->published_at)?->toDateString()];
        $list = $items->map($shape)->values()->all();
        if ($referenceUrl !== null) {
            $list[] = ['evidence_id' => null, 'ref' => null, 'url' => $referenceUrl, 'title' => null, 'excerpt' => null, 'source_type' => 'reviewer_reference', 'reliability' => null, 'date' => null];
        }
        $relation = $verdict === Verdict::Contradicted ? 'contradicts' : 'supports';

        $result = DB::transaction(function () use ($claim, $verdict, $note, $list, $items, $relation, $userId): FactCheckResult {
            $r = new FactCheckResult;
            $r->forceFill([
                'fact_check_run_id' => null, 'article_claim_id' => $claim->id, 'verdict' => $verdict->value,
                'supporting' => $relation === 'supports' ? $list : [], 'contradicting' => $relation === 'contradicts' ? $list : [],
                'explanation' => Text::clean($note, 2000), 'rule_notes' => ['manual_review'],
                'reviewer_type' => 'human', 'reviewer_user_id' => $userId, 'reviewed_at' => now(),
            ])->save();
            foreach ($items as $e) {
                ClaimEvidence::link($claim->id, $e->id, $relation);
            }
            $claim->forceFill(['status' => $verdict->value, 'latest_result_id' => $r->id])->save();

            return $r;
        });
        ClaimStats::refresh($a);
        if ($a->status === ArticleStatus::Approved && $verdict !== Verdict::Verified) {
            $this->invalidate($a, 'Khẳng định được đánh giá lại: '.$verdict->value);
        }
        Audit::record('writer.claim_verified_manually', $a, ['claim_id' => $claim->id, 'risk' => $claim->risk_level, 'verdict' => $verdict->value]);

        return $result;
    }

    // ---------------------------------------------------------------- review

    public function duplicateDecision(Article $a, string $decision, ?string $note, string $userId): Article
    {
        if (! in_array($decision, ['create_new', 'update_existing', 'merge', 'reject'], true)) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['decision' => [__('writer.errors.invalid_decision')]]);
        }
        $a->forceFill(['duplicate_decision' => $decision])->save();
        Audit::record('writer.duplicate_decision', $a, ['decision' => $decision, 'note' => $note !== null ? mb_substr($note, 0, 300) : null]);
        if ($decision === 'reject' && ! $a->status->isTerminal()) {
            if ($this->workflows->active($a) !== null) {
                $this->workflows->cancel($a, $userId);
            }
            $this->machine->move($a, ArticleStatus::Cancelled, ['blocked_reason' => 'duplicate_rejected']);
        }

        return $a->refresh();
    }

    /** Rule-based quality audit of the current version (no AI call, no cost). */
    public function audit(Article $a): QualityReport
    {
        $this->assertIdle($a);
        $v = $a->currentVersion ?? throw ApiException::conflict('no_content', __('writer.errors.no_content'));

        return $this->quality->audit($a, $v);
    }

    /**
     * @return array{ok: bool, blocking: list<array<string, mixed>>, warnings: list<array<string, mixed>>, stats: array<string, mixed>}
     */
    public function checklist(Article $a, bool $ack = false): array
    {
        return $this->gate->evaluate($a, $ack);
    }

    public function approve(Article $a, string $versionId, bool $ackHighRisk, ?string $note, string $userId): EditorialApproval
    {
        return DB::transaction(function () use ($a, $versionId, $ackHighRisk, $note, $userId): EditorialApproval {
            /** @var Article $locked */
            $locked = Article::query()->whereKey($a->id)->lockForUpdate()->firstOrFail();
            if ($locked->current_version_id !== $versionId) {
                throw ApiException::conflict('version_conflict', __('writer.errors.version_conflict'));
            }
            $result = $this->gate->evaluate($locked, $ackHighRisk);
            if (! $result['ok']) {
                throw new ApiException(422, 'approval_blocked', __('writer.errors.approval_blocked'), ['blocking' => $result['blocking']]);
            }
            if ($locked->status === ArticleStatus::QualityReview) {
                $this->machine->transition($locked, ArticleStatus::NeedsHumanReview);
            }
            if (! $this->machine->transition($locked, ArticleStatus::Approved, [
                '__approval_gate' => true, 'approved_version_id' => $versionId, 'approved_by' => $userId, 'approved_at' => now(), 'blocked_reason' => null,
            ])) {
                throw ApiException::conflict('version_conflict', __('writer.errors.version_conflict'));
            }
            $ap = new EditorialApproval;
            $ap->forceFill([
                'article_id' => $locked->id, 'article_version_id' => $versionId, 'decision' => 'approved',
                'note' => $note !== null ? mb_substr($note, 0, 2000) : null, 'user_id' => $userId,
                'checks' => ['acknowledged_high_risk' => $ackHighRisk, 'warnings' => $result['warnings'], 'stats' => $result['stats']],
            ])->save();
            Audit::record('writer.article_approved', $locked, ['version_id' => $versionId, 'ack_high_risk' => $ackHighRisk, 'quality' => $result['stats']['quality_score']]);
            $a->refresh();

            return $ap;
        });
    }

    public function reject(Article $a, string $note, string $userId): EditorialApproval
    {
        if (! in_array($a->status, [ArticleStatus::NeedsHumanReview, ArticleStatus::Approved, ArticleStatus::QualityReview], true) || $a->current_version_id === null) {
            throw ApiException::conflict('invalid_state_transition', __('writer.errors.not_reviewable'));
        }
        $this->invalidate($a, 'Yêu cầu chỉnh sửa');
        $ap = new EditorialApproval;
        $ap->forceFill([
            'article_id' => $a->id, 'article_version_id' => $a->current_version_id, 'decision' => 'changes_requested',
            'note' => mb_substr($note, 0, 2000), 'user_id' => $userId,
        ])->save();
        Audit::record('writer.article_changes_requested', $a, ['version_id' => $a->current_version_id]);

        return $ap;
    }

    public function cancelArticle(Article $a, string $userId): Article
    {
        if ($a->status === ArticleStatus::Cancelled) {
            return $a;
        }
        if ($this->workflows->active($a) !== null) {
            $this->workflows->cancel($a, $userId);
        }
        $this->machine->move($a, ArticleStatus::Cancelled);
        Audit::record('writer.article_cancelled', $a);
        app(Batches::class)->articleFinished($a->refresh());

        return $a;
    }

    // ---------------------------------------------------------------- helpers

    private function invalidate(Article $a, string $reason): void
    {
        EditorialApproval::query()->where('article_id', $a->id)->where('decision', 'approved')->whereNull('invalidated_at')
            ->update(['invalidated_at' => now(), 'invalidated_reason' => mb_substr($reason, 0, 300), 'updated_at' => now()]);
        if ($a->status === ArticleStatus::Approved) {
            $this->machine->transition($a, ArticleStatus::NeedsHumanReview, ['approved_version_id' => null, 'approved_by' => null, 'approved_at' => null]);
        } elseif ($a->status === ArticleStatus::QualityReview) {
            $this->machine->move($a, ArticleStatus::NeedsHumanReview);
        }
    }

    /** Human edits are allowed once there is content and no AI step is running. */
    private function assertEditable(Article $a): void
    {
        if ($a->current_version_id === null || ! in_array($a->status, [ArticleStatus::NeedsHumanReview, ArticleStatus::Approved, ArticleStatus::Failed], true)) {
            throw ApiException::conflict('not_editable', __('writer.errors.not_editable'));
        }
        $this->assertIdle($a, ['rewrite']);
    }

    /**
     * @param  list<string>  $ignore
     */
    private function assertIdle(Article $a, array $ignore = []): void
    {
        if ($this->workflows->active($a, $ignore) !== null) {
            throw ApiException::conflict('workflow_active', __('writer.errors.workflow_active'));
        }
    }
}
