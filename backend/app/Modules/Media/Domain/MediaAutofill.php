<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Auto-image state of one article (library first, AI for the rest).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $article_id
 * @property ?string $version_id
 * @property int $target
 * @property string $status
 * @property int $filled
 * @property int $library_count
 * @property int $ai_count
 * @property list<array{job_id: string, placement_id: string}>|null $pending
 * @property ?string $error_code
 * @property ?string $error_message
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class MediaAutofill extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'media_autofill_runs';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'pending' => 'array',
            'target' => 'integer',
            'filled' => 'integer',
            'library_count' => 'integer',
            'ai_count' => 'integer',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return ['status' => $this->status, 'target' => $this->target, 'filled' => $this->filled, 'library_count' => $this->library_count,
            'ai_count' => $this->ai_count, 'pending' => count($this->pending ?? []),
            'error' => $this->error_code !== null ? ['code' => $this->error_code, 'message' => $this->error_message] : null,
            'updated_at' => $this->updated_at?->toIso8601ZuluString()];
    }
}
