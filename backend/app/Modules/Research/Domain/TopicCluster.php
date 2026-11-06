<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Pillar/cluster group of keywords sharing a topic and a compatible intent.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $campaign_id
 * @property string $name
 * @property string $fingerprint
 * @property string $intent
 * @property ?string $pillar_keyword_id
 * @property ?string $pillar_content_item_id
 * @property ?string $description
 * @property ?int $opportunity_score
 * @property int $keywords_count
 * @property array<string, mixed>|null $coverage
 * @property string $status
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class TopicCluster extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'topic_clusters';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'coverage' => 'array',
            'opportunity_score' => 'integer',
            'keywords_count' => 'integer',
        ];
    }

    /**
     * @return HasMany<Keyword, $this>
     */
    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class, 'cluster_id');
    }

    /**
     * @return HasMany<ContentIdea, $this>
     */
    public function ideas(): HasMany
    {
        return $this->hasMany(ContentIdea::class, 'cluster_id');
    }

    /**
     * @return BelongsTo<Keyword, $this>
     */
    public function pillarKeyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'pillar_keyword_id');
    }
}
