# Analytics Architecture (Phase 8)

Module `backend/app/Modules/Analytics`. Ba lớp, mỗi lớp chỉ ghi bảng của chính nó:

| Lớp | Thành phần | Ghi vào |
|---|---|---|
| **Thu thập** | `Connectors/SearchConsoleConnector` (qua `Research\Contracts\SearchConsoleAccess`), `Connectors/Ga4Connector` (chưa triển khai, trả `not_available`), `Actions/SyncSearchConsole`, `Actions/StartSync`, `Jobs/SyncSiteJob`, `Console/ScheduleAnalyticsSync` | `analytics_properties`, `analytics_sync_runs`, `search_performance_daily`, `search_query_metrics`, `search_page_metrics`, `search_query_page_metrics`, `analytics_url_mappings` |
| **Phân tích** | `Actions/AnalyzeSite` + `Engine/{Metrics,TrendClassifier,DecayDetector,OpportunityRules,RecommendationEngine,HealthScorer,ExperimentEvaluator}`, `Support/SiteDataset` | `content_performance_snapshots`, `content_decay_events`, `seo_opportunities`, `seo_recommendations`, `seo_health_scores`, `seo_experiments`, `experiment_observations` |
| **Hành động** | `Actions/ContentRefresh` (+ `Jobs/AdvanceRefreshJob`, `Console/TickContentRefresh`), `Actions/TrackExperiments`, `Actions/GenerateReport`, `Actions/Copilot` | `content_refresh_jobs`, `seo_reports`, `copilot_queries` |

Engine (`Engine/*`) là hàm thuần (không DB, không HTTP) nên test đơn vị được và cho cùng kết quả với cùng dữ liệu.

## 1. Ranh giới module
- Đọc module khác **chỉ qua contract**: `Research\Contracts\SearchConsoleAccess` (OAuth/token GSC của Phase 4, không tạo bảng GSC trùng), `Sites\Contracts\SiteDirectory` (site, danh mục nội dung WordPress đã đồng bộ), `Writer\Contracts\{ArticleCatalog,ArticleRefresh}`, `Seo\Contracts\SeoGate`, `Publishing\Contracts\{PublicationDirectory,PublishRequests}`, `Billing\Contracts\Entitlements`, `AiGateway` qua workflow.
- Contract công khai cho module khác: `Analytics\Contracts\SeoInsights` (Agent dùng để đọc số liệu, cơ hội, decay, health; không ghi).
- `tests/Unit/ArchitectureTest.php` cưỡng chế: model tenant dùng `BelongsToWorkspace`, request kế thừa `ApiRequest`, Analytics/Agent chỉ dùng contract của module khác.

## 2. Ánh xạ bài viết ↔ URL (khớp chính xác)
`Support/UrlMapper` dựng `analytics_url_mappings` từ, theo thứ tự ưu tiên: mapping hiện tại của Publisher (`publisher`), URL cũ của cùng bài (`publisher_history`, khi đổi slug), danh mục WordPress đã đồng bộ theo `wp_post_id` (`inventory`). URL được chuẩn hóa (`UrlNormalizer`: bỏ fragment, tham số tracking, chuẩn hóa host/slash). Hai bài cùng nhận một URL → để trống, **không đoán**. `search_page_metrics.article_id` được điền theo mapping.

## 3. Cửa sổ so sánh
`Support/Windows`: kỳ gần nhất `window_days` (mặc định 28) kết thúc ở ngày dữ liệu hoàn chỉnh cuối cùng (hôm nay − 3 ngày), kỳ trước liền kề, 3 kỳ cơ sở trước đó (baseline) và cùng kỳ năm trước (mùa vụ). CTR luôn tính từ tổng (clicks/impressions), vị trí là trung bình có trọng số theo impressions — giống cách GSC tính.

## 4. Phân loại nội dung
`TrendClassifier`: `GROWING`, `STABLE`, `DECLINING`, `UNDERPERFORMING`, `INSUFFICIENT_DATA`, `RECENTLY_PUBLISHED` + nhãn phụ `high_impr_low_ctr`, `near_top`, `decay_candidate`, `needs_refresh`. Luôn kèm `reasons` (số liệu dẫn đến kết luận). Dưới `min_impressions` hoặc `min_days_with_data` → `INSUFFICIENT_DATA`; bài trẻ hơn `recent_publish_days` → `RECENTLY_PUBLISHED`, không bao giờ bị coi là thất bại.

## 5. Suy giảm (decay)
`DecayDetector` chỉ mở sự kiện khi đủ lịch sử, mức giảm ≥ `decay_pct`, clicks kỳ trước ≥ `decay_min_clicks`; trừ hao đột biến (so với baseline) và mùa vụ (so cùng kỳ năm trước). Trạng thái: `DECAY_SUSPECTED` → người chuyển `NEEDS_INVESTIGATION` / `CONFIRMED_ISSUE` / `NO_ACTION`. Mỗi trang tối đa một sự kiện mở (`open_key`). Tín hiệu (truy vấn mất, đổi URL, nội dung sửa trên WordPress) là **tương quan**, ghi rõ là giả thuyết.

## 6. Cơ hội & đề xuất
15 quy tắc trong `OpportunityRules` (xem `SEO-OPPORTUNITY-RULES.md`). Dedupe theo `fingerprint` (quy tắc + đối tượng); phát hiện lại chỉ tăng `detections`. Hết điều kiện → `expired`. `RecommendationEngine` đổi cơ hội thành đề xuất có loại hành động, ưu tiên, rủi ro, cấp duyệt, giả thuyết lợi ích (không cam kết thứ hạng) và chi phí AI ước tính.

## 7. SEO Health Score
`ComputeHealthScore` + `HealthScorer`: 8 thành phần có trọng số cấu hình được. Thành phần **không có dữ liệu bị loại khỏi điểm** (trọng số chia lại), không tính là 0. Không có thành phần nào → `score = null`. UI luôn ghi “không phải điểm của Google”.

## 8. Dashboard
`Support/DashboardQuery`: overview, queries, pages, articles, dimensions (thiết bị/quốc gia), lọc theo khoảng ngày, loại tìm kiếm, thiết bị, quốc gia, từ khóa, chỉ trang đã ánh xạ; so sánh với kỳ trước. `meta.latest_date` + ghi chú GSC (trễ 2–3 ngày, ẩn truy vấn ẩn danh nên tổng theo truy vấn < tổng site).

## 9. Theo dõi trước/sau (experiments)
`TrackExperiments` + `ExperimentEvaluator`: `method = before_after` (không bao giờ “A/B”). Baseline 28 ngày trước thay đổi, quan sát 28 ngày sau; thiếu dữ liệu → `insufficient_data`; kết quả `improved` / `declined` / `no_clear_change` kèm so sánh với xu hướng toàn site và `external_factors`.

## 10. Báo cáo & Copilot
- `GenerateReport`: tuần/tháng/tùy chọn, chỉ từ dữ liệu đã lưu; mục thiếu dữ liệu ghi `null`. CSV tải về; email chỉ tới `recipient_ids` là thành viên đang hoạt động của workspace (`SeoReportNotice`, queue `notifications`).
- `Copilot`: trả lời từ dữ liệu tenant (quy tắc + tùy chọn AI qua workflow `CopilotPhraseJob`), tách **dữ kiện / suy luận / đề xuất**, kèm `data_refs` và ngày dữ liệu; không bao giờ thực hiện hành động.

## 11. Queue & lịch
Queue `analytics` (Horizon `supervisor-analytics`). Lịch trong `routes/console.php`: `sitex:analytics:sync` (hằng giờ), `sitex:analytics:refresh-tick` (5 phút), `sitex:analytics:prune` (03:40, giữ dữ liệu thô `raw_retention_days`, mặc định 490 ngày).

## 12. Cấu hình
`config/sitex.php` → `analytics.*` (backfill, lag, page_size, max_days_per_run, window). Ngưỡng và trọng số theo workspace trong `analytics_settings` (`AnalyticsConfig::THRESHOLDS`, `HEALTH_WEIGHTS` là mặc định).
