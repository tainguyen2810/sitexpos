<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Sites\Actions\IssuePairingCode;
use SiteXPost\Modules\Sites\Actions\RevokeSite;
use SiteXPost\Modules\Sites\Actions\RotateCredentials;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Http\Resources\SiteResource;
use SiteXPost\Modules\Sites\Jobs\ConnectorJob;
use SiteXPost\Modules\Sites\Jobs\HealthCheckSite;
use SiteXPost\Modules\Sites\Jobs\SyncSiteContent;
use SiteXPost\Modules\Sites\Jobs\TestPublishSite;
use SiteXPost\Modules\Workspace\Domain\Workspace;

final class SiteActionController
{
    public function pairingCode(Request $request, Workspace $workspace, Site $site, IssuePairingCode $action): JsonResponse
    {
        Gate::authorize('manage', $site);

        /** @var User $user */
        $user = $request->user();
        [$code, $expiresAt] = $action->handle($site, $user);

        return new JsonResponse(['data' => [
            'pairing_code' => $code,
            'expires_at' => $expiresAt->toIso8601ZuluString(),
            'cloud_url' => config('sitex.connector.public_url'),
        ]], 201);
    }

    public function healthCheck(Request $request, Workspace $workspace, Site $site): JsonResponse
    {
        Gate::authorize('operate', $site);

        return $this->queue($request, $site, 'sites.health_check', HealthCheckSite::class);
    }

    public function sync(Request $request, Workspace $workspace, Site $site): JsonResponse
    {
        Gate::authorize('operate', $site);

        return $this->queue($request, $site, 'sites.sync', SyncSiteContent::class);
    }

    public function testPublish(Request $request, Workspace $workspace, Site $site): JsonResponse
    {
        Gate::authorize('manage', $site);

        return $this->queue($request, $site, 'sites.test_publish', TestPublishSite::class);
    }

    public function rotateKeys(Workspace $workspace, Site $site, RotateCredentials $action): SiteResource
    {
        Gate::authorize('manage', $site);
        $action->handle($site);

        return new SiteResource($site->refresh());
    }

    public function revoke(Workspace $workspace, Site $site, RevokeSite $action): JsonResponse
    {
        Gate::authorize('manage', $site);

        if ($site->status->value === 'revoked') {
            throw ApiException::conflict('invalid_state_transition', __('api.invalid_state_transition'));
        }
        $result = $action->handle($site, 'cloud');

        return (new SiteResource($site->refresh()))->additional(['meta' => $result])->response();
    }

    /**
     * @param  class-string<ConnectorJob>  $job
     */
    private function queue(Request $request, Site $site, string $type, string $job): JsonResponse
    {
        if (! $site->status->isPaired()) {
            throw ApiException::conflict('not_connected', __('sites.errors.not_connected'));
        }

        $run = ConnectorJob::startRun($type, $site, (string) $request->user()?->getAuthIdentifier());
        $job::dispatch($site->id, $run->id)->afterCommit();

        return new JsonResponse(['data' => ['job_run' => [
            'id' => $run->id, 'status' => $run->fresh()->status ?? 'queued', 'type' => $type,
        ]]], 202);
    }
}
