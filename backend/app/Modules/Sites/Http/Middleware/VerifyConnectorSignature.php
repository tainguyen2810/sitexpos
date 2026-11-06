<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Sites\Connector\NonceStore;
use SiteXPost\Modules\Sites\Connector\Signer;
use SiteXPost\Modules\Sites\Domain\SiteCredential;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates plugin → Cloud calls (`/connector/v1/*` except pairing):
 * key id → credential, timestamp window, single-use nonce, HMAC signature.
 * On success the tenant context is set to the site's workspace and the
 * credential is available as request attribute `sitex.credential`.
 * Every failure returns the same generic 401 (no oracle).
 */
final class VerifyConnectorSignature
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly NonceStore $nonces,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $keyId = (string) $request->header(Signer::HEADER_KEY, '');
        $timestamp = (string) $request->header(Signer::HEADER_TIMESTAMP, '');
        $nonce = (string) $request->header(Signer::HEADER_NONCE, '');
        $signature = (string) $request->header(Signer::HEADER_SIGNATURE, '');

        if ($keyId === '' || strlen($keyId) > 40 || ! ctype_digit($timestamp) || ! Signer::isValidNonce($nonce) || $signature === '') {
            throw $this->reject();
        }

        if (abs(time() - (int) $timestamp) > (int) config('sitex.connector.clock_skew', 300)) {
            throw $this->reject();
        }

        $credential = $this->context->bypass(fn () => SiteCredential::query()->with('site')->where('key_id', $keyId)->first());
        if ($credential === null || ! $credential->acceptsRequests()) {
            throw $this->reject();
        }

        /** @var array<string, scalar|null> $query */
        $query = $request->query->all();
        $canonical = Signer::canonical(Signer::DIRECTION_TO_CLOUD, $request->method(), '/'.$request->path(), $query, (int) $timestamp, $nonce, $request->getContent());

        if (! Signer::verify($canonical, $credential->secret, $signature)) {
            throw $this->reject();
        }

        // Only consume the nonce once the signature is valid (no DoS on other keys).
        if (! $this->nonces->claim($keyId, $nonce)) {
            throw $this->reject();
        }

        $workspace = Workspace::query()->find($credential->workspace_id);
        if ($workspace === null || $workspace->status !== WorkspaceStatus::Active) {
            throw $this->reject();
        }

        $request->attributes->set('sitex.credential', $credential);

        return $this->context->runAs($workspace, null, static fn () => $next($request));
    }

    private function reject(): ApiException
    {
        return new ApiException(401, 'invalid_signature', __('sites.errors.invalid_signature'));
    }
}
