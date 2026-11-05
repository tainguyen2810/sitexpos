<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Keyword role (primary/secondary) in an idea.
 *
 * @property string $id
 */
class ContentIdeaKeyword extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'content_idea_keywords';

    protected $guarded = ['*'];
}
