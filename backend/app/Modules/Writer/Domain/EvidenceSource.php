<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * A candidate or retrieved source (metadata only from the page itself, never invented).
 *
 * @property string $id
 */
class EvidenceSource extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'evidence_sources';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'reliability' => 'array',
            'published_at' => 'datetime',
            'modified_at' => 'datetime',
            'retrieved_at' => 'datetime',
            'reliability_score' => 'integer',
            'http_status' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<EvidenceSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(EvidenceSnapshot::class, 'snapshot_id');
    }
}
