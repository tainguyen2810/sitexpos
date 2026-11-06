<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Pipeline;

use SiteXPost\Modules\AiGateway\Gateway\AiGatewayException;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Writer\Ai\WriterAi;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ContentBrief;
use SiteXPost\Modules\Writer\Domain\EvidenceItem;
use SiteXPost\Modules\Writer\Domain\ResearchDossier;
use SiteXPost\Modules\Writer\Domain\ResearchQuestion;
use SiteXPost\Modules\Writer\Support\HtmlSanitizer;

/**
 * Content Brief from idea + research dossier. AI drafts it; the system
 * enforces: key facts only with existing evidence refs (else → missing
 * information), internal links only from the site index, no keyword-stuffed
 * headings. Without AI a rule-based brief is produced from the dossier.
 */
class BriefBuilder
{
    public function __construct(
        private readonly WriterAi $ai,
        private readonly ArticleContext $context,
    ) {}

    public function build(Article $a, ResearchDossier $d, string $key, ?string $run): ContentBrief
    {
        $existing = ContentBrief::query()->where('article_id', $a->id)->where('research_dossier_id', $d->id)->first();
        if ($existing !== null) {
            return $existing; // idempotent replay
        }
        $links = $this->context->linkCandidates($a);
        $evidence = $this->context->evidence($d->id, null, 30, 300);
        $questions = ResearchQuestion::query()->where('research_dossier_id', $d->id)->orderBy('position')->get(['question', 'status']);
        $ctx = $this->context->base($a) + [
            'idea' => ['angle' => $a->setting('angle'), 'rationale' => $a->setting('rationale'), 'outline' => $a->setting('idea_outline', [])],
            'research_questions' => $questions->map(fn ($q) => ['question' => $q->question, 'has_evidence' => $q->status === 'answered'])->all(),
            'evidence' => $evidence,
            'internal_link_candidates' => $links,
        ];

        $model = null;
        $warnings = [];
        try {
            $res = $this->ai->brief($a, $ctx, $key, $run);
            $raw = is_array($res->json) ? $res->json : [];
            $model = $res->modelKey;
        } catch (AiGatewayException $e) {
            $raw = $this->ruleBased($a, $evidence, $questions->all());
            $warnings[] = 'ai_unavailable_rule_based:'.$e->errorCode;
        }
        [$data, $w] = self::validate($a, $raw, $d->id, array_column($links, 'url'));

        $brief = new ContentBrief;
        $brief->forceFill([
            'article_id' => $a->id, 'research_dossier_id' => $d->id,
            'version' => (int) ContentBrief::query()->where('article_id', $a->id)->max('version') + 1,
            'status' => 'draft', 'data' => $data, 'warnings' => [...$warnings, ...$w], 'generated_by_model' => $model,
        ])->save();

        return $brief;
    }

    /**
     * Normalise + enforce the brief rules (also used when a user edits it).
     *
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $allowedLinks
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function validate(Article $a, array $raw, ?string $dossierId, array $allowedLinks): array
    {
        $warnings = [];
        $refs = $dossierId !== null ? array_flip(EvidenceItem::query()->where('research_dossier_id', $dossierId)->pluck('ref')->all()) : [];
        $list = static fn (mixed $v, int $max = 20, int $len = 300): array => array_values(array_filter(array_map(static fn ($x) => Text::clean($x, $len), array_slice(is_array($v) ? $v : [], 0, $max))));

        $missing = $list($raw['missing_information'] ?? [], 30, 500);
        $facts = [];
        foreach (array_slice(is_array($raw['key_facts'] ?? null) ? $raw['key_facts'] : [], 0, 40) as $f) {
            $text = Text::clean($f['fact'] ?? '', 500);
            if ($text === '') {
                continue;
            }
            $valid = array_values(array_filter(array_map('intval', (array) ($f['evidence_refs'] ?? [])), static fn (int $r): bool => isset($refs[$r])));
            if ($valid === []) {
                $missing[] = 'Chưa có bằng chứng: '.$text;
                $warnings[] = 'fact_without_evidence';

                continue;
            }
            $facts[] = ['fact' => $text, 'evidence_refs' => $valid];
        }

        $structure = [];
        foreach (array_slice(is_array($raw['structure'] ?? null) ? $raw['structure'] : [], 0, 30) as $s) {
            $h = Text::clean($s['heading'] ?? '', 200);
            if ($h !== '') {
                $structure[] = ['heading' => $h, 'level' => in_array((int) ($s['level'] ?? 2), [1, 2, 3], true) ? (int) $s['level'] : 2, 'purpose' => Text::clean($s['purpose'] ?? '', 300)];
            }
        }
        $stuffed = self::stuffedHeadings(array_column($structure, 'heading'), $a->primary_keyword);
        if ($stuffed !== []) {
            $warnings[] = 'heading_keyword_stuffing';
        }

        $allowed = array_flip(array_map([HtmlSanitizer::class, 'normalizeUrl'], $allowedLinks));
        $links = [];
        foreach (array_slice(is_array($raw['internal_links'] ?? null) ? $raw['internal_links'] : [], 0, 15) as $l) {
            $url = trim((string) ($l['url'] ?? ''));
            if ($url !== '' && isset($allowed[HtmlSanitizer::normalizeUrl($url)])) {
                $links[] = ['url' => $url, 'anchor' => Text::clean($l['anchor'] ?? '', 120)];
            } elseif ($url !== '') {
                $warnings[] = 'internal_link_not_in_index';
            }
        }

        $intent = in_array($raw['search_intent'] ?? null, ['informational', 'commercial', 'transactional', 'navigational'], true) ? $raw['search_intent'] : $a->search_intent;
        $titles = $list($raw['title_suggestions'] ?? [], 6, 200);

        return [[
            'title_suggestions' => $titles !== [] ? $titles : [$a->title],
            'search_intent' => $intent,
            'audience' => Text::clean($raw['audience'] ?? ($a->setting('audience') ?? ''), 300),
            'main_question' => Text::clean($raw['main_question'] ?? '', 300),
            'supporting_questions' => $list($raw['supporting_questions'] ?? [], 15),
            'primary_keyword' => $a->primary_keyword,
            'secondary_keywords' => $a->secondary_keywords ?? [],
            'content_angle' => Text::clean($raw['content_angle'] ?? '', 800),
            'differentiation' => Text::clean($raw['differentiation'] ?? '', 800),
            'structure' => $structure,
            'key_facts' => $facts,
            'required_evidence' => $list($raw['required_evidence'] ?? [], 20, 300),
            'internal_links' => $links,
            'faq' => $list($raw['faq'] ?? [], 10),
            'risks' => $list($raw['risks'] ?? [], 15, 300),
            'missing_information' => array_values(array_unique($missing)),
            'stuffed_headings' => $stuffed,
        ], array_values(array_unique($warnings))];
    }

    /**
     * Headings that only exist to repeat the keyword: more than half of the
     * headings contain the exact keyword (with ≥ 3 headings), or a heading
     * repeats it twice.
     *
     * @param  list<string>  $headings
     * @return list<string>
     */
    public static function stuffedHeadings(array $headings, string $keyword): array
    {
        $kw = mb_strtolower(trim($keyword));
        if ($kw === '' || $headings === []) {
            return [];
        }
        $with = array_values(array_filter($headings, static fn (string $h): bool => mb_substr_count(mb_strtolower($h), $kw) > 0));
        $twice = array_values(array_filter($headings, static fn (string $h): bool => mb_substr_count(mb_strtolower($h), $kw) > 1));
        if (count($headings) >= 3 && count($with) > count($headings) / 2) {
            return array_values(array_unique([...array_slice($with, 1), ...$twice]));
        }

        return $twice;
    }

    /**
     * @param  list<array<string, mixed>>  $evidence
     * @param  list<ResearchQuestion>  $questions
     * @return array<string, mixed>
     */
    private function ruleBased(Article $a, array $evidence, array $questions): array
    {
        $structure = [];
        foreach ($questions as $q) {
            $structure[] = ['heading' => rtrim($q->question, '?'), 'level' => 2, 'purpose' => 'Trả lời câu hỏi nghiên cứu'];
        }

        return [
            'title_suggestions' => [$a->title], 'search_intent' => $a->search_intent, 'audience' => (string) ($a->setting('audience') ?? ''),
            'main_question' => $questions[0]->question ?? $a->title, 'supporting_questions' => array_map(fn ($q) => $q->question, array_slice($questions, 1)),
            'content_angle' => (string) ($a->setting('angle') ?? ''), 'differentiation' => '', 'structure' => $structure,
            'key_facts' => array_map(fn ($e) => ['fact' => $e['statement'], 'evidence_refs' => [$e['ref']]], array_slice($evidence, 0, 12)),
            'required_evidence' => [], 'faq' => [], 'internal_links' => [], 'risks' => ['Brief tạo theo quy tắc vì AI không khả dụng — cần biên tập viên rà soát.'],
            'missing_information' => array_map(fn ($q) => 'Chưa có bằng chứng: '.$q->question, array_filter($questions, fn ($q) => $q->status !== 'answered')),
        ];
    }
}
