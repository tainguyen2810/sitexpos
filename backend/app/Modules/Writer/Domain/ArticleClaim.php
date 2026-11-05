<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * A factual claim extracted from an article version.
 *
 * @property string $id
 */
class ArticleClaim extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'article_claims';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'extracted_at' => 'datetime',
            'is_stale' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<FactCheckResult, $this>
     */
    public function latestResult(): BelongsTo
    {
        return $this->belongsTo(FactCheckResult::class, 'latest_result_id');
    }

    /**
     * @return HasMany<ClaimEvidence, $this>
     */
    public function evidenceLinks(): HasMany
    {
        return $this->hasMany(ClaimEvidence::class, 'article_claim_id');
    }
}
