<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Actions;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\Domain\ResearchRun;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Support\QuotaManager;
use SiteXPost\Modules\Research\Workflows\ResearchDiscoveryWorkflow;
use SiteXPost\Modules\Workflows\Engine\WorkflowEngine;

/**
 * Queue a research run: one active run per campaign, monthly + concurrency
 * quota reserved atomically, idempotent per Idempotency-Key. Never runs the
 * research in the HTTP request.
 */
final class StartResearch
{
    public function __construct(private readonly QuotaManager $quota, private readonly WorkflowEngine $engine) {}

    /**
     * @return array{0: ResearchRun, 1: bool} [run, created]
     */
    public function handle(SeoCampaign $campaign, string $trigger, ?string $userId, ?string $idempotencyKey = null, bool $forceProfile = false): array
    {
        if ($idempotencyKey !== null && ($existing = ResearchRun::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
            if ($existing->campaign_id !== $campaign->id) {
                throw ApiException::conflict('idempotency_conflict', __('research.errors.idempotency_conflict'));
            }

            return [$existing, false];
        }
        if ($campaign->status !== 'active') {
            throw ApiException::conflict('campaign_not_active', __('research.errors.campaign_not_active'));
        }
        if (ResearchRun::query()->where('campaign_id', $campaign->id)->whereIn('status', ResearchRun::ACTIVE)->exists()) {
            throw ApiException::conflict('research_in_progress', __('research.errors.in_progress'));
        }

        $run = $this->quota->reserveRun($campaign->workspace_id, function () use ($campaign, $trigger, $userId, $idempotencyKey): ResearchRun {
            $run = new ResearchRun;
            $run->forceFill([
                'campaign_id' => $campaign->id, 'trigger' => $trigger, 'status' => 'queued', 'progress' => 0,
                'idempotency_key' => $idempotencyKey, 'quota_reserved' => true, 'created_by' => $userId,
            ])->save();

            return $run;
        });

        [$workflow] = $this->engine->start(ResearchDiscoveryWorkflow::TYPE, [
            'research_run_id' => $run->id, 'campaign_id' => $campaign->id, 'force_profile' => $forceProfile,
        ], 'research:'.$run->id, $userId);
        ResearchRun::query()->whereKey($run->id)->whereNull('workflow_run_id')->update(['workflow_run_id' => $workflow->id]);

        Audit::record('research.run.started', $run, ['campaign_id' => $campaign->id, 'trigger' => $trigger]);

        return [$run->refresh(), true];
    }
}
