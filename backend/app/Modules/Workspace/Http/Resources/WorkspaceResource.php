<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * @mixin Workspace
 */
final class WorkspaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $context = app(TenantContext::class);
        $inContext = $context->has() && $context->workspaceId() === $this->id;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type->value,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'status' => $this->status->value,
            'is_demo' => $this->is_demo,
            'my_role' => $inContext ? $context->role()?->value : null,
            'permissions' => $inContext ? $context->permissions() : [],
            'impersonating' => $inContext && $context->isImpersonating(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
