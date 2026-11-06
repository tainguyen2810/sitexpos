<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Research\Domain\InternalLinkSuggestion;

/**
 * Internal link suggestion.
 *
 * @mixin InternalLinkSuggestion
 */
final class LinkSuggestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cluster_id' => $this->cluster_id,
            'from' => ['type' => $this->from_type, 'id' => $this->from_id, 'label' => $this->getAttribute('from_label')],
            'to' => ['type' => $this->to_type, 'id' => $this->to_id, 'label' => $this->getAttribute('to_label')],
            'anchor_text' => $this->anchor_text,
            'reason' => $this->reason,
            'score' => $this->score,
            'status' => $this->status,
        ];
    }
}
