<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Actions\LearnStyle;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\WritingPreset;
use SiteXPost\Modules\Writer\Http\Requests\LearnStyleRequest;
use SiteXPost\Modules\Writer\Http\Requests\PresetRequest;
use SiteXPost\Modules\Writer\Http\WriterPresenter;
use SiteXPost\Modules\Writer\Pipeline\StyleLearner;
use SiteXPost\Modules\Writer\Support\BuiltinStyles;

/**
 * Brand Voice & Writing Presets. Facts carry their provenance
 * (user_provided / website_extracted / source_verified / ai_inferred); a
 * fact typed here by a user is always user_provided unless they name a
 * source URL, and is never upgraded to "verified" by the system.
 */
final class PresetController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', Article::class);
        $q = WritingPreset::query()->orderByDesc('is_default')->orderBy('name');
        $site = $request->query('site_id');
        if (is_string($site) && preg_match('/^[0-9a-z]{26}$/i', $site) === 1) {
            $q->where(fn ($w) => $w->whereNull('site_id')->orWhere('site_id', $site));
        }

        return new JsonResponse(['data' => $q->limit(100)->get()->map(fn ($p) => WriterPresenter::preset($p))->all()]);
    }

    public function store(PresetRequest $request, Workspace $workspace, SiteDirectory $sites): JsonResponse
    {
        Gate::authorize('create', Article::class);
        if (WritingPreset::query()->count() >= 50) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['name' => [__('writer.errors.too_many_presets')]]);
        }
        $p = new WritingPreset;
        $this->fill($p, $request->validated(), $sites);
        $p->created_by = (string) $request->user()?->getAuthIdentifier();
        $this->persist($p);
        Audit::record('writer.preset_created', $p, ['name' => $p->name]);

        return new JsonResponse(['data' => WriterPresenter::preset($p)], 201);
    }

    public function show(Workspace $workspace, WritingPreset $preset): JsonResponse
    {
        Gate::authorize('view', $preset);

        return new JsonResponse(['data' => WriterPresenter::preset($preset)]);
    }

    public function update(PresetRequest $request, Workspace $workspace, WritingPreset $preset, SiteDirectory $sites): JsonResponse
    {
        Gate::authorize('operate', $preset);
        $this->fill($preset, $request->validated(), $sites);
        $this->persist($preset);
        Audit::record('writer.preset_updated', $preset, ['fields' => array_keys($request->validated())]);

        return new JsonResponse(['data' => WriterPresenter::preset($preset)]);
    }

    public function destroy(Workspace $workspace, WritingPreset $preset): JsonResponse
    {
        Gate::authorize('operate', $preset);
        Article::query()->where('writing_preset_id', $preset->id)->update(['writing_preset_id' => null]);
        $preset->delete();
        Audit::record('writer.preset_deleted', $preset, ['name' => $preset->name]);

        return new JsonResponse(null, 204);
    }

    /**
     * Queue "learn writing style" from the site's own posts and/or pasted samples (202 + job_run).
     */
    public function learnStyle(LearnStyleRequest $request, Workspace $workspace, WritingPreset $preset, SiteDirectory $sites, LearnStyle $action): JsonResponse
    {
        Gate::authorize('operate', $preset);
        $data = $request->validated();
        $siteId = isset($data['site_id']) ? (string) $data['site_id'] : $preset->site_id;
        if ($siteId !== null && $sites->find($siteId) === null) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['site_id' => [__('writer.errors.site_not_found')]]);
        }
        $run = $action->start($preset, $siteId, array_values($data['content_item_ids'] ?? []), array_values($data['samples'] ?? []),
            (string) $request->user()?->getAuthIdentifier(), isset($data['origin']) && is_array($data['origin']) ? $data['origin'] : null);

        return new JsonResponse(['data' => ['job_run' => ['id' => $run->id, 'status' => $run->status, 'type' => $run->type]]], 202);
    }

    /**
     * Discard the pending learned style (the applied profile is kept).
     */
    public function discardStyleDraft(Workspace $workspace, WritingPreset $preset): JsonResponse
    {
        Gate::authorize('operate', $preset);
        $preset->forceFill(['style_draft' => null])->save();
        Audit::record('writer.style_draft_discarded', $preset);

        return new JsonResponse(['data' => WriterPresenter::preset($preset)]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fill(WritingPreset $p, array $data, SiteDirectory $sites): void
    {
        if (array_key_exists('site_id', $data) && $data['site_id'] !== null && $sites->find((string) $data['site_id']) === null) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['site_id' => [__('writer.errors.site_not_found')]]);
        }
        if (isset($data['name'])) {
            $data['name'] = Text::clean($data['name'], 120);
            if (WritingPreset::query()->where('name', $data['name'])->where('id', '!=', (string) $p->id)->exists()) {
                throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['name' => [__('writer.errors.preset_name_taken')]]);
            }
        }
        if (array_key_exists('facts', $data)) {
            $data['facts'] = array_values(array_map(static fn (array $f): array => [
                'text' => Text::clean($f['text'], 500),
                // A human-typed fact is user_provided; "source_verified" requires a URL the user names.
                'provenance' => ($f['provenance'] ?? 'user_provided') === 'source_verified' && empty($f['source_url']) ? 'user_provided' : ($f['provenance'] ?? 'user_provided'),
                'source_url' => $f['source_url'] ?? null,
            ], $data['facts'] ?? []));
        }
        if (array_key_exists('from_builtin', $data)) {
            // "Make my own copy" of a ready-made style: start from its profile unless one is sent.
            $builtin = BuiltinStyles::profile(is_string($data['from_builtin']) ? $data['from_builtin'] : null);
            if ($builtin !== null && ! isset($data['style_profile'])) {
                $data['style_profile'] = $builtin;
                $data['style_sources'] = ['origin' => ['type' => 'builtin', 'label' => (string) $data['from_builtin']]];
            }
            unset($data['from_builtin']);
        }
        if (array_key_exists('style_profile', $data)) {
            // Saving a style guide (edited or as learned) applies it and closes the pending draft.
            $profile = is_array($data['style_profile']) ? StyleLearner::sanitize($data['style_profile']) : null;
            $data['style_profile'] = StyleLearner::isUsable($profile) ? $profile : null;
            $data['style_draft'] = null;
            $data['style_learned_at'] = now();
        }
        foreach (['preferred_terms', 'forbidden_phrases', 'target_customers'] as $k) {
            if (array_key_exists($k, $data)) {
                $data[$k] = array_values(array_unique(array_filter(array_map(static fn ($x) => Text::clean($x, 200), $data[$k] ?? []))));
            }
        }
        $p->forceFill($data);
    }

    private function persist(WritingPreset $p): void
    {
        DB::transaction(function () use ($p): void {
            if ($p->is_default) {
                WritingPreset::query()->where('id', '!=', (string) $p->id)->update(['is_default' => false]);
            }
            $p->save();
        });
    }
}
