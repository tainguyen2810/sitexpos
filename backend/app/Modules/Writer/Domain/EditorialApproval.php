<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Human approval / rejection of a specific version.
 *
 * @property string $id
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
