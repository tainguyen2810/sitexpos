<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Tools;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Agent\Domain\AgentConfig;
use SiteXPost\Modules\Agent\Domain\AgentTask;
use SiteXPost\Modules\Analytics\Contracts\SeoInsights;
use SiteXPost\Modules\Seo\Contracts\SeoGate;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;

/**
 * Executes one registered tool through module contracts only. Inputs are
 * re-validated here (ids must belong to the run's site inside the tenant
 * scope); the acting user is always the configured human principal.
 */
final class ToolExecutor
{
    public function __construct(
        private readonly SeoInsights $insights,
        private readonly SiteDirectory $sites,
        private readonly SeoGate $seo,
        private readonly ArticleCatalog $articles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(AgentTask $task, AgentConfig $config): array
    {
        $in = $task->input;
        $site = $task->site_id;
        $principal = (string) $config->principal_user_id;

        return match ($task->tool) {
            'read_analytics' => $this->insights->observations($site),
            'analyze_site' => $this->insights->analyze($site),
            'read_wp_content' => $this->inventory($site),
            'run_seo_audit' => (function () use ($in, $site, $principal): array {
                $a = $this->article($in, $site);
                $r = $this->seo->audit($a, $principal);

                return ['audit_id' => $r['id'], 'score' => $r['score'], 'blocked' => $r['blocked'], 'errors' => $r['errors'], 'warnings' => $r['warnings']];
            })(),
            'create_proposal' => ['recommendation_id' => $this->insights->propose($site, [
                'type' => (string) ($in['type'] ?? 'refresh_content'), 'target_label' => mb_substr((string) ($in['target_label'] ?? ''), 0, 300),
                'rationale' => 'SEO Agent: '.mb_substr((string) ($task->evidence['reason'] ?? ''), 0, 1000), 'evidence' => (array) ($task->evidence ?? []),
                'article_id' => isset($in['article_id']) && is_string($in['article_id']) ? $this->article($in, $site) : null,
                'opportunity_id' => isset($in['opportunity_id']) && is_string($in['opportunity_id']) ? $in['opportunity_id'] : null,
            ], $task->agent_run_id)],
            'create_refresh_brief' => $this->insights->startRefresh($this->article($in, $site), isset($in['recommendation_id']) ? (string) $in['recommendation_id'] : null,
                $principal, $task->id, 'agent:'.$task->idempotency_key),
            'publish_via_workflow' => (function () use ($in, $site, $principal): array {
                $r = $this->insights->refresh((string) ($in['refresh_job_id'] ?? ''));
                if ($r === null || $r['approved_by'] === null) {
                    throw ApiException::conflict('content_not_human_approved', __('agent.errors.content_not_human_approved'));
                }
                $this->article(['article_id' => $r['article_id']], $site);

                return $this->insights->publishRefresh($r['id'], $principal);
            })(),
            default => throw ApiException::conflict('unknown_tool', __('agent.errors.unknown_tool')),
        };
    }

    /**
     * @param  array<string, mixed>  $in
     */
    private function article(array $in, string $siteId): string
    {
        $id = (string) ($in['article_id'] ?? '');
        $a = preg_match('/^[0-9a-z]{26}$/i', $id) === 1 ? $this->articles->find($id) : null;
        if ($a === null || $a['site_id'] !== $siteId) {
            throw ApiException::notFound();
        }

        return $id;
    }

    /**
     * Counts only (no page content is copied into agent tables).
     *
     * @return array<string, mixed>
     */
    private function inventory(string $siteId): array
    {
        $items = $this->sites->contentIndex($siteId);
        $by = [];
        foreach ($items as $i) {
            $k = $i['post_type'].':'.$i['status'];
            $by[$k] = ($by[$k] ?? 0) + 1;
        }

        return ['total' => count($items), 'by_type_status' => $by, 'managed' => count(array_filter($items, fn ($i) => $i['is_managed']))];
    }
}
