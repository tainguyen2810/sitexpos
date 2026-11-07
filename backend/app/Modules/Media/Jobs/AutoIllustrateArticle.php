<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Jobs;

use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Modules\Media\Actions\AutoIllustrate;

/**
 * Auto images for a finished draft (library first, AI for the rest).
 */
final class AutoIllustrateArticle extends MediaJob
{
    public function __construct(public string $articleId, public ?string $versionId, public int $target)
    {
        parent::__construct();
    }

    protected function run(?JobRun $run): void
    {
        app(AutoIllustrate::class)->run($this->workspaceId, $this->articleId, $this->versionId, $this->target);
    }
}
