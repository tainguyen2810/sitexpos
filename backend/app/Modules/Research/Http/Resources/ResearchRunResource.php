<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Research\Domain\ResearchRun;
use SiteXPost\Modules\Research\Workflows\ResearchDiscoveryWorkflow;

/**
 * @mixin ResearchRun
 */
final class ResearchRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaign_id' => $this->campaign_id,
            'workflow_run_id' => $this->workflow_run_id,
            'trigger' => $this->trigger,
            'status' => $this->status,
            'stage' => $this->stage,
            'stage_label' => ResearchDiscoveryWorkflow::stageLabel($this->stage),
            'progress' => $this->progress,
            'stats' => $this->stats ?? (object) [],
            'warnings' => $this->warnings ?? [],
            'cost_micro_usd' => $this->cost_micro_usd,
            'error' => $this->error_code !== null ? ['code' => $this->error_code, 'message' => $this->error_message] : null,
            'sources' => ResearchSourceResource::collection($this->whenLoaded('sources')),
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'finished_at' => $this->finished_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
