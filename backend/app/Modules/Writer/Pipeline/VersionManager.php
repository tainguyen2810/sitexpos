<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Pipeline;

use Illuminate\Support\Facades\DB;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleClaim;
use SiteXPost\Modules\Writer\Domain\ArticleVersion;
use SiteXPost\Modules\Writer\Domain\ClaimEvidence;
use SiteXPost\Modules\Writer\Domain\EditorialApproval;
use SiteXPost\Modules\Writer\Domain\EvidenceItem;
use SiteXPost\Modules\Writer\Enums\ArticleStatus;
use SiteXPost\Modules\Writer\Support\ArticleDocument;
use SiteXPost\Modules\Writer\Support\ArticleStateMachine;
use SiteXPost\Modules\Writer\Support\HtmlSanitizer;

/**
 * Creates immutable article versions and works out what a new version
 * invalidates:
 * - claims of UNCHANGED sections are carried over with their verdicts;
 * - sections whose text changed are marked `claims_checked = false` and
 *   must be fact-checked again before approval;
 * - an approval given to an earlier version is invalidated and an APPROVED
 *   article goes back to NEEDS_HUMAN_REVIEW.
 */
class VersionManager
{
    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly ArticleStateMachine $machine,
    ) {}

    /**
     * evidence id => source URL for every usable evidence item of the article.
     *
     * @return array<string, string>
     */
    public function evidenceUrls(Article $article): array
    {
        $dossier = $article->dossier()->first();
        if ($dossier === null) {
            return [];
        }

        return EvidenceItem::query()->where('research_dossier_id', $dossier->id)->with('source:id,url,final_url')->get()
            ->mapWithKeys(fn (EvidenceItem $e): array => [$e->id => (string) ($e->source?->final_url ?? $e->source?->url ?? '')])
            ->filter()->all();
    }

    public function siteHost(Article $article): ?string
    {
        $url = DB::table('sites')->where('id', $article->site_id)->value('url');

        return is_string($url) ? (string) parse_url($url, PHP_URL_HOST) : null;
    }

    /**
     * Sanitize HTML the same way for every writer (AI or human).
     *
     * @param  list<string>|null  $allowedLinks
     * @return array{html: string, violations: list<array{type: string, value: string}>}
     */
    public function sanitize(Article $article, string $html, ?array $allowedLinks = null): array
    {
        $clean = $this->sanitizer->clean($html, $this->evidenceUrls($article), $allowedLinks, $this->siteHost($article));

        return ['html' => $clean, 'violations' => $this->sanitizer->violations()];
    }

    /**
     * @param  array<string, true>  $checkedKeys  section keys already fact-checked in THIS version (AI draft / revision)
     * @param  list<array{key: string, heading: string}>  $keyHints  outline sections (heading → key) for the first draft
     */
    public function create(Article $article, string $html, string $source, ?string $userId, ?string $model = null, ?string $summary = null,
        bool $alreadySanitized = false, array $checkedKeys = [], array $keyHints = []): ArticleVersion
    {
        if (! $alreadySanitized) {
            $html = $this->sanitize($article, $html)['html'];
        }

        return DB::transaction(function () use ($article, $html, $source, $userId, $model, $summary, $checkedKeys, $keyHints): ArticleVersion {
            /** @var Article $locked */
            $locked = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->current_version_id !== null ? ArticleVersion::query()->find($locked->current_version_id) : null;
            $prevSections = $previous?->section_hashes ?? [];
            $sections = ArticleDocument::hashes($html, [...$prevSections, ...$keyHints]);
            $changed = array_flip(ArticleDocument::changedKeys($prevSections, $sections));
            foreach ($sections as $i => $s) {
                $prev = collect($prevSections)->firstWhere('key', $s['key']);
                $sections[$i]['claims_checked'] = isset($checkedKeys[$s['key']])
                    || (! isset($changed[$s['key']]) && $prev !== null && ($prev['claims_checked'] ?? false));
            }

            $title = ArticleDocument::title($html) ?? $locked->title;
            $number = (int) ArticleVersion::query()->where('article_id', $locked->id)->max('number') + 1;
            $version = new ArticleVersion;
            $version->forceFill([
                'article_id' => $locked->id, 'number' => $number, 'source' => $source, 'title' => $title,
                'content_html' => $html, 'content_hash' => hash('sha256', $html), 'section_hashes' => $sections,
                'word_count' => HtmlSanitizer::wordCount($html), 'parent_version_id' => $previous?->id,
                'change_summary' => $summary !== null ? mb_substr($summary, 0, 500) : null, 'model' => $model, 'created_by' => $userId,
            ])->save();

            if ($previous !== null) {
                $this->carryClaims($previous, $version, array_keys($changed));
            }
            $locked->forceFill([
                'current_version_id' => $version->id, 'title' => $title, 'lock_version' => $locked->lock_version + 1,
                'autosave_html' => null, 'autosave_base_version_id' => null, 'autosaved_at' => null,
            ])->save();

            if ($previous !== null && $changed !== []) {
                $this->invalidateApprovals($locked, $version, array_keys($changed));
            }
            ClaimStats::refresh($locked);

            return $version;
        });
    }

    /**
     * @param  list<string>  $changedKeys
     */
    private function carryClaims(ArticleVersion $from, ArticleVersion $to, array $changedKeys): void
    {
        $claims = ArticleClaim::query()->where('article_version_id', $from->id)->whereNotIn('section_key', $changedKeys === [] ? ['__none__'] : $changedKeys)->get();
        foreach ($claims as $c) {
            $copy = $c->replicate(['id']);
            $copy->forceFill(['article_version_id' => $to->id, 'is_stale' => false])->save();
            foreach (ClaimEvidence::query()->where('article_claim_id', $c->id)->get() as $link) {
                $l = $link->replicate(['id']);
                $l->forceFill(['article_claim_id' => $copy->id])->save();
            }
        }
        // Claims of changed sections are no longer valid for the new text.
        ArticleClaim::query()->where('article_version_id', $from->id)->whereIn('section_key', $changedKeys === [] ? ['__none__'] : $changedKeys)->update(['is_stale' => true]);
    }

    /**
     * @param  list<string>  $changedKeys
     */
    private function invalidateApprovals(Article $article, ArticleVersion $version, array $changedKeys): void
    {
        $reason = 'Nội dung đã thay đổi ở phiên bản '.$version->number.' (section: '.implode(', ', array_slice($changedKeys, 0, 10)).')';
        $n = EditorialApproval::query()->where('article_id', $article->id)->where('decision', 'approved')->whereNull('invalidated_at')
            ->update(['invalidated_at' => now(), 'invalidated_reason' => mb_substr($reason, 0, 300), 'updated_at' => now()]);
        if ($article->status === ArticleStatus::Approved) {
            $this->machine->transition($article, ArticleStatus::NeedsHumanReview, ['approved_version_id' => null, 'approved_by' => null, 'approved_at' => null]);
        }
        if ($n > 0) {
            Audit::record('writer.approval_invalidated', $article, ['version' => $version->number, 'sections' => $changedKeys]);
        }
    }
}
