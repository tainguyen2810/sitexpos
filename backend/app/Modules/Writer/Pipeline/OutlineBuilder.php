<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Pipeline;

use SiteXPost\Modules\AiGateway\Gateway\AiGatewayException;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Writer\Ai\WriterAi;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleOutline;
use SiteXPost\Modules\Writer\Domain\ContentBrief;
use SiteXPost\Modules\Writer\Domain\EvidenceItem;
use SiteXPost\Modules\Writer\Support\HtmlSanitizer;

/**
 * Structured outline from the APPROVED brief. Sections are H2 blocks
 * (H3 items become sub-headings of the preceding H2) so every section maps
 * to one block of the final HTML. Facts need evidence refs; a "specs" block
 * without evidence is removed — no invented product specifications.
 */
class OutlineBuilder
{
    public function __construct(
        private readonly WriterAi $ai,
        private readonly ArticleContext $context,
    ) {}

    public function build(Article $a, ContentBrief $brief, string $key, ?string $run): ArticleOutline
    {
        $links = $this->context->linkCandidates($a);
        $ctx = [
            'brief' => $brief->data, 'target_words' => (int) $a->setting('target_words', 1500), 'content_type' => $a->content_type,
            'tone' => $a->setting('tone'), 'evidence' => $brief->research_dossier_id !== null ? $this->context->evidence($brief->research_dossier_id, null, 40, 160) : [],
            'internal_link_candidates' => $links, 'cta_goal' => $a->setting('cta'),
        ];
        $model = null;
        $warnings = [];
        try {
            $res = $this->ai->outline($a, $ctx, $key, $run);
            $raw = (array) ($res->json['sections'] ?? []);
            $model = $res->modelKey;
        } catch (AiGatewayException $e) {
            $raw = array_map(fn (array $s) => $s + ['key_questions' => [], 'required_facts' => [], 'word_min' => 150, 'word_max' => 300, 'internal_links' => [], 'blocks' => ['paragraphs']],
                $brief->data['structure'] ?? []);
            $warnings[] = 'ai_unavailable_rule_based:'.$e->errorCode;
        }
        [$sections, $w] = self::normalize($a, $raw, $brief->research_dossier_id, array_column($links, 'url'));

        $outline = new ArticleOutline;
        $outline->forceFill([
            'article_id' => $a->id, 'content_brief_id' => $brief->id,
            'version' => (int) ArticleOutline::query()->where('article_id', $a->id)->max('version') + 1,
            'status' => 'draft', 'sections' => $sections, 'warnings' => [...$warnings, ...$w], 'generated_by_model' => $model,
        ])->save();

        return $outline;
    }

    /**
     * @param  list<array<string, mixed>>  $raw
     * @param  list<string>  $allowedLinks
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    public static function normalize(Article $a, array $raw, ?string $dossierId, array $allowedLinks): array
    {
        $refs = $dossierId !== null ? array_flip(EvidenceItem::query()->where('research_dossier_id', $dossierId)->pluck('ref')->all()) : [];
        $allowed = array_flip(array_map([HtmlSanitizer::class, 'normalizeUrl'], $allowedLinks));
        $warnings = [];
        $sections = [];
        foreach (array_slice($raw, 0, 30) as $s) {
            $heading = Text::clean($s['heading'] ?? '', 200);
            if ($heading === '' && ($s['key'] ?? null) !== 'intro') {
                continue;
            }
            $level = (int) ($s['level'] ?? 2);
            if ($level >= 3 && $sections !== []) {
                $sections[count($sections) - 1]['subheadings'][] = $heading;
                $sections[count($sections) - 1]['key_questions'] = [...$sections[count($sections) - 1]['key_questions'], ...self::strings($s['key_questions'] ?? [], 5)];

                continue;
            }
            $facts = [];
            foreach (array_slice((array) ($s['required_facts'] ?? []), 0, 12) as $f) {
                $text = Text::clean(is_array($f) ? ($f['fact'] ?? '') : $f, 300);
                if ($text === '') {
                    continue;
                }
                $valid = array_values(array_filter(array_map('intval', (array) (is_array($f) ? ($f['evidence_refs'] ?? []) : [])), static fn (int $r): bool => isset($refs[$r])));
                $facts[] = ['fact' => $text, 'evidence_refs' => $valid, 'needs_evidence' => $valid === []];
            }
            $evidenceRefs = array_values(array_unique(array_merge(...array_map(static fn ($f) => $f['evidence_refs'], $facts ?: [['evidence_refs' => []]]))));
            $blocks = array_values(array_intersect(self::strings($s['blocks'] ?? ['paragraphs'], 8), WriterAi::BLOCKS)) ?: ['paragraphs'];
            if (in_array('specs', $blocks, true) && $evidenceRefs === []) {
                $blocks = array_values(array_diff($blocks, ['specs']));
                $warnings[] = 'specs_block_without_evidence_removed';
            }
            $links = array_values(array_filter(self::strings($s['internal_links'] ?? [], 5, 1000), static fn (string $u): bool => isset($allowed[HtmlSanitizer::normalizeUrl($u)])));
            $range = is_array($s['word_range'] ?? null) ? array_values($s['word_range']) : [];
            $min = max(40, min(1500, (int) ($s['word_min'] ?? $range[0] ?? 120)));
            $max = max($min, min(2500, (int) ($s['word_max'] ?? $range[1] ?? $min * 2)));
            $sections[] = [
                'key' => ($s['key'] ?? null) === 'intro' ? 'intro' : 's'.(count($sections) + 1),
                'heading' => $heading, 'level' => 2, 'purpose' => Text::clean($s['purpose'] ?? '', 400),
                'key_questions' => self::strings($s['key_questions'] ?? [], 6), 'required_facts' => $facts, 'evidence_refs' => $evidenceRefs,
                'word_range' => [$min, $max], 'internal_links' => $links, 'blocks' => $blocks, 'subheadings' => self::strings($s['subheadings'] ?? [], 10, 200),
            ];
        }
        if ($sections === [] || $sections[0]['key'] !== 'intro') {
            array_unshift($sections, ['key' => 'intro', 'heading' => '', 'level' => 2, 'purpose' => 'Mở đầu: trả lời nhanh câu hỏi chính, nêu người đọc sẽ nhận được gì',
                'key_questions' => [], 'required_facts' => [], 'evidence_refs' => [], 'word_range' => [60, 150], 'internal_links' => [], 'blocks' => ['paragraphs'], 'subheadings' => []]);
        }
        // Stable keys after the intro insert.
        $n = 0;
        foreach ($sections as $i => $s) {
            if ($s['key'] !== 'intro') {
                $sections[$i]['key'] = 's'.(++$n);
            }
        }
        $target = (int) $a->setting('target_words', 1500);
        $sum = array_sum(array_map(static fn ($s) => $s['word_range'][1], $sections));
        if ($sum > $target * 1.6) {
            $warnings[] = 'outline_longer_than_target';
        }
        if (count(array_filter($sections, static fn ($s) => collect($s['required_facts'])->contains('needs_evidence', true))) > 0) {
            $warnings[] = 'facts_need_evidence';
        }
        $stuffed = BriefBuilder::stuffedHeadings(array_values(array_filter(array_column($sections, 'heading'))), $a->primary_keyword);
        if ($stuffed !== []) {
            $warnings[] = 'heading_keyword_stuffing';
        }

        return [$sections, array_values(array_unique($warnings))];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $v, int $max, int $len = 300): array
    {
        return array_values(array_filter(array_map(static fn ($x) => Text::clean($x, $len), array_slice(is_array($v) ? $v : [], 0, $max))));
    }
}
