# SEO Opportunity Rules (Phase 8)

Nguồn: `backend/app/Modules/Analytics/Engine/OpportunityRules.php` (`VERSION` lưu vào `rule_version`). Mọi quy tắc là **hàm thuần** trên dữ liệu đã đồng bộ của chính website (Search Console + nội dung SiteX + danh mục WordPress). Không dùng search volume bên ngoài, không dự báo traffic, không cam kết thứ hạng.

## Mỗi cơ hội luôn có
| Trường | Ý nghĩa |
|---|---|
| `evidence` | Số liệu dẫn chứng (clicks/impressions/CTR/vị trí theo kỳ, URL, truy vấn) |
| `detection_rule` | Câu mô tả điều kiện đã khớp, kèm ngưỡng thực tế |
| `severity` | `low` / `medium` / `high` / `critical` |
| `impact` + `impact_basis` | `low` / `medium` / `high` / `unknown` — dựa trên tỷ trọng clicks của trang trong website, không phải dự báo |
| `confidence` + `confidence_explanation` | 0–100 và lý do (ví dụ “ứng viên cannibalization, có thể là chủ ý”) |
| `recommended_action` | Một trong các hành động của `RecommendationEngine` |
| `estimated_ai_cost_micro_usd` | Chi phí AI ước tính nếu thực hiện qua quy trình làm mới (`AnalyticsConfig` bảng chi phí) |
| `status` | `open` → người chọn `accepted` / `dismissed` / `resolved`; hết điều kiện → `expired` |
| Duyệt | `PATCH /analytics/opportunities/{id}` (`content.edit`), ghi `reviewed_by/at/note`, audit `analytics.opportunity_reviewed` |

Dedupe: `fingerprint = sha1(rule + đối tượng)`; phát hiện lại cập nhật `last_detected_at`, tăng `detections`, không tạo bản ghi mới.

## 15 quy tắc
Ngưỡng là mặc định trong `AnalyticsConfig::THRESHOLDS`, chỉnh theo workspace ở trang Cài đặt.

| # | Mã | Điều kiện (tóm tắt) | Hành động |
|---|---|---|---|
| 1 | `query_high_impr_low_ctr` | Truy vấn trong top 10, impressions ≥ `opp_min_impressions` (200), CTR < `low_ctr_ratio` (50%) trung vị CTR nội bộ cùng nhóm vị trí (nhóm < 5 trang → `low_ctr_fallback_pct` 1%) | `rewrite_title_meta` |
| 2 | `page_traffic_decline` | Clicks giảm ≥ `declining_pct` (20%) và impressions giảm so với kỳ trước | `investigate` / `refresh_content` |
| 3 | `page_position_improving` | Vị trí TB cải thiện ≥ `improving_positions` (3) bậc, đang trong top 20, impressions không giảm | `improve_internal_links` |
| 4 | `new_query` | Truy vấn có ≥ `new_query_min_impressions` (50) impressions kỳ này và 0 ở các kỳ trước | `create_supporting_article` / `add_missing_sections` |
| 5 | `content_missing_info` | Bài nhận impressions cho truy vấn mà nội dung không chứa ≥ 50% từ khóa của truy vấn | `add_missing_sections` |
| 6 | `outdated_info` | Bài chưa cập nhật ≥ `refresh_age_days` (365) và nhắc tới năm ≤ hiện tại − `outdated_years` (2) | `update_outdated_facts` |
| 7 | `keyword_cannibalization` | ≥ 2 URL cùng nhận ≥ `cannibal_min_share` (20%) impressions cho một truy vấn (≥ `cannibal_min_impressions`) | `merge_overlapping_articles` (độ tin cậy 50, cần người xem) |
| 8 | `internal_link_opportunity` | Bài gần top (vị trí 4–15) và có bài khác nhắc tới từ khóa chính nhưng chưa liên kết | `improve_internal_links` |
| 9 | `orphan_content` | Không bài SiteX nào liên kết tới bài. **Chỉ chạy khi SiteX giữ nội dung của ≥ 80% bài** (nếu không thì không kết luận) | `improve_internal_links` |
| 10 | `broken_internal_links` | Liên kết nội bộ trỏ tới URL không có trong danh mục WordPress đã đồng bộ (chỉ khi đã có danh mục) | `fix_broken_links` |
| 11 | `duplicate_title_meta` | Cùng SEO title hoặc meta description ở ≥ 2 URL (so khớp chính xác, không phân biệt hoa thường) | `fix_metadata` |
| 12 | `missing_metadata` | Bài đã xuất bản thiếu SEO title hoặc meta description | `fix_metadata` |
| 13 | `content_gap` | Impressions ≥ `gap_min_impressions` (100) cho truy vấn nhưng vị trí TB > `gap_min_position` (30) | `create_supporting_article` |
| 14 | `cluster_coverage_gap` | Cụm chủ đề (Phase 4) có ≥ 3 ý tưởng nhưng < 50% đã xuất bản | `expand_topic_cluster` |
| 15 | `refresh_candidate` | Nội dung chưa cập nhật ≥ `refresh_age_days` và đang suy giảm | `refresh_content` |

## Nguyên tắc
- **Thiếu dữ liệu → không kết luận.** Dưới `min_impressions` / `min_days_with_data` không có quy tắc hiệu suất nào chạy; quy tắc liên kết cần dữ liệu nội dung/danh mục.
- **Giả thuyết, không phải nguyên nhân.** `impact_basis` và `benefit_hypothesis` viết dạng giả thuyết, khuyến nghị đo trước/sau (`seo_experiments`).
- **Không tự hành động.** Cơ hội → đề xuất (`seo_recommendations`, nguồn `rule` / `ai` / `agent`) → người duyệt → (tùy chọn) yêu cầu làm mới qua Writer + fact-check + SEO Audit + Publisher.
- Test: `tests/Unit/Analytics/AnalyticsEngineTest.php`, `tests/Feature/Analytics/AnalysisApiTest.php`.
