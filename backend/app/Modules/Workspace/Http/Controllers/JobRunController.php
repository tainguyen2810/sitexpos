<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Modules\Workspace\Domain\Workspace;

final class JobRunController
{
    public function show(Workspace $workspace, JobRun $jobRun): JsonResponse
    {
        Gate::authorize('view', $workspace);

        return new JsonResponse(['data' => [
            'id' => $jobRun->id,
            'type' => $jobRun->type,
            'status' => $jobRun->status,
            'progress' => $jobRun->progress,
            'step' => $jobRun->step,
            'error' => $jobRun->error_code === null ? null : ['code' => $jobRun->error_code, 'message' => $jobRun->error_message],
            'started_at' => $jobRun->started_at?->toIso8601ZuluString(),
            'finished_at' => $jobRun->finished_at?->toIso8601ZuluString(),
            'created_at' => $jobRun->created_at->toIso8601ZuluString(),
        ]]);
    }
}
