<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Sites\Domain\SiteHealthCheck;

/**
 * @mixin SiteHealthCheck
 */
final class SiteHealthCheckResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ok' => $this->ok,
            'http_status' => $this->http_status,
            'latency_ms' => $this->latency_ms,
            'error_code' => $this->error_code,
            'checked_at' => $this->checked_at->toIso8601ZuluString(),
        ];
    }
}
