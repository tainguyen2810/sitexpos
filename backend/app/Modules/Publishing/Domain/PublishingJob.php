<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`publishing_jobs`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 */
class PublishingJob extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'publishing_jobs';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'payload_meta' => 'array',
            'gate_results' => 'array',
            'scheduled_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'locked_until' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }
}
