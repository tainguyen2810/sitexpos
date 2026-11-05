<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Pipeline;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SiteXPost\Modules\AiGateway\Gateway\AiGatewayException;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Writer\Ai\WriterAi;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleClaim;
use SiteXPost\Modules\Writer\Domain\ArticleVersion;
use SiteXPost\Modules\Writer\Domain\ClaimEvidence;
use SiteXPost\Modules\Writer\Domain\EvidenceItem;
use SiteXPost\Modules\Writer\Domain\FactCheckResult;
use SiteXPost\Modules\Writer\Domain\FactCheckRun;
use SiteXPost\Modules\Writer\Enums\RiskLevel;
use SiteXPost\Modules\Writer\Enums\Verdict;
use SiteXPost\Modules\Writer\Support\ArticleDocument;
use SiteXPost\Modules\Writer\Support\WriterSettings;

/**
 * Checks every PENDING claim of a version against REAL evidence (excerpts
 * of snapshots the system fetched). The model proposes a verdict with
 * evidence refs; VerdictRules decides. Claims without any candidate
 * evidence are INSUFFICIENT_EVIDENCE by rule (no AI call, no cost).
 */
class FactChecker
{
    private const BATCH = 8;

    public function __construct(
        private readonly WriterAi $ai,
        private readonly WriterSettings $settings,
    ) {}

    /**
     * @param  list<string>|null  $onlyKeys
     */
    public function check(Article $a, ArticleVersion $v, int $round, string $key, ?string $run, ?array $onlyKeys = null): FactCheckRun
    {
        $fc = new FactCheckRun;
        $fc->forceFill(['article_id' => $a->id, 'article_version_id' => $v->id, 'workflow_run_id' => $run, 'round' => $round, 'status' => 'running', 'started_at' => now()])->save();

        $dossier = $a->dossier()->first();
        $items = $dossier !== null
            ? EvidenceItem::query()->with('source:id,url,final_url,title,reliability_score,published_at,modified_at,retrieval_status,source_type')->where('research_dossier_id', $dossier->id)->get()
            : collect();
        $byRef = $items->keyBy('ref');
        $byId = $items->keyBy('id');
        $sectionCitations = $this->sectionCitations($v);

        $q = ArticleClaim::query()->where('article_version_id', $v->id)->where('status', 'PENDING');
        if ($onlyKeys !== null) {
            $q->whereIn('section_key', $onlyKeys);
        }
        $claims = $q->orderBy('created_at')->get();
        $minRel = $this->settings->int('high_risk_min_reliability', $a->workspace_id);
        $months = $this->settings->int('outdated_after_months', $a->workspace_id);
        $warnings = [];
        $model = null;

        $toAi = [];
        foreach ($claims as $c) {
            $candidates = $this->candidates($c, $items, $sectionCitations[$c->section_key] ?? [], $byId);
            if ($candidates === []) {
                $this->record($fc, $c, Verdict::InsufficientEvidence, [], [], null, 'Không tìm thấy đoạn bằng chứng nào liên quan trong hồ sơ nghiên cứu (không có nghĩa là thông tin sai).', ['no_candidate_evidence'], 'rule', null);

                continue;
            }
            $toAi[] = ['claim' => $c, 'candidates' => $candidates];
        }

        foreach (array_chunk($toAi, self::BATCH) as $bi => $batch) {
            $payload = [];
            foreach ($batch as $n => $row) {
                $payload[] = ['n' => $n + 1, 'claim' => $row['claim']->claim_text, 'category' => $row['claim']->category, 'risk' => $row['claim']->risk_level,
                    'evidence' => array_map(fn (EvidenceItem $e) => ['ref' => $e->ref, 'excerpt' => mb_substr($e->excerpt, 0, 600),
                        'source' => $e->source->title ?? $e->source?->url, 'date' => ($e->source->modified_at ?? $e->source?->published_at)?->toDateString(),
                        'version_context' => $e->version_context], $row['candidates'])];
            }
            try {
                $res = $this->ai->factCheck($a, $payload, $key.':b'.$bi, $run);
                $model = $res->modelKey;
                $answers = collect((array) ($res->json['results'] ?? []))->keyBy(fn ($r) => (int) ($r['claim'] ?? 0));
            } catch (AiGatewayException $e) {
                $warnings[] = 'factcheck_ai_failed:'.$e->errorCode;

                // Claims stay PENDING (not checked) — never silently "verified".
                continue;
            }
            foreach ($batch as $n => $row) {
                /** @var ArticleClaim $c */
                $c = $row['claim'];
                $ans = $answers->get($n + 1);
                $allowed = collect($row['candidates'])->keyBy('ref');
                $pick = static fn (array $refs) => collect($refs)->map(fn ($r) => $allowed->get((int) $r))->filter()->unique('id')->values()->all();
                $sup = $ans !== null ? $pick((array) ($ans['supporting_refs'] ?? [])) : [];
                $con = $ans !== null ? $pick((array) ($ans['contradicting_refs'] ?? [])) : [];
                $shape = fn (array $list) => array_map(fn (EvidenceItem $e) => ['ref' => $e->ref, 'excerpt' => $e->excerpt,
                    'reliability' => $e->source?->reliability_score, 'date' => ($e->source->modified_at ?? $e->source?->published_at)?->toDateString()], $list);
                $decision = VerdictRules::decide($c->claim_text, $c->category, RiskLevel::from($c->risk_level), $ans['verdict'] ?? null, $shape($sup), $shape($con), $minRel, $months);
                $this->record($fc, $c, $decision['verdict'], $sup, $con, $ans !== null ? Text::clean($ans['date_context'] ?? '', 300) : null,
                    $ans !== null ? Text::clean($ans['explanation'] ?? '', 2000) : 'Mô hình không trả lời cho claim này.', $decision['notes'], 'model', $model);
            }
        }

        $issues = ConsistencyChecker::check($v->content_html);
        $this->markChecked($v, $onlyKeys);
        $counts = ArticleClaim::query()->where('article_version_id', $v->id)->get(['status'])->countBy('status')->all();
        $fc->forceFill([
            'status' => $warnings === [] ? 'completed' : 'partial', 'claims_total' => $claims->count(), 'counts' => $counts + ['warnings' => $warnings],
            'consistency_issues' => $issues, 'model' => $model, 'finished_at' => now(),
        ])->save();
        ClaimStats::refresh($a);

        return $fc;
    }

    /**
     * @param  Collection<int, EvidenceItem>  $items
     * @param  list<string>  $cited
     * @param  Collection<string, EvidenceItem>  $byId
     * @return list<EvidenceItem>
     */
    private function candidates(ArticleClaim $c, $items, array $cited, $byId): array
    {
        $tokens = Text::tokens($c->claim_text);
        $scored = $items->filter(fn (EvidenceItem $e) => $e->source?->retrieval_status === 'fetched')
            ->map(fn (EvidenceItem $e) => ['e' => $e, 's' => Text::containment($tokens, Text::tokens($e->statement.' '.$e->excerpt))])
            ->filter(fn ($x) => $x['s'] >= 0.2)->sortByDesc('s')->take(5)->pluck('e');
        $citedItems = collect($cited)->map(fn ($id) => $byId->get($id))->filter();

        return $citedItems->merge($scored)->unique('id')->take(6)->values()->all();
    }

    /**
     * @return array<string, list<string>> section key => cited evidence ids
     */
    private function sectionCitations(ArticleVersion $v): array
    {
        $out = [];
        foreach (ArticleDocument::split($v->content_html, $v->section_hashes ?? []) as $s) {
            preg_match_all('/data-evidence-id="([0-9a-z]{26})"/', $s['html'], $m);
            $out[$s['key']] = array_values(array_unique($m[1] ?? []));
        }

        return $out;
    }

    /**
     * @param  list<EvidenceItem>  $sup
     * @param  list<EvidenceItem>  $con
     * @param  list<string>  $notes
     */
    private function record(FactCheckRun $fc, ArticleClaim $c, Verdict $verdict, array $sup, array $con, ?string $date, ?string $explanation, array $notes, string $type, ?string $model): void
    {
        DB::transaction(function () use ($fc, $c, $verdict, $sup, $con, $date, $explanation, $notes, $type, $model): void {
            $shape = static fn (EvidenceItem $e): array => ['evidence_id' => $e->id, 'ref' => $e->ref, 'url' => $e->source->final_url ?? $e->source?->url,
                'title' => $e->source?->title, 'excerpt' => mb_substr($e->excerpt, 0, 1000), 'source_type' => $e->source?->source_type,
                'reliability' => $e->source?->reliability_score, 'date' => ($e->source->modified_at ?? $e->source?->published_at)?->toDateString(), 'version_context' => $e->version_context];
            $r = new FactCheckResult;
            $r->forceFill([
                'fact_check_run_id' => $fc->id, 'article_claim_id' => $c->id, 'verdict' => $verdict->value,
                'supporting' => array_map($shape, $sup), 'contradicting' => array_map($shape, $con),
                'date_context' => $date ?: null, 'explanation' => $explanation, 'rule_notes' => $notes,
                'reviewer_type' => $type, 'reviewer_model' => $model, 'reviewed_at' => now(),
            ])->save();
            foreach ([['supports', $sup], ['contradicts', $con]] as [$rel, $list]) {
                foreach ($list as $e) {
                    ClaimEvidence::link($c->id, $e->id, $rel);
                }
            }
            $c->forceFill(['status' => $verdict->value, 'latest_result_id' => $r->id])->save();
        });
    }

    /**
     * @param  list<string>|null  $onlyKeys
     */
    private function markChecked(ArticleVersion $v, ?array $onlyKeys): void
    {
        $sections = $v->section_hashes ?? [];
        foreach ($sections as $i => $s) {
            if ($onlyKeys === null || in_array($s['key'], $onlyKeys, true)) {
                $sections[$i]['claims_checked'] = true;
            }
        }
        $v->forceFill(['section_hashes' => $sections])->save();
    }
}
