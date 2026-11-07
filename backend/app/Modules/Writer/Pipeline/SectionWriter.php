<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Pipeline;

use SiteXPost\Modules\AiGateway\Gateway\AiGatewayException;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Writer\Ai\BudgetBlocked;
use SiteXPost\Modules\Writer\Ai\WriterAi;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleOutline;
use SiteXPost\Modules\Writer\Domain\ArticleSection;
use SiteXPost\Modules\Writer\Domain\ArticleVersion;
use SiteXPost\Modules\Writer\Domain\ContentBrief;
use SiteXPost\Modules\Writer\Domain\EvidenceItem;
use SiteXPost\Modules\Writer\Support\ArticleDocument;
use SiteXPost\Modules\Writer\Support\HtmlSanitizer;
use SiteXPost\Modules\Writer\Support\PlaceholderDetector;

/**
 * Writes the article section by section (smaller prompts, precise retry):
 * each finished section is checkpointed in `article_sections`, so a retry
 * after a failure continues with the next section and never pays twice for
 * a section that is already written.
 *
 * Citations: the model may only write markers [E<ref>] for evidence of the
 * dossier; they become <a data-evidence-id> links to the real source. An
 * unknown ref is dropped and reported (citation spoofing). Links are limited
 * to the site's own content index.
 */
class SectionWriter
{
    public function __construct(
        private readonly WriterAi $ai,
        private readonly ArticleContext $context,
        private readonly VersionManager $versions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function write(Article $a, ArticleOutline $outline, string $key, ?string $run): array
    {
        $brief = ContentBrief::query()->find($outline->content_brief_id);
        $dossierId = $brief?->research_dossier_id;
        foreach ($outline->sections as $i => $s) {
            if (! ArticleSection::query()->where('outline_id', $outline->id)->where('section_key', $s['key'])->exists()) {
                (new ArticleSection)->forceFill(['outline_id' => $outline->id, 'section_key' => $s['key'], 'article_id' => $a->id, 'position' => $i + 1,
                    'heading' => mb_substr($s['heading'] !== '' ? $s['heading'] : 'Mở đầu', 0, 300), 'level' => 2, 'status' => 'pending'])->save();
            }
        }
        $links = $this->context->linkCandidates($a);
        $written = 0;
        foreach ($outline->sections as $s) {
            /** @var ArticleSection $row */
            $row = ArticleSection::query()->where('outline_id', $outline->id)->where('section_key', $s['key'])->firstOrFail();
            if ($row->status === 'done') {
                continue;
            }
            $row->forceFill(['status' => 'writing', 'attempts' => $row->attempts + 1, 'error_code' => null])->save();
            $done = ArticleSection::query()->where('outline_id', $outline->id)->where('status', 'done')->orderBy('position')->get(['heading', 'content_html']);
            $evidence = $dossierId !== null ? $this->evidenceFor($dossierId, $s) : [];
            $sectionLinks = $s['internal_links'] !== [] ? $s['internal_links'] : array_column(array_slice($links, 0, 4), 'url');
            $ctx = [
                'article' => ['title' => $a->title, 'primary_keyword' => $a->primary_keyword, 'search_intent' => $a->search_intent, 'content_type' => $a->content_type,
                    'audience' => $brief->data['audience'] ?? $a->setting('audience'), 'main_question' => $brief->data['main_question'] ?? null,
                    'angle' => $brief->data['content_angle'] ?? null, 'tone' => $a->setting('tone'), 'cta' => $a->setting('cta')],
                'brand_voice' => $this->context->brandVoice($a),
                'section' => $s,
                'already_written' => $done->map(fn ($d) => ['heading' => $d->heading, 'gist' => mb_substr(Text::clean($d->content_html, 2000), 0, 240)])->all(),
                'evidence' => $evidence,
                'internal_links' => $sectionLinks,
            ];
            $maxOut = self::outputBudget((int) ($s['word_range'][1] ?? 300));
            try {
                $res = $this->ai->section($a, $ctx, $key.':'.$s['key'], $run, $maxOut);
            } catch (AiGatewayException|BudgetBlocked $e) {
                $row->forceFill(['status' => 'failed', 'error_code' => $e instanceof AiGatewayException ? $e->errorCode : 'budget_exhausted'])->save();
                throw $e;
            }
            $json = is_array($res->json) ? $res->json : [];
            $cost = $res->costMicroUsd;
            $placeholder = PlaceholderDetector::scan((string) ($json['html'] ?? ''));
            if ($placeholder['hollow']) {
                // "Cần xác minh" cells / talk about missing sources: one rewrite
                // with the reason, under its own call key (counted separately).
                try {
                    $fix = $this->ai->section($a, $ctx + ['fix_placeholder' => $placeholder['phrases'] ?: ['placeholder_cells']], $key.':'.$s['key'].':fix', $run, $maxOut);
                    $fixed = is_array($fix->json) ? $fix->json : [];
                    $cost += $fix->costMicroUsd;
                    if (trim(strip_tags((string) ($fixed['html'] ?? ''))) !== '') {
                        [$res, $json] = [$fix, $fixed];
                    }
                } catch (AiGatewayException|BudgetBlocked) {
                    // keep the first answer; the approval gate still blocks placeholder text
                }
            }
            [$html, $refs, $notes] = $this->citations((string) ($json['html'] ?? ''), $dossierId);
            if (PlaceholderDetector::scan($html)['hollow']) {
                $notes[] = 'placeholder_content';
            }
            $html = (string) preg_replace('#^\s*<h2\b[^>]*>.*?</h2>#is', '', $html);
            $clean = $this->versions->sanitize($a, $html, [...$sectionLinks, ...array_column($links, 'url')]);
            foreach ($clean['violations'] as $v) {
                $notes[] = $v['type'].': '.$v['value'];
            }
            foreach ((array) ($json['notes'] ?? []) as $n) {
                if (is_string($n) && trim($n) !== '') {
                    $notes[] = Text::clean($n, 300);
                }
            }
            $row->forceFill([
                'status' => 'done', 'content_html' => $clean['html'], 'word_count' => HtmlSanitizer::wordCount($clean['html']),
                'citations' => $refs, 'notes' => array_slice($notes, 0, 20), 'model' => $res->modelKey, 'cost_micro_usd' => $row->cost_micro_usd + $cost,
            ])->save();
            $written++;
        }

        return ['written' => $written, 'sections' => count($outline->sections)];
    }

    /**
     * Output tokens for one section: Vietnamese text costs ~2 tokens per word,
     * plus HTML tags (tables!), [E<ref>] markers, JSON escaping and notes.
     * Only the reservation uses this number; the bill is the real usage.
     * (The old `words × 3, min 600` cut 200-word sections off mid-JSON.)
     */
    public static function outputBudget(int $wordMax): int
    {
        return max(2000, min(8000, $wordMax * 6 + 1000));
    }

    public function assemble(Article $a, ArticleOutline $outline, ?string $userId = null): ArticleVersion
    {
        $rows = ArticleSection::query()->where('outline_id', $outline->id)->orderBy('position')->get()->keyBy('section_key');
        $parts = [];
        $hints = [];
        foreach ($outline->sections as $s) {
            $row = $rows->get($s['key']);
            if ($row === null || $row->status !== 'done') {
                continue;
            }
            $parts[] = ['key' => $s['key'], 'heading' => $s['heading'], 'html' => (string) $row->content_html];
            $hints[] = ['key' => $s['key'], 'heading' => $s['heading']];
        }
        $html = ArticleDocument::assemble($a->title, $parts);
        $models = $rows->pluck('model')->filter()->unique()->implode(', ');

        return $this->versions->create($a, $html, 'ai_draft', $userId, mb_substr($models, 0, 120) ?: null, 'Bản nháp AI từ dàn ý v'.$outline->version, keyHints: $hints);
    }

    /**
     * @param  array<string, mixed>  $s
     * @return list<array<string, mixed>>
     */
    private function evidenceFor(string $dossierId, array $s): array
    {
        $refs = $s['evidence_refs'] ?? [];
        $list = $refs !== [] ? $this->context->evidence($dossierId, $refs, 15, 400) : [];
        if (count($list) < 6) {
            $topic = Text::tokens(($s['heading'] ?? '').' '.implode(' ', $s['key_questions'] ?? []).' '.($s['purpose'] ?? ''));
            $extra = collect($this->context->evidence($dossierId, null, 40, 400))
                ->reject(fn ($e) => in_array($e['ref'], $refs, true))
                ->sortByDesc(fn ($e) => Text::containment($topic, Text::tokens($e['statement'].' '.$e['excerpt'])))
                ->take(6 - count($list))->values()->all();
            $list = [...$list, ...$extra];
        }

        return $list;
    }

    /**
     * [E12] → <a data-evidence-id="…">[12]</a>; unknown refs are removed.
     *
     * @return array{0: string, 1: list<int>, 2: list<string>}
     */
    public function citations(string $html, ?string $dossierId): array
    {
        $map = $dossierId !== null ? EvidenceItem::query()->where('research_dossier_id', $dossierId)->pluck('id', 'ref')->all() : [];
        $used = [];
        $notes = [];
        $html = (string) preg_replace_callback('/\[\s*E\s*(\d{1,4})\s*\]/i', function (array $m) use ($map, &$used, &$notes): string {
            $ref = (int) $m[1];
            if (! isset($map[$ref])) {
                $notes[] = 'unknown_citation_ref: E'.$ref;

                return '';
            }
            $used[$ref] = true;

            return '<sup><a data-evidence-id="'.$map[$ref].'" href="#">['.$ref.']</a></sup>';
        }, $html);

        return [$html, array_keys($used), $notes];
    }
}
