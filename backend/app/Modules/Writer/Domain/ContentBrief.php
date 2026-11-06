<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Editable / approvable content brief.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $article_id
 * @property ?string $research_dossier_id
 * @property int $version
 * @property string $status
 * @property array<string, mixed>|null $data
 * @property list<string>|null $warnings
 * @property ?string $generated_by_model
 * @property ?string $edited_by
 * @property ?string $approved_by
 * @property ?Carbon $approved_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ContentBrief extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'content_briefs';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'warnings' => 'array',
            'approved_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
