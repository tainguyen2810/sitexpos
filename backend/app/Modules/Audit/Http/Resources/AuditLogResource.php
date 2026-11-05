<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Audit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Audit\Domain\AuditLog;

/**
 * @mixin AuditLog
 */
final class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'action' => $this->action,
            'actor_type' => $this->actor_type,
            'actor' => $this->whenLoaded('actor', fn () => $this->actor === null ? null : [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ]),
            'impersonator' => $this->whenLoaded('impersonator', fn () => $this->impersonator === null ? null : [
                'id' => $this->impersonator->id,
                'name' => $this->impersonator->name,
            ]),
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'changes' => $this->changes,
            'ip' => $this->ip,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
