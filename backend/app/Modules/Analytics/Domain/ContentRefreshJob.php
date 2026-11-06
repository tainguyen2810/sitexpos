<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`content_refresh_jobs`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $article_id
 * @property ?string $recommendation_id
 * @property ?string $agent_task_id
 * @property string $status
 * @property ?string $active_key
 * @property array<string, mixed>|null $wp_check
 * @property ?string $base_version_id
 * @property ?string $base_content_hash
 * @property array<string, mixed>|null $analysis
 * @property array<string, mixed>|null $brief
 * @property list<string>|null $revision_ids
 * @property ?string $new_version_id
 * @property array<string, mixed>|null $diff
 * @property array<string, mixed>|null $fact_check
 * @property array<string, mixed>|null $seo_audit
 * @property ?string $publishing_job_id
 * @property ?string $experiment_id
 * @property int $estimated_cost_micro_usd
 * @property ?string $error_code
 * @property ?string $error_message
 * @property ?string $requested_by
 * @property ?string $approved_by
 * @property ?Carbon $approved_at
 * @property ?string $idempotency_key
 * @property bool $is_demo
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ContentRefreshJob extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'content_refresh_jobs';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'wp_check' => 'array',
            'analysis' => 'array',
            'brief' => 'array',
            'revision_ids' => 'array',
            'diff' => 'array',
            'fact_check' => 'array',
            'seo_audit' => 'array',
            'estimated_cost_micro_usd' => 'integer',
            'approved_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }
}
