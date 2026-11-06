<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`seo_metadata`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $article_id
 * @property string $site_id
 * @property ?string $seo_title
 * @property ?string $meta_description
 * @property ?string $slug
 * @property ?string $canonical_url
 * @property ?string $focus_keyword
 * @property array<string, mixed>|null $secondary_keywords
 * @property ?string $og_title
 * @property ?string $og_description
 * @property ?string $social_image_asset_id
 * @property bool $robots_noindex
 * @property bool $robots_nofollow
 * @property array<string, mixed>|null $schema_types
 * @property array<string, mixed>|null $manual_fields
 * @property string $source
 * @property int $lock_version
 * @property ?string $updated_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SeoMetadata extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'seo_metadata';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'secondary_keywords' => 'array',
            'schema_types' => 'array',
            'manual_fields' => 'array',
            'robots_noindex' => 'boolean',
            'robots_nofollow' => 'boolean',
            'lock_version' => 'integer',
        ];
    }
}
