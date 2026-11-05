<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Platform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * @mixin Workspace
 */
final class AdminWorkspaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'is_demo' => $this->is_demo,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner === null ? null : [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
                'email' => $this->owner->email,
            ]),
            'members_count' => $this->whenCounted('members'),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
