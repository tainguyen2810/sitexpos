<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Evidence research result for one article.
 *
 * @property string $id
 */
class ResearchDossier extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'research_dossiers';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'warnings' => 'array',
            'retrieval_available' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ResearchQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(ResearchQuestion::class, 'research_dossier_id');
    }

    /**
     * @return HasMany<EvidenceSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(EvidenceSource::class, 'research_dossier_id');
    }

    /**
     * @return HasMany<EvidenceItem, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(EvidenceItem::class, 'research_dossier_id');
    }
}
