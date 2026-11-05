<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\Contracts\PlannerDirectory;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\WritingPreset;
use SiteXPost\Modules\Writer\Pipeline\AutoApprover;
use SiteXPost\Modules\Writer\Pipeline\DuplicateDetector;
use SiteXPost\Modules\Writer\Support\WriterQuota;
use SiteXPost\Modules\Writer\Support\WriterSettings;

/**
 * Wizard → article (manual topic or Phase 4 Content Idea). Validates the
 * site / campaign / idea belong to the workspace (tenant scope), reserves
 * one article of the monthly quota, runs the duplicate check, and starts
 * research unless asked to wait. Idempotent per Idempotency-Key.
 */
class CreateArticle
{
    public const CONTENT_TYPES = ['blog_article', 'how_to', 'product_guide', 'comparison', 'troubleshooting', 'faq', 'pillar', 'cluster', 'service', 'buying_guide'];

    /** Phase 4 idea content_type → writer content type */
    private const FROM_IDEA = ['informational' => 'blog_article', 'commercial' => 'buying_guide', 'transactional' => 'product_guide', 'navigational' => 'blog_article',
        'tutorial' => 'how_to', 'comparison' => 'comparison', 'troubleshooting' => 'troubleshooting', 'faq' => 'faq', 'pillar' => 'pillar'];

    public function __construct(
        private readonly PlannerDirectory $planner,
        private readonly SiteDirectory $sites,
        private readonly WriterSettings $settings,
        private readonly WriterQuota $quota,
        private readonly DuplicateDetector $duplicates,
        private readonly ArticleWorkflows $workflows,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated wizard input
     */
    public function handle(array $data, ?string $userId, ?string $idempotencyKey = null, bool $reserveQuota = true, ?string $batchId = null): Article
    {
        if ($idempotencyKey !== null && ($existing = Article::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return $existing;
        }
        $site = $this->sites->find((string) $data['site_id']) ?? throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['site_id' => [__('writer.errors.site_not_found')]]);
        $idea = null;
        if (! empty($data['content_idea_id'])) {
            $idea = $this->planner->idea((string) $data['content_idea_id']) ?? throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['content_idea_id' => [__('writer.errors.idea_not_found')]]);
            if ($idea['site_id'] !== $site['id']) {
                throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['content_idea_id' => [__('writer.errors.idea_other_site')]]);
            }
            if (! in_array($idea['status'], ['approved', 'briefed'], true)) {
                throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['content_idea_id' => [__('writer.errors.idea_not_approved')]]);
            }
            $data['campaign_id'] ??= $idea['campaign_id'];
        }
        if (! empty($data['campaign_id'])) {
            $campaign = $this->planner->campaign((string) $data['campaign_id']) ?? throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['campaign_id' => [__('writer.errors.campaign_not_found')]]);
            if ($campaign['site_id'] !== $site['id']) {
                throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['campaign_id' => [__('writer.errors.campaign_other_site')]]);
            }
        }
        $preset = ! empty($data['writing_preset_id']) ? WritingPreset::query()->find($data['writing_preset_id']) : null;
        if (! empty($data['writing_preset_id']) && $preset === null) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['writing_preset_id' => [__('writer.errors.preset_not_found')]]);
        }
        $defaults = $preset?->defaults ?? [];
        $ws = $this->context()->workspaceId();
        $budget = (int) ($data['budget_limit_micro_usd'] ?? $defaults['budget_limit_micro_usd'] ?? $this->settings->int('default_article_budget_micro_usd', $ws));
        $maxBudget = $this->settings->int('max_article_budget_micro_usd', $ws);
        if ($budget > $maxBudget) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['budget_limit_micro_usd' => [__('writer.errors.budget_too_high', ['max' => $maxBudget / 1_000_000])]]);
        }

        AutoApprover::assertAllowed($this->settings, $ws, $data['approval_mode'] ?? null, 'approval_mode');

        $title = Text::clean($data['title'] ?? ($idea['title'] ?? $data['topic'] ?? ''), 300);
        $primary = Text::clean($data['primary_keyword'] ?? ($idea['primary_keyword'] ?? $title), 190);
        if ($title === '' || $primary === '') {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['topic' => [__('writer.errors.topic_required')]]);
        }
        $secondary = array_values(array_slice(array_filter(array_map(fn ($k) => Text::clean($k, 120), $data['secondary_keywords'] ?? ($idea['secondary_keywords'] ?? [])), fn ($k) => $k !== '' && $k !== $primary), 0, 15));
        $settings = [
            'audience' => $data['audience'] ?? ($idea['audience'] ?? null),
            'tone' => $data['tone'] ?? ($defaults['tone'] ?? $preset?->tone),
            'target_words' => (int) ($data['target_words'] ?? $defaults['target_words'] ?? 1500),
            'content_depth' => $data['content_depth'] ?? ($defaults['content_depth'] ?? 'standard'),
            'research_depth' => $data['research_depth'] ?? ($defaults['research_depth'] ?? 'standard'),
            'internal_links' => $data['internal_links'] ?? ['enabled' => true, 'max' => 5],
            'brand_rules' => array_values($data['brand_rules'] ?? []),
            'source_urls' => array_values($data['source_urls'] ?? []),
            'brand_domains' => array_values($data['brand_domains'] ?? []),
            'cta' => $data['cta'] ?? ($preset?->cta),
            'notes' => $data['notes'] ?? null,
            'ai_source_discovery' => (bool) ($data['ai_source_discovery'] ?? true),
            'allow_web_search' => (bool) ($data['allow_web_search'] ?? false),
            'ai_review_notes' => (bool) ($data['ai_review_notes'] ?? true),
            'approval_mode' => ($data['approval_mode'] ?? 'manual') === 'auto' ? 'auto' : 'manual',
            'angle' => $idea['angle'] ?? null,
            'rationale' => $idea['rationale'] ?? null,
            'idea_outline' => $idea['outline'] ?? [],
            'cluster' => $idea['cluster'] ?? null,
        ];
        $contentType = $data['content_type'] ?? ($idea !== null ? (self::FROM_IDEA[$idea['content_type']] ?? 'blog_article') : ($defaults['content_type'] ?? 'blog_article'));
        $mode = $batchId !== null && ($data['mode'] ?? 'manual') === 'autonomous' ? 'autonomous' : (string) ($data['mode'] ?? 'manual');

        if ($reserveQuota) {
            $this->quota->reserve(1);
        }
        try {
            $article = DB::transaction(function () use ($data, $site, $idea, $preset, $title, $primary, $secondary, $settings, $contentType, $budget, $mode, $userId, $idempotencyKey, $batchId): Article {
                $a = new Article;
                $a->forceFill([
                    'site_id' => $site['id'], 'campaign_id' => $data['campaign_id'] ?? null, 'content_idea_id' => $idea['id'] ?? null,
                    'writing_preset_id' => $preset?->id, 'writing_batch_id' => $batchId, 'mode' => $mode, 'title' => $title, 'status' => 'IDEA_SELECTED',
                    'primary_keyword' => $primary, 'secondary_keywords' => $secondary,
                    'search_intent' => $data['search_intent'] ?? ($idea['search_intent'] ?? 'informational'),
                    'content_type' => $contentType, 'language' => $data['language'] ?? 'vi', 'country' => strtoupper((string) ($data['country'] ?? 'VN')),
                    'settings' => $settings, 'review_mode' => $data['review_mode'] ?? ($mode === 'autonomous' ? 'final_only' : 'step_by_step'),
                    'budget_limit_micro_usd' => $budget, 'idempotency_key' => $idempotencyKey,
                    'fingerprint' => sha1(Text::fold($primary).'|'.($data['search_intent'] ?? ($idea['search_intent'] ?? 'informational'))),
                    'created_by' => $userId,
                ])->save();

                return $a;
            });
        } catch (QueryException $e) {
            if ($reserveQuota) {
                $this->quota->release(1);
            }
            if ($idempotencyKey !== null && ($existing = Article::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
                return $existing;
            }
            throw $e;
        }

        $dup = $this->duplicates->check($article);
        $article->forceFill(['duplicate_check' => $dup])->save();
        Audit::record('writer.article_created', $article, ['mode' => $article->mode, 'idea' => $article->content_idea_id, 'duplicate' => $dup['recommendation']]);

        if (($data['start'] ?? true) === true) {
            $this->workflows->start($article, 'research', $userId, $idempotencyKey !== null ? 'create:'.$idempotencyKey : null, chained: $batchId !== null);
        }

        return $article->refresh();
    }

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }
}
