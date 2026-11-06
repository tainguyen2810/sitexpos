<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Research\Domain\Keyword;

/**
 * Metrics are null unless a verified source measured them (UI shows "Chưa có dữ liệu").
 *
 * @mixin Keyword
 */
final class KeywordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $gsc = null;
        $provider = null;
        if ($this->relationLoaded('metrics')) {
            foreach ($this->metrics->sortBy('period_start') as $m) {
                if ($m->source === 'search_console') {
                    $gsc = ['impressions' => $m->impressions, 'clicks' => $m->clicks, 'ctr' => $m->ctr, 'position' => $m->position,
                        'period' => [$m->period_start?->toDateString(), $m->period_end?->toDateString()], 'collected_at' => $m->collected_at?->toIso8601ZuluString()];
                } else {
                    $provider = ['source' => $m->source, 'search_volume' => $m->search_volume, 'difficulty' => $m->difficulty, 'cpc_micro_usd' => $m->cpc_micro_usd,
                        'trend' => $m->trend, 'collected_at' => $m->collected_at?->toIso8601ZuluString()];
                }
            }
        }
        $breakdown = $this->score_breakdown ?? [];

        return [
            'id' => $this->id,
            'keyword' => $this->keyword,
            'intent' => $this->intent,
            'intent_source' => $this->intent_source,
            'content_type' => $breakdown['content_type'] ?? null,
            'topic_category' => $this->topic_category,
            'cluster_id' => $this->cluster_id,
            'country' => $this->country,
            'language' => $this->language,
            'sources' => $this->sources ?? [],
            'primary_source' => $this->primary_source,
            'evidence' => $this->evidence ?? [],
            'status' => $this->status,
            'metrics' => [
                'search_volume' => $provider['search_volume'] ?? null,
                'difficulty' => $provider['difficulty'] ?? null,
                'cpc_micro_usd' => $provider['cpc_micro_usd'] ?? null,
                'provider' => $provider,
                'search_console' => $gsc,
            ],
            'opportunity' => [
                'score' => $this->opportunity_score,
                'kind' => 'internal_estimate',
                'version' => $breakdown['version'] ?? null,
                'components' => $breakdown['components'] ?? [],
                'confidence' => $breakdown['confidence'] ?? null,
                'penalty' => $breakdown['penalty'] ?? 0,
                'coverage' => $breakdown['coverage'] ?? null,
            ],
            'covered_by' => $this->covered_by ?? [],
            'first_seen_at' => $this->first_seen_at?->toIso8601ZuluString(),
            'data_collected_at' => $this->data_collected_at?->toIso8601ZuluString(),
        ];
    }
}
