<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Support\ArticleStateMachine;

/**
 * Article summary (lists) — the editor / review screens add their own
 * blocks (version, claims, checklist...) through WriterPresenter.
 *
 * @mixin Article
 */
final class ArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'next_statuses' => array_map(static fn ($s) => $s->value, ArticleStateMachine::next($this->status)),
            'mode' => $this->mode,
            'review_mode' => $this->review_mode,
            'site_id' => $this->site_id,
            'campaign_id' => $this->campaign_id,
            'content_idea_id' => $this->content_idea_id,
            'writing_preset_id' => $this->writing_preset_id,
            'writing_batch_id' => $this->writing_batch_id,
            'primary_keyword' => $this->primary_keyword,
            'secondary_keywords' => $this->secondary_keywords ?? [],
            'search_intent' => $this->search_intent,
            'content_type' => $this->content_type,
            'language' => $this->language,
            'country' => $this->country,
            'settings' => $this->settings ?? [],
            'budget' => [
                'limit_micro_usd' => $this->budget_limit_micro_usd,
                'spent_micro_usd' => $this->spent_micro_usd,
                'remaining_micro_usd' => $this->remainingBudget(),
            ],
            'current_version_id' => $this->current_version_id,
            'approved_version_id' => $this->approved_version_id,
            'revision_rounds' => $this->revision_rounds,
            'unresolved_critical' => $this->unresolved_critical,
            'unresolved_high' => $this->unresolved_high,
            'quality_score' => $this->quality_score,
            'duplicate_check' => $this->duplicate_check,
            'duplicate_decision' => $this->duplicate_decision,
            'blocked_reason' => $this->blocked_reason,
            'last_error' => $this->last_error_code !== null ? ['code' => $this->last_error_code, 'message' => $this->last_error_message] : null,
            'status_before_failure' => $this->status_before_failure,
            'lock_version' => $this->lock_version,
            'has_autosave' => $this->autosaved_at !== null,
            'autosaved_at' => $this->autosaved_at?->toIso8601ZuluString(),
            'is_demo' => $this->is_demo,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601ZuluString(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
