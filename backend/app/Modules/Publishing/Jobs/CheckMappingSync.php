<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Jobs;

use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Kernel\Tenancy\Jobs\TenantJob;
use SiteXPost\Modules\Publishing\Domain\PublishingConflict;
use SiteXPost\Modules\Publishing\Domain\WordPressPostMapping;
use SiteXPost\Modules\Publishing\Support\ConflictDetector;
use SiteXPost\Modules\Sites\Connector\ConnectorException;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Sites\Contracts\WordPressGateway;
use Throwable;

/**
 * Reads the signed state of a published post and records whether it is
 * still in sync. A divergence opens a conflict (shown in Publishing
 * Conflicts) so that no later update overwrites it silently.
 */
final class CheckMappingSync extends TenantJob
{
    public int $tries = 1;

    public function __construct(public string $mappingId, public ?string $jobRunId = null)
    {
        parent::__construct();
        $this->onQueue('publishing');
    }

    public function handle(WordPressGateway $wp, SiteDirectory $sites): void
    {
        $run = $this->jobRunId !== null ? JobRun::query()->find($this->jobRunId) : null;
        $run?->forceFill(['status' => 'running', 'started_at' => now()])->save();
        try {
            $m = WordPressPostMapping::query()->find($this->mappingId);
            if ($m === null || $m->wp_post_id === null) {
                $run?->forceFill(['status' => 'succeeded', 'progress' => 100, 'finished_at' => now()])->save();

                return;
            }
            $host = (string) ($sites->publishingProfile($m->site_id)['host'] ?? $m->site_host);
            try {
                $state = $wp->postState($m->site_id, (int) $m->wp_post_id);
            } catch (ConnectorException $e) {
                ConflictDetector::record($m, null, 'unreachable', ['error' => $e->errorCode]);
                $run?->forceFill(['status' => 'failed', 'finished_at' => now(), 'error_code' => $e->errorCode, 'error_message' => mb_substr($e->getMessage(), 0, 500)])->save();

                return;
            }
            $types = ConflictDetector::types($m, $state, $host);
            ConflictDetector::record($m, $state, $types === [] ? 'in_sync' : ($types[0] === 'post_missing' ? 'missing' : $types[0]), ['types' => $types]);
            if ($types !== [] && ! PublishingConflict::query()->where('mapping_id', $m->id)->where('status', 'open')->exists()) {
                ConflictDetector::open($m, null, $types, $state);
            }
            $run?->forceFill(['status' => 'succeeded', 'progress' => 100, 'finished_at' => now(), 'step' => $types === [] ? 'in_sync' : implode(',', $types)])->save();
        } catch (Throwable $e) {
            $run?->forceFill(['status' => 'failed', 'finished_at' => now(), 'error_code' => 'server_error', 'error_message' => __('api.server_error')])->save();
            throw $e;
        }
    }
}
