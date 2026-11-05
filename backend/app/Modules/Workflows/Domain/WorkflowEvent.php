<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Append-only timeline of a run (transitions, retries, recoveries, cancels).
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $workflow_run_id
 * @property string $event
 * @property string|null $from_status
 * @property string|null $to_status
 * @property array<string, mixed>|null $details
 * @property Carbon $created_at
 */
class WorkflowEvent extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }
}
