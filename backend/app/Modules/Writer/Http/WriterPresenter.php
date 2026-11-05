<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleClaim;
use SiteXPost\Modules\Writer\Domain\ArticleOutline;
use SiteXPost\Modules\Writer\Domain\ArticleRevision;
use SiteXPost\Modules\Writer\Domain\ArticleVersion;
use SiteXPost\Modules\Writer\Domain\ArticleWorkflowRun;
use SiteXPost\Modules\Writer\Domain\ContentBrief;
use SiteXPost\Modules\Writer\Domain\EditorialApproval;
use SiteXPost\Modules\Writer\Domain\EvidenceItem;
use SiteXPost\Modules\Writer\Domain\EvidenceSource;
use SiteXPost\Modules\Writer\Domain\FactCheckResult;
use SiteXPost\Modules\Writer\Domain\FactCheckRun;
use SiteXPost\Modules\Writer\Domain\QualityReport;
use SiteXPost\Modules\Writer\Domain\ResearchDossier;
use SiteXPost\Modules\Writer\Domain\ResearchQuestion;
use SiteXPost\Modules\Writer\Domain\WritingBatch;
use SiteXPost\Modules\Writer\Domain\WritingBatchItem;
use SiteXPost\Modules\Writer\Domain\WritingPreset;
use SiteXPost\Modules\Writer\Workflows\ArticleWorkflow;

/**
 * JSON shapes for writer child records. Content HTML is always the stored,
 * server-sanitized HTML; snapshots' raw page text is never returned.
 */
final class WriterPresenter
{
    /**
     * Cursor page with the same envelope as Kernel\Http\Paginated.
     *
     * @param  Builder<Model>  $query
     */
    public static function page(Builder $query, callable $map, Request $request, int $default = 25): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->integer('per_page', $default)));
        $page = $query->cursorPaginate($perPage);

        return new JsonResponse([
            'data' => array_map($map, $page->items()),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'prev_cursor' => $page->previousCursor()?->encode(), 'per_page' => $perPage],
        ]);
    }

    private static function ts(?Carbon $t): ?string
    {
        return $t?->toIso8601ZuluString();
    }

    /**
     * @return array<string, mixed>
     */
    public static function version(ArticleVersion $v, bool $withContent = true): array
    {
        return [
            'id' => $v->id, 'number' => $v->number, 'source' => $v->source, 'title' => $v->title,
            'word_count' => $v->word_count, 'content_hash' => $v->content_hash, 'parent_version_id' => $v->parent_version_id,
            'sections' => array_map(static fn ($s) => ['key' => $s['key'], 'heading' => $s['heading'], 'claims_checked' => (bool) ($s['claims_checked'] ?? false)], $v->section_hashes ?? []),
            'change_summary' => $v->change_summary, 'model' => $v->model, 'created_by' => $v->created_by, 'created_at' => self::ts($v->created_at),
        ] + ($withContent ? ['content_html' => $v->content_html] : []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function workflow(?ArticleWorkflowRun $link, ?WorkflowRun $run): ?array
    {
        if ($link === null || $run === null) {
            return null;
        }
        $steps = [];
        foreach ($run->steps as $s) {
            $steps[] = ['key' => $s->key, 'label' => ArticleWorkflow::stepLabel($s->key), 'status' => $s->status->value, 'attempts' => $s->attempts,
                'error' => $s->error_code !== null ? ['code' => $s->error_code, 'message' => $s->error_message] : null,
                'started_at' => self::ts($s->started_at), 'finished_at' => self::ts($s->finished_at)];
        }
        $done = collect($steps)->where('status', 'succeeded')->count();

        return [
            'id' => $run->id, 'kind' => $link->kind, 'type' => $run->type, 'status' => $run->status->value, 'current_step' => $run->current_step,
            'current_step_label' => $run->current_step !== null ? ArticleWorkflow::stepLabel($run->current_step) : null,
            'progress' => $steps === [] ? 0 : (int) round($done * 100 / count($steps)),
            'steps' => $steps, 'error' => $run->error_code !== null ? ['code' => $run->error_code, 'message' => $run->error_message] : null,
            'started_at' => self::ts($run->started_at), 'finished_at' => self::ts($run->finished_at), 'created_at' => self::ts($run->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function dossier(ResearchDossier $d): array
    {
        return [
            'id' => $d->id, 'status' => $d->status, 'depth' => $d->depth, 'retrieval_available' => (bool) $d->retrieval_available,
            'questions_count' => $d->questions_count, 'sources_count' => $d->sources_count, 'usable_sources_count' => $d->usable_sources_count,
            'evidence_count' => $d->evidence_count, 'rejected_evidence_count' => $d->rejected_evidence_count,
            'warnings' => $d->warnings ?? [], 'summary' => $d->summary, 'completed_at' => self::ts($d->completed_at), 'created_at' => self::ts($d->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function question(ResearchQuestion $q): array
    {
        return ['id' => $q->id, 'position' => $q->position, 'question' => $q->question, 'purpose' => $q->purpose, 'origin' => $q->origin, 'status' => $q->status];
    }

    /**
     * @return array<string, mixed>
     */
    public static function source(EvidenceSource $s): array
    {
        return [
            'id' => $s->id, 'url' => $s->url, 'final_url' => $s->final_url, 'title' => $s->title, 'publisher' => $s->publisher, 'author' => $s->author,
            'published_at' => self::ts($s->published_at), 'modified_at' => self::ts($s->modified_at), 'retrieved_at' => self::ts($s->retrieved_at),
            'source_type' => $s->source_type, 'discovered_via' => $s->discovered_via, 'retrieval_status' => $s->retrieval_status,
            'http_status' => $s->http_status, 'error_code' => $s->error_code, 'reliability_score' => $s->reliability_score,
            'reliability' => $s->reliability, 'license_note' => $s->license_note,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function evidence(EvidenceItem $e): array
    {
        return [
            'id' => $e->id, 'ref' => $e->ref, 'source_id' => $e->evidence_source_id, 'question_id' => $e->research_question_id,
            'excerpt' => $e->excerpt, 'statement' => $e->statement, 'date_context' => $e->date_context, 'version_context' => $e->version_context,
            'relevance' => $e->relevance, 'extracted_by_model' => $e->extracted_by_model,
            'source' => $e->relationLoaded('source') && $e->source !== null ? ['url' => $e->source->final_url ?? $e->source->url, 'title' => $e->source->title,
                'source_type' => $e->source->source_type, 'reliability_score' => $e->source->reliability_score,
                'published_at' => self::ts($e->source->published_at), 'retrieved_at' => self::ts($e->source->retrieved_at)] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function brief(ContentBrief $b): array
    {
        return ['id' => $b->id, 'version' => $b->version, 'status' => $b->status, 'data' => $b->data, 'warnings' => $b->warnings ?? [],
            'research_dossier_id' => $b->research_dossier_id, 'generated_by_model' => $b->generated_by_model, 'edited_by' => $b->edited_by,
            'approved_by' => $b->approved_by, 'approved_at' => self::ts($b->approved_at), 'created_at' => self::ts($b->created_at)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function outline(ArticleOutline $o): array
    {
        return ['id' => $o->id, 'version' => $o->version, 'status' => $o->status, 'sections' => $o->sections, 'warnings' => $o->warnings ?? [],
            'content_brief_id' => $o->content_brief_id, 'generated_by_model' => $o->generated_by_model,
            'approved_by' => $o->approved_by, 'approved_at' => self::ts($o->approved_at), 'created_at' => self::ts($o->created_at)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function result(?FactCheckResult $r): ?array
    {
        if ($r === null) {
            return null;
        }

        return ['id' => $r->id, 'verdict' => $r->verdict, 'supporting' => $r->supporting ?? [], 'contradicting' => $r->contradicting ?? [],
            'date_context' => $r->date_context, 'explanation' => $r->explanation, 'rule_notes' => $r->rule_notes ?? [],
            'reviewer_type' => $r->reviewer_type, 'reviewer_model' => $r->reviewer_model, 'reviewer_user_id' => $r->reviewer_user_id,
            'reviewed_at' => self::ts($r->reviewed_at)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function claim(ArticleClaim $c): array
    {
        return [
            'id' => $c->id, 'version_id' => $c->article_version_id, 'section_key' => $c->section_key, 'claim' => $c->claim_text,
            'category' => $c->category, 'risk_level' => $c->risk_level, 'evidence_requirements' => $c->evidence_requirements,
            'origin' => $c->origin, 'extracted_by_model' => $c->extracted_by_model, 'extracted_at' => self::ts($c->extracted_at),
            'status' => $c->status, 'is_stale' => (bool) $c->is_stale,
            'latest_result' => $c->relationLoaded('latestResult') ? self::result($c->latestResult) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function factCheckRun(FactCheckRun $r): array
    {
        return ['id' => $r->id, 'version_id' => $r->article_version_id, 'round' => $r->round, 'status' => $r->status, 'claims_total' => $r->claims_total,
            'counts' => $r->counts ?? [], 'consistency_issues' => $r->consistency_issues ?? [], 'model' => $r->model,
            'started_at' => self::ts($r->started_at), 'finished_at' => self::ts($r->finished_at)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function revision(ArticleRevision $r): array
    {
        return ['id' => $r->id, 'kind' => $r->kind, 'action' => $r->action, 'round' => $r->round, 'status' => $r->status, 'section_key' => $r->section_key,
            'from_version_id' => $r->from_version_id, 'to_version_id' => $r->to_version_id, 'claim_ids' => $r->claim_ids ?? [],
            'request' => $r->request, 'diff' => $r->diff ?? [], 'proposed_html' => $r->proposed_html, 'model' => $r->model,
            'error_code' => $r->error_code, 'created_by' => $r->created_by, 'created_at' => self::ts($r->created_at)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function quality(QualityReport $q): array
    {
        return ['id' => $q->id, 'version_id' => $q->article_version_id, 'rubric_version' => $q->rubric_version, 'score' => $q->score,
            'criteria' => $q->criteria, 'issues' => $q->issues ?? [], 'duplicates' => $q->duplicates, 'ai_notes' => $q->ai_notes ?? [],
            'disclaimer' => __('writer.quality_disclaimer'), 'created_at' => self::ts($q->created_at)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function approval(EditorialApproval $a): array
    {
        return ['id' => $a->id, 'version_id' => $a->article_version_id, 'decision' => $a->decision, 'note' => $a->note, 'checks' => $a->checks,
            'user_id' => $a->user_id, 'invalidated_at' => self::ts($a->invalidated_at), 'invalidated_reason' => $a->invalidated_reason, 'created_at' => self::ts($a->created_at)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function preset(WritingPreset $p): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'site_id' => $p->site_id, 'is_default' => $p->is_default, 'tone' => $p->tone,
            'writing_style' => $p->writing_style, 'preferred_terms' => $p->preferred_terms ?? [], 'forbidden_phrases' => $p->forbidden_phrases ?? [],
            'facts' => $p->facts ?? [], 'target_customers' => $p->target_customers ?? [], 'geographic_context' => $p->geographic_context,
            'editorial_guidelines' => $p->editorial_guidelines, 'cta' => $p->cta, 'defaults' => $p->defaults ?? [], 'is_demo' => $p->is_demo,
            'created_at' => self::ts($p->created_at), 'updated_at' => self::ts($p->updated_at)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function batch(WritingBatch $b, bool $withItems = false): array
    {
        $counts = WritingBatchItem::query()->where('writing_batch_id', $b->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $spent = (int) Article::query()->where('writing_batch_id', $b->id)->sum('spent_micro_usd');
        $out = ['id' => $b->id, 'site_id' => $b->site_id, 'campaign_id' => $b->campaign_id, 'writing_preset_id' => $b->writing_preset_id,
            'source' => $b->source, 'status' => $b->status, 'total' => $b->total, 'concurrency' => $b->concurrency, 'settings' => $b->settings ?? [],
            'per_article_budget_micro_usd' => $b->per_article_budget_micro_usd, 'estimated_cost_micro_usd' => $b->estimated_cost_micro_usd,
            'reserved_cost_micro_usd' => $b->reserved_cost_micro_usd, 'spent_micro_usd' => $spent, 'counts' => $counts, 'is_demo' => $b->is_demo,
            'paused_at' => self::ts($b->paused_at), 'finished_at' => self::ts($b->finished_at), 'created_at' => self::ts($b->created_at)];
        if ($withItems) {
            $out['items'] = WritingBatchItem::query()->where('writing_batch_id', $b->id)->orderBy('position')->get()
                ->map(fn (WritingBatchItem $i) => ['id' => $i->id, 'position' => $i->position, 'title' => $i->title, 'content_idea_id' => $i->content_idea_id,
                    'article_id' => $i->article_id, 'status' => $i->status, 'attempts' => $i->attempts,
                    'error' => $i->error_code !== null ? ['code' => $i->error_code, 'message' => $i->error_message] : null])->all();
        }

        return $out;
    }
}
