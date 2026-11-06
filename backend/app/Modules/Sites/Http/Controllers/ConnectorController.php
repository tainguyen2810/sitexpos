<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Sites\Actions\CompletePairing;
use SiteXPost\Modules\Sites\Actions\RecordHeartbeat;
use SiteXPost\Modules\Sites\Actions\ResolvePairing;
use SiteXPost\Modules\Sites\Actions\RevokeSite;
use SiteXPost\Modules\Sites\Connector\Signer;
use SiteXPost\Modules\Sites\Domain\SiteCredential;
use SiteXPost\Modules\Sites\Enums\SiteStatus;
use SiteXPost\Modules\Sites\Http\Requests\HeartbeatRequest;
use SiteXPost\Modules\Sites\Http\Requests\PairPreviewRequest;
use SiteXPost\Modules\Sites\Http\Requests\PairRequest;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Connector API called by the WordPress plugin (API-CONTRACTS.md §4).
 */
final class ConnectorController
{
    /**
     * Step 1 of the WordPress-side wizard: show the WP admin WHO is asking
     * before anything is granted. Does not consume the code.
     */
    public function preview(PairPreviewRequest $request, ResolvePairing $resolve, TenantContext $context): JsonResponse
    {
        $pairing = $resolve->handle($request->string('pairing_code')->toString(), $request->string('site_url')->toString());

        $workspace = $context->bypass(fn () => Workspace::query()->find($pairing->workspace_id));

        return new JsonResponse(['data' => [
            'workspace_name' => $workspace?->name,
            'site_name' => $pairing->site->name,
            'site_url' => $pairing->site->url,
            'expires_at' => $pairing->expires_at->toIso8601ZuluString(),
            'permissions' => ['read_site_info', 'read_content', 'create_posts', 'update_own_posts', 'upload_media', 'write_seo_meta'],
            'signature_version' => Signer::VERSION,
        ]]);
    }

    /**
     * Step 2, after the WP admin confirmed: issue the per-site secret.
     */
    public function pair(PairRequest $request, ResolvePairing $resolve, CompletePairing $complete): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $restHost = parse_url((string) $data['rest_url'], PHP_URL_HOST);
        if ($restHost !== parse_url((string) $data['site_url'], PHP_URL_HOST)) {
            return new JsonResponse(['error' => [
                'code' => 'validation_failed', 'message' => __('api.validation_failed'),
                'details' => ['rest_url' => [__('sites.errors.rest_url_host')]],
                'request_id' => $request->attributes->get('request_id'),
            ]], 422);
        }

        $pairing = $resolve->handle((string) $data['pairing_code'], (string) $data['site_url']);

        return new JsonResponse(['data' => $complete->handle($pairing, $data)]);
    }

    public function heartbeat(HeartbeatRequest $request, RecordHeartbeat $action): JsonResponse
    {
        $credential = $this->credential($request);
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $action->handle($credential, $data);

        return new JsonResponse(['data' => ['ok' => true, 'site_status' => $credential->site->status->value, 'server_time' => time()]]);
    }

    /**
     * The WordPress admin disconnected from the plugin side.
     */
    public function revoke(Request $request, RevokeSite $action): JsonResponse
    {
        $credential = $this->credential($request);
        if ($credential->site->status !== SiteStatus::Revoked) {
            $action->handle($credential->site, 'wordpress');
        }

        return new JsonResponse(['data' => ['revoked' => true]]);
    }

    private function credential(Request $request): SiteCredential
    {
        /** @var SiteCredential $credential */
        $credential = $request->attributes->get('sitex.credential');

        return $credential;
    }
}
