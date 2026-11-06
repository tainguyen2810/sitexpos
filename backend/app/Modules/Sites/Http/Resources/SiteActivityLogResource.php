<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Sites\Domain\SiteActivityLog;

/**
 * @mixin SiteActivityLog
 */
final class SiteActivityLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'operation' => $this->operation,
            'level' => $this->level,
            'http_status' => $this->http_status,
            'error_code' => $this->error_code,
            'message' => $this->message,
            'latency_ms' => $this->latency_ms,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
