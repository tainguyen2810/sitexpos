<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Support;

use Illuminate\Support\Facades\DB;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Enums\ArticleStatus as S;

/**
 * The only place that changes `articles.status`. Every transition is a
 * compare-and-set on the current status (no lost update when two workers or
 * a worker and a user race), and APPROVED is reachable only through
 * ApproveArticle, which checks the approval gate inside the same lock.
 */
final class ArticleStateMachine
{
    /** @var array<string, list<S>> */
    private const ALLOWED = [
        'IDEA_SELECTED' => [S::Researching, S::Cancelled],
        'RESEARCHING' => [S::ResearchComplete, S::Failed, S::Cancelled],
        'RESEARCH_COMPLETE' => [S::BriefReady, S::Researching, S::Failed, S::Cancelled],
        'BRIEF_READY' => [S::Outlining, S::Researching, S::Cancelled],
        'OUTLINING' => [S::Writing, S::BriefReady, S::Researching, S::Failed, S::Cancelled],
        'WRITING' => [S::FactChecking, S::Failed, S::Cancelled],
        'FACT_CHECKING' => [S::Revising, S::QualityReview, S::NeedsHumanReview, S::Failed, S::Cancelled],
        'REVISING' => [S::FactChecking, S::NeedsHumanReview, S::Failed, S::Cancelled],
        'QUALITY_REVIEW' => [S::NeedsHumanReview, S::Failed, S::Cancelled],
        'NEEDS_HUMAN_REVIEW' => [S::Approved, S::FactChecking, S::Writing, S::Outlining, S::Revising, S::Cancelled],
        'APPROVED' => [S::NeedsHumanReview, S::FactChecking, S::Revising, S::Cancelled],
        'FAILED' => [S::Researching, S::Outlining, S::Writing, S::FactChecking, S::Revising, S::QualityReview, S::NeedsHumanReview, S::Cancelled],
        'CANCELLED' => [],
    ];

    public static function allows(S $from, S $to): bool
    {
        return in_array($to, self::ALLOWED[$from->value], true);
    }

    /**
     * @return list<S>
     */
    public static function next(S $from): array
    {
        return self::ALLOWED[$from->value];
    }

    /**
     * Transition (CAS). Returns false when the article is no longer in an
     * allowed source state.
     *
     * @param  array<string, mixed>  $attributes  extra columns to write atomically
     *
     * @throws InvalidArticleTransition when the transition is never allowed
     */
    public function transition(Article $article, S $to, array $attributes = []): bool
    {
        $from = $article->status;
        if ($from === $to && $attributes === []) {
            return true;
        }
        if ($from !== $to && ! self::allows($from, $to)) {
            throw new InvalidArticleTransition($from, $to);
        }
        if ($to === S::Approved && ! ($attributes['__approval_gate'] ?? false)) {
            throw new InvalidArticleTransition($from, $to, 'APPROVED is only set by ApproveArticle.');
        }
        unset($attributes['__approval_gate']);

        $values = $attributes + ['status' => $to->value, 'updated_at' => now()];
        if ($to === S::Failed && $from !== S::Failed) {
            $values['status_before_failure'] = $from->value;
        }
        $updated = DB::table('articles')->where('id', $article->id)->where('status', $from->value)->update($values);
        if ($updated === 0) {
            return false;
        }
        $article->refresh();

        return true;
    }

    /**
     * Transition from whatever the current status is, if allowed (used by
     * workflow steps after a refresh).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function move(Article $article, S $to, array $attributes = []): bool
    {
        $article->refresh();
        if ($article->status === $to) {
            if ($attributes !== []) {
                $article->forceFill($attributes)->save();
            }

            return true;
        }
        if (! self::allows($article->status, $to)) {
            return false;
        }

        return $this->transition($article, $to, $attributes);
    }
}
