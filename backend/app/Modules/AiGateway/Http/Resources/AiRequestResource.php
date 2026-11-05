<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\AiGateway\Domain\AiRequest;

/**
 * @mixin AiRequest
 */
final class AiRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'workspace' => $this->workspace_id !== null ? ['id' => $this->workspace_id, 'name' => $this->workspace?->name] : null,
            'task_key' => $this->task_key,
            'provider' => $this->provider,
            'model' => $this->model,
            'attempt' => $this->attempt,
            'is_fallback' => $this->is_fallback,
            'status' => $this->status,
            'error' => $this->error_code === null ? null : ['code' => $this->error_code, 'message' => $this->error_message, 'http_status' => $this->http_status],
            'billing_uncertain' => $this->billing_uncertain,
            'usage' => [
                'input_tokens' => $this->input_tokens,
                'cached_input_tokens' => $this->cached_input_tokens,
                'cache_write_tokens' => $this->cache_write_tokens,
                'output_tokens' => $this->output_tokens,
                'reasoning_tokens' => $this->reasoning_tokens,
                'images' => $this->image_count,
                'searches' => $this->search_count,
            ],
            'cost_micro_usd' => $this->cost_micro_usd,
            'estimated_cost_micro_usd' => $this->estimated_cost_micro_usd,
            'latency_ms' => $this->latency_ms,
            'finish_reason' => $this->finish_reason,
            'workflow_run_id' => $this->workflow_run_id,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
