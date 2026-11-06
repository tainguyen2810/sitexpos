<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Media\Actions\CreateInfographic;
use SiteXPost\Modules\Media\Actions\ManageAssets;
use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Media\Domain\MediaSource;
use SiteXPost\Modules\Media\Domain\MediaVersion;
use SiteXPost\Modules\Media\Http\MediaPresenter;
use SiteXPost\Modules\Media\Http\Requests\DeriveMediaRequest;
use SiteXPost\Modules\Media\Http\Requests\InfographicRequest;
use SiteXPost\Modules\Media\Http\Requests\SyncMediaRequest;
use SiteXPost\Modules\Media\Http\Requests\UpdateMediaRequest;
use SiteXPost\Modules\Media\Http\Requests\UploadMediaRequest;
use SiteXPost\Modules\Media\Jobs\MediaJob;
use SiteXPost\Modules\Media\Jobs\SyncRemoteMedia;
use SiteXPost\Modules\Media\Queries\MediaLibraryQuery;
use SiteXPost\Modules\Media\Support\MediaLimits;
use SiteXPost\Modules\Media\Support\MediaStorage;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use Symfony\Component\HttpFoundation\Response;

/**
 * Media Library: list / upload / edit / derive / delete, WordPress &
 * WooCommerce import, infographics, usage.
 */
final class MediaController
{
    public function __construct(private readonly ManageAssets $assets) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', MediaAsset::class);
        $q = MediaLibraryQuery::withVariantFlag(MediaAsset::query());
        foreach (['site_id', 'source_type', 'status', 'license'] as $f) {
            $v = $request->query($f);
            if (is_string($v) && $v !== '') {
                $q->where($f, $v);
            }
        }
        if ($request->boolean('ai')) {
            $q->where('is_ai_generated', true);
        }
        $search = $request->query('q');
        if (is_string($search) && trim($search) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim($search), 0, 100)).'%';
            $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('alt_text', 'like', $like)->orWhere('original_filename', 'like', $like));
        }

        return MediaPresenter::page($q->orderByDesc('created_at')->orderByDesc('id'), fn (MediaAsset $a) => MediaPresenter::asset($a), $request, 30);
    }

    public function store(UploadMediaRequest $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('create', MediaAsset::class);
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            throw ApiException::notFound();
        }
        $data = $request->validated();
        unset($data['file']);
        ['asset' => $asset, 'job_run' => $run] = $this->assets->upload($workspace->id, $file, $data, (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => MediaPresenter::asset($asset->refresh(), true), 'job_run' => ['id' => $run->id, 'status' => $run->refresh()->status]], 202);
    }

    public function show(Workspace $workspace, MediaAsset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        return new JsonResponse(['data' => MediaPresenter::asset($asset, true)]);
    }

    public function update(UpdateMediaRequest $request, Workspace $workspace, MediaAsset $asset): JsonResponse
    {
        Gate::authorize('operate', $asset);

        return new JsonResponse(['data' => MediaPresenter::asset($this->assets->update($asset, $request->validated()), true)]);
    }

    public function derive(DeriveMediaRequest $request, Workspace $workspace, MediaAsset $asset): JsonResponse
    {
        Gate::authorize('operate', $asset);

        return new JsonResponse(['data' => MediaPresenter::asset($this->assets->derive($asset, $request->validated(), (string) $request->user()?->getAuthIdentifier()), true)], 201);
    }

    public function destroy(Workspace $workspace, MediaAsset $asset): JsonResponse
    {
        Gate::authorize('operate', $asset);
        $this->assets->delete($asset);

        return new JsonResponse(null, 204);
    }

    public function sources(Request $request, Workspace $workspace, SiteDirectory $sites): JsonResponse
    {
        Gate::authorize('viewAny', MediaAsset::class);
        $siteId = (string) $request->query('site_id', '');
        $rows = MediaSource::query()->when($siteId !== '', fn ($q) => $q->where('site_id', $siteId))->get()->keyBy(fn ($s) => $s->site_id.'|'.$s->type);
        $out = [];
        foreach ($siteId !== '' ? [$siteId] : array_unique($rows->pluck('site_id')->all()) as $sid) {
            $profile = $sites->publishingProfile($sid);
            foreach (['wordpress', 'woocommerce', 'upload', 'ai', 'infographic', 'stock'] as $type) {
                $row = $rows[$sid.'|'.$type] ?? null;
                $out[] = [
                    'site_id' => $sid, 'type' => $type,
                    'available' => match ($type) {
                        'woocommerce' => (bool) ($profile['woocommerce'] ?? false),
                        'wordpress' => (bool) ($profile['paired'] ?? false),
                        'stock' => false, // no licensed stock provider integrated yet
                        default => true,
                    },
                    'status' => $row->status ?? 'idle', 'items_count' => (int) ($row->items_count ?? MediaAsset::query()->where('site_id', $sid)->where('source_type', $type === 'upload' ? 'upload' : $type)->count()),
                    'last_synced_at' => $row?->last_synced_at?->toIso8601ZuluString(), 'last_error_code' => $row?->last_error_code,
                ];
            }
        }

        return new JsonResponse(['data' => $out]);
    }

    public function sync(SyncMediaRequest $request, Workspace $workspace, SiteDirectory $sites): JsonResponse
    {
        Gate::authorize('create', MediaAsset::class);
        $siteId = (string) $request->validated('site_id');
        $site = $sites->find($siteId);
        if ($site === null) {
            throw ApiException::notFound();
        }
        if (! $site['paired']) {
            throw new ApiException(409, 'site_not_connected', __('sites.errors.not_connected'));
        }
        $run = MediaJob::startRun('media.sync_wordpress', 'site', $siteId, (string) $request->user()?->getAuthIdentifier());
        SyncRemoteMedia::dispatch($siteId, $run->id);

        return new JsonResponse(['job_run' => ['id' => $run->id, 'status' => $run->refresh()->status]], 202);
    }

    public function infographic(InfographicRequest $request, Workspace $workspace, CreateInfographic $action): JsonResponse
    {
        Gate::authorize('create', MediaAsset::class);

        return new JsonResponse(['data' => MediaPresenter::asset($action->handle($request->validated(), (string) $request->user()?->getAuthIdentifier()), true)], 201);
    }

    public function usage(Workspace $workspace, MediaLimits $limits): JsonResponse
    {
        Gate::authorize('viewAny', MediaAsset::class);

        return new JsonResponse(['data' => [
            'stored_bytes' => (int) MediaAsset::query()->whereNotNull('storage_path')->sum('bytes')
                + (int) MediaVersion::query()->where('kind', '!=', 'original')->sum('bytes'),
            'assets' => MediaAsset::query()->count(),
            'by_source' => MediaAsset::query()->selectRaw('source_type, count(*) as n')->groupBy('source_type')->pluck('n', 'source_type'),
            'ai_images_today' => MediaAsset::query()->where('is_ai_generated', true)->where('created_at', '>=', now()->startOfDay())->count(),
            'limits' => $limits->for($workspace->id),
        ]]);
    }

    /**
     * Signed, expiring file URL (no session needed, works in <img> and the
     * sandboxed preview iframe). The signature covers workspace + asset +
     * variant; the asset is looked up inside that workspace only.
     */
    public function file(Request $request, string $workspace, string $asset, string $variant): Response
    {
        $ws = Workspace::query()->find($workspace);
        if ($ws === null || ! in_array($variant, ['original', 'optimized', 'webp', 'avif', 'thumbnail'], true)) {
            throw ApiException::notFound();
        }

        return app(TenantContext::class)->runAs($ws, null, function () use ($asset, $variant) {
            $a = MediaAsset::query()->find($asset);
            $v = $a !== null ? (MediaVersion::query()->where('media_asset_id', $a->id)->where('kind', $variant)->first()
                ?? MediaVersion::query()->where('media_asset_id', $a->id)->where('kind', 'optimized')->first()) : null;
            $bytes = $v !== null ? app(MediaStorage::class)->get($v->storage_path) : null;
            if ($v === null || $bytes === null) {
                throw ApiException::notFound();
            }

            return response($bytes, 200, [
                'Content-Type' => $v->mime, 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, max-age=1800', 'Content-Security-Policy' => "default-src 'none'",
            ]);
        });
    }
}
