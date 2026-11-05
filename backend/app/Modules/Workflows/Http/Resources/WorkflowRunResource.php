<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Workflows\Domain\WorkflowEvent;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Domain\WorkflowStep;

/**
 * @mixin WorkflowRun
 */
final class WorkflowRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $detail = $this->relationLoaded('steps');

        return [
            'id' => $this->id,
            'type' => $this->type,
            'workspace' => $this->when($this->relationLoaded('workspace'), fn () => ['id' => $this->workspace_id, 'name' => $this->workspace?->name]),
            'queue' => $this->queue,
            'status' => $this->status->value,
            'current_step' => $this->current_step,
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'cycle' => $this->cycle,
            'cancel_requested' => $this->cancel_requested_at !== null,
            'next_attempt_at' => $this->next_attempt_at?->toIso8601ZuluString(),
            'error' => $this->error_code === null ? null : ['code' => $this->error_code, 'message' => $this->error_message],
            'input' => $this->safeInput(),
            'output' => $this->when($detail, fn () => $this->output),
            'job_run_id' => $this->job_run_id,
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'finished_at' => $this->finished_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'steps' => $this->when($detail, fn () => $this->steps->map(fn (WorkflowStep $s): array => [
                'key' => $s->key,
                'status' => $s->status->value,
                'attempts' => $s->attempts,
                'error' => $s->error_code === null ? null : ['code' => $s->error_code, 'message' => $s->error_message],
                'started_at' => $s->started_at?->toIso8601ZuluString(),
                'finished_at' => $s->finished_at?->toIso8601ZuluString(),
            ])->all()),
            'events' => $this->when($this->relationLoaded('events'), fn () => $this->events->map(fn (WorkflowEvent $e): array => [
                'event' => $e->event,
                'from' => $e->from_status,
                'to' => $e->to_status,
                'details' => $e->details,
                'at' => $e->created_at->toIso8601ZuluString(),
            ])->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function safeInput(): array
    {
        $input = $this->input ?? [];
        if (isset($input['prompt']) && is_string($input['prompt'])) {
            $input['prompt'] = mb_substr($input['prompt'], 0, 300);
        }

        return $input;
    }
}
