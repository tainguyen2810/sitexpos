<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Security\SsrfGuard;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Domain\AiBudget;
use SiteXPost\Modules\AiGateway\Domain\AiModel;
use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;
use SiteXPost\Modules\AiGateway\Registry\AiRegistry;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Sites\Connector\Signer;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Domain\SiteCredential;
use SiteXPost\Modules\Sites\Enums\SiteStatus;
use SiteXPost\Modules\Workspace\Actions\CreateWorkspace;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\MemberStatus;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;
use Tests\Support\FakeWordPress;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Unit');

/**
 * Create a workspace (with its OWNER membership) through the real action.
 */
function createWorkspace(?User $owner = null, string $name = 'Workspace'): Workspace
{
    $owner ??= User::factory()->create();

    return app(CreateWorkspace::class)->handle($owner, ['name' => $name]);
}

function addMember(Workspace $workspace, WorkspaceRole $role, ?User $user = null): WorkspaceMember
{
    $user ??= User::factory()->create();

    return app(TenantContext::class)->runAs($workspace, null, function () use ($user, $role): WorkspaceMember {
        $member = new WorkspaceMember;
        $member->forceFill([
            'user_id' => $user->id,
            'role' => $role,
            'status' => MemberStatus::Active,
            'joined_at' => now(),
        ])->save();

        return $member->load('user');
    });
}

/**
 * @return array{workspace: Workspace, owner: User, admin: User, editor: User, viewer: User}
 */
function workspaceWithRoles(string $name = 'Tenant'): array
{
    $owner = User::factory()->create();
    $workspace = createWorkspace($owner, $name);

    return [
        'workspace' => $workspace,
        'owner' => $owner,
        'admin' => addMember($workspace, WorkspaceRole::Admin)->user,
        'editor' => addMember($workspace, WorkspaceRole::Editor)->user,
        'viewer' => addMember($workspace, WorkspaceRole::Viewer)->user,
    ];
}

function memberOf(Workspace $workspace, User $user): WorkspaceMember
{
    return app(TenantContext::class)->bypass(fn () => WorkspaceMember::query()
        ->where('workspace_id', $workspace->id)->where('user_id', $user->id)->firstOrFail());
}

/**
 * Phase 2 helpers --------------------------------------------------------
 */

/**
 * Resolve every hostname to a fixed public IP (no real DNS in tests).
 */
function fakePublicDns(string $ip = '93.184.216.34'): void
{
    app()->instance(SsrfGuard::class, new SsrfGuard([], false, static fn (string $host): array => [$ip]));
}

/**
 * A site already paired with the given fake plugin (its key is registered
 * on both sides).
 */
function connectedSite(Workspace $workspace, FakeWordPress $wp, string $name = 'Blog'): Site
{
    return app(TenantContext::class)->runAs($workspace, null, function () use ($wp, $name): Site {
        $site = new Site;
        $site->fill(['name' => $name]);
        $site->forceFill([
            'url' => $wp->baseUrl,
            'url_hash' => Site::hashUrl($wp->baseUrl),
            'status' => SiteStatus::Connected,
            'connected_at' => now(),
            'capabilities' => ['rest_url' => $wp->baseUrl.'/wp-json/', 'features' => ['woocommerce' => $wp->woocommerce]],
        ])->save();

        $secret = SiteCredential::generateSecret();
        $credential = new SiteCredential;
        $credential->forceFill(['site_id' => $site->id, 'key_id' => (string) Str::ulid(), 'status' => 'active']);
        $credential->secret = $secret;
        $credential->save();
        $wp->keys[$credential->key_id] = $secret;

        return $site;
    });
}

function siteCredential(Site $site): SiteCredential
{
    return app(TenantContext::class)->bypass(fn () => SiteCredential::query()
        ->where('site_id', $site->id)->where('status', 'active')->firstOrFail());
}

/**
 * Headers for a plugin → Cloud request signed like the WordPress plugin does.
 *
 * @param  array<string, mixed>  $body
 * @return array<string, string>
 */
function pluginHeaders(string $keyId, string $secret, string $path, array $body, ?int $timestamp = null, ?string $nonce = null): array
{
    $timestamp ??= time();
    $nonce ??= Signer::nonce();
    $raw = (string) json_encode($body);

    return [
        'X-SiteX-Key-Id' => $keyId,
        'X-SiteX-Timestamp' => (string) $timestamp,
        'X-SiteX-Nonce' => $nonce,
        'X-SiteX-Signature' => Signer::sign(
            Signer::canonical('w2c', 'POST', $path, [], $timestamp, $nonce, $raw),
            $secret,
        ),
        'Content-Type' => 'application/json',
    ];
}

/**
 * Phase 3 helpers --------------------------------------------------------
 */

/**
 * Seed the AI registry (providers, models, versioned prices, task routes).
 * Real providers have no key in tests, so the fake provider answers unless
 * a test sets keys and fakes HTTP.
 */
function seedAi(): void
{
    config(['sitex.ai.fake_provider' => true]);
    foreach (['openai', 'gemini', 'anthropic', 'deepseek'] as $p) {
        config(["sitex.ai.providers.{$p}.api_key" => null]);
    }
    app(AiRegistry::class)->sync();
}

function aiModel(string $modelKey): AiModel
{
    return AiModel::query()->where('model_key', $modelKey)->firstOrFail();
}

/**
 * Point a task route at an ordered list of models (first = primary).
 *
 * @param  list<string>  $modelKeys
 * @param  array<string, mixed>  $extra
 */
function routeTask(string $taskKey, array $modelKeys, array $extra = []): AiTaskRoute
{
    $ids = array_map(fn (string $k): string => aiModel($k)->id, $modelKeys);
    $route = AiTaskRoute::query()->where('task_key', $taskKey)->firstOrFail();
    $route->forceFill([
        'primary_model_id' => $ids[0],
        'fallback_model_ids' => array_slice($ids, 1),
        ...$extra,
    ])->save();

    return $route;
}

function aiBudget(?string $workspaceId, int $limitMicroUsd, string $action = 'pause_all', string $period = 'month', int $alertPct = 80): AiBudget
{
    $budget = new AiBudget;
    $budget->forceFill([
        'scope' => $workspaceId === null ? 'global' : 'workspace',
        'scope_key' => $workspaceId === null ? 'global' : AiBudget::scopeKeyFor($workspaceId),
        'workspace_id' => $workspaceId,
        'period' => $period,
        'limit_micro_usd' => $limitMicroUsd,
        'alert_threshold_pct' => $alertPct,
        'action_on_exceed' => $action,
        'is_enabled' => true,
    ])->save();

    return $budget;
}
