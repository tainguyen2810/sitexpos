<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Workflows;

use SiteXPost\Modules\AiGateway\Gateway\AiGatewayException;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Engine\StepContext;
use SiteXPost\Modules\Workflows\Engine\StepFailed;
use SiteXPost\Modules\Writer\Ai\WriterAi;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleOutline;
use SiteXPost\Modules\Writer\Domain\ArticleRevision;
use SiteXPost\Modules\Writer\Domain\ArticleVersion;
use SiteXPost\Modules\Writer\Pipeline\ArticleContext;
use SiteXPost\Modules\Writer\Pipeline\Reviser;
use SiteXPost\Modules\Writer\Pipeline\SectionWriter;
use SiteXPost\Modules\Writer\Pipeline\VersionManager;
use SiteXPost\Modules\Writer\Support\ArticleDocument;
use SiteXPost\Modules\Writer\Support\ArticleStateMachine;
use SiteXPost\Modules\Writer\Support\HtmlSanitizer;
use SiteXPost\Modules\Writer\Support\TextDiff;

/**
 * Editor AI actions (rewrite selection, expand, shorten, change tone, fix
 * grammar, regenerate section). The result is a PROPOSAL stored in
 * article_revisions; the article content is untouched until the user
 * applies it, so a failed or bad AI answer can never lose content.
 */
final class RewriteWorkflow extends ArticleWorkflow
{
    public const TYPE = 'writer.rewrite';

    protected const STEPS = ['rewrite' => 90];

    protected const PAID = ['rewrite'];

    public const ACTIONS = [
        'rewrite' => 'Viết lại đoạn được chọn rõ ràng, tự nhiên hơn, giữ nguyên ý và mọi dữ kiện.',
        'expand' => 'Mở rộng đoạn được chọn bằng giải thích/ví dụ thực tế; KHÔNG thêm số liệu hay thông số mới ngoài evidence.',
        'shorten' => 'Rút gọn đoạn được chọn, bỏ ý lặp và câu thừa, giữ dữ kiện quan trọng.',
        'tone' => 'Đổi giọng văn của đoạn được chọn theo tone yêu cầu, giữ nguyên dữ kiện.',
        'grammar' => 'Sửa chính tả, ngữ pháp, dấu câu của đoạn được chọn; không đổi nội dung.',
        'regenerate_section' => 'Viết lại toàn bộ section theo spec và evidence, tránh lặp với các phần khác.',
    ];

    public function __construct(
        ArticleStateMachine $machine,
        private readonly WriterAi $ai,
        private readonly ArticleContext $context,
        private readonly VersionManager $versions,
        private readonly SectionWriter $writer,
        private readonly Reviser $reviser,
    ) {
        parent::__construct($machine);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function queue(array $input): string
    {
        return 'writing';
    }

    protected function step(string $step, Article $a, WorkflowRun $run, StepContext $ctx): array
    {
        $revision = ArticleRevision::query()->findOrFail((string) ($run->input['revision_id'] ?? ''));
        if ($revision->status !== 'queued') {
            return ['skipped' => $revision->status];
        }
        $req = $revision->request ?? [];
        $version = ArticleVersion::query()->findOrFail((string) $revision->from_version_id);
        $sections = ArticleDocument::split($version->content_html, $version->section_hashes ?? []);
        $section = collect($sections)->firstWhere('key', $revision->section_key);
        if ($section === null) {
            $revision->forceFill(['status' => 'failed', 'error_code' => 'section_not_found'])->save();
            throw new StepFailed('section_not_found', __('writer.errors.section_not_found'));
        }
        $dossier = $a->dossier()->first();
        $action = (string) $revision->action;
        $selection = trim((string) ($req['selection'] ?? ''));
        $instruction = self::ACTIONS[$action] ?? self::ACTIONS['rewrite'];
        if ($action === 'tone') {
            $instruction .= ' Tone: '.mb_substr((string) ($req['tone'] ?? 'thân thiện, chuyên nghiệp'), 0, 100).'.';
        }
        if ($selection !== '' && $action !== 'regenerate_section') {
            $instruction .= ' CHỈ sửa phần văn bản "selection"; phần còn lại của section giữ nguyên từng chữ.';
        }
        $outline = ArticleOutline::query()->where('article_id', $a->id)->where('status', 'approved')->orderByDesc('version')->first();
        $spec = collect($outline->sections ?? [])->firstWhere('key', $section['key']);

        try {
            $res = $this->ai->rewrite($a, $instruction, [
                'section_html' => $this->reviser->toMarkers($section['html']), 'selection' => $selection, 'section_spec' => $spec,
                'brand_voice' => $this->context->brandVoice($a), 'primary_keyword' => $a->primary_keyword,
                'evidence' => $dossier !== null ? $this->context->evidence($dossier->id, null, 20, 300) : [],
            ], $ctx->callKey(), $run->id);
        } catch (\Throwable $e) {
            $revision->forceFill(['status' => 'failed', 'error_code' => mb_substr($e instanceof AiGatewayException ? $e->errorCode : 'budget_exhausted', 0, 64)])->save();
            throw $e;
        }
        [$html] = $this->writer->citations((string) ($res->json['html'] ?? ''), $dossier?->id);
        $links = array_column($this->context->linkCandidates($a), 'url');
        preg_match_all('#<a\b[^>]*href="([^"]+)"#i', $section['html'], $m);
        $html = $this->versions->sanitize($a, $html, [...$links, ...($m[1] ?? [])])['html'];
        if ($section['key'] !== 'intro' && preg_match('#^\s*<h2\b#i', $html) !== 1) {
            $html = '<h2>'.e($section['heading']).'</h2>'.$html;
        }
        if (trim(strip_tags($html)) === '') {
            $revision->forceFill(['status' => 'failed', 'error_code' => 'empty_output', 'model' => $res->modelKey])->save();

            return ['status' => 'failed'];
        }
        $revision->forceFill([
            'status' => 'proposed', 'proposed_html' => $html, 'model' => $res->modelKey,
            'diff' => array_slice(TextDiff::compact(TextDiff::diff($section['text'], HtmlSanitizer::text($html))), 0, 200),
        ])->save();

        return ['status' => 'proposed', 'revision_id' => $revision->id];
    }
}
