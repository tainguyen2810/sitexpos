<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Claim ↔ evidence mapping (supports / contradicts / context).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $article_claim_id
 * @property string $evidence_item_id
 * @property string $relation
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ClaimEvidence extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'claim_evidence';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
        ];
    }

    /**
     * @return BelongsTo<EvidenceItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(EvidenceItem::class, 'evidence_item_id');
    }

    public static function link(string $claimId, string $evidenceId, string $relation): void
    {
        if (! self::query()->where(['article_claim_id' => $claimId, 'evidence_item_id' => $evidenceId, 'relation' => $relation])->exists()) {
            (new self)->forceFill(['article_claim_id' => $claimId, 'evidence_item_id' => $evidenceId, 'relation' => $relation])->save();
        }
    }
}
