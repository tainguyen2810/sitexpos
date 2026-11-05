<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * One idea inside a bulk batch.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $writing_batch_id
 * @property ?string $content_idea_id
 * @property string $title
 * @property ?string $article_id
 * @property int $position
 * @property string $status
 * @property ?string $error_code
 * @property ?string $error_message
 * @property int $attempts
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
