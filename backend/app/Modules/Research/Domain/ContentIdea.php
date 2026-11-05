<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * A proposed article (title, keywords, intent, angle, rationale, evidence).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $campaign_id
 * @property ?string $research_run_id
 * @property ?string $cluster_id
 * @property ?string $primary_keyword_id
 * @property string $title
 * @property string $search_intent
 * @property string $content_type
 * @property ?string $audience
 * @property ?string $angle
 * @property string $priority
 * @property ?int $opportunity_score
 * @property array<string, mixed>|null $score_breakdown
 * @property ?string $rationale
 * @property array<string, mixed>|null $evidence
 * @property string $source
 * @property bool $is_pillar
 * @property string $status
 * @property string $fingerprint
 * @property ?string $overlap_content_item_id
 * @property ?int $overlap_score
 * @property array<string, mixed>|null $brief
 * @property ?Carbon $briefed_at
 * @property ?string $decided_by
 * @property ?Carbon $decided_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ContentIdea extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'content_ideas';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'score_breakdown' => 'array',
            'evidence' => 'array',
            'brief' => 'array',
            'is_pillar' => 'boolean',
            'opportunity_score' => 'integer',
            'overlap_score' => 'integer',
            'briefed_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Keyword, $this>
     */
    public function primaryKeyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'primary_keyword_id');
    }

    /**
     * @return BelongsTo<TopicCluster, $this>
     */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(TopicCluster::class, 'cluster_id');
    }

    /**
     * @return BelongsToMany<Keyword, $this>
     */
    public function keywords(): BelongsToMany
    {
        return $this->belongsToMany(Keyword::class, 'content_idea_keywords')->withPivot('role');
    }

    /**
     * @return HasOne<ContentCalendarItem, $this>
     */
    public function calendarItem(): HasOne
    {
        return $this->hasOne(ContentCalendarItem::class);
    }
}
