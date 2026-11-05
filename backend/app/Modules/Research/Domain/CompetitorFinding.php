<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * A page / category / question found on a competitor (structure only).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $competitor_website_id
 * @property ?string $research_run_id
 * @property string $kind
 * @property string $title
 * @property ?string $url
 * @property array<string, mixed>|null $headings
 * @property string $fingerprint
 * @property Carbon $fetched_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class CompetitorFinding extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'competitor_findings';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'headings' => 'array',
            'fetched_at' => 'datetime',
        ];
    }
}
