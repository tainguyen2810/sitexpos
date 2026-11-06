<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Verbatim excerpt from a snapshot + the statement it supports.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $research_dossier_id
 * @property string $evidence_source_id
 * @property string $snapshot_id
 * @property ?string $research_question_id
 * @property int $ref
 * @property string $excerpt
 * @property string $statement
 * @property ?string $date_context
 * @property ?string $version_context
 * @property int $relevance
 * @property ?string $extracted_by_model
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
