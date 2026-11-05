<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Editable / approvable content brief.
 *
 * @property string $id
 */
class ContentBrief extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'content_briefs';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'warnings' => 'array',
            'approved_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
