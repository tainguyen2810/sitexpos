<?php

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use SiteXPost\Modules\AiGateway\Providers\FakeProvider;
use SiteXPost\Modules\AiGateway\Providers\ProviderRequest;
use Tests\Support\ScriptedAi;

/*
 * Phase 5 test fixtures: a research tenant whose public "manufacturer"
 * pages are served by Http::fake, and a scripted AI that behaves like a
 * careful model (verbatim excerpts, [E<ref>] markers, claims quoted from
 * the text). Individual tests override handlers to simulate bad models.
 */

const AX210_URL = 'https://www.intel.example/content/www/us/en/products/ax210/specifications.html';
const AX200_URL = 'https://www.intel.example/content/www/us/en/products/ax200/specifications.html';

function writerPages(): array
{
    $page = static fn (string $title, string $body, string $date = '2025-03-01'): string => '<!doctype html><html lang="en"><head><title>'.$title.'</title>'
        .'<meta property="article:modified_time" content="'.$date.'T00:00:00Z"></head><body><main><h1>'.$title.'</h1>'.$body.'</main></body></html>';

    return [
        AX210_URL => $page('Intel Wi-Fi 6E AX210 Specifications', '<p>The Intel Wi-Fi 6E AX210 supports the 6 GHz band in addition to 2.4 GHz and 5 GHz.</p>'
            .'<p>The Intel Wi-Fi 6E AX210 supports Bluetooth 5.3 and a maximum speed of 2.4 Gbps.</p><p>Windows 10 64-bit and Windows 11 are supported operating systems.</p>'),
        AX200_URL => $page('Intel Wi-Fi 6 AX200 Specifications', '<p>The Intel Wi-Fi 6 AX200 supports the 2.4 GHz and 5 GHz bands only.</p>'
            .'<p>The Intel Wi-Fi 6 AX200 does not support the 6 GHz band.</p>'),
        'https://www.intel.example/robots.txt' => "User-agent: *\nAllow: /\n",
    ];
}

/**
 * @return array<string, mixed> research tenant + 'ai' (ScriptedAi)
 */
function writerTenant(array $opts = []): array
{
    // Sync queue: chained stages run nested inside the previous stage's
    // hand-off step, so each nesting level holds a tenant slot.
    config(['sitex.workflows.tenant_concurrency' => 10]);
    $pages = writerPages() + ($opts['pages'] ?? []);
    $t = researchTenant('Wifi Store', ['http' => function (HttpRequest $r) use ($pages) {
        $url = strtok($r->url(), '?');
        if (isset($pages[$url])) {
            return Http::response($pages[$url], 200, ['Content-Type' => str_ends_with($url, '.txt') ? 'text/plain' : 'text/html; charset=utf-8']);
        }
        if (str_contains($url, 'intel.example')) {
            return Http::response('not found', 404, ['Content-Type' => 'text/html']);
        }

        return null;
    }]);
    $ai = scriptedWriterAi();
    app()->instance(FakeProvider::class, $ai);

    return $t + ['ai' => $ai];
}

/** The evidence ref whose statement contains $needle in a prompt's JSON context (0 when absent). */
function refIn(string $prompt, string $needle): int
{
    return preg_match('/"ref":(\d+),"statement":"[^"]*'.preg_quote($needle, '/').'/u', $prompt, $m) === 1 ? (int) $m[1] : 0;
}

function scriptedWriterAi(): ScriptedAi
{
    $ai = new ScriptedAi;
    $ai->on('research_questions', fn () => ['questions' => [
        ['question' => 'Intel AX210 có hỗ trợ băng tần 6 GHz không?', 'purpose' => 'Xác minh thông số'],
        ['question' => 'AX200 khác AX210 thế nào?', 'purpose' => 'So sánh model'],
    ]]);
    $ai->on('source_candidates', fn () => ['urls' => []]);
    $ai->on('evidence', function (ProviderRequest $r) {
        $text = ScriptedAi::data($r->prompt);
        $items = [];
        foreach (['The Intel Wi-Fi 6E AX210 supports the 6 GHz band in addition to 2.4 GHz and 5 GHz.', 'The Intel Wi-Fi 6E AX210 supports Bluetooth 5.3 and a maximum speed of 2.4 Gbps.',
            'The Intel Wi-Fi 6 AX200 does not support the 6 GHz band.'] as $s) {
            if (str_contains($text, $s)) {
                $items[] = ['question' => 1, 'excerpt' => $s, 'statement' => $s, 'date_context' => '', 'version_context' => '', 'relevance' => 90];
            }
        }

        return ['items' => $items];
    });
    $ai->on('content_brief', fn (ProviderRequest $r) => [
        'title_suggestions' => ['Intel AX210 có hỗ trợ WiFi 6E không?'], 'search_intent' => 'informational', 'audience' => 'Người dùng laptop',
        'main_question' => 'AX210 có hỗ trợ 6 GHz không?', 'supporting_questions' => ['AX200 có hỗ trợ 6 GHz không?'], 'content_angle' => 'Giải thích rõ', 'differentiation' => 'Dựa trên tài liệu Intel',
        'structure' => [['heading' => 'AX210 hỗ trợ những băng tần nào?', 'level' => 2, 'purpose' => 'Trả lời'], ['heading' => 'Khác biệt với AX200', 'level' => 2, 'purpose' => 'So sánh']],
        'key_facts' => [['fact' => 'AX210 hỗ trợ 6 GHz', 'evidence_refs' => [refIn($r->prompt, 'AX210 supports the 6 GHz')]], ['fact' => 'Bịa đặt không có nguồn', 'evidence_refs' => [99]]],
        'required_evidence' => [], 'faq' => [], 'internal_links' => [['url' => 'https://evil.example/x', 'anchor' => 'x']], 'risks' => ['Nhầm AX200/AX210'], 'missing_information' => [],
    ]);
    $ai->on('article_outline', fn (ProviderRequest $r) => ['sections' => [
        ['heading' => 'AX210 hỗ trợ những băng tần nào?', 'level' => 2, 'purpose' => 'Trả lời', 'key_questions' => [], 'required_facts' => [['fact' => 'AX210 hỗ trợ 6 GHz', 'evidence_refs' => [refIn($r->prompt, 'AX210 supports the 6 GHz') ?: 1]]],
            'word_min' => 80, 'word_max' => 200, 'internal_links' => [], 'blocks' => ['paragraphs']],
        ['heading' => 'Khác biệt với AX200', 'level' => 2, 'purpose' => 'So sánh', 'key_questions' => [], 'required_facts' => [], 'word_min' => 80, 'word_max' => 200, 'internal_links' => [], 'blocks' => ['paragraphs']],
    ]]);
    $ai->on('article_section', function (ProviderRequest $r) {
        $ctx = ScriptedAi::json($r->prompt);
        $heading = (string) ($ctx['section']['heading'] ?? '');

        return match (true) {
            str_contains($heading, 'băng tần') => ['html' => '<p>Intel AX210 hỗ trợ băng tần 6 GHz bên cạnh 2.4 GHz và 5 GHz.[E'.refIn($r->prompt, 'AX210 supports the 6 GHz').']</p><p>Đây là điểm khác biệt chính của chuẩn WiFi 6E.</p>', 'used_evidence_refs' => [], 'notes' => []],
            str_contains($heading, 'AX200') => ['html' => '<p>Intel AX200 không hỗ trợ băng tần 6 GHz.[E'.refIn($r->prompt, 'AX200 does not support').']</p>', 'used_evidence_refs' => [], 'notes' => []],
            default => ['html' => '<p>Bài viết giải thích khả năng hỗ trợ WiFi 6E của card Intel AX210.</p>', 'used_evidence_refs' => [], 'notes' => []],
        };
    });
    $ai->on('claims', function (ProviderRequest $r) {
        $text = ScriptedAi::data($r->prompt);
        $out = [];
        foreach (['Intel AX210 hỗ trợ băng tần 6 GHz bên cạnh 2.4 GHz và 5 GHz.' => ['Intel AX210 hỗ trợ băng tần 6 GHz', 'spec', 'HIGH'],
            'Intel AX200 không hỗ trợ băng tần 6 GHz.' => ['Intel AX200 không hỗ trợ băng tần 6 GHz', 'spec', 'HIGH']] as $quote => [$claim, $cat, $risk]) {
            if (str_contains($text, $quote)) {
                $out[] = ['quote' => $quote, 'claim' => $claim, 'category' => $cat, 'risk' => $risk, 'evidence_requirements' => 'Tài liệu Intel'];
            }
        }

        return ['claims' => $out];
    });
    $ai->on('fact_check', function (ProviderRequest $r) {
        $claims = json_decode(ScriptedAi::data($r->prompt), true) ?: [];

        return ['results' => array_map(fn ($c) => ['claim' => $c['n'], 'verdict' => ($c['evidence'] ?? []) === [] ? 'INSUFFICIENT_EVIDENCE' : 'VERIFIED',
            'supporting_refs' => array_column($c['evidence'] ?? [], 'ref'), 'contradicting_refs' => [], 'date_context' => '', 'explanation' => 'Khớp tài liệu.'], $claims)];
    });
    $ai->on('rewrite', fn (ProviderRequest $r) => ['html' => '<p>Intel AX210 hỗ trợ băng tần 6 GHz bên cạnh 2.4 GHz và 5 GHz.[E1]</p><p>Nói ngắn gọn: AX210 là card WiFi 6E.</p>', 'changes' => []]);
    $ai->on('review_notes', fn () => ['notes' => [['severity' => 'info', 'message' => 'Có thể thêm FAQ.']]]);

    return $ai;
}

function writerUrl(array $t, string $path = ''): string
{
    return "/api/v1/workspaces/{$t['workspace']->id}/writer".($path === '' ? '' : '/'.$path);
}

/**
 * @param  array<string, mixed>  $extra
 */
function createArticle(array $t, array $extra = [], ?string $key = null): TestResponse
{
    return test()->actingAs($t['owner'])->postJson(writerUrl($t, 'articles'), $extra + [
        'site_id' => $t['site']->id, 'topic' => 'Intel AX210 có hỗ trợ WiFi 6E không?', 'primary_keyword' => 'intel ax210 wifi 6e',
        'content_type' => 'product_guide', 'target_words' => 600, 'source_urls' => [AX210_URL, AX200_URL], 'ai_source_discovery' => false,
    ], $key !== null ? ['Idempotency-Key' => $key] : []);
}
