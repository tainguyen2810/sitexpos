<?php

use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Publishing\Domain\PublishingPolicy;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Domain\SiteContentItem;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use Tests\Support\FakeWordPress;

/*
 * Phase 6 fixtures: an APPROVED article (human-approved version, no open
 * fact-check issue) on a site paired with the FakeWordPress plugin.
 */

function phase6Html(string $kw = 'máy lọc nước'): string
{
    $p = static fn (string $s): string => '<p>'.$s.'</p>';
    $body = str_repeat('Nội dung giải thích chi tiết từng bước để người đọc hiểu rõ cách chọn và sử dụng thiết bị trong gia đình một cách an toàn và tiết kiệm. ', 4);

    return '<h1>Cách chọn '.$kw.' cho gia đình</h1>'
        .$p('Bài viết hướng dẫn cách chọn '.$kw.' phù hợp cho gia đình, dựa trên nhu cầu sử dụng và nguồn nước thực tế. '.$body)
        .'<h2>Tiêu chí chọn '.$kw.'</h2>'.$p('Khi chọn '.$kw.', hãy xem công suất lọc, loại lõi lọc và chi phí thay lõi định kỳ. '.$body)
        .$p('Ngoài ra cần kiểm tra chế độ bảo hành và dịch vụ hậu mãi của nhà cung cấp tại địa phương. '.$body)
        .'<h2>Cách bảo dưỡng thiết bị</h2>'.$p('Vệ sinh và thay lõi đúng hạn giúp thiết bị hoạt động ổn định và nước luôn sạch. '.$body)
        .'<h2>Câu hỏi thường gặp</h2><h3>Bao lâu thay lõi một lần?</h3>'.$p('Thông thường từ sáu đến mười hai tháng tùy loại lõi và chất lượng nguồn nước.')
        .'<h3>Có cần lắp đặt chuyên nghiệp không?</h3>'.$p('Nên nhờ kỹ thuật viên để bảo đảm đường ống kín và an toàn điện.');
}

/**
 * @param  array<string, mixed>  $opts  status, critical, title, keyword, html
 */
function approvedArticle(Workspace $workspace, Site $site, array $opts = []): string
{
    return app(TenantContext::class)->runAs($workspace, null, function () use ($site, $opts): string {
        $W = 'SiteXPost\\Modules\\Writer\\Domain\\';
        $kw = $opts['keyword'] ?? 'máy lọc nước';
        $title = $opts['title'] ?? 'Cách chọn '.$kw.' cho gia đình';
        $article = new ($W.'Article');
        $article->forceFill(['site_id' => $site->id, 'title' => $title, 'status' => $opts['status'] ?? 'APPROVED', 'primary_keyword' => $kw,
            'search_intent' => 'informational', 'content_type' => 'guide', 'settings' => [], 'budget_limit_micro_usd' => 100000,
            'fingerprint' => sha1($title.uniqid()), 'unresolved_critical' => $opts['critical'] ?? 0, 'unresolved_high' => 0])->save();
        $html = $opts['html'] ?? phase6Html($kw);
        $version = new ($W.'ArticleVersion');
        $version->forceFill(['article_id' => $article->id, 'number' => 1, 'source' => 'human', 'title' => $title, 'content_html' => $html,
            'content_hash' => hash('sha256', $html), 'section_hashes' => [], 'word_count' => 400])->save();
        $article->forceFill(['current_version_id' => $version->id] + (($opts['status'] ?? 'APPROVED') === 'APPROVED'
            ? ['approved_version_id' => $version->id, 'approved_at' => now()] : []))->save();

        return $article->id;
    });
}

/**
 * @return array<string, mixed>
 */
function publishingTenant(array $policy = []): array
{
    fakePublicDns();
    $t = workspaceWithRoles('Publisher');
    $wp = (new FakeWordPress)->install();
    $site = connectedSite($t['workspace'], $wp, 'Blog');
    app(TenantContext::class)->runAs($t['workspace'], null, function () use ($site, $policy): void {
        $p = new PublishingPolicy;
        $p->forceFill($policy + ['site_id' => $site->id, 'require_media_ready' => false, 'window_start_hour' => 0, 'window_end_hour' => 24,
            'min_minutes_between_posts' => 0, 'max_posts_per_day' => 20])->save();
        $item = new SiteContentItem;
        $item->forceFill(['site_id' => $site->id, 'wp_post_id' => 7, 'post_type' => 'post', 'status' => 'publish', 'title' => 'Hướng dẫn thay lõi máy lọc nước tại nhà',
            'url' => $site->url.'/thay-loi-may-loc-nuoc/', 'slug' => 'thay-loi-may-loc-nuoc', 'modified_at' => now(), 'synced_at' => now()])->save();
    });

    return $t + ['wp' => $wp, 'site' => $site];
}

function p6url(Workspace $ws, string $path): string
{
    return '/api/v1/workspaces/'.$ws->id.'/'.ltrim($path, '/');
}
