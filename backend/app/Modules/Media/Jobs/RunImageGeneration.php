<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Jobs;

use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Modules\Media\Actions\AutoIllustrate;
use SiteXPost\Modules\Media\Actions\GenerateImages;

final class RunImageGeneration extends MediaJob
{
    public function __construct(public string $generationJobId, ?string $jobRunId = null)
    {
        parent::__construct($jobRunId);
    }

    protected function run(?JobRun $run): void
    {
        $job = app(GenerateImages::class)->execute($this->generationJobId);
        if ($job !== null && $job->article_id !== null) {
            app(AutoIllustrate::class)->finished($job); // no-op unless an auto-image run waits for this job
        }
        if ($job !== null && in_array($job->status, ['failed', 'blocked'], true)) {
            self::failRun($run, (string) $job->error_code, (string) $job->error_message);
        }
    }
}
