<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Suggested internal link between ideas and existing WordPress content.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $campaign_id
 * @property ?string $cluster_id
 * @property string $from_type
 * @property string $from_id
 * @property string $to_type
 * @property string $to_id
 * @property string $anchor_text
 * @property string $reason
 * @property int $score
 * @property string $status
 * @property string $fingerprint
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class InternalLinkSuggestion extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'internal_link_suggestions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
        ];
    }
}
