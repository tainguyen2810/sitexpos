<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Pipeline;

use Illuminate\Database\QueryException;
use SiteXPost\Modules\AiGateway\Gateway\AiGatewayException;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Writer\Ai\WriterAi;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleClaim;
use SiteXPost\Modules\Writer\Domain\ArticleVersion;
use SiteXPost\Modules\Writer\Support\ArticleDocument;
use SiteXPost\Modules\Writer\Support\HtmlSanitizer;
use SiteXPost\Modules\Writer\Support\Verbatim;

/**
 * Splits a version into sections and extracts factual claims per section
 * (AI + deterministic safety net). A claim is kept only if its quote is
 * really in the section text (the model cannot invent claims the article
 * does not make). Idempotent per (version, normalised claim).
 */
class ClaimExtractor
{
    public function __construct(private readonly WriterAi $ai) {}

    /**
     * @param  list<string>|null  $onlyKeys  sections to (re)extract; null = sections not yet checked
     * @return array<string, mixed>
     */
    public function extract(Article $a, ArticleVersion $v, ?array $onlyKeys, string $key, ?string $run): array
    {
        $sections = ArticleDocument::split($v->content_html, $v->section_hashes ?? []);
        $checked = collect($v->section_hashes ?? [])->filter(fn ($s) => (bool) ($s['claims_checked'] ?? false))->pluck('key')->flip()->all();
        $stats = ['sections' => 0, 'ai' => 0, 'rule' => 0, 'dropped_unanchored' => 0, 'warnings' => []];

        foreach ($sections as $s) {
            $want = $onlyKeys !== null ? in_array($s['key'], $onlyKeys, true) : ! isset($checked[$s['key']]);
            if (! $want || trim($s['text']) === '') {
                continue;
            }
            $stats['sections']++;
            $items = [];
            $model = null;
            try {
                $res = $this->ai->claims($a, $s['text'], $key.':'.$s['key'], $run);
                $model = $res->modelKey;
                foreach (array_slice((array) ($res->json['claims'] ?? []), 0, 40) as $c) {
                    $quote = (string) ($c['quote'] ?? '');
                    if (! Verbatim::contains($s['text'], $quote, 10)) {
                        $stats['dropped_unanchored']++;

                        continue;
                    }
                    $items[] = ['origin' => 'ai'] + $c;
                }
            } catch (AiGatewayException $e) {
                $stats['warnings'][] = 'claims_ai_failed:'.$e->errorCode;
            }
            // Safety net: deterministic claims for sentences the model skipped.
            $covered = array_map(static fn ($c) => Verbatim::norm((string) $c['quote']), $items);
            // Headings are questions / labels, not claims.
            $body = HtmlSanitizer::text((string) preg_replace('#<h[1-6]\b[^>]*>.*?</h[1-6]>#is', "\n", $s['html']));
            foreach (RuleClaims::extract($body) as $rc) {
                $q = Verbatim::norm($rc['quote']);
                $dup = false;
                foreach ($covered as $cq) {
                    if ($cq !== '' && (str_contains($q, $cq) || str_contains($cq, $q))) {
                        $dup = true;
                        break;
                    }
                }
                if (! $dup) {
                    $items[] = ['origin' => 'rule'] + $rc;
                }
            }

            foreach ($items as $c) {
                $claimText = Text::clean($c['claim'] ?? $c['quote'], 1000);
                if ($claimText === '') {
                    continue;
                }
                $category = in_array($c['category'] ?? '', WriterAi::CATEGORIES, true) ? $c['category'] : 'other';
                $row = new ArticleClaim;
                $row->forceFill([
                    'article_id' => $a->id, 'article_version_id' => $v->id, 'section_key' => $s['key'],
                    'claim_text' => $claimText, 'claim_hash' => sha1(Verbatim::norm($claimText)),
                    'category' => $category, 'risk_level' => RuleClaims::risk($category, $c['risk'] ?? null)->value,
                    'evidence_requirements' => Text::clean($c['evidence_requirements'] ?? RuleClaims::requirement($category), 500) ?: null,
                    'origin' => $c['origin'], 'extracted_by_model' => $c['origin'] === 'ai' ? $model : 'rules', 'extracted_at' => now(), 'status' => 'PENDING',
                ]);
                try {
                    $row->save();
                    $stats[$c['origin']]++;
                } catch (QueryException) {
                    // same claim already extracted for this version (replay)
                }
            }
        }

        return $stats;
    }
}
