<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * One idea inside a bulk batch.
 *
 * @property string $id
 */
class WritingBatchItem extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'writing_batch_items';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'attempts' => 'integer',
        ];
    }
}
