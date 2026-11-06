<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Actions;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\Contracts\PlannerDirectory;
use SiteXPost\Modules\Seo\Domain\SeoLinkSuggestion;
use SiteXPost\Modules\Seo\Support\ArticleHtml;
use SiteXPost\Modules\Seo\Support\InternalLinkEngine;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;

/**
 * Internal Link Suggestions: suggest (keeps human decisions), accept /
 * reject. Accepted links are re-validated against the content index when
 * suggested again and at publish time (PublishableRenderer marks links
 * whose anchor no longer exists as stale).
 */
final class InternalLinks
{
    public function __construct(
        private readonly ArticleCatalog $articles,
        private readonly SiteDirectory $sites,
        private readonly PlannerDirectory $planner,
        private readonly InternalLinkEngine $engine,
    ) {}

    public function suggest(string $articleId, ?string $userId): int
    {
        $article = $this->articles->find($articleId) ?? throw ApiException::notFound();
        $content = $this->articles->approvedContent($articleId) ?? $this->articles->currentContent($articleId);
        $html = ArticleHtml::parse((string) ($content['html'] ?? ''));
        $inventory = $this->sites->contentIndex($article['site_id'], ['post', 'page', 'product']);
        $ideas = $this->planner->ideasForSite($article['site_id']);
        $cluster = null;
        if ($article['content_idea_id'] !== null) {
            $idea = $this->planner->idea($article['content_idea_id']);
            $cluster = is_string($idea['cluster']['name'] ?? null) ? $idea['cluster']['name'] : null;
        }
        $own = $this->sites->publication($article['site_id'], 'article', $article['id']);
        $suggestions = $this->engine->suggest($article, $html, $inventory, $ideas, $cluster, $own['wp_post_id'] ?? null);

        // Accepted links whose target vanished from the published inventory become stale.
        $live = [];
        foreach ($inventory as $i) {
            if (($i['status'] ?? '') === 'publish' && ! empty($i['url'])) {
                $live[(int) $i['wp_post_id']] = (string) $i['url'];
            }
        }
        foreach (SeoLinkSuggestion::query()->where('article_id', $article['id'])->whereIn('status', ['accepted', 'suggested'])->get() as $old) {
            if (! isset($live[$old->target_wp_post_id])) {
                $old->forceFill(['status' => 'stale'])->save();
            } elseif ($old->status === 'accepted' && $live[$old->target_wp_post_id] !== $old->target_url) {
                $old->forceFill(['target_url' => $live[$old->target_wp_post_id], 'verified_at' => now()])->save();
            }
        }
        SeoLinkSuggestion::query()->where('article_id', $article['id'])->where('status', 'suggested')->delete();

        $n = 0;
        foreach ($suggestions as $s) {
            $existing = SeoLinkSuggestion::query()->where('article_id', $article['id'])->where('target_wp_post_id', $s['target_wp_post_id'])->first();
            if ($existing !== null && in_array($existing->status, ['accepted', 'rejected'], true)) {
                continue; // a human already decided
            }
            $row = $existing ?? new SeoLinkSuggestion;
            $row->forceFill([
                'article_id' => $article['id'], 'site_id' => $article['site_id'], 'target_wp_post_id' => $s['target_wp_post_id'],
                'target_post_type' => $s['target_post_type'], 'target_url' => mb_substr($s['target_url'], 0, 1000), 'target_title' => $s['target_title'],
                'anchor_text' => $s['anchor_text'], 'section_key' => mb_substr((string) $s['section_heading'], 0, 40), 'paragraph_index' => $s['paragraph_index'],
                'relevance' => $s['relevance'], 'signals' => $s['signals'], 'reason' => $s['reason'], 'status' => 'suggested', 'verified_at' => now(), 'decided_by' => null,
            ])->save();
            $n++;
        }
        Audit::record('seo.links_suggested', null, ['article_id' => $article['id'], 'count' => $n]);

        return $n;
    }

    public function decide(SeoLinkSuggestion $s, string $status, string $userId): SeoLinkSuggestion
    {
        if ($status === 'accepted') {
            if ($s->status === 'stale') {
                throw new ApiException(422, 'link_target_missing', __('seo.errors.link_target_missing'));
            }
            $exists = false;
            foreach ($this->sites->contentIndex($s->site_id, [$s->target_post_type]) as $i) {
                if ((int) $i['wp_post_id'] === $s->target_wp_post_id && ($i['status'] ?? '') === 'publish' && ! empty($i['url'])) {
                    $exists = true;
                }
            }
            if (! $exists) {
                $s->forceFill(['status' => 'stale'])->save();
                throw new ApiException(422, 'link_target_missing', __('seo.errors.link_target_missing'));
            }
            $taken = SeoLinkSuggestion::query()->where('article_id', $s->article_id)->where('status', 'accepted')->where('id', '!=', $s->id);
            if ((clone $taken)->where('paragraph_index', $s->paragraph_index)->exists()) {
                throw new ApiException(422, 'paragraph_already_linked', __('seo.errors.paragraph_already_linked'));
            }
            if ($taken->count() >= InternalLinkEngine::MAX_PER_ARTICLE) {
                throw new ApiException(422, 'too_many_links', __('seo.errors.too_many_links'));
            }
        }
        $s->forceFill(['status' => $status, 'decided_by' => $userId, 'verified_at' => now()])->save();
        Audit::record('seo.link_'.$status, $s, ['target' => $s->target_url]);

        return $s;
    }
}
