# SEO Audit Rules (Phase 6)

Module `backend/app/Modules/Seo`. Bộ quy tắc: `Seo/Audit/SeoRules.php`, phiên bản **`2026.11-1`** (lưu ở `seo_audit_runs.rules_version`; đổi quy tắc phải tăng phiên bản).

> **SEO score là checklist có trọng số, không phải bảo đảm thứ hạng.** UI luôn hiển thị nhãn này. Không quy tắc nào dùng search volume, thứ hạng hay dữ liệu SERP.

## 1. Đối tượng được audit

Audit chạy trên **đúng HTML sẽ được đăng** (`PublishableRenderer`): phiên bản đã duyệt (bỏ H1 vì WordPress in tiêu đề) + danh sách "Nguồn tham khảo" từ trích dẫn thật + link nội bộ đã chấp nhận + ảnh đã chấp nhận + metadata + schema. `content_hash` của bản render được lưu cùng kết quả.

Audit đồng bộ và cục bộ (không request ra ngoài). Kiểm tra link ngoài cần gọi website khác nên chạy trong queue (`CheckArticleLinks`, qua `PublicWebFetcher` có `SsrfGuard` của Phase 4, tối đa 30 link) và tạo một audit mới kèm mã HTTP đo được.

## 2. Mức độ

| Mức | Ý nghĩa | Điểm |
|---|---|---|
| PASS | Đạt | trọng số đầy đủ |
| WARNING | Nên sửa, không chặn | ½ trọng số |
| ERROR | Lỗi; chặn đăng **công khai** khi website bật `require_seo_pass` | 0 |
| BLOCKED | Nguy hiểm cho index; **luôn chặn đăng** | 0 |

Điểm = `round(100 × Σ điểm / Σ trọng số)`. Mỗi mục có `message` (cái gì), `explanation` (vì sao), `fix` (sửa thế nào) và `context` (số liệu đo được).

## 3. Quy tắc

| Rule | Nhóm | Trọng số | ERROR / BLOCKED | WARNING |
|---|---|---|---|---|
| `h1` | structure | 5 | Thiếu tiêu đề; nội dung có H1 thừa | Tiêu đề > 70 ký tự |
| `heading_hierarchy` | structure | 8 | Heading rỗng | Nhảy cấp (H2→H4); bài > 600 từ mà < 2 H2 |
| `seo_title` | metadata | 10 | Trống; > 70 ký tự | Ngoài 30–60 ký tự hoặc thiếu từ khóa chính |
| `meta_description` | metadata | 8 | — | Trống hoặc ngoài 70–160 ký tự |
| `slug` | metadata | 6 | Trống; ký tự ngoài `[a-z0-9-]` | > 75 ký tự hoặc phủ < 50 % từ khóa |
| `keyword_relevance` | content | 10 | Từ khóa không có ở tiêu đề, mở đầu lẫn H2 nào | Chưa đặt từ khóa; mật độ > 4 %; thiếu ở một trong ba vị trí |
| `search_intent` | content | 6 | — | Heuristic từ ngữ (ghi rõ "không phải phân tích SERP"): bài thông tin quá nhiều từ bán hàng; ý định so sánh mà không có bảng/so sánh; ý định giao dịch mà không có thông tin mua |
| `internal_links` | links | 8 | Link nội bộ tới URL **không có** trong danh sách bài đã đăng đồng bộ | Không có link nội bộ; > 10 link |
| `external_links` | links | 5 | — | Link `http://`; bài có số liệu nhưng không trích dẫn nguồn |
| `broken_links` | links | 8 | Link ngoài trả lỗi / ≥ 400 | Chưa kiểm tra link ngoài |
| `image_alt` | media | 6 | Ảnh thiếu alt | Chưa có ảnh đại diện; bài > 600 từ không có ảnh |
| `image_size` | media | 5 | Ảnh lấy từ WordPress > 2 MB | > 500 KB (ảnh SiteX đã được tối ưu ≤ 1600 px) |
| `duplicate_content` | content | 8 | Tiêu đề giống bài đã có ≥ 0,85 | ≥ 0,65 (cannibalization) |
| `thin_content` | content | 8 | < 300 từ | < 600 từ |
| `readability` | content | 5 | — | Câu trung bình > 25 từ hoặc đoạn > 150 từ |
| `structured_data` | schema | 4 | — | Loại schema được chọn nhưng nội dung không đủ điều kiện (sẽ không chèn) |
| `canonical` | indexing | 4 | **BLOCKED**: canonical khác domain khi website chưa cho phép | Khác domain nhưng đã được cho phép |
| `indexability` | indexing | 8 | **BLOCKED**: `noindex` khi website chưa cho phép | `noindex` đã được cho phép |
| `wp_conflicts` | wordpress | 8 | Slug đã được bài/trang khác trên WordPress dùng | — |

## 4. Metadata an toàn

- `MetadataGenerator` đề xuất **không dùng AI**: SEO title từ tiêu đề + từ khóa (≤ 60 ký tự), description từ đoạn văn thật đầu tiên (≤ 155 ký tự), slug ASCII từ từ khóa. Trường người dùng đã sửa (`manual_fields`) không bao giờ bị ghi đè. Cập nhật dùng optimistic lock (`lock_version`).
- Canonical khác domain và `noindex` bị **từ chối ở API** (422) trừ khi chính sách website (`schema_configs.allow_canonical_override` / `allow_robots_override`, quyền `sites.manage`) cho phép; audit vẫn BLOCKED nếu giá trị đã lưu trước khi chính sách đổi.

## 5. Schema (JSON-LD)

`SchemaBuilder` — tắt mặc định, bật theo website (`schema_configs.injection_enabled`):

- Article / BlogPosting / BreadcrumbList **bị bỏ qua khi Yoast hoặc Rank Math đang bật** (các plugin này đã in) → không trùng graph.
- FAQPage chỉ khi bài có H2 FAQ với ≥ 2 câu hỏi H3 và câu trả lời, chép nguyên văn.
- HowTo chỉ cho bài hướng dẫn có danh sách có thứ tự ≥ 3 bước.
- BreadcrumbList chỉ khi website xác nhận theme hỗ trợ và đã biết URL bài.
- Tác giả/publisher là tổ chức (tên website) — không bịa người. Không hứa rich results.

## 6. Link nội bộ

`InternalLinkEngine` chỉ đề xuất URL **thật** (bài đã đăng trong danh sách đồng bộ Phase 2, có URL); anchor là cụm 2–6 từ **đã có sẵn** trong đoạn văn (không viết lại câu), mọi từ đều có trong tiêu đề đích, không dùng chính từ khóa của bài; tối đa 1 link/đoạn, 1 link/đích, 5 link/bài; không chèn ở đoạn đầu, FAQ, nguồn, đoạn ngắn hoặc đoạn đã có link. Xếp hạng = độ trùng ngữ nghĩa + cùng cluster (Phase 4) + ý định tương thích + chất lượng anchor; mọi tín hiệu được lưu để UI giải thích. Người dùng chấp nhận / từ chối từng gợi ý.
