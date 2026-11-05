<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Pipeline;

use SiteXPost\Modules\AiGateway\Gateway\AiGatewayException;
use SiteXPost\Modules\Writer\Ai\BudgetBlocked;
use SiteXPost\Modules\Writer\Ai\WriterAi;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleVersion;
use SiteXPost\Modules\Writer\Domain\FactCheckRun;
use SiteXPost\Modules\Writer\Domain\QualityReport;
use SiteXPost\Modules\Writer\Domain\WritingPreset;
use SiteXPost\Modules\Writer\Support\HtmlSanitizer;

/**
 * Quality audit = deterministic rubric score + duplicate check (+ optional
 * advisory AI notes that never change the score).
 */
class QualityAuditor
{
    public function __construct(
        private readonly DuplicateDetector $duplicates,
        private readonly ArticleContext $context,
        private readonly WriterAi $ai,
    ) {}

    public function audit(Article $a, ArticleVersion $v, ?string $key = null, ?string $run = null): QualityReport
    {
        $dup = $this->duplicates->check($a, $v->content_html);
        $brief = $this->context->approvedBrief($a) ?? $a->brief()->first();
        $preset = $a->writing_preset_id !== null ? WritingPreset::query()->find($a->writing_preset_id) : null;
        $fc = FactCheckRun::query()->where('article_version_id', $v->id)->latest()->first();
        $consistency = $fc->consistency_issues ?? ConsistencyChecker::check($v->content_html);
        $r = QualityScorer::score($a, $v, $brief, $preset, $dup, $consistency);

        $notes = null;
        if ($key !== null && (bool) $a->setting('ai_review_notes', true)) {
            $notes = [];
            foreach ([WriterAi::TASK_SEO, WriterAi::TASK_FINAL] as $task) {
                try {
                    $res = $this->ai->review($a, $task, HtmlSanitizer::text($v->content_html), ['primary_keyword' => $a->primary_keyword,
                        'search_intent' => $a->search_intent, 'brand_voice' => $this->context->brandVoice($a)], $key.':'.$task, $run);
                    foreach (array_slice((array) ($res->json['notes'] ?? []), 0, 8) as $n) {
                        $notes[] = ['task' => $task, 'severity' => $n['severity'] ?? 'info', 'message' => mb_substr((string) ($n['message'] ?? ''), 0, 500), 'model' => $res->modelKey];
                    }
                } catch (AiGatewayException|BudgetBlocked $e) {
                    $notes[] = ['task' => $task, 'severity' => 'info', 'message' => 'Không lấy được nhận xét AI ('.($e instanceof AiGatewayException ? $e->errorCode : 'budget').').', 'model' => null];
                }
            }
        }

        $report = new QualityReport;
        $report->forceFill([
            'article_id' => $a->id, 'article_version_id' => $v->id, 'rubric_version' => QualityScorer::RUBRIC_VERSION,
            'score' => $r['score'], 'criteria' => $r['criteria'], 'issues' => $r['issues'], 'duplicates' => $dup, 'ai_notes' => $notes,
        ])->save();
        Article::query()->whereKey($a->id)->update(['quality_score' => $r['score'], 'duplicate_check' => json_encode($dup, JSON_UNESCAPED_UNICODE)]);
        $a->refresh();

        return $report;
    }
}
