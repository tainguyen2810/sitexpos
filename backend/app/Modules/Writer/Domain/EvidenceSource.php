<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * A candidate or retrieved source (metadata only from the page itself, never invented).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $research_dossier_id
 * @property string $url
 * @property string $url_hash
 * @property ?string $final_url
 * @property ?string $title
 * @property ?string $publisher
 * @property ?string $author
 * @property ?Carbon $published_at
 * @property ?Carbon $modified_at
 * @property ?Carbon $retrieved_at
 * @property string $source_type
 * @property string $discovered_via
 * @property string $retrieval_status
 * @property ?int $http_status
 * @property ?string $error_code
 * @property ?int $reliability_score
 * @property array<string, mixed>|null $reliability
 * @property ?string $license_note
 * @property ?string $snapshot_id
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
