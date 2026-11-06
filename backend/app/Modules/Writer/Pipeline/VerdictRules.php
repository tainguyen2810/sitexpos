<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Pipeline;

use SiteXPost\Modules\Writer\Enums\RiskLevel;
use SiteXPost\Modules\Writer\Enums\Verdict;
use SiteXPost\Modules\Writer\Support\Identifiers;

/**
 * Deterministic verdict policy applied on top of the model's opinion
 * (FACT-CHECK-POLICY.md §4). The model proposes; these rules decide:
 * - VERIFIED needs ≥ 1 real supporting excerpt that contains every exact
 *   identifier of the claim (model, version, number + unit) and no
 *   contradicting excerpt; HIGH/CRITICAL also need a reliable source;
 * - CONTRADICTED needs a real contradicting excerpt — "not found" is
 *   INSUFFICIENT_EVIDENCE, never false;
 * - conflicting evidence → NEEDS_HUMAN_REVIEW;
 * - model confidence is never used.
 */
final class VerdictRules
{
    public const TIME_SENSITIVE = ['price', 'version', 'release_date', 'legal'];

    /**
     * @param  list<array{ref: int, excerpt: string, reliability: int|null, date: string|null}>  $supporting  valid evidence only
     * @param  list<array{ref: int, excerpt: string, reliability: int|null, date: string|null}>  $contradicting
     * @return array{verdict: Verdict, notes: list<string>}
     */
    public static function decide(string $claim, string $category, RiskLevel $risk, ?string $proposed, array $supporting, array $contradicting,
        int $minReliability = 60, int $outdatedMonths = 24): array
    {
        $notes = [];
        if ($proposed === null) {
            return ['verdict' => $supporting === [] && $contradicting === [] ? Verdict::InsufficientEvidence : Verdict::NeedsHumanReview,
                'notes' => ['no_model_review']];
        }
        if ($supporting !== [] && $contradicting !== []) {
            return ['verdict' => Verdict::NeedsHumanReview, 'notes' => ['conflicting_evidence']];
        }

        switch ($proposed) {
            case 'VERIFIED':
                if ($supporting === []) {
                    return ['verdict' => Verdict::InsufficientEvidence, 'notes' => ['model_verified_without_valid_evidence']];
                }
                $missing = Identifiers::missing($claim, array_column($supporting, 'excerpt'));
                if ($missing !== []) {
                    return ['verdict' => Verdict::NeedsHumanReview, 'notes' => ['identifier_not_in_evidence: '.implode(', ', array_slice($missing, 0, 5))]];
                }
                $best = max(array_map(static fn ($e) => (int) ($e['reliability'] ?? 0), $supporting));
                if ($risk->weight() >= RiskLevel::High->weight() && $best < $minReliability) {
                    return ['verdict' => Verdict::NeedsHumanReview, 'notes' => ['low_reliability_for_high_risk: '.$best]];
                }
                if (in_array($category, self::TIME_SENSITIVE, true)) {
                    $dates = array_filter(array_column($supporting, 'date'));
                    $cut = strtotime("-{$outdatedMonths} months");
                    if ($dates !== [] && max(array_map('strtotime', $dates)) < $cut) {
                        return ['verdict' => Verdict::Outdated, 'notes' => ['evidence_older_than_'.$outdatedMonths.'_months']];
                    }
                    if ($dates === []) {
                        $notes[] = 'evidence_undated';
                    }
                }

                return ['verdict' => Verdict::Verified, 'notes' => $notes];

            case 'CONTRADICTED':
                return $contradicting === []
                    ? ['verdict' => Verdict::InsufficientEvidence, 'notes' => ['not_found_is_not_false']]
                    : ['verdict' => Verdict::Contradicted, 'notes' => []];

            case 'OUTDATED':
                return $supporting === [] && $contradicting === []
                    ? ['verdict' => Verdict::InsufficientEvidence, 'notes' => ['outdated_without_evidence']]
                    : ['verdict' => Verdict::Outdated, 'notes' => []];

            case 'NEEDS_HUMAN_REVIEW':
                return ['verdict' => Verdict::NeedsHumanReview, 'notes' => []];

            default:
                return ['verdict' => Verdict::InsufficientEvidence, 'notes' => []];
        }
    }
}
