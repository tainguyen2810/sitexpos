<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`publishing_jobs`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $article_id
 * @property ?string $campaign_id
 * @property ?string $publishing_batch_id
 * @property ?string $mapping_id
 * @property string $mode
 * @property string $status
 * @property ?string $active_key
 * @property ?string $article_version_id
 * @property ?string $content_hash
 * @property ?string $rendered_html
 * @property array<string, mixed>|null $payload_meta
 * @property list<array<string, mixed>>|null $gate_results
 * @property ?Carbon $scheduled_at
 * @property string $wp_target_status
 * @property int $attempts
 * @property int $max_attempts
 * @property ?Carbon $next_attempt_at
 * @property ?string $last_error_code
 * @property ?string $last_error_message
 * @property ?string $blocked_reason
 * @property ?string $idempotency_key
 * @property ?string $approval_source
 * @property ?string $approved_by
 * @property ?Carbon $approved_at
 * @property ?Carbon $published_at
 * @property ?Carbon $locked_until
 * @property bool $is_demo
 * @property ?string $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
