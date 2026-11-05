<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * A topic the site has not covered (or covers with overlap/cannibalization).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $campaign_id
 * @property ?string $research_run_id
 * @property ?string $cluster_id
 * @property ?string $competitor_website_id
 * @property string $gap_type
 * @property string $topic
 * @property ?string $description
 * @property array<string, mixed>|null $evidence
 * @property list<string>|null $related_content_item_ids
 * @property ?int $score
 * @property string $status
 * @property string $fingerprint
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ContentGap extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'content_gaps';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'related_content_item_ids' => 'array',
            'score' => 'integer',
        ];
    }
}
