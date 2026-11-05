<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;

/**
 * @mixin WorkspaceMember
 */
final class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ],
            'role' => $this->role->value,
            'status' => $this->status->value,
            'joined_at' => $this->joined_at->toIso8601ZuluString(),
            'is_me' => $request->user()?->getAuthIdentifier() === $this->user_id,
        ];
    }
}
