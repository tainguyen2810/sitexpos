<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`media_assets`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 * @property ?string $site_id
 * @property string $source_type
 * @property ?string $source_url
 * @property ?string $source_ref
 * @property ?string $original_filename
 * @property ?string $mime
 * @property ?int $width
 * @property ?int $height
 * @property ?int $bytes
 * @property ?string $sha256
 * @property ?string $dhash
 * @property ?string $duplicate_of_id
 * @property ?string $storage_path
 * @property string $license
 * @property ?string $attribution
 * @property bool $is_ai_generated
 * @property ?string $ai_provider
 * @property ?string $ai_model
 * @property ?string $ai_prompt
 * @property ?string $generation_job_id
 * @property ?string $title
 * @property ?string $alt_text
 * @property ?string $caption
 * @property array<string, mixed>|null $metadata
 * @property string $status
 * @property ?string $error_code
 * @property ?int $wp_media_id
 * @property int $usage_count
 * @property bool $is_demo
 * @property ?string $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
class MediaAsset extends Model
{
    use BelongsToWorkspace, HasUlids, SoftDeletes;

    protected $table = 'media_assets';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'is_ai_generated' => 'boolean',
            'metadata' => 'array',
            'wp_media_id' => 'integer',
            'usage_count' => 'integer',
            'is_demo' => 'boolean',
        ];
    }
}
