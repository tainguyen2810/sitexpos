<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Text snapshot of a retrieved source (hash for re-verification).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $url
 * @property string $url_hash
 * @property ?string $content_type
 * @property string $content_text
 * @property string $content_hash
 * @property int $byte_size
 * @property array<string, mixed>|null $meta
 * @property Carbon $fetched_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
