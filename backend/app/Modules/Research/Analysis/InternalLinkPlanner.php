<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Analysis;

use SiteXPost\Modules\Research\Domain\ContentIdea;
use SiteXPost\Modules\Research\Domain\InternalLinkSuggestion;
use SiteXPost\Modules\Research\Domain\Keyword;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Domain\TopicCluster;
use SiteXPost\Modules\Research\Support\Text;

/**
 * Hub-and-spoke internal links per cluster: every cluster article ↔ pillar,
 * and existing WordPress posts on the cluster topic → pillar.
 */
final class InternalLinkPlanner
{
    public function plan(SeoCampaign $campaign): int
    {
        $n = 0;
        foreach (TopicCluster::query()->where('campaign_id', $campaign->id)->where('status', 'active')->get() as $cluster) {
            $ideas = ContentIdea::query()->where('cluster_id', $cluster->id)->whereNotIn('status', ['rejected'])->with('primaryKeyword:id,keyword')->get();
            $pillarIdea = $ideas->firstWhere('is_pillar', true);
            $pillarKeyword = Keyword::query()->find($cluster->pillar_keyword_id)->keyword ?? $cluster->name;

            [$toType, $toId] = $pillarIdea !== null ? ['idea', $pillarIdea->id] : ($cluster->pillar_content_item_id !== null ? ['content', $cluster->pillar_content_item_id] : [null, null]);
            if ($toId === null) {
                continue;
            }

            foreach ($ideas as $idea) {
                if ($idea->id === $toId) {
                    continue;
                }
                $n += $this->suggest($campaign, $cluster, 'idea', $idea->id, $toType, $toId, $pillarKeyword, 'Bài con trong cụm liên kết về Pillar Page', 80);
                $n += $this->suggest($campaign, $cluster, $toType, $toId, 'idea', $idea->id, $idea->primaryKeyword->keyword ?? $idea->title, 'Pillar Page liên kết xuống bài con', 70);
            }

            $existing = [];
            foreach (Keyword::query()->where('cluster_id', $cluster->id)->limit(200)->get() as $kw) {
                foreach ($kw->covered_by ?? [] as $c) {
                    if (($c['similarity'] ?? 0) >= 0.5) {
                        $existing[$c['id']] = $c;
                    }
                }
            }
            foreach (array_slice($existing, 0, 15) as $id => $c) {
                if ($id === $toId) {
                    continue;
                }
                $n += $this->suggest($campaign, $cluster, 'content', (string) $id, $toType, $toId, $pillarKeyword, 'Bài đã đăng cùng chủ đề nên trỏ về Pillar Page', (int) round(($c['similarity'] ?? 0.5) * 100));
            }
        }

        return $n;
    }

    private function suggest(SeoCampaign $campaign, TopicCluster $cluster, string $fromType, string $fromId, string $toType, string $toId, string $anchor, string $reason, int $score): int
    {
        $fp = Text::fingerprint($fromType, $fromId, $toType, $toId);
        $row = InternalLinkSuggestion::query()->where('campaign_id', $campaign->id)->where('fingerprint', $fp)->first();
        if ($row !== null) {
            return 0;
        }
        $row = new InternalLinkSuggestion;
        $row->forceFill([
            'campaign_id' => $campaign->id, 'cluster_id' => $cluster->id, 'from_type' => $fromType, 'from_id' => $fromId,
            'to_type' => $toType, 'to_id' => $toId, 'anchor_text' => Text::clean($anchor, 190), 'reason' => $reason,
            'score' => max(0, min(100, $score)), 'status' => 'suggested', 'fingerprint' => $fp,
        ])->save();

        return 1;
    }
}
