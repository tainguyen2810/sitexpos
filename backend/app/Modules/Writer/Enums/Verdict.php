<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Enums;

/**
 * Fact-check verdicts (FACT-CHECK-POLICY.md §3). PENDING = not checked yet.
 */
enum Verdict: string
{
    case Pending = 'PENDING';
    case Verified = 'VERIFIED';
    case Contradicted = 'CONTRADICTED';
    case InsufficientEvidence = 'INSUFFICIENT_EVIDENCE';
    case Outdated = 'OUTDATED';
    case NeedsHumanReview = 'NEEDS_HUMAN_REVIEW';

    public function isResolved(): bool
    {
        return $this === self::Verified;
    }

    /**
     * @return list<string>
     */
    public static function checkable(): array
    {
        return [self::Verified->value, self::Contradicted->value, self::InsufficientEvidence->value, self::Outdated->value, self::NeedsHumanReview->value];
    }
}
