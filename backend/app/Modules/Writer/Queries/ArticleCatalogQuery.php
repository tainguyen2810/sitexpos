<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Queries;

use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleVersion;
use SiteXPost\Modules\Writer\Domain\ContentBrief;
use SiteXPost\Modules\Writer\Domain\EvidenceItem;
use SiteXPost\Modules\Writer\Enums\ArticleStatus;

final class ArticleCatalogQuery implements ArticleCatalog
{
    public function find(string $articleId): ?array
    {
        $a = Article::query()->find($articleId);

        return $a === null ? null : $this->summary($a);
    }

    public function approvedContent(string $articleId): ?array
    {
        $a = Article::query()->find($articleId);
        if ($a === null || $a->status !== ArticleStatus::Approved || $a->approved_version_id === null) {
            return null;
        }
        $v = ArticleVersion::query()->where('article_id', $a->id)->find($a->approved_version_id);
        if ($v === null) {
            return null;
        }

        preg_match_all('/data-evidence-id="([0-9a-z]{26})"/', (string) $v->content_html, $m);
        $sources = [];
        if ($m[1] !== []) {
            foreach (EvidenceItem::query()->with('source:id,url,final_url,title,publisher')->whereIn('id', array_unique($m[1]))->get() as $e) {
                $url = (string) ($e->source->final_url ?? $e->source->url ?? '');
                if ($url !== '') {
                    $sources[$e->id] = ['url' => $url, 'title' => $e->source?->title, 'publisher' => $e->source?->publisher];
                }
            }
        }

        $brief = ContentBrief::query()->where('article_id', $a->id)->orderByDesc('version')->first();
        $data = is_array($brief?->data) ? $brief->data : [];
        $faq = [];
        foreach (is_array($data['faq'] ?? null) ? $data['faq'] : [] as $f) {
            if (is_array($f) && is_string($f['question'] ?? null) && is_string($f['answer'] ?? null)) {
                $faq[] = ['question' => $f['question'], 'answer' => $f['answer']];
            }
        }

        return [
            'version_id' => $v->id, 'number' => (int) $v->number, 'title' => (string) $v->title, 'html' => (string) $v->content_html,
            'content_hash' => (string) $v->content_hash, 'word_count' => (int) $v->word_count, 'sources' => $sources,
            'brief' => [
                'audience' => is_string($data['audience'] ?? null) ? $data['audience'] : null,
                'search_intent' => is_string($data['search_intent'] ?? null) ? $data['search_intent'] : null,
                'main_question' => is_string($data['main_question'] ?? null) ? $data['main_question'] : null,
                'faq' => $faq,
            ],
        ];
    }

    public function currentContent(string $articleId): ?array
    {
        $a = Article::query()->find($articleId);
        $id = $a->current_version_id ?? $a?->approved_version_id;
        $v = $id !== null ? ArticleVersion::query()->where('article_id', $a->id)->find($id) : null;

        return $v === null ? null : [
            'version_id' => $v->id, 'number' => (int) $v->number, 'title' => (string) $v->title, 'html' => (string) $v->content_html,
            'content_hash' => (string) $v->content_hash, 'word_count' => (int) $v->word_count,
        ];
    }

    public function list(?string $siteId, array $statuses = ['APPROVED'], int $limit = 200): array
    {
        $q = Article::query()->whereIn('status', $statuses)->orderByDesc('updated_at')->orderByDesc('id')->limit(max(1, min(1000, $limit)));
        if ($siteId !== null) {
            $q->where('site_id', $siteId);
        }

        return $q->get()->map(fn (Article $a): array => $this->summary($a))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Article $a): array
    {
        return [
            'id' => $a->id, 'site_id' => $a->site_id, 'campaign_id' => $a->campaign_id, 'content_idea_id' => $a->content_idea_id,
            'title' => (string) $a->title, 'status' => $a->status->value, 'primary_keyword' => (string) $a->primary_keyword,
            'secondary_keywords' => array_values(array_filter($a->secondary_keywords ?? [], 'is_string')),
            'search_intent' => (string) $a->search_intent, 'content_type' => (string) $a->content_type, 'language' => (string) $a->language,
            'current_version_id' => $a->current_version_id, 'approved_version_id' => $a->approved_version_id,
            'approved_at' => $a->approved_at?->toIso8601ZuluString(), 'approved_by' => $a->approved_by,
            'unresolved_critical' => (int) $a->unresolved_critical, 'unresolved_high' => (int) $a->unresolved_high,
            'quality_score' => $a->quality_score, 'is_demo' => (bool) $a->is_demo, 'updated_at' => $a->updated_at?->toIso8601ZuluString(),
        ];
    }
}
