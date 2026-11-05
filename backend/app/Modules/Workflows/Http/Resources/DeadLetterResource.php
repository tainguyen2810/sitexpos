<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Workflows\Domain\DeadLetter;

/**
 * @mixin DeadLetter
 */
final class DeadLetterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'workspace_id' => $this->workspace_id,
            'workflow_run_id' => $this->workflow_run_id,
            'job_class' => $this->job_class,
            'queue' => $this->queue,
            'error' => ['code' => $this->error_code, 'message' => $this->error_message],
            'attempts' => $this->attempts,
            'status' => $this->status,
            'resolved_at' => $this->resolved_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
