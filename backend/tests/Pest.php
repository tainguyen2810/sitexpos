<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Security\SsrfGuard;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Domain\AiBudget;
use SiteXPost\Modules\AiGateway\Domain\AiModel;
use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;
use SiteXPost\Modules\AiGateway\Registry\AiRegistry;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Research\Domain\CampaignSettings;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Sites\Connector\Signer;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Domain\SiteContentItem;
use SiteXPost\Modules\Sites\Domain\SiteCredential;
use SiteXPost\Modules\Sites\Domain\SiteTaxonomy;
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

/**
 * Phase 4 helpers --------------------------------------------------------
 */

/**
 * A tech-store WordPress site (synced index + public pages served by
 * Http::fake) with an SEO campaign. No real network, DNS or AI.
 *
 * @param  array<string, mixed>  $opts  offline: public pages fail; pages: extra url => html
 * @return array{workspace: Workspace, owner: User, admin: User, editor: User, viewer: User, site: Site, campaign: SeoCampaign}
 */
function researchTenant(string $name = 'Tech Store', array $opts = [], string $industry = 'Bán lẻ đồ công nghệ'): array
{
    Sleep::fake();
    seedAi();
    fakePublicDns();
    $t = workspaceWithRoles($name);
    $host = 'https://'.Str::slug($name).'.example.vn';
    $site = connectedSite($t['workspace'], new FakeWordPress($host), $name);

    $posts = [
        'Cách chọn laptop cho sinh viên', 'Top 5 tai nghe chống ồn', 'Hướng dẫn vệ sinh bàn phím cơ',
        'So sánh iPhone và Samsung', 'Laptop gaming dưới 20 triệu', 'Cách sạc pin laptop đúng cách',
    ];
    $terms = [['category', 1, 'Laptop', 12], ['category', 2, 'Tai nghe', 7], ['category', 3, 'Bàn phím', 5], ['product_cat', 10, 'Laptop gaming', 9], ['post_tag', 20, 'chống ồn', 3]];
    app(TenantContext::class)->runAs($t['workspace'], null, function () use ($site, $host, $posts, $terms): void {
        foreach ($posts as $i => $title) {
            $item = new SiteContentItem;
            $item->forceFill(['site_id' => $site->id, 'post_type' => 'post', 'wp_post_id' => $i + 1, 'status' => 'publish', 'title' => $title,
                'url' => $host.'/'.Str::slug($title).'/', 'slug' => Str::slug($title), 'excerpt' => $title, 'term_ids' => [1],
                'published_at' => now()->subDays($i + 1), 'modified_at' => now()->subDays($i), 'synced_at' => now()])->save();
        }
        foreach (['Laptop Gaming ROG 16', 'Tai nghe Sony WH-1000XM6', 'Bàn phím cơ Keychron K8'] as $i => $p) {
            $item = new SiteContentItem;
            $item->forceFill(['site_id' => $site->id, 'post_type' => 'product', 'wp_post_id' => 100 + $i, 'status' => 'publish', 'title' => $p,
                'url' => $host.'/san-pham/'.Str::slug($p).'/', 'slug' => Str::slug($p), 'term_ids' => [10], 'product' => ['price' => '1000000'],
                'published_at' => now()->subDays(10), 'modified_at' => now()->subDays(10), 'synced_at' => now()])->save();
        }
        foreach ($terms as [$tax, $id, $termName, $count]) {
            $term = new SiteTaxonomy;
            $term->forceFill(['site_id' => $site->id, 'taxonomy' => $tax, 'wp_term_id' => $id, 'name' => $termName, 'slug' => Str::slug($termName), 'count' => $count, 'synced_at' => now()])->save();
        }
    });

    $page = static fn (string $title, string $body = ''): string => '<!doctype html><html lang="vi"><head><title>'.$title.'</title><meta name="description" content="'.$title.' - cửa hàng công nghệ"></head><body><h1>'.$title.'</h1><h2>Laptop nào tốt cho lập trình?</h2><h2>Tai nghe chống ồn có hại tai không?</h2>'.$body.'<a href="/laptop/">Laptop</a></body></html>';
    $routes = [$host.'/robots.txt' => "User-agent: *\nAllow: /\nSitemap: {$host}/sitemap.xml\n", $host.'/' => $page($name.' - Laptop, tai nghe, phụ kiện')];
    foreach ($posts as $title) {
        $routes[$host.'/'.Str::slug($title).'/'] = $page($title);
    }
    foreach (($opts['pages'] ?? []) as $url => $html) {
        $routes[$url] = $html;
    }
    $routes[$host.'/sitemap.xml'] = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', array_map(fn ($t) => '<url><loc>'.$host.'/'.Str::slug($t).'/</loc></url>', $posts)).'</urlset>';
    $offline = (bool) ($opts['offline'] ?? false);
    $extra = $opts['http'] ?? null;
    Http::fake(function (Request $request) use ($routes, $offline, $extra) {
        if ($extra !== null && ($r = $extra($request)) !== null) {
            return $r;
        }
        $url = strtok($request->url(), '?');
        if ($offline && str_contains($url, '.example.vn')) {
            throw new ConnectionException('offline');
        }
        if (isset($routes[$url])) {
            $type = str_ends_with($url, '.txt') ? 'text/plain' : (str_ends_with($url, '.xml') ? 'application/xml' : 'text/html; charset=utf-8');

            return Http::response($routes[$url], 200, ['Content-Type' => $type]);
        }

        return Http::response('not found', 404, ['Content-Type' => 'text/html']);
    });

    $campaign = app(TenantContext::class)->runAs($t['workspace'], null, function () use ($site, $t, $industry) {
        $c = new SeoCampaign;
        $c->forceFill(['site_id' => $site->id, 'name' => 'SEO cửa hàng', 'industry' => $industry, 'market' => 'VN', 'language' => 'vi',
            'products' => ['laptop', 'tai nghe', 'bàn phím cơ'], 'services' => ['bảo hành'], 'audiences' => ['sinh viên', 'game thủ'],
            'goals' => ['traffic', 'sales'], 'brand_terms' => [], 'status' => 'active', 'created_by' => $t['owner']->id])->save();
        CampaignSettings::defaultsFor($c)->save();

        return $c;
    });

    return $t + ['site' => $site, 'campaign' => $campaign];
}

function researchUrl(array $t, string $path = ''): string
{
    return "/api/v1/workspaces/{$t['workspace']->id}/research/campaigns/{$t['campaign']->id}".($path === '' ? '' : '/'.$path);
}

function inResearchTenant(array $t, Closure $fn): mixed
{
    return app(TenantContext::class)->runAs($t['workspace'], null, $fn);
}

/**
 * One row of every Research child type for a workspace (ids by route parameter).
 *
 * @return array<string, string>
 */
function researchFixture(Workspace $workspace, Site $site, string $label = 'B'): array
{
    return app(TenantContext::class)->runAs($workspace, null, function () use ($site, $label): array {
        $m = static function (string $class, array $attrs) {
            $row = new $class;
            $row->forceFill($attrs)->save();

            return $row;
        };
        $R = 'SiteXPost\\Modules\\Research\\Domain\\';
        $campaign = $m($R.'SeoCampaign', ['site_id' => $site->id, 'name' => "Campaign {$label}", 'industry' => 'Secret industry '.$label, 'market' => 'VN', 'language' => 'vi', 'status' => 'active']);
        $profile = $m($R.'WebsiteProfile', ['site_id' => $site->id, 'status' => 'ready']);
        $knowledge = $m($R.'WebsiteKnowledge', ['website_profile_id' => $profile->id, 'kind' => 'audience', 'value' => 'Secret audience '.$label, 'source' => 'user', 'status' => 'confirmed', 'fingerprint' => sha1('k'.$label)]);
        $run = $m($R.'ResearchRun', ['campaign_id' => $campaign->id, 'status' => 'failed', 'trigger' => 'manual']);
        $cluster = $m($R.'TopicCluster', ['campaign_id' => $campaign->id, 'name' => 'Cluster '.$label, 'fingerprint' => sha1('c'.$label), 'intent' => 'informational', 'status' => 'active']);
        $keyword = $m($R.'Keyword', ['campaign_id' => $campaign->id, 'keyword' => 'secret keyword '.$label, 'normalized' => 'secret keyword', 'fingerprint' => sha1('kw'.$label),
            'language' => 'vi', 'country' => 'VN', 'intent' => 'informational', 'sources' => ['user'], 'primary_source' => 'user', 'status' => 'approved', 'first_seen_at' => now(), 'data_collected_at' => now()]);
        $idea = $m($R.'ContentIdea', ['campaign_id' => $campaign->id, 'title' => 'Secret idea '.$label, 'search_intent' => 'informational', 'content_type' => 'informational',
            'fingerprint' => sha1('i'.$label), 'status' => 'suggested', 'source' => 'user', 'primary_keyword_id' => $keyword->id]);
        $gap = $m($R.'ContentGap', ['campaign_id' => $campaign->id, 'gap_type' => 'missing_topic', 'topic' => 'Secret gap '.$label, 'fingerprint' => sha1('g'.$label), 'status' => 'open']);
        $competitor = $m($R.'CompetitorWebsite', ['campaign_id' => $campaign->id, 'name' => 'Rival '.$label, 'url' => 'https://rival-'.strtolower($label).'.example.vn', 'url_hash' => hash('sha256', 'r'.$label), 'status' => 'active']);
        $item = $m($R.'ContentCalendarItem', ['campaign_id' => $campaign->id, 'content_idea_id' => $idea->id, 'scheduled_for' => now()->addDay()->toDateString(), 'status' => 'approved', 'source' => 'user']);
        $note = $m($R.'ResearchNotification', ['type' => 'opportunities', 'title' => 'Secret note '.$label, 'dedupe_key' => sha1('n'.$label)]);

        return ['campaign' => $campaign->id, 'knowledge' => $knowledge->id, 'researchRun' => $run->id, 'keyword' => $keyword->id, 'idea' => $idea->id,
            'cluster' => $cluster->id, 'gap' => $gap->id, 'competitor' => $competitor->id, 'calendarItem' => $item->id, 'notification' => $note->id];
    });
}
