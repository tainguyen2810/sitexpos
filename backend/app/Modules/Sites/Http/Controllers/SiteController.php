<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Sites\Actions\CreateSite;
use SiteXPost\Modules\Sites\Actions\DeleteSite;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Http\Requests\StoreSiteRequest;
use SiteXPost\Modules\Sites\Http\Requests\UpdateSiteRequest;
use SiteXPost\Modules\Sites\Http\Resources\SiteResource;
use SiteXPost\Modules\Workspace\Domain\Workspace;

final class SiteController
{
    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Site::class);

        $sites = Site::query()->withCount(['contentItems', 'taxonomies'])->orderBy('created_at')->get();

        return SiteResource::collection($sites);
    }

    public function store(StoreSiteRequest $request, Workspace $workspace, CreateSite $action): JsonResponse
    {
        Gate::authorize('create', Site::class);

        /** @var User $user */
        $user = $request->user();
        /** @var array{name: string, url: string, content_language?: string|null} $data */
        $data = $request->validated();
        $result = $action->handle($user, $data);

        return (new SiteResource($result['site']))->additional(['meta' => [
            'pairing_code' => $result['pairing_code'],
            'pairing_expires_at' => $result['expires_at']->toIso8601ZuluString(),
            'cloud_url' => config('sitex.connector.public_url'),
        ]])->response()->setStatusCode(201);
    }

    public function show(Workspace $workspace, Site $site): SiteResource
    {
        Gate::authorize('view', $site);

        return new SiteResource($site->loadCount(['contentItems', 'taxonomies']));
    }

    public function update(UpdateSiteRequest $request, Workspace $workspace, Site $site): SiteResource
    {
        Gate::authorize('manage', $site);

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        if (isset($data['settings']) && is_array($data['settings'])) {
            $data['settings'] = array_merge($site->settings ?? [], $data['settings']);
        }
        $site->fill($data);
        $changes = $site->getDirty();
        $site->save();

        if ($changes !== []) {
            Audit::record('site.updated', $site, $changes);
        }

        return new SiteResource($site->loadCount(['contentItems', 'taxonomies']));
    }

    public function destroy(Workspace $workspace, Site $site, DeleteSite $action): Response
    {
        Gate::authorize('manage', $site);
        $action->handle($site);

        return response()->noContent();
    }
}
