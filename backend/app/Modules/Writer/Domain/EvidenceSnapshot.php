<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Text snapshot of a retrieved source (hash for re-verification).
 *
 * @property string $id
 */
class EvidenceSnapshot extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'evidence_snapshots';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'fetched_at' => 'datetime',
            'byte_size' => 'integer',
        ];
    }
}
