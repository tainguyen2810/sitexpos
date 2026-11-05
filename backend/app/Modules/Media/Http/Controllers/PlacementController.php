<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Media\Actions\Placements;
use SiteXPost\Modules\Media\Contracts\MediaLibrary;
use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Media\Http\Requests\SavePlacementsRequest;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;
use SiteXPost\Modules\Writer\Support\ArticleDocument;

/**
 * Article Media Editor. `{article}` is resolved through the Writer contract
 * inside the tenant scope (another tenant's article → 404).
 */
final class PlacementController
{
    public function __construct(private readonly ArticleCatalog $articles, private readonly Placements $placements, private readonly MediaLibrary $library) {}

    public function index(Request $request, Workspace $workspace, string $articleRef): JsonResponse
    {
        Gate::authorize('viewAny', MediaAsset::class);
        $a = $this->article($articleRef);
        $content = $this->articles->currentContent($a['id']);

        return new JsonResponse(['data' => [
            'article' => ['id' => $a['id'], 'title' => $a['title'], 'site_id' => $a['site_id'], 'status' => $a['status'], 'primary_keyword' => $a['primary_keyword']],
            'sections' => array_map(fn ($s) => ['key' => $s['key'], 'heading' => $s['heading'], 'words' => str_word_count($s['text'])], ArticleDocument::split((string) ($content['html'] ?? ''))),
            'placements' => $this->library->placements($a['id'], false),
        ]]);
    }

    public function suggest(Request $request, Workspace $workspace, string $articleRef): JsonResponse
    {
        Gate::authorize('create', MediaAsset::class);
        $a = $this->article($articleRef);
        $n = $this->placements->suggest($a['id'], (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => ['suggested' => $n, 'placements' => $this->library->placements($a['id'], false)]]);
    }

    public function save(SavePlacementsRequest $request, Workspace $workspace, string $articleRef): JsonResponse
    {
        Gate::authorize('create', MediaAsset::class);
        $a = $this->article($articleRef);
        $this->placements->save($a['id'], $request->validated('items'), (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => ['placements' => $this->library->placements($a['id'], false)]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function article(string $id): array
    {
        return preg_match('/^[0-9a-z]{26}$/i', $id) === 1 ? ($this->articles->find($id) ?? throw ApiException::notFound()) : throw ApiException::notFound();
    }
}
