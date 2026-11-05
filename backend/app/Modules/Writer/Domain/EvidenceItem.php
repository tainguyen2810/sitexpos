<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Verbatim excerpt from a snapshot + the statement it supports.
 *
 * @property string $id
 */
class EvidenceItem extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'evidence_items';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'ref' => 'integer',
            'relevance' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<EvidenceSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(EvidenceSource::class, 'evidence_source_id');
    }
}
