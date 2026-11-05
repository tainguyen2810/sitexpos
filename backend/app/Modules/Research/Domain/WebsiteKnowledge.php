<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * One statement about the business (industry, brand, product line, audience...).
 * source = website | ai | user. AI statements are suggestions until a user
 * confirms them; the website itself is not assumed to be fully accurate.
 *
 * @property string $id
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
