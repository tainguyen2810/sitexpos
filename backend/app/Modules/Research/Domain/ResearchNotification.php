<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * In-app notice (new SEO opportunities, failed research...).
 *
 * @property string $id
 */
class ResearchNotification extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'research_notifications';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }
}
