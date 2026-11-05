<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleClaim;
use SiteXPost\Modules\Writer\Domain\ArticleVersion;
use SiteXPost\Modules\Writer\Domain\ContentBrief;
use SiteXPost\Modules\Writer\Domain\EvidenceItem;
use SiteXPost\Modules\Writer\Domain\EvidenceSnapshot;
use SiteXPost\Modules\Writer\Domain\EvidenceSource;
use SiteXPost\Modules\Writer\Domain\FactCheckResult;
use SiteXPost\Modules\Writer\Domain\ResearchDossier;
use SiteXPost\Modules\Writer\Domain\WritingBatch;
use SiteXPost\Modules\Writer\Domain\WritingPreset;
use SiteXPost\Modules\Writer\Support\ArticleDocument;
use SiteXPost\Modules\Writer\Support\HtmlSanitizer;

/**
 * DEVELOPMENT ONLY Phase 5 demo for "Cửa hàng A (Demo)": 5 writing presets,
 * 3 bulk workflows, 20 articles across the workflow states, 10 briefs,
 * 5 research dossiers, 50 claims and 20 fact-check results.
 *
 * Everything is labelled [DEMO] / is_demo = true. Demo "sources" are pages
 * of the demo store itself (a .test domain) — no real publisher, author,
 * date or quotation is invented. Demo fact-check results are marked
 * reviewer_type = "demo", never "model" or "human". Refuses to run when
 * APP_ENV=production.
 */
class WriterDemoSeeder extends Seeder
{
    private const TOPICS = [
        'Cách chọn card WiFi cho laptop', 'WiFi 6E khác WiFi 6 thế nào', 'Cách nâng cấp SSD cho laptop', 'Laptop không nhận WiFi phải làm sao',
        'So sánh tai nghe chống ồn phổ biến', 'Cách vệ sinh bàn phím cơ', 'Chọn nguồn máy tính cho PC gaming', 'RAM DDR5 có cần thiết không',
        'Cách build PC 15 triệu', 'Hướng dẫn kết nối tai nghe Bluetooth với laptop', 'Laptop cho sinh viên IT', 'Chọn màn hình 2K cho PC',
        'Cách đo nhiệt độ CPU', 'Hub USB-C cho laptop: cần lưu ý gì', 'Tản nhiệt nước hay tản nhiệt khí', 'Chuột không dây cho văn phòng',
        'Webcam cho học online', 'Câu hỏi thường gặp khi mua laptop trả góp', 'Cách chọn case máy tính', 'Chính sách bảo hành laptop tại TechZone',
    ];

    private const STATUSES = ['IDEA_SELECTED', 'RESEARCHING', 'RESEARCH_COMPLETE', 'BRIEF_READY', 'BRIEF_READY', 'OUTLINING', 'WRITING', 'FACT_CHECKING', 'REVISING', 'QUALITY_REVIEW',
        'NEEDS_HUMAN_REVIEW', 'NEEDS_HUMAN_REVIEW', 'NEEDS_HUMAN_REVIEW', 'NEEDS_HUMAN_REVIEW', 'APPROVED', 'APPROVED', 'APPROVED', 'FAILED', 'CANCELLED', 'NEEDS_HUMAN_REVIEW'];

    private const VERDICTS = ['VERIFIED', 'VERIFIED', 'VERIFIED', 'INSUFFICIENT_EVIDENCE', 'NEEDS_HUMAN_REVIEW', 'CONTRADICTED', 'OUTDATED', 'VERIFIED', 'INSUFFICIENT_EVIDENCE', 'VERIFIED'];

    public function run(TenantContext $context): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('WriterDemoSeeder is disabled in production.');
        }
        $workspace = Workspace::query()->where('name', 'Cửa hàng A (Demo)')->where('is_demo', true)->first();
        if ($workspace === null) {
            return;
        }
        $context->runAs($workspace, null, function (): void {
            $site = Site::query()->where('url_hash', Site::hashUrl(ResearchDemoSeeder::SITE_URL))->first();
            if ($site === null || Article::query()->where('is_demo', true)->exists()) {
                return; // needs the Phase 4 demo site; idempotent
            }
            $presets = $this->presets($site);
            $batches = $this->batches($site, $presets[0]);
            $this->articles($site, $presets, $batches);
        });
    }

    /**
     * @return list<WritingPreset>
     */
    private function presets(Site $site): array
    {
        $out = [];
        foreach ([
            ['[DEMO] Giọng TechZone thân thiện', 'thân thiện, dễ hiểu', 'blog_article', true],
            ['[DEMO] Hướng dẫn kỹ thuật', 'chính xác, từng bước', 'how_to', false],
            ['[DEMO] So sánh sản phẩm', 'khách quan, có bảng so sánh', 'comparison', false],
            ['[DEMO] Khắc phục sự cố', 'bình tĩnh, rõ ràng', 'troubleshooting', false],
            ['[DEMO] Hướng dẫn mua hàng', 'tư vấn, không phóng đại', 'buying_guide', false],
        ] as [$name, $tone, $type, $default]) {
            $p = new WritingPreset;
            $p->forceFill(['site_id' => $site->id, 'name' => $name, 'is_default' => $default, 'tone' => $tone, 'is_demo' => true,
                'writing_style' => 'Câu ngắn, ví dụ thực tế cho người dùng Việt Nam. (DEMO)',
                'preferred_terms' => ['laptop', 'card WiFi', 'bảo hành chính hãng'], 'forbidden_phrases' => ['tốt nhất thế giới', 'cam kết lên top Google'],
                'facts' => [['text' => 'TechZone (DEMO) bảo hành đổi mới 7 ngày — dữ liệu minh họa', 'provenance' => 'user_provided', 'source_url' => null]],
                'target_customers' => ['sinh viên', 'dân văn phòng'], 'geographic_context' => 'Việt Nam', 'cta' => 'Liên hệ TechZone (DEMO) để được tư vấn.',
                'defaults' => ['content_type' => $type, 'target_words' => 1200, 'research_depth' => 'standard']])->save();
            $out[] = $p;
        }

        return $out;
    }

    /**
     * @return list<WritingBatch>
     */
    private function batches(Site $site, WritingPreset $preset): array
    {
        $out = [];
        foreach (['completed', 'paused', 'canceled'] as $i => $status) {
            $b = new WritingBatch;
            $b->forceFill(['site_id' => $site->id, 'writing_preset_id' => $preset->id, 'source' => 'manual', 'status' => $status, 'total' => 3, 'concurrency' => 2,
                'settings' => ['target_words' => 1200, 'review_mode' => 'final_only', 'label' => '[DEMO] Lô viết bài '.($i + 1)],
                'per_article_budget_micro_usd' => 1_000_000, 'estimated_cost_micro_usd' => 0, 'reserved_cost_micro_usd' => 0, 'is_demo' => true,
                'paused_at' => $status === 'paused' ? now() : null, 'finished_at' => $status !== 'paused' ? now() : null])->save();
            $out[] = $b;
        }

        return $out;
    }

    /**
     * @param  list<WritingPreset>  $presets
     * @param  list<WritingBatch>  $batches
     */
    private function articles(Site $site, array $presets, array $batches): void
    {
        $claimsLeft = 50;
        $resultsLeft = 20;
        $briefs = 0;
        $dossiers = 0;
        foreach (self::TOPICS as $i => $topic) {
            $status = self::STATUSES[$i];
            $a = new Article;
            $a->forceFill(['site_id' => $site->id, 'writing_preset_id' => $presets[$i % 5]->id, 'writing_batch_id' => $i < 9 ? $batches[intdiv($i, 3)]->id : null,
                'mode' => 'manual', 'title' => '[DEMO] '.$topic, 'status' => $status, 'primary_keyword' => mb_strtolower($topic), 'secondary_keywords' => [],
                'search_intent' => 'informational', 'content_type' => $presets[$i % 5]->defaults['content_type'], 'settings' => ['target_words' => 1200, 'demo' => true],
                'review_mode' => 'step_by_step', 'budget_limit_micro_usd' => 1_000_000, 'spent_micro_usd' => 0, 'is_demo' => true, 'fingerprint' => sha1('demo'.$i),
                'last_error_code' => $status === 'FAILED' ? 'demo_failure' : null, 'last_error_message' => $status === 'FAILED' ? 'Lỗi minh họa (DEMO).' : null,
                'status_before_failure' => $status === 'FAILED' ? 'WRITING' : null])->save();

            $dossier = null;
            if ($dossiers < 5 && ! in_array($status, ['IDEA_SELECTED', 'RESEARCHING'], true)) {
                $dossier = $this->dossier($a, $topic);
                $dossiers++;
            }
            if ($briefs < 10 && ! in_array($status, ['IDEA_SELECTED', 'RESEARCHING', 'RESEARCH_COMPLETE'], true)) {
                $b = new ContentBrief;
                $b->forceFill(['article_id' => $a->id, 'research_dossier_id' => $dossier?->id, 'version' => 1, 'status' => $status === 'BRIEF_READY' ? 'draft' : 'approved',
                    'data' => ['title_suggestions' => ['[DEMO] '.$topic], 'search_intent' => 'informational', 'audience' => 'Khách hàng TechZone (DEMO)',
                        'main_question' => $topic.'?', 'supporting_questions' => [], 'primary_keyword' => mb_strtolower($topic), 'secondary_keywords' => [],
                        'content_angle' => 'Dữ liệu minh họa', 'differentiation' => '', 'structure' => [['heading' => 'Tổng quan', 'level' => 2, 'purpose' => 'Giới thiệu']],
                        'key_facts' => [], 'missing_information' => ['DEMO: chưa có nguồn thật'], 'internal_links' => [], 'risks' => [], 'faq' => []],
                    'warnings' => ['demo_data']])->save();
                $briefs++;
            }
            if (in_array($status, ['FACT_CHECKING', 'REVISING', 'QUALITY_REVIEW', 'NEEDS_HUMAN_REVIEW', 'APPROVED'], true)) {
                $v = $this->version($a, $topic);
                $n = min(5, $claimsLeft);
                for ($c = 0; $c < $n; $c++) {
                    $verdict = $resultsLeft > 0 ? self::VERDICTS[($i + $c) % 10] : 'PENDING';
                    if ($status === 'APPROVED') {
                        $verdict = $resultsLeft > 0 ? 'VERIFIED' : 'PENDING';
                    }
                    $claim = new ArticleClaim;
                    $claim->forceFill(['article_id' => $a->id, 'article_version_id' => $v->id, 'section_key' => 'intro', 'claim_text' => '[DEMO] Khẳng định minh họa số '.($c + 1).' về '.mb_strtolower($topic),
                        'claim_hash' => sha1($a->id.$c), 'category' => ['spec', 'compatibility', 'price', 'statistic', 'other'][$c], 'risk_level' => ['HIGH', 'HIGH', 'HIGH', 'MEDIUM', 'LOW'][$c],
                        'origin' => 'rule', 'extracted_at' => now(), 'status' => $verdict])->save();
                    $claimsLeft--;
                    if ($verdict !== 'PENDING') {
                        $r = new FactCheckResult;
                        $r->forceFill(['article_claim_id' => $claim->id, 'verdict' => $verdict, 'supporting' => [], 'contradicting' => [],
                            'explanation' => 'Kết quả minh họa (DEMO) — không phải kiểm chứng thật.', 'rule_notes' => ['demo_data'], 'reviewer_type' => 'demo', 'reviewed_at' => now()])->save();
                        $claim->forceFill(['latest_result_id' => $r->id])->save();
                        $resultsLeft--;
                    }
                }
                $stats = ArticleClaim::query()->where('article_version_id', $v->id)->get();
                $a->forceFill(['unresolved_critical' => 0, 'unresolved_high' => $stats->where('risk_level', 'HIGH')->where('status', '!=', 'VERIFIED')->count(),
                    'approved_version_id' => $status === 'APPROVED' ? $v->id : null, 'approved_at' => $status === 'APPROVED' ? now() : null])->save();
            }
        }
    }

    private function dossier(Article $a, string $topic): ResearchDossier
    {
        $d = new ResearchDossier;
        $d->forceFill(['article_id' => $a->id, 'status' => 'complete', 'depth' => 'standard', 'questions_count' => 0, 'sources_count' => 1, 'usable_sources_count' => 1,
            'evidence_count' => 1, 'warnings' => ['demo_data'], 'summary' => 'Hồ sơ nghiên cứu minh họa (DEMO).', 'completed_at' => now()])->save();
        $url = ResearchDemoSeeder::SITE_URL.'/demo-nguon/'.substr(sha1($topic), 0, 8);
        $text = '[DEMO] Trang minh họa của cửa hàng TechZone về chủ đề: '.$topic.'. Đây không phải nguồn thật.';
        $snap = new EvidenceSnapshot;
        $snap->forceFill(['url' => $url, 'url_hash' => hash('sha256', $url), 'content_type' => 'text/html', 'content_text' => $text, 'content_hash' => hash('sha256', $text),
            'byte_size' => strlen($text), 'meta' => ['demo' => true], 'fetched_at' => now()])->save();
        $s = new EvidenceSource;
        $s->forceFill(['research_dossier_id' => $d->id, 'url' => $url, 'url_hash' => hash('sha256', $url), 'title' => '[DEMO] '.$topic, 'retrieved_at' => now(),
            'source_type' => 'customer_site', 'discovered_via' => 'customer_site', 'retrieval_status' => 'fetched', 'http_status' => 200, 'reliability_score' => 55,
            'reliability' => ['indicators' => ['type:customer_site', 'demo_data']], 'snapshot_id' => $snap->id])->save();
        $e = new EvidenceItem;
        $e->forceFill(['research_dossier_id' => $d->id, 'evidence_source_id' => $s->id, 'snapshot_id' => $snap->id, 'ref' => 1, 'excerpt' => $text,
            'statement' => 'Nội dung minh họa (DEMO).', 'relevance' => 50])->save();

        return $d;
    }

    private function version(Article $a, string $topic): ArticleVersion
    {
        $html = '<h1>[DEMO] '.e($topic).'</h1><p>Đây là bài viết minh họa (DEMO) cho giao diện AI Writer. Nội dung không dùng để xuất bản.</p>'
            .'<h2>Tổng quan</h2><p>Phần nội dung minh họa.</p>';
        $v = new ArticleVersion;
        $v->forceFill(['article_id' => $a->id, 'number' => 1, 'source' => 'demo', 'title' => '[DEMO] '.$topic, 'content_html' => $html, 'content_hash' => hash('sha256', $html),
            'section_hashes' => array_map(fn ($s) => $s + ['claims_checked' => true], ArticleDocument::hashes($html)), 'word_count' => HtmlSanitizer::wordCount($html)])->save();
        $a->forceFill(['current_version_id' => $v->id])->save();

        return $v;
    }
}
