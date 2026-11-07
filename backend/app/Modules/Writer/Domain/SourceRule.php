<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * One domain in a workspace's source filter (prefer | block).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $domain
 * @property string $mode
 * @property ?string $note
 * @property ?string $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SourceRule extends Model
{
    use BelongsToWorkspace, HasUlids;

    public const MODES = ['prefer', 'block'];

    protected $table = 'writer_source_rules';

    protected $guarded = ['*'];
}
