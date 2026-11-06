<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * One statement about the business (industry, brand, product line, audience...).
 * source = website | ai | user. AI statements are suggestions until a user
 * confirms them; the website itself is not assumed to be fully accurate.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $website_profile_id
 * @property string $kind
 * @property string $value
 * @property string $source
 * @property ?int $confidence
 * @property array<string, mixed>|null $evidence
 * @property string $status
 * @property string $fingerprint
 * @property ?string $edited_by
 * @property ?Carbon $edited_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class WebsiteKnowledge extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'website_knowledge';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'confidence' => 'integer',
            'edited_at' => 'datetime',
        ];
    }
}
