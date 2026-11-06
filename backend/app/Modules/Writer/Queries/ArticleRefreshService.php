<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Queries;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Writer\Actions\ArticleWorkflows;
use SiteXPost\Modules\Writer\Actions\Editorial;
use SiteXPost\Modules\Writer\Contracts\ArticleRefresh;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleRevision;
use SiteXPost\Modules\Writer\Domain\ArticleVersion;
use SiteXPost\Modules\Writer\Support\ArticleDocument;

final class ArticleRefreshService implements ArticleRefresh
{
    public const ACTIONS = ['expand', 'regenerate_section', 'rewrite'];

    public function __construct(private readonly Editorial $editorial, private readonly ArticleWorkflows $workflows) {}

    public function sections(string $articleId): array
    {
        $a = $this->article($articleId);
        $v = $a->current_version_id !== null ? ArticleVersion::query()->find($a->current_version_id) : null;
        if ($v === null) {
            return [];
        }

        return array_map(fn ($s) => ['key' => (string) $s['key'], 'heading' => (string) $s['heading'], 'words' => str_word_count(strip_tags((string) $s['html']))],
            ArticleDocument::split($v->content_html, $v->section_hashes ?? []));
    }

    public function proposeSectionRewrite(string $articleId, string $sectionKey, string $action, string $userId, string $idempotencyKey): string
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['action' => ['invalid']]);
        }

        return $this->editorial->requestRewrite($this->article($articleId), ['action' => $action, 'section_key' => $sectionKey], $userId, $idempotencyKey)->id;
    }

    public function revision(string $revisionId): ?array
    {
        $r = ArticleRevision::query()->find($revisionId);

        return $r === null ? null : ['status' => $r->status, 'section_key' => $r->section_key, 'proposed_html' => $r->proposed_html, 'error_code' => $r->error_code];
    }

    public function applyRevision(string $articleId, string $revisionId, string $userId): string
    {
        $a = $this->article($articleId);
        $rev = ArticleRevision::query()->where('article_id', $a->id)->findOrFail($revisionId);

        return $this->editorial->applyRevision($a, $rev, $userId)->id;
    }

    public function startRecheck(string $articleId, string $userId, string $idempotencyKey): string
    {
        return $this->workflows->start($this->article($articleId), 'recheck', $userId, $idempotencyKey)->id;
    }

    public function state(string $articleId): ?array
    {
        $a = Article::query()->find($articleId);

        return $a === null ? null : [
            'status' => $a->status->value, 'current_version_id' => $a->current_version_id, 'approved_version_id' => $a->approved_version_id,
            'unresolved_critical' => (int) $a->unresolved_critical, 'unresolved_high' => (int) $a->unresolved_high,
            'workflow_active' => $this->workflows->active($a, ['rewrite']) !== null,
        ];
    }

    public function approve(string $articleId, string $versionId, string $userId, ?string $note): void
    {
        $this->editorial->approve($this->article($articleId), $versionId, false, $note, $userId);
    }

    public function version(string $versionId): ?array
    {
        $v = ArticleVersion::query()->find($versionId);

        return $v === null ? null : ['id' => $v->id, 'number' => (int) $v->number, 'html' => (string) $v->content_html, 'content_hash' => (string) $v->content_hash];
    }

    private function article(string $id): Article
    {
        return Article::query()->find($id) ?? throw ApiException::notFound();
    }
}
