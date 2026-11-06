<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Keyword role (primary/secondary) in an idea.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $content_idea_id
 * @property string $keyword_id
 * @property string $role
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ContentIdeaKeyword extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'content_idea_keywords';

    protected $guarded = ['*'];
}
