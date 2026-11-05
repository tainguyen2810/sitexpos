<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Identity\Domain\User;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'two_factor_enabled' => $this->hasConfirmedTwoFactor(),
            'platform_role' => $this->platform_role?->value,
            // UI hint only; every admin route re-checks the permission server-side.
            'platform_permissions' => $this->platform_role === null
                ? []
                : array_map(static fn ($p): string => $p->value, $this->platform_role->permissions()),
            'status' => $this->status->value,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
