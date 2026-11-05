<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Sites\Domain\Site;

/**
 * Never exposes credentials, secrets or pairing code hashes.
 *
 * @mixin Site
 */
final class SiteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $caps = $this->capabilities ?? [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'status' => $this->status->value,
            'content_language' => $this->content_language,
            'versions' => [
                'wordpress' => $this->wp_version,
                'php' => $this->php_version,
                'plugin' => $this->plugin_version,
            ],
            'publish_author' => $this->publish_author_wp_id === null ? null : [
                'wp_user_id' => $this->publish_author_wp_id,
                'display_name' => $this->publish_author_name,
            ],
            'features' => $caps['features'] ?? new \stdClass,
            'issues' => $caps['issues'] ?? [],
            'settings' => $this->settings ?? new \stdClass,
            'last_error' => $this->last_error_code === null ? null : [
                'code' => $this->last_error_code,
                'message' => $this->last_error_message,
            ],
            'last_seen_at' => $this->last_seen_at?->toIso8601ZuluString(),
            'last_health_at' => $this->last_health_at?->toIso8601ZuluString(),
            'last_synced_at' => $this->last_synced_at?->toIso8601ZuluString(),
            'connected_at' => $this->connected_at?->toIso8601ZuluString(),
            'revoked_at' => $this->revoked_at?->toIso8601ZuluString(),
            'revoked_by' => $this->revoked_by,
            'counts' => $this->when(isset($this->content_items_count), fn () => [
                'content' => $this->content_items_count,
                'taxonomies' => $this->taxonomies_count,
            ]),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
