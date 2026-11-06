<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Human approval / rejection of a specific version.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $article_id
 * @property string $article_version_id
 * @property string $decision
 * @property ?string $note
 * @property array<string, mixed>|null $checks
 * @property ?string $user_id
 * @property ?Carbon $invalidated_at
 * @property ?string $invalidated_reason
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class EditorialApproval extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'editorial_approvals';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'invalidated_at' => 'datetime',
        ];
    }
}
