<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Planned publication slot for an idea. Never creates or publishes content
 * by itself (that is Phase 5, behind explicit switches).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $campaign_id
 * @property string $content_idea_id
 * @property ?string $cluster_id
 * @property Carbon $scheduled_for
 * @property int $position
 * @property string $status
 * @property string $source
 * @property ?string $notes
 * @property ?string $approved_by
 * @property ?Carbon $approved_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ContentCalendarItem extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'content_calendar_items';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'date',
            'position' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ContentIdea, $this>
     */
    public function idea(): BelongsTo
    {
        return $this->belongsTo(ContentIdea::class, 'content_idea_id');
    }
}
