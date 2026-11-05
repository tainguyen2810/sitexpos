<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Workspace\Domain\WorkspaceInvitation;

/**
 * Never exposes the token or its hash.
 *
 * @mixin WorkspaceInvitation
 */
final class InvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role->value,
            'status' => $this->status(),
            'invited_by' => $this->whenLoaded('inviter', fn () => $this->inviter === null ? null : [
                'id' => $this->inviter->id,
                'name' => $this->inviter->name,
            ]),
            'expires_at' => $this->expires_at->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
