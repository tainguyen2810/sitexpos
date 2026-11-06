<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Research\Domain\SeoCampaign;

/**
 * @mixin SeoCampaign
 */
final class CampaignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'name' => $this->name,
            'industry' => $this->industry,
            'market' => $this->market,
            'language' => $this->language,
            'products' => $this->products ?? [],
            'services' => $this->services ?? [],
            'audiences' => $this->audiences ?? [],
            'goals' => $this->goals ?? [],
            'brand_terms' => $this->brand_terms ?? [],
            'status' => $this->status,
            'is_demo' => $this->is_demo,
            'counts' => $this->when(isset($this->keywords_count), fn () => [
                'keywords' => (int) $this->keywords_count,
                'ideas' => (int) ($this->ideas_count ?? 0),
                'clusters' => (int) ($this->clusters_count ?? 0),
                'competitors' => (int) ($this->competitors_count ?? 0),
            ]),
            'last_run_at' => $this->last_run_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
