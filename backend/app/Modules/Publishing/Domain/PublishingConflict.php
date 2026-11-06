<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`publishing_conflicts`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $article_id
 * @property string $mapping_id
 * @property ?string $publishing_job_id
 * @property array<string, mixed> $types
 * @property string $status
 * @property ?string $resolution
 * @property array<string, mixed>|null $wp_state
 * @property array<string, mixed>|null $expected_state
 * @property ?string $note
 * @property ?string $resolved_by
 * @property ?Carbon $resolved_at
 * @property bool $is_demo
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class PublishingConflict extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'publishing_conflicts';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'types' => 'array',
            'wp_state' => 'array',
            'expected_state' => 'array',
            'resolved_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }
}
