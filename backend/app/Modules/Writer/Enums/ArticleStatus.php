<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Enums;

/**
 * Article workflow states (AI-WRITER-PIPELINE.md §2). Transitions are only
 * made through ArticleStateMachine.
 */
enum ArticleStatus: string
{
    case IdeaSelected = 'IDEA_SELECTED';
    case Researching = 'RESEARCHING';
    case ResearchComplete = 'RESEARCH_COMPLETE';
    case BriefReady = 'BRIEF_READY';
    case Outlining = 'OUTLINING';
    case Writing = 'WRITING';
    case FactChecking = 'FACT_CHECKING';
    case Revising = 'REVISING';
    case QualityReview = 'QUALITY_REVIEW';
    case NeedsHumanReview = 'NEEDS_HUMAN_REVIEW';
    case Approved = 'APPROVED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';

    public function isTerminal(): bool
    {
        return $this === self::Cancelled;
    }

    /**
     * States in which the content is being produced by a workflow (the
     * editor is read-only meanwhile).
     */
    public function isBusy(): bool
    {
        return in_array($this, [self::Researching, self::Writing, self::FactChecking, self::Revising, self::QualityReview], true);
    }

    public function hasContent(): bool
    {
        return in_array($this, [self::FactChecking, self::Revising, self::QualityReview, self::NeedsHumanReview, self::Approved], true);
    }
}
