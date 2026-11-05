<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Monthly article quota reservations.
 *
 * @property string $id
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
