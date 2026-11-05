<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`publishing_attempts`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $publishing_job_id
 * @property int $attempt_no
 * @property string $operation
 * @property string $outcome
 * @property ?string $error_code
 * @property ?int $http_status
 * @property bool $retryable
 * @property ?string $payload_hash
 * @property ?int $wp_post_id
 * @property ?int $duration_ms
 * @property array<string, mixed>|null $details
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class PublishingAttempt extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'publishing_attempts';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'retryable' => 'boolean',
            'wp_post_id' => 'integer',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'details' => 'array',
        ];
    }
}
