<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Audit;

use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Seo\Support\ArticleHtml;

/**
 * Explainable on-page SEO checklist (SEO-AUDIT-RULES.md). Every rule is a
 * pure function of the audit context and returns PASS / WARNING / ERROR /
 * BLOCKED with the reason and how to fix it. The score is a weighted
 * checklist — never a ranking guarantee.
 *
 * Context keys: title, html (ArticleHtml of the publishable render), meta
 * (seo_title, meta_description, slug, canonical_url, focus_keyword,
 * robots_noindex), article (search_intent, content_type), site (host,
 * yoast, rank_math), policy (allow_canonical_override, allow_robots_override),
 * inventory (synced WordPress items), own_wp_post_id, images (rendered images
 * + featured), link_status (url => http status|null, null map = not checked),
 * schema (SchemaBuilder result), sources (cited sources count).
 */
final class SeoRules
{
    public const VERSION = '2026.11-1';

    /**
     * @param  array<string, mixed>  $c
     * @return list<AuditIssue>
     */
    public function run(array $c): array
    {
        return [
            $this->h1($c), $this->headings($c), $this->seoTitle($c), $this->metaDescription($c), $this->slug($c),
            $this->keyword($c), $this->intent($c), $this->internalLinks($c), $this->externalLinks($c), $this->brokenLinks($c),
            $this->imageAlt($c), $this->imageSize($c), $this->duplicate($c), $this->thin($c), $this->readability($c),
            $this->structuredData($c), $this->canonical($c), $this->indexability($c), $this->wpConflicts($c),
        ];
    }

    /**
     * @param  list<AuditIssue>  $issues
     */
    public static function score(array $issues): int
    {
        $max = array_sum(array_map(fn (AuditIssue $i) => $i->weight, $issues));

        return $max > 0 ? (int) round(100 * array_sum(array_map(fn (AuditIssue $i) => $i->points(), $issues)) / $max) : 0;
    }

    /** @param array<string, mixed> $c */
    private function h1(array $c): AuditIssue
    {
        /** @var ArticleHtml $h */
        $h = $c['html'];
        $title = trim((string) $c['title']);
        $extra = count(array_filter($h->headings, fn ($x) => $x['level'] === 1));
        if ($title === '') {
            return $this->issue('h1', 'structure', AuditIssue::ERROR, 5, 'Bài chưa có tiêu đề (H1).', 'WordPress in tiêu đề bài làm thẻ H1 của trang; thiếu tiêu đề thì trang không có H1.', 'Đặt tiêu đề cho bài.');
        }
        if ($extra > 0) {
            return $this->issue('h1', 'structure', AuditIssue::ERROR, 5, "Nội dung có {$extra} thẻ H1 thừa.", 'Theme WordPress đã in tiêu đề bài làm H1; thêm H1 trong nội dung tạo nhiều H1 trên một trang.', 'Đổi các H1 trong nội dung thành H2.');
        }
        if (mb_strlen($title) > 70) {
            return $this->issue('h1', 'structure', AuditIssue::WARNING, 5, 'Tiêu đề (H1) dài '.mb_strlen($title).' ký tự.', 'Tiêu đề quá dài khó đọc trên di động; nên dưới 70 ký tự.', 'Rút gọn tiêu đề.');
        }

        return $this->issue('h1', 'structure', AuditIssue::PASS, 5, 'Có đúng một H1 (tiêu đề bài).', 'WordPress in tiêu đề làm H1; nội dung không chứa H1 khác.');
    }

    /** @param array<string, mixed> $c */
    private function headings(array $c): AuditIssue
    {
        /** @var ArticleHtml $h */
        $h = $c['html'];
        $list = array_values(array_filter($h->headings, fn ($x) => $x['level'] > 1));
        foreach ($list as $x) {
            if ($x['text'] === '') {
                return $this->issue('heading_hierarchy', 'structure', AuditIssue::ERROR, 8, 'Có heading rỗng.', 'Heading rỗng làm hỏng cấu trúc tài liệu và mục lục.', 'Xóa hoặc điền nội dung cho heading.');
            }
        }
        $problems = [];
        $prev = 1;
        foreach ($list as $x) {
            if ($x['level'] > $prev + 1) {
                $problems[] = 'H'.$prev.' → H'.$x['level'].' ("'.mb_substr($x['text'], 0, 40).'")';
            }
            $prev = $x['level'];
        }
        $h2 = count(array_filter($list, fn ($x) => $x['level'] === 2));
        if ($problems !== []) {
            return $this->issue('heading_hierarchy', 'structure', AuditIssue::WARNING, 8, 'Heading nhảy cấp: '.implode(', ', array_slice($problems, 0, 3)).'.', 'Cấp heading nên tăng dần từng bậc (H2 → H3) để công cụ tìm kiếm và trình đọc màn hình hiểu cấu trúc.', 'Sửa cấp heading cho liền mạch.', ['jumps' => $problems]);
        }
        if ($h->words > 600 && $h2 < 2) {
            return $this->issue('heading_hierarchy', 'structure', AuditIssue::WARNING, 8, "Bài {$h->words} từ nhưng chỉ có {$h2} H2.", 'Bài dài nên chia thành nhiều phần có H2 để dễ quét.', 'Thêm H2 cho các ý chính.');
        }

        return $this->issue('heading_hierarchy', 'structure', AuditIssue::PASS, 8, "{$h2} H2, cấp heading liền mạch.", 'Cấu trúc heading hợp lệ.');
    }

    /** @param array<string, mixed> $c */
    private function seoTitle(array $c): AuditIssue
    {
        $t = trim((string) ($c['meta']['seo_title'] ?? ''));
        $len = mb_strlen($t);
        if ($t === '') {
            return $this->issue('seo_title', 'metadata', AuditIssue::ERROR, 10, 'Chưa có SEO title.', 'SEO title là dòng tiêu đề hiển thị trên kết quả tìm kiếm.', 'Tạo hoặc nhập SEO title.');
        }
        $kw = $this->hasKeyword($t, (string) ($c['meta']['focus_keyword'] ?? ''));
        if ($len > 70) {
            return $this->issue('seo_title', 'metadata', AuditIssue::ERROR, 10, "SEO title dài {$len} ký tự.", 'Tiêu đề trên ~60–65 ký tự thường bị Google cắt bớt.', 'Rút gọn còn 30–60 ký tự.', ['length' => $len]);
        }
        if ($len < 30 || $len > 60 || ! $kw) {
            $why = [];
            if ($len < 30 || $len > 60) {
                $why[] = "dài {$len} ký tự (khuyến nghị 30–60)";
            }
            if (! $kw) {
                $why[] = 'không chứa từ khóa chính';
            }

            return $this->issue('seo_title', 'metadata', AuditIssue::WARNING, 10, 'SEO title '.implode(', ', $why).'.', 'Độ dài 30–60 ký tự và có từ khóa chính giúp tiêu đề hiển thị đủ và rõ chủ đề.', 'Chỉnh SEO title.', ['length' => $len, 'has_keyword' => $kw]);
        }

        return $this->issue('seo_title', 'metadata', AuditIssue::PASS, 10, "SEO title {$len} ký tự, có từ khóa chính.", 'Độ dài và từ khóa phù hợp.');
    }

    /** @param array<string, mixed> $c */
    private function metaDescription(array $c): AuditIssue
    {
        $d = trim((string) ($c['meta']['meta_description'] ?? ''));
        $len = mb_strlen($d);
        if ($d === '') {
            return $this->issue('meta_description', 'metadata', AuditIssue::WARNING, 8, 'Chưa có meta description.', 'Không có mô tả, Google tự trích một đoạn trong bài — có thể không phù hợp.', 'Tạo hoặc nhập meta description 70–160 ký tự.');
        }
        if ($len < 70 || $len > 160) {
            return $this->issue('meta_description', 'metadata', AuditIssue::WARNING, 8, "Meta description dài {$len} ký tự.", 'Khoảng 70–160 ký tự hiển thị trọn vẹn trên kết quả tìm kiếm.', 'Chỉnh độ dài meta description.', ['length' => $len]);
        }

        return $this->issue('meta_description', 'metadata', AuditIssue::PASS, 8, "Meta description {$len} ký tự.", 'Độ dài phù hợp.');
    }

    /** @param array<string, mixed> $c */
    private function slug(array $c): AuditIssue
    {
        $s = (string) ($c['meta']['slug'] ?? '');
        if ($s === '') {
            return $this->issue('slug', 'metadata', AuditIssue::ERROR, 6, 'Chưa có slug.', 'WordPress sẽ tự tạo slug từ tiêu đề, thường dài và khó kiểm soát.', 'Tạo slug ngắn từ từ khóa chính.');
        }
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $s) !== 1) {
            return $this->issue('slug', 'metadata', AuditIssue::ERROR, 6, 'Slug chứa ký tự không hợp lệ (dấu, chữ hoa, khoảng trắng).', 'URL nên chỉ gồm chữ thường không dấu, số và dấu gạch ngang.', 'Dùng slug dạng "tu-khoa-chinh".');
        }
        $kwTokens = Text::tokens(Text::fold((string) ($c['meta']['focus_keyword'] ?? '')));
        $cover = $kwTokens === [] ? 1.0 : Text::containment($kwTokens, explode('-', $s));
        if (strlen($s) > 75 || $cover < 0.5) {
            return $this->issue('slug', 'metadata', AuditIssue::WARNING, 6, strlen($s) > 75 ? 'Slug dài '.strlen($s).' ký tự.' : 'Slug không chứa từ khóa chính.', 'Slug ngắn, có từ khóa giúp người đọc và công cụ tìm kiếm hiểu trang.', 'Rút gọn slug, giữ từ khóa chính.');
        }

        return $this->issue('slug', 'metadata', AuditIssue::PASS, 6, 'Slug ngắn gọn, có từ khóa.', 'Slug hợp lệ.');
    }

    /** @param array<string, mixed> $c */
    private function keyword(array $c): AuditIssue
    {
        /** @var ArticleHtml $h */
        $h = $c['html'];
        $kw = trim((string) ($c['meta']['focus_keyword'] ?? ''));
        if ($kw === '') {
            return $this->issue('keyword_relevance', 'content', AuditIssue::WARNING, 10, 'Chưa đặt từ khóa chính.', 'Không có từ khóa chính thì không đánh giá được mức liên quan.', 'Nhập focus keyword.');
        }
        $inTitle = $this->hasKeyword((string) $c['title'], $kw);
        $inIntro = $this->hasKeyword(implode(' ', array_column(array_slice($h->paragraphs, 0, 2), 'text')), $kw);
        $inH2 = false;
        foreach ($h->headings as $x) {
            if ($x['level'] === 2 && $this->hasKeyword($x['text'], $kw)) {
                $inH2 = true;
            }
        }
        $occ = substr_count(' '.Text::fold($h->text).' ', ' '.Text::fold($kw).' ');
        $density = $h->words > 0 ? round(100 * $occ * max(1, count(Text::tokens($kw))) / $h->words, 2) : 0.0;
        $ctx = ['in_title' => $inTitle, 'in_intro' => $inIntro, 'in_h2' => $inH2, 'occurrences' => $occ, 'density_percent' => $density];
        if (! $inTitle && ! $inIntro && ! $inH2) {
            return $this->issue('keyword_relevance', 'content', AuditIssue::ERROR, 10, 'Từ khóa chính không xuất hiện ở tiêu đề, đoạn mở đầu hay H2 nào.', 'Nội dung có vẻ không tập trung vào từ khóa đã chọn.', 'Kiểm tra lại từ khóa hoặc nội dung.', $ctx);
        }
        if ($density > 4) {
            return $this->issue('keyword_relevance', 'content', AuditIssue::WARNING, 10, "Mật độ từ khóa {$density}% — có dấu hiệu nhồi từ khóa.", 'Lặp từ khóa quá nhiều làm văn bản thiếu tự nhiên.', 'Thay bớt bằng từ đồng nghĩa.', $ctx);
        }
        if (! ($inTitle && $inIntro && $inH2)) {
            $miss = array_keys(array_filter(['tiêu đề' => ! $inTitle, 'đoạn mở đầu' => ! $inIntro, 'H2' => ! $inH2]));

            return $this->issue('keyword_relevance', 'content', AuditIssue::WARNING, 10, 'Từ khóa chính chưa có ở: '.implode(', ', $miss).'.', 'Từ khóa ở tiêu đề, đoạn mở đầu và ít nhất một H2 giúp làm rõ chủ đề.', 'Bổ sung tự nhiên ở các vị trí còn thiếu.', $ctx);
        }

        return $this->issue('keyword_relevance', 'content', AuditIssue::PASS, 10, "Từ khóa có ở tiêu đề, mở đầu, H2; mật độ {$density}%.", 'Nội dung tập trung đúng chủ đề.', null, $ctx);
    }

    /** @param array<string, mixed> $c */
    private function intent(array $c): AuditIssue
    {
        /** @var ArticleHtml $h */
        $h = $c['html'];
        $intent = (string) ($c['article']['search_intent'] ?? 'informational');
        $t = ' '.Text::fold($h->text).' ';
        $commercialWords = 0;
        foreach ([' gia ', ' mua ', ' dat hang ', ' khuyen mai ', ' giam gia ', ' lien he ', ' bao gia '] as $w) {
            $commercialWords += substr_count($t, $w);
        }
        $per1k = $h->words > 0 ? $commercialWords * 1000 / $h->words : 0;
        $hasComparison = $h->tables > 0 || str_contains($t, ' so sanh ');
        $note = 'Đây là kiểm tra heuristic dựa trên từ ngữ, không phải phân tích SERP.';
        if ($intent === 'informational' && $per1k > 15) {
            return $this->issue('search_intent', 'content', AuditIssue::WARNING, 6, 'Ý định tìm kiếm là thông tin nhưng bài có nhiều từ bán hàng.', 'Người tìm thông tin dễ rời trang khi gặp quá nhiều lời chào bán. '.$note, 'Giảm lời kêu gọi mua hàng, tập trung giải đáp.');
        }
        if (in_array($intent, ['commercial', 'commercial_investigation'], true) && ! $hasComparison) {
            return $this->issue('search_intent', 'content', AuditIssue::WARNING, 6, 'Ý định so sánh/thương mại nhưng bài không có bảng hay phần so sánh.', 'Người dùng ở giai đoạn cân nhắc thường cần so sánh lựa chọn. '.$note, 'Thêm bảng so sánh có nguồn.');
        }
        if ($intent === 'transactional' && $commercialWords === 0) {
            return $this->issue('search_intent', 'content', AuditIssue::WARNING, 6, 'Ý định giao dịch nhưng bài không có thông tin mua/giá/liên hệ.', 'Người tìm để mua cần biết cách mua. '.$note, 'Bổ sung thông tin mua hàng thật của website.');
        }

        return $this->issue('search_intent', 'content', AuditIssue::PASS, 6, 'Nội dung phù hợp ý định "'.$intent.'".', $note);
    }

    /** @param array<string, mixed> $c */
    private function internalLinks(array $c): AuditIssue
    {
        /** @var ArticleHtml $h */
        $h = $c['html'];
        $host = (string) ($c['site']['host'] ?? '');
        $internal = array_values(array_filter($h->links, fn ($l) => $host !== '' && $this->host($l['href']) === $host));
        $known = array_flip(array_map(fn ($i) => $this->normUrl((string) $i['url']), array_filter($c['inventory'], fn ($i) => ($i['status'] ?? '') === 'publish' && ! empty($i['url']))));
        $unknown = array_values(array_filter($internal, fn ($l) => ! isset($known[$this->normUrl($l['href'])])));
        if ($unknown !== []) {
            return $this->issue('internal_links', 'links', AuditIssue::ERROR, 8, count($unknown).' link nội bộ trỏ tới URL không có trong danh sách bài đã đăng.', 'Link tới URL không tồn tại gây lỗi 404 cho người đọc.', 'Đồng bộ lại website hoặc bỏ các link này.', ['unknown' => array_column($unknown, 'href')]);
        }
        $n = count($internal);
        if ($n === 0) {
            return $this->issue('internal_links', 'links', AuditIssue::WARNING, 8, 'Bài chưa có link nội bộ.', 'Link nội bộ giúp người đọc tìm bài liên quan và giúp công cụ tìm kiếm hiểu cấu trúc website.', 'Xem tab Gợi ý link nội bộ.', ['suggestions' => $c['suggestions'] ?? 0]);
        }
        if ($n > 10) {
            return $this->issue('internal_links', 'links', AuditIssue::WARNING, 8, "Bài có {$n} link nội bộ.", 'Quá nhiều link làm loãng nội dung.', 'Giữ những link liên quan nhất.');
        }

        return $this->issue('internal_links', 'links', AuditIssue::PASS, 8, "{$n} link nội bộ tới bài đang tồn tại.", 'Số lượng hợp lý, URL đã xác minh trong danh sách nội dung.');
    }

    /** @param array<string, mixed> $c */
    private function externalLinks(array $c): AuditIssue
    {
        /** @var ArticleHtml $h */
        $h = $c['html'];
        $host = (string) ($c['site']['host'] ?? '');
        $external = array_values(array_filter($h->links, fn ($l) => preg_match('#^https?://#i', $l['href']) === 1 && $this->host($l['href']) !== $host));
        $insecure = array_values(array_filter($external, fn ($l) => str_starts_with(strtolower($l['href']), 'http://')));
        if ($insecure !== []) {
            return $this->issue('external_links', 'links', AuditIssue::WARNING, 5, count($insecure).' link ngoài dùng http (không mã hóa).', 'Trình duyệt có thể cảnh báo trang không an toàn.', 'Dùng https nếu nguồn hỗ trợ.', ['urls' => array_column($insecure, 'href')]);
        }
        if ((int) ($c['sources'] ?? 0) === 0 && preg_match('/\d/', $h->text) === 1 && $h->words > 300) {
            return $this->issue('external_links', 'links', AuditIssue::WARNING, 5, 'Bài có số liệu nhưng không trích dẫn nguồn.', 'Nguồn tham khảo giúp người đọc kiểm chứng và tăng độ tin cậy.', 'Chạy lại kiểm chứng ở AI Writer để gắn nguồn.');
        }

        return $this->issue('external_links', 'links', AuditIssue::PASS, 5, count($external).' link ngoài, '.(int) ($c['sources'] ?? 0).' nguồn được trích dẫn.', 'Nguồn trích dẫn lấy từ hồ sơ bằng chứng của bài.');
    }

    /** @param array<string, mixed> $c */
    private function brokenLinks(array $c): AuditIssue
    {
        $status = $c['link_status'];
        if ($status === null) {
            return $this->issue('broken_links', 'links', AuditIssue::WARNING, 8, 'Chưa kiểm tra link ngoài.', 'Kiểm tra link ngoài cần gửi request tới website khác nên chạy trong hàng đợi.', 'Bấm "Kiểm tra cả link ngoài".');
        }
        $bad = array_filter($status, fn ($s) => $s === null || $s >= 400);
        if ($bad !== []) {
            return $this->issue('broken_links', 'links', AuditIssue::ERROR, 8, count($bad).' link không truy cập được.', 'Link hỏng làm giảm trải nghiệm và độ tin cậy.', 'Sửa hoặc bỏ các link hỏng.', ['links' => $bad]);
        }

        return $this->issue('broken_links', 'links', AuditIssue::PASS, 8, count($status).' link đều truy cập được.', 'Đã kiểm tra lúc chạy audit.');
    }

    /** @param array<string, mixed> $c */
    private function imageAlt(array $c): AuditIssue
    {
        /** @var ArticleHtml $h */
        $h = $c['html'];
        $missing = array_values(array_filter($h->images, fn ($i) => trim($i['alt']) === ''));
        if ($missing !== []) {
            return $this->issue('image_alt', 'media', AuditIssue::ERROR, 6, count($missing).' ảnh thiếu alt text.', 'Alt text cần cho người dùng trình đọc màn hình và giúp công cụ tìm kiếm hiểu ảnh.', 'Nhập alt text trong Article Media Editor.');
        }
        if (($c['featured'] ?? null) === null) {
            return $this->issue('image_alt', 'media', AuditIssue::WARNING, 6, 'Chưa chọn ảnh đại diện.', 'Ảnh đại diện hiển thị khi chia sẻ mạng xã hội và trong danh sách bài.', 'Chọn ảnh đại diện.');
        }
        if ($h->images === [] && $h->words > 600) {
            return $this->issue('image_alt', 'media', AuditIssue::WARNING, 6, 'Bài dài nhưng không có ảnh minh họa.', 'Ảnh giúp ngắt đoạn văn dài.', 'Xem gợi ý vị trí ảnh.');
        }

        return $this->issue('image_alt', 'media', AuditIssue::PASS, 6, count($h->images).' ảnh trong bài đều có alt text.', 'Đạt yêu cầu truy cập.');
    }

    /** @param array<string, mixed> $c */
    private function imageSize(array $c): AuditIssue
    {
        $all = array_merge($c['images'] ?? [], ($c['featured'] ?? null) !== null ? [$c['featured']] : []);
        $big = array_values(array_filter($all, fn ($i) => (int) ($i['bytes'] ?? 0) > 2 * 1024 * 1024 && in_array($i['source_type'] ?? '', ['wordpress', 'woocommerce'], true)));
        $warn = array_values(array_filter($all, fn ($i) => (int) ($i['bytes'] ?? 0) > 500 * 1024 && in_array($i['source_type'] ?? '', ['wordpress', 'woocommerce'], true)));
        if ($big !== []) {
            return $this->issue('image_size', 'media', AuditIssue::ERROR, 5, count($big).' ảnh từ WordPress nặng hơn 2 MB.', 'Ảnh nặng làm trang tải chậm. Ảnh tải lên SiteX đã được tối ưu; ảnh lấy từ thư viện WordPress thì không.', 'Tối ưu ảnh trên WordPress hoặc dùng bản sao đã tối ưu.');
        }
        if ($warn !== []) {
            return $this->issue('image_size', 'media', AuditIssue::WARNING, 5, count($warn).' ảnh từ WordPress nặng hơn 500 KB.', 'Ảnh nên dưới ~500 KB.', 'Tối ưu ảnh.');
        }

        return $this->issue('image_size', 'media', AuditIssue::PASS, 5, 'Dung lượng ảnh hợp lý.', 'Ảnh của SiteX được gửi bản tối ưu (≤ 1600 px).');
    }

    /** @param array<string, mixed> $c */
    private function duplicate(array $c): AuditIssue
    {
        $mine = Text::tokens((string) $c['title']);
        $best = 0.0;
        $match = null;
        foreach ($c['inventory'] as $i) {
            if ((int) ($i['wp_post_id'] ?? 0) === (int) ($c['own_wp_post_id'] ?? -1) || ! in_array($i['post_type'] ?? '', ['post', 'page'], true)) {
                continue;
            }
            $s = Text::similarity($mine, Text::tokens((string) $i['title']));
            if ($s > $best) {
                $best = $s;
                $match = $i;
            }
        }
        $ctx = ['similarity' => round($best, 2), 'match' => $match !== null ? ['title' => $match['title'], 'url' => $match['url']] : null];
        if ($best >= 0.85) {
            return $this->issue('duplicate_content', 'content', AuditIssue::ERROR, 8, 'Tiêu đề gần trùng với bài đã có: "'.mb_substr((string) $match['title'], 0, 80).'".', 'Hai trang cùng chủ đề cạnh tranh từ khóa với nhau (cannibalization).', 'Đổi góc tiếp cận hoặc cập nhật bài cũ thay vì đăng mới.', $ctx);
        }
        if ($best >= 0.65) {
            return $this->issue('duplicate_content', 'content', AuditIssue::WARNING, 8, 'Tiêu đề khá giống bài đã có: "'.mb_substr((string) $match['title'], 0, 80).'".', 'Có thể trùng chủ đề.', 'Kiểm tra và liên kết hai bài với nhau.', $ctx);
        }

        return $this->issue('duplicate_content', 'content', AuditIssue::PASS, 8, 'Không thấy bài trùng chủ đề trên website.', 'So sánh tiêu đề với các bài đã đồng bộ.', null, $ctx);
    }

    /** @param array<string, mixed> $c */
    private function thin(array $c): AuditIssue
    {
        $w = $c['html']->words;
        if ($w < 300) {
            return $this->issue('thin_content', 'content', AuditIssue::ERROR, 8, "Bài chỉ có {$w} từ.", 'Nội dung quá ngắn thường không trả lời đủ câu hỏi của người đọc.', 'Bổ sung nội dung có giá trị.');
        }
        if ($w < 600) {
            return $this->issue('thin_content', 'content', AuditIssue::WARNING, 8, "Bài có {$w} từ.", 'Với đa số chủ đề, 600+ từ cho phép giải thích đầy đủ hơn. Độ dài không phải mục tiêu tự thân.', 'Bổ sung nếu chủ đề cần.');
        }

        return $this->issue('thin_content', 'content', AuditIssue::PASS, 8, "Bài có {$w} từ.", 'Độ dài đủ để bao quát chủ đề.');
    }

    /** @param array<string, mixed> $c */
    private function readability(array $c): AuditIssue
    {
        /** @var ArticleHtml $h */
        $h = $c['html'];
        $sentences = $h->sentences();
        $avg = $sentences !== [] ? round(array_sum(array_map(fn ($s) => ArticleHtml::wordCount($s), $sentences)) / count($sentences), 1) : 0.0;
        $long = count(array_filter($h->paragraphs, fn ($p) => $p['words'] > 150));
        $ctx = ['avg_sentence_words' => $avg, 'long_paragraphs' => $long];
        if ($avg > 25 || $long > 0) {
            return $this->issue('readability', 'content', AuditIssue::WARNING, 5, ($avg > 25 ? "Câu trung bình {$avg} từ. " : '').($long > 0 ? "{$long} đoạn dài hơn 150 từ." : ''), 'Câu và đoạn ngắn dễ đọc hơn, nhất là trên điện thoại.', 'Tách câu dài, chia đoạn.', $ctx);
        }

        return $this->issue('readability', 'content', AuditIssue::PASS, 5, "Câu trung bình {$avg} từ, đoạn văn ngắn gọn.", 'Dễ đọc.', null, $ctx);
    }

    /** @param array<string, mixed> $c */
    private function structuredData(array $c): AuditIssue
    {
        $s = $c['schema'] ?? ['types' => [], 'skipped' => [], 'enabled' => false];
        $skipped = $s['skipped'] ?? [];
        if (! ($s['enabled'] ?? false)) {
            return $this->issue('structured_data', 'schema', AuditIssue::PASS, 4, 'SiteX không chèn schema cho website này.', ($c['site']['yoast'] ?? false) || ($c['site']['rank_math'] ?? false)
                ? 'Plugin SEO trên website đã xuất schema Article; tránh trùng lặp.' : 'Có thể bật chèn schema trong Cài đặt đăng bài. Schema không bảo đảm rich results.');
        }
        $notEligible = array_filter($skipped, fn ($r) => $r === 'not_eligible');
        if ($notEligible !== []) {
            return $this->issue('structured_data', 'schema', AuditIssue::WARNING, 4, 'Schema '.implode(', ', array_keys($notEligible)).' được chọn nhưng nội dung không đáp ứng điều kiện — sẽ không chèn.', 'SiteX chỉ tạo schema khớp nội dung thật (ví dụ FAQ cần ≥ 2 cặp hỏi–đáp trong bài).', 'Bỏ chọn loại schema hoặc bổ sung nội dung.', ['skipped' => $skipped]);
        }

        return $this->issue('structured_data', 'schema', AuditIssue::PASS, 4, 'Schema sẽ chèn: '.(($s['types'] ?? []) === [] ? 'không có' : implode(', ', $s['types'])).'.', 'Bỏ qua loại trùng với plugin SEO. Không cam kết rich results.', null, ['skipped' => $skipped]);
    }

    /** @param array<string, mixed> $c */
    private function canonical(array $c): AuditIssue
    {
        $url = trim((string) ($c['meta']['canonical_url'] ?? ''));
        if ($url === '') {
            return $this->issue('canonical', 'indexing', AuditIssue::PASS, 4, 'Canonical mặc định (chính URL của bài).', 'WordPress / plugin SEO tự đặt canonical về chính bài.');
        }
        if ($this->host($url) !== (string) ($c['site']['host'] ?? '')) {
            if (! ($c['policy']['allow_canonical_override'] ?? false)) {
                return $this->issue('canonical', 'indexing', AuditIssue::BLOCKED, 4, 'Canonical trỏ sang domain khác.', 'Canonical khác domain khiến Google bỏ index bài này. Website chưa cho phép thay đổi canonical.', 'Xóa canonical, hoặc chủ website bật cho phép trong Cài đặt SEO.');
            }

            return $this->issue('canonical', 'indexing', AuditIssue::WARNING, 4, 'Canonical trỏ sang domain khác (đã được cho phép).', 'Bài sẽ không được index dưới URL của website này.');
        }

        return $this->issue('canonical', 'indexing', AuditIssue::PASS, 4, 'Canonical cùng domain.', 'Hợp lệ.');
    }

    /** @param array<string, mixed> $c */
    private function indexability(array $c): AuditIssue
    {
        if (! ($c['meta']['robots_noindex'] ?? false)) {
            return $this->issue('indexability', 'indexing', AuditIssue::PASS, 8, 'Bài được phép index.', 'Không có noindex.');
        }
        if (! ($c['policy']['allow_robots_override'] ?? false)) {
            return $this->issue('indexability', 'indexing', AuditIssue::BLOCKED, 8, 'Bài đặt noindex nhưng website chưa cho phép.', 'Noindex làm bài biến mất khỏi kết quả tìm kiếm.', 'Bỏ noindex hoặc chủ website bật cho phép.');
        }

        return $this->issue('indexability', 'indexing', AuditIssue::WARNING, 8, 'Bài sẽ không được index (noindex).', 'Đã được cho phép theo chính sách website.');
    }

    /** @param array<string, mixed> $c */
    private function wpConflicts(array $c): AuditIssue
    {
        $slug = (string) ($c['meta']['slug'] ?? '');
        foreach ($c['inventory'] as $i) {
            if ($slug !== '' && ($i['slug'] ?? '') === $slug && (int) $i['wp_post_id'] !== (int) ($c['own_wp_post_id'] ?? -1) && in_array($i['post_type'] ?? '', ['post', 'page'], true)) {
                return $this->issue('wp_conflicts', 'wordpress', AuditIssue::ERROR, 8, 'Slug "'.$slug.'" đã được dùng bởi "'.mb_substr((string) $i['title'], 0, 80).'".', 'WordPress sẽ tự thêm hậu tố (-2) và URL không như dự kiến.', 'Đổi slug.', ['url' => $i['url']]);
            }
        }

        return $this->issue('wp_conflicts', 'wordpress', AuditIssue::PASS, 8, 'Không trùng slug với nội dung hiện có.', 'So với danh sách nội dung đồng bộ lần gần nhất.');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function issue(string $rule, string $category, string $severity, int $weight, string $message, string $explanation, ?string $fix = null, array $context = []): AuditIssue
    {
        return new AuditIssue($rule, $category, $severity, $weight, $message, $explanation, $fix, $context);
    }

    private function hasKeyword(string $text, string $kw): bool
    {
        $kwTokens = Text::tokens($kw);

        return $kwTokens !== [] && Text::containment($kwTokens, Text::tokens($text)) >= 0.75;
    }

    private function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    private function normUrl(string $url): string
    {
        $p = parse_url($url);

        return strtolower((string) ($p['host'] ?? '')).rtrim((string) ($p['path'] ?? '/'), '/').(isset($p['query']) ? '?'.$p['query'] : '');
    }
}
