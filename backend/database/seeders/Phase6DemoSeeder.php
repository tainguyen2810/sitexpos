<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Media\Domain\ImageGenerationJob;
use SiteXPost\Modules\Media\Domain\ImagePlacement;
use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Media\Support\MediaPipeline;
use SiteXPost\Modules\Publishing\Domain\PublishingAttempt;
use SiteXPost\Modules\Publishing\Domain\PublishingConflict;
use SiteXPost\Modules\Publishing\Domain\PublishingJob;
use SiteXPost\Modules\Publishing\Domain\PublishingPolicy;
use SiteXPost\Modules\Publishing\Domain\PublishingSchedule;
use SiteXPost\Modules\Publishing\Domain\WordPressPostMapping;
use SiteXPost\Modules\Publishing\Domain\WordPressSyncState;
use SiteXPost\Modules\Seo\Actions\RunSeoAudit;
use SiteXPost\Modules\Seo\Domain\SeoAuditIssue;
use SiteXPost\Modules\Seo\Domain\SeoAuditRun;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Domain\Article;
use Throwable;

/**
 * DEVELOPMENT ONLY Phase 6 demo for "Cửa hàng A (Demo)": 30 media assets
 * (10 of them AI illustrations, always labelled), 10 AI image jobs,
 * 10 SEO audits, 20 publishing jobs (5 scheduled) and 3 open conflicts.
 *
 * Nothing is sent to WordPress or to an AI provider: images are simple
 * generated shapes, WordPress post ids / URLs point at the demo .test
 * domain and every row is is_demo = true or titled [DEMO]. SEO audits are
 * produced by the real rule engine when the article content allows it,
 * otherwise a small labelled demo run is stored. Refuses to run in
 * production.
 */
class Phase6DemoSeeder extends Seeder
{
    private const TZ = 'Asia/Ho_Chi_Minh';

    public function run(TenantContext $context): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Phase6DemoSeeder is disabled in production.');
        }
        $workspace = Workspace::query()->where('name', 'Cửa hàng A (Demo)')->where('is_demo', true)->first();
        if ($workspace === null) {
            return;
        }
        $context->runAs($workspace, null, function () use ($workspace): void {
            $site = Site::query()->where('url_hash', Site::hashUrl(ResearchDemoSeeder::SITE_URL))->first();
            if ($site === null || PublishingJob::query()->where('is_demo', true)->exists()) {
                return; // needs the Phase 4/5 demo data; idempotent
            }
            $articles = Article::query()->where('site_id', $site->id)->where('is_demo', true)->orderBy('created_at')->orderBy('id')->get()->all();
            if (count($articles) < 20) {
                return;
            }
            $assets = $this->media($workspace, $site, $articles);
            $this->audits($site, $articles);
            $this->publishing($site, $articles, $assets);
        });
    }

    /**
     * @param  list<Article>  $articles
     * @return list<MediaAsset>
     */
    private function media(Workspace $workspace, Site $site, array $articles): array
    {
        $pipeline = app(MediaPipeline::class);
        $assets = [];
        $palette = [[15, 118, 110], [37, 99, 235], [234, 88, 12], [124, 58, 237], [22, 163, 74], [220, 38, 38]];
        for ($i = 0; $i < 30; $i++) {
            [$type, $license, $ai] = match (true) {
                $i < 12 => ['upload', 'owned', false],
                $i < 17 => ['wordpress', 'wordpress_site', false],
                $i < 20 => ['woocommerce', 'wordpress_site', false],
                default => ['ai', 'ai_generated', true],
            };
            $n = $i + 1;
            $a = new MediaAsset;
            $a->forceFill([
                'site_id' => $site->id, 'source_type' => $type, 'license' => $license, 'is_ai_generated' => $ai, 'status' => 'processing',
                'title' => $ai ? "[DEMO] Ảnh minh họa AI {$n}" : "[DEMO] Ảnh {$n}", 'original_filename' => "demo-{$n}.png",
                'alt_text' => $i % 7 === 3 ? null : ($ai ? 'Minh họa khái niệm thiết bị mạng (ảnh AI)' : "Ảnh demo số {$n} của cửa hàng TechZone"),
                'caption' => $ai ? 'Ảnh minh họa tạo bằng AI (DEMO)' : null, 'attribution' => null,
                'ai_provider' => $ai ? 'demo' : null, 'ai_model' => $ai ? 'demo-image' : null, 'ai_prompt' => $ai ? '[DEMO] editorial illustration, no text, no logos' : null,
                'wp_media_id' => in_array($type, ['wordpress', 'woocommerce'], true) ? 9000 + $n : null,
                'source_url' => in_array($type, ['wordpress', 'woocommerce'], true) ? ResearchDemoSeeder::SITE_URL."/wp-content/uploads/demo-{$n}.png" : null,
                'metadata' => $type === 'woocommerce' ? ['product' => ['wp_post_id' => 700 + $n, 'title' => "[DEMO] Sản phẩm {$n}"]] : [],
                'usage_count' => 0, 'is_demo' => true,
            ])->save();
            if (function_exists('imagecreatetruecolor')) {
                try {
                    $pipeline->ingest($a, self::png(960, 540, $palette[$i % count($palette)], $i), "demo-{$n}.png");
                } catch (Throwable) {
                    $a->forceFill(['status' => 'ready'])->save();
                }
            } else {
                $a->forceFill(['status' => 'ready'])->save();
            }
            if ($ai) {
                $job = new ImageGenerationJob;
                $job->forceFill([
                    'site_id' => $site->id, 'article_id' => $articles[$i - 20]->id, 'version' => 1, 'image_type' => $i % 2 === 0 ? 'featured' : 'section',
                    'aspect_ratio' => '16:9', 'style' => 'editorial_concept', 'quality' => 'standard', 'image_count' => 1, 'brand_colors' => ['#0F766E'],
                    'prompt_template' => 'editorial', 'prompt' => '[DEMO] editorial illustration, no text, no logos, not a product photo', 'prompt_edited' => false,
                    'status' => 'succeeded', 'budget_cap_micro_usd' => 200000, 'estimated_cost_micro_usd' => 40000, 'cost_micro_usd' => 0, 'provider' => 'demo', 'model' => 'demo-image',
                    'call_key' => 'demo-image-'.$a->id, 'result_asset_ids' => [$a->id], 'is_demo' => true, 'started_at' => now()->subMinutes(30), 'finished_at' => now()->subMinutes(29),
                ])->save();
                $a->forceFill(['generation_job_id' => $job->id])->save();
            }
            $assets[] = $a->refresh();
        }
        unset($workspace);

        return $assets;
    }

    /**
     * Real rule engine when possible; a labelled demo run otherwise.
     *
     * @param  list<Article>  $articles
     */
    private function audits(Site $site, array $articles): void
    {
        $runner = app(RunSeoAudit::class);
        $made = 0;
        foreach ($articles as $a) {
            if ($made >= 10) {
                break;
            }
            if ($a->current_version_id === null) {
                continue;
            }
            try {
                $runner->run($a->id, null);
                SeoAuditRun::query()->where('article_id', $a->id)->update(['is_demo' => true]);
                $made++;
            } catch (Throwable) {
                $this->demoAudit($site, $a);
                $made++;
            }
        }
        foreach (array_slice($articles, 0, 10 - $made) as $a) {
            $this->demoAudit($site, $a);
        }
    }

    private function demoAudit(Site $site, Article $a): void
    {
        $run = new SeoAuditRun;
        $run->forceFill([
            'article_id' => $a->id, 'site_id' => $site->id, 'article_version_id' => $a->current_version_id, 'content_hash' => hash('sha256', $a->id),
            'rules_version' => 'demo', 'status' => 'completed', 'score' => 72, 'pass_count' => 2, 'warning_count' => 1, 'error_count' => 1, 'blocked_count' => 0,
            'links_checked' => false, 'summary' => ['demo' => true], 'is_demo' => true,
        ])->save();
        foreach ([
            ['title_length', 'metadata', 'PASS', 8, '[DEMO] SEO title dài phù hợp.'],
            ['meta_description', 'metadata', 'ERROR', 8, '[DEMO] Thiếu meta description.'],
            ['image_alt', 'media', 'WARNING', 5, '[DEMO] Một ảnh chưa có alt text.'],
            ['single_h1', 'structure', 'PASS', 6, '[DEMO] Bài có đúng một H1.'],
        ] as [$rule, $cat, $sev, $w, $msg]) {
            (new SeoAuditIssue)->forceFill([
                'seo_audit_run_id' => $run->id, 'rule' => $rule, 'category' => $cat, 'severity' => $sev, 'weight' => $w,
                'points' => $sev === 'PASS' ? $w : ($sev === 'WARNING' ? $w / 2 : 0), 'message' => $msg,
                'explanation' => '[DEMO] Dữ liệu minh họa, không phải kết quả kiểm tra thật.', 'fix' => $sev === 'PASS' ? null : '[DEMO] Bổ sung trong Metadata SEO.', 'context' => [],
            ])->save();
        }
    }

    /**
     * 20 jobs: 5 scheduled, 6 published, 3 update-pending with open conflicts,
     * 2 failed, 2 ready for review, 1 draft, 1 cancelled.
     *
     * @param  list<Article>  $articles
     * @param  list<MediaAsset>  $assets
     */
    private function publishing(Site $site, array $articles, array $assets): void
    {
        (new PublishingPolicy)->forceFill(['site_id' => $site->id, 'default_mode' => 'manual', 'auto_publish_enabled' => false])->save();
        $plan = [...array_fill(0, 5, 'SCHEDULED'), ...array_fill(0, 6, 'PUBLISHED'), ...array_fill(0, 3, 'UPDATE_PENDING'),
            'FAILED', 'FAILED', 'READY_FOR_REVIEW', 'READY_FOR_REVIEW', 'DRAFT', 'CANCELLED'];
        $host = (string) parse_url(ResearchDemoSeeder::SITE_URL, PHP_URL_HOST);
        $gates = static fn (bool $ok): array => array_map(fn (string $g) => ['gate' => $g, 'ok' => $ok || $g !== 'seo', 'code' => $ok || $g !== 'seo' ? null : 'seo_errors',
            'message' => '[DEMO] '.($ok || $g !== 'seo' ? __('publishing.gates.'.$g.'_ok') : __('publishing.codes.seo_errors')), 'details' => []],
            ['site', 'article_approved', 'fact_check', 'seo', 'media', 'policy', 'quota', 'conflict']);
        $conflictTypes = [['manual_edit'], ['seo_modified', 'slug_changed'], ['post_missing']];
        $c = 0;
        foreach ($plan as $i => $status) {
            $a = $articles[$i];
            $slug = 'demo-bai-viet-'.($i + 1);
            $hash = hash('sha256', 'demo|'.$a->id);
            $mapping = null;
            if (in_array($status, ['PUBLISHED', 'UPDATE_PENDING'], true)) {
                $mapping = new WordPressPostMapping;
                $mapping->forceFill([
                    'site_id' => $site->id, 'article_id' => $a->id, 'external_content_id' => 'sxa-'.strtolower($a->id), 'idempotency_key' => 'sxa-'.strtolower($a->id),
                    'is_current' => true, 'wp_post_id' => 5000 + $i, 'wp_status' => 'publish', 'url' => ResearchDemoSeeder::SITE_URL.'/'.$slug.'/', 'slug' => $slug,
                    'site_host' => $host, 'last_published_version_id' => $a->current_version_id, 'last_payload_hash' => $hash, 'last_synced_hash' => $hash,
                    'last_seo_hash' => hash('sha256', 'seo|'.$a->id), 'last_modified_gmt' => now()->subDays(3)->format('Y-m-d H:i:s'), 'last_revision_id' => 8000 + $i,
                    'last_published_at' => now()->subDays(10 - $i), 'last_checked_at' => now()->subHours(6),
                ])->save();
                (new WordPressSyncState)->forceFill(['mapping_id' => $mapping->id, 'state' => $status === 'PUBLISHED' ? 'in_sync' : ($conflictTypes[$c][0] === 'post_missing' ? 'missing' : $conflictTypes[$c][0]),
                    'wp_status' => 'publish', 'wp_slug' => $slug, 'details' => ['demo' => true], 'checked_at' => now()->subHours(6)])->save();
            }
            $scheduledAt = $status === 'SCHEDULED' ? Carbon::now(self::TZ)->addDays($i + 1)->setTime(9 + $i, 0)->utc() : null;
            $job = new PublishingJob;
            $job->forceFill([
                'site_id' => $site->id, 'article_id' => $a->id, 'campaign_id' => $a->campaign_id, 'mapping_id' => $mapping?->id,
                'mode' => match ($status) {
                    'SCHEDULED' => 'scheduled', 'UPDATE_PENDING' => 'update', 'READY_FOR_REVIEW', 'DRAFT' => 'wp_draft', default => 'publish_now'
                },
                'status' => $status, 'active_key' => in_array($status, ['PUBLISHED', 'CANCELLED'], true) ? null : $site->id.':'.$a->id,
                'article_version_id' => $a->current_version_id, 'content_hash' => $hash,
                'payload_meta' => ['timezone' => self::TZ, 'wp_post_id' => $mapping?->wp_post_id, 'url' => $mapping?->url, 'demo' => true],
                'gate_results' => $gates($status !== 'DRAFT'), 'blocked_reason' => $status === 'DRAFT' ? 'seo_errors' : ($status === 'UPDATE_PENDING' ? 'unresolved_conflict' : null),
                'scheduled_at' => $scheduledAt, 'wp_target_status' => $status === 'READY_FOR_REVIEW' || $status === 'DRAFT' ? 'draft' : 'publish',
                'attempts' => match ($status) {
                    'FAILED' => 5, 'PUBLISHED', 'UPDATE_PENDING' => 1, default => 0
                }, 'max_attempts' => 5,
                'last_error_code' => $status === 'FAILED' ? 'unreachable' : null,
                'last_error_message' => $status === 'FAILED' ? '[DEMO] Không kết nối được website sau 5 lần thử.' : null,
                'idempotency_key' => 'demo-job-'.$i, 'approval_source' => in_array($status, ['DRAFT', 'READY_FOR_REVIEW'], true) ? null : 'user',
                'approved_at' => in_array($status, ['DRAFT', 'READY_FOR_REVIEW'], true) ? null : now()->subDays(2),
                'published_at' => $status === 'PUBLISHED' ? now()->subDays(10 - $i) : null, 'is_demo' => true,
            ])->save();
            if ($status === 'SCHEDULED' && $scheduledAt !== null) {
                (new PublishingSchedule)->forceFill(['site_id' => $site->id, 'publishing_job_id' => $job->id, 'scheduled_at' => $scheduledAt, 'timezone' => self::TZ,
                    'local_time' => $scheduledAt->copy()->setTimezone(self::TZ)->format('Y-m-d H:i'), 'status' => 'pending', 'note' => '[DEMO]'])->save();
            }
            for ($n = 1; $n <= (int) $job->attempts; $n++) {
                $ok = $status !== 'FAILED';
                (new PublishingAttempt)->forceFill(['publishing_job_id' => $job->id, 'attempt_no' => $n, 'operation' => $status === 'UPDATE_PENDING' ? 'update' : 'create',
                    'outcome' => $ok ? 'succeeded' : 'failed', 'error_code' => $ok ? null : 'unreachable', 'http_status' => $ok ? 200 : null, 'retryable' => ! $ok,
                    'payload_hash' => $hash, 'wp_post_id' => $mapping?->wp_post_id, 'duration_ms' => 800 + 100 * $n, 'details' => ['demo' => true]])->save();
            }
            if ($status === 'UPDATE_PENDING' && $mapping !== null) {
                $types = $conflictTypes[$c++];
                (new PublishingConflict)->forceFill([
                    'site_id' => $site->id, 'article_id' => $a->id, 'mapping_id' => $mapping->id, 'publishing_job_id' => $job->id, 'types' => $types, 'status' => 'open',
                    'wp_state' => $types === ['post_missing'] ? null : ['id' => $mapping->wp_post_id, 'status' => 'publish', 'title' => '[DEMO] '.$a->title.' (đã sửa trên WordPress)',
                        'slug' => in_array('slug_changed', $types, true) ? $slug.'-moi' : $slug, 'url' => $mapping->url, 'modified_gmt' => now()->subHours(5)->format('Y-m-d H:i:s'),
                        'content_hash' => in_array('manual_edit', $types, true) ? hash('sha256', 'edited|'.$a->id) : $hash,
                        'seo_hash' => in_array('seo_modified', $types, true) ? hash('sha256', 'seo-edited|'.$a->id) : $mapping->last_seo_hash, 'revision_id' => 8100 + $i],
                    'expected_state' => ['content_hash' => $mapping->last_synced_hash, 'slug' => $mapping->slug, 'seo_hash' => $mapping->last_seo_hash, 'site_host' => $host, 'revision_id' => $mapping->last_revision_id],
                    'note' => '[DEMO] Dữ liệu minh họa', 'is_demo' => true,
                ])->save();
            }
        }
        // Image placements for the first two scheduled articles (alt text set, featured + one section).
        foreach (array_slice($articles, 0, 2) as $k => $a) {
            foreach ([['featured', $assets[20 + $k]], ['section', $assets[$k]]] as $p => [$slot, $asset]) {
                (new ImagePlacement)->forceFill(['article_id' => $a->id, 'media_asset_id' => $asset->id, 'slot' => $slot, 'position' => $p, 'status' => 'accepted',
                    'alt_text' => $asset->alt_text ?? 'Ảnh minh họa (DEMO)', 'origin' => 'demo', 'reason' => '[DEMO]'])->save();
            }
        }
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    private static function png(int $w, int $h, array $rgb, int $seed): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, (int) imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]));
        $white = (int) imagecolorallocate($img, 255, 255, 255);
        imagefilledellipse($img, 160 + ($seed * 37) % ($w - 320), (int) ($h / 2), 200 + $seed * 3, 200 + $seed * 3, $white);
        imagefilledrectangle($img, 40, $h - 80, 40 + (($seed * 53) % ($w - 120)), $h - 50, $white);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
