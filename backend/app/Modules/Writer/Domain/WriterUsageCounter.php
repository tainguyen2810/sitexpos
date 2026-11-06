<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Monthly article quota reservations.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $period
 * @property int $articles_reserved
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class WriterUsageCounter extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'writer_usage_counters';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'articles_reserved' => 'integer',
        ];
    }
}
