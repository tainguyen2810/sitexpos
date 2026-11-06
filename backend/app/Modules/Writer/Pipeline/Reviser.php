<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Pipeline;

use Illuminate\Support\Collection;
use SiteXPost\Modules\AiGateway\Gateway\AiGatewayException;
use SiteXPost\Modules\Writer\Ai\WriterAi;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleClaim;
use SiteXPost\Modules\Writer\Domain\ArticleRevision;
use SiteXPost\Modules\Writer\Domain\ArticleVersion;
use SiteXPost\Modules\Writer\Domain\FactCheckResult;
use SiteXPost\Modules\Writer\Support\ArticleDocument;
use SiteXPost\Modules\Writer\Support\HtmlSanitizer;
use SiteXPost\Modules\Writer\Support\TextDiff;

/**
 * Automatic revision of claims that failed fact-check:
 * - only sections with problem claims are sent, only those sentences may
 *   change (a rewrite that changes > 50 % of a section is rejected);
 * - corrections may only use evidence that exists in the dossier — no new
 *   source is invented; without evidence the sentence is softened/removed;
 * - every applied change is stored with its before/after diff and the
 *   claims it addresses; the affected sections are re-extracted and
 *   re-checked by the caller. Rounds are bounded by the caller.
 */
class Reviser
{
    public const MAX_CHANGE_RATIO = 0.5;

    public function __construct(
        private readonly WriterAi $ai,
        private readonly ArticleContext $context,
        private readonly VersionManager $versions,
        private readonly SectionWriter $writer,
    ) {}

    /**
     * Claims of the current version that need a fix.
     *
     * @return Collection<int, ArticleClaim>
     */
    public static function problems(ArticleVersion $v)
    {
        return ArticleClaim::query()->where('article_version_id', $v->id)
            ->where(fn ($q) => $q->whereIn('status', ['CONTRADICTED', 'OUTDATED'])
                ->orWhere(fn ($q) => $q->whereIn('status', ['INSUFFICIENT_EVIDENCE', 'NEEDS_HUMAN_REVIEW'])->whereIn('risk_level', ['HIGH', 'CRITICAL'])))
            ->get();
    }

    /**
     * @return array{version: ArticleVersion|null, changed: list<string>, rejected: int}
     */
    public function revise(Article $a, ArticleVersion $v, int $round, string $key, ?string $run): array
    {
        $problems = self::problems($v)->groupBy('section_key');
        if ($problems->isEmpty()) {
            return ['version' => null, 'changed' => [], 'rejected' => 0];
        }
        $sections = ArticleDocument::split($v->content_html, $v->section_hashes ?? []);
        $dossier = $a->dossier()->first();
        $changed = [];
        $rejected = 0;
        $records = [];

        foreach ($sections as $i => $s) {
            $claims = $problems->get($s['key']);
            if ($claims === null) {
                continue;
            }
            $results = FactCheckResult::query()->whereIn('id', $claims->pluck('latest_result_id')->filter())->get()->keyBy('id');
            $issues = $claims->map(function (ArticleClaim $c) use ($results) {
                $r = $results->get($c->latest_result_id);

                return ['claim_id' => $c->id, 'claim' => $c->claim_text, 'verdict' => $c->status, 'risk' => $c->risk_level,
                    'explanation' => $r?->explanation, 'contradicting' => array_map(fn ($e) => ['ref' => $e['ref'], 'excerpt' => mb_substr((string) $e['excerpt'], 0, 500)], $r->contradicting ?? []),
                    'supporting' => array_map(fn ($e) => ['ref' => $e['ref'], 'excerpt' => mb_substr((string) $e['excerpt'], 0, 500)], $r->supporting ?? []),
                    'instruction' => match ($c->status) {
                        'CONTRADICTED' => 'Sửa câu cho khớp với bằng chứng contradicting và đặt marker [E<ref>] của bằng chứng đó.',
                        'OUTDATED' => 'Cập nhật theo bằng chứng mới hơn nếu có; nếu không, ghi rõ mốc thời gian của thông tin hoặc bỏ khẳng định.',
                        default => 'Không có bằng chứng đủ mạnh: viết lại thận trọng (bỏ con số/thông số cụ thể) hoặc bỏ khẳng định. Không bịa nguồn.',
                    }];
            })->values()->all();
            $sectionHtml = $this->toMarkers($s['html']);
            try {
                $res = $this->ai->rewrite($a, 'Sửa các khẳng định có vấn đề trong đoạn HTML (section) theo danh sách issues.', [
                    'section_html' => $sectionHtml, 'issues' => $issues,
                    'evidence' => $dossier !== null ? $this->context->evidence($dossier->id, null, 20, 300) : [],
                ], $key.':'.$s['key'], $run, 'revise');
            } catch (AiGatewayException $e) {
                $records[] = $this->record($a, $v, null, $round, 'failed', $s['key'], $claims->pluck('id')->all(), [], null, null, $e->errorCode);
                $rejected++;

                continue;
            }
            [$html] = $this->writer->citations((string) ($res->json['html'] ?? ''), $dossier?->id);
            $html = $this->versions->sanitize($a, $html, $this->linksIn($s['html']))['html'];
            $ratio = TextDiff::changeRatio($s['text'], HtmlSanitizer::text($html));
            if (trim(strip_tags($html)) === '' || $ratio > self::MAX_CHANGE_RATIO) {
                $records[] = $this->record($a, $v, null, $round, 'rejected', $s['key'], $claims->pluck('id')->all(),
                    TextDiff::compact(TextDiff::diff($s['text'], HtmlSanitizer::text($html))), $html, $res->modelKey, $ratio > self::MAX_CHANGE_RATIO ? 'change_too_large' : 'empty_output');
                $rejected++;

                continue;
            }
            if ($s['key'] !== 'intro' && preg_match('#^\s*<h2\b#i', $html) !== 1) {
                $html = '<h2>'.e($s['heading']).'</h2>'.$html;
            }
            $sections[$i]['html'] = $html;
            $changed[] = $s['key'];
            $records[] = ['pending' => true, 'section' => $s['key'], 'claims' => $claims->pluck('id')->all(),
                'diff' => TextDiff::compact(TextDiff::diff($s['text'], HtmlSanitizer::text($html))), 'model' => $res->modelKey];
        }

        if ($changed === []) {
            return ['version' => null, 'changed' => [], 'rejected' => $rejected];
        }
        $html = ArticleDocument::assemble($v->title, array_map(fn ($s) => ['key' => $s['key'], 'heading' => $s['heading'], 'html' => $s['html']], $sections));
        $new = $this->versions->create($a, $html, 'ai_revision', null, null, 'Tự động sửa sau fact-check (vòng '.$round.')');
        foreach ($records as $r) {
            if (($r['pending'] ?? false) === true) {
                $this->record($a, $v, $new, $round, 'applied', $r['section'], $r['claims'], $r['diff'], null, $r['model'], null);
            }
        }

        return ['version' => $new, 'changed' => $changed, 'rejected' => $rejected];
    }

    /**
     * <a data-evidence-id="…">[n]</a> → [En] so the model keeps citations.
     */
    public function toMarkers(string $html): string
    {
        return (string) preg_replace('#<sup>\s*<a\b[^>]*data-evidence-id="[0-9a-z]{26}"[^>]*>\s*\[(\d+)\]\s*</a>\s*</sup>|<a\b[^>]*data-evidence-id="[0-9a-z]{26}"[^>]*>\s*\[(\d+)\]\s*</a>#i', '[E$1$2]', $html);
    }

    /**
     * @return list<string>
     */
    private function linksIn(string $html): array
    {
        preg_match_all('#<a\b[^>]*href="([^"]+)"#i', $html, $m);

        return array_values(array_filter($m[1] ?? [], fn ($u) => $u !== '#'));
    }

    /**
     * @param  list<string>  $claimIds
     * @param  list<array{op: string, text: string}>  $diff
     */
    private function record(Article $a, ArticleVersion $from, ?ArticleVersion $to, int $round, string $status, string $section, array $claimIds, array $diff, ?string $proposed, ?string $model, ?string $error): ArticleRevision
    {
        $r = new ArticleRevision;
        $r->forceFill([
            'article_id' => $a->id, 'kind' => 'auto_fix', 'action' => 'fact_fix', 'round' => $round, 'status' => $status, 'section_key' => $section,
            'from_version_id' => $from->id, 'to_version_id' => $to?->id, 'claim_ids' => $claimIds, 'diff' => array_slice($diff, 0, 200),
            'proposed_html' => $proposed, 'model' => $model, 'error_code' => $error,
        ])->save();

        return $r;
    }
}
