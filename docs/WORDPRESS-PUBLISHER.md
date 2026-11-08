# WordPress Publisher (Phase 6)

Module `backend/app/Modules/Publishing`. Đẩy bài đã duyệt (Phase 5 Writer) lên WordPress qua connector Phase 2 (`Sites\Contracts\WordPressGateway`). Không module nào khác gọi WordPress để đăng bài (kiểm bằng `tests/Unit/ArchitectureTest.php`).

Nguyên tắc: **an toàn mặc định**, **không bỏ qua được cổng kiểm tra**, **không ghi đè sửa tay trên WordPress**, **không tạo bài trùng**.

## 1. Chế độ đăng (Modes A–E)

| Mode | Giá trị `mode` | Ý nghĩa | Trạng thái WP |
|---|---|---|---|
| A | `wp_draft` | Tạo bản nháp trên WordPress — **mặc định an toàn** (`publishing_policies.default_mode = manual` → UI chọn A) | `draft` |
| B | `publish_now` | Đăng công khai ngay khi job được người có quyền duyệt và qua mọi cổng | `publish` |
| C | `scheduled` | Lên lịch theo **múi giờ của website**, trong khung giờ cho phép và giới hạn bài/ngày | `publish` khi tới giờ |
| D | `auto` | Chỉ do `sitex:publishing:auto` tạo, chỉ cho website có `auto_publish_enabled` (bật bằng xác nhận rõ ràng `confirm_auto_publish`, có audit) **và** nền tảng cho phép (`publishing_limits.auto_publish_allowed`). Chỉ chọn bài đã được **người** duyệt ở Writer, không còn claim chưa giải quyết | `publish` |
| E | `update` | Cập nhật bài đã đăng (cần mapping hiện có và `allow_update_published`); luôn kiểm tra xung đột trước | giữ nguyên |

## 2. Trạng thái (States)

`DRAFT → READY_FOR_REVIEW → APPROVED → (SCHEDULED) → PUBLISHING → PUBLISHED`, cùng `UPDATE_PENDING`, `FAILED`, `CANCELLED`. Bảng chuyển trạng thái nằm trong `Support/PublishingStatus::ALLOWED`; mọi chuyển khác trả **409 `invalid_transition`**.

| Từ | Được sang |
|---|---|
| DRAFT | READY_FOR_REVIEW, CANCELLED |
| READY_FOR_REVIEW | APPROVED, DRAFT, CANCELLED |
| APPROVED | SCHEDULED, PUBLISHING, READY_FOR_REVIEW, CANCELLED |
| SCHEDULED | PUBLISHING, SCHEDULED (đổi lịch), READY_FOR_REVIEW, FAILED (lỡ lịch), CANCELLED |
| PUBLISHING | PUBLISHED, FAILED, UPDATE_PENDING (xung đột), READY_FOR_REVIEW (cổng chặn), PUBLISHING (thử lại) |
| UPDATE_PENDING | PUBLISHING, CANCELLED, READY_FOR_REVIEW |
| FAILED | PUBLISHING (retry), READY_FOR_REVIEW, CANCELLED |
| PUBLISHED, CANCELLED | (kết thúc) |

Một bài chỉ có **một job đang hoạt động trên mỗi website**: cột `publishing_jobs.active_key = "<site>:<article>"` UNIQUE, được xóa khi job về trạng thái kết thúc.

## 3. Cổng kiểm tra (Gates)

`Support/PublishingGates::evaluate()` chạy ở **3 thời điểm**: tạo job, duyệt job, và **ngay trước lời gọi connector**. Không có cờ "force" cho cổng nào.

| Gate | Chặn khi |
|---|---|
| `site` | website không thuộc workspace, chưa ghép nối, tài khoản WP không có quyền đăng, bài thuộc website khác |
| `article_approved` | bài chưa `APPROVED` ở Writer; lúc thực thi: nội dung đã đổi so với phiên bản được duyệt (`content_changed_since_approval`) |
| `fact_check` | chỉ cảnh báo từ v1.0.12 (ADR-030): còn claim CRITICAL/HIGH chưa giải quyết thì ghi `details.warning`, không chặn — người duyệt bài đã xác nhận |
| `seo` | audit có mục **BLOCKED** (luôn chặn); có **ERROR** khi website bật `require_seo_pass` và đích là `publish` |
| `media` | ảnh được gán chưa `ready` hoặc thiếu alt; thiếu ảnh đại diện khi `require_media_ready` và đích là `publish` |
| `policy` | kill switch nền tảng (`publishing_enabled=false`), auto chưa bật, update khi không có bài / không cho phép, tạo mới khi bài đã đăng (`already_published_use_update`) |
| `quota` | ngoài khung giờ/ngày, vượt số bài/ngày (site, chiến dịch, workspace), quá gần bài trước, quá xa trong tương lai — tính theo múi giờ website |
| `conflict` | mapping còn xung đột mở |

Quyền: tạo/chuẩn bị job cần `content.edit`; **duyệt, đổi lịch, retry, giải quyết xung đột, tạo batch** cần `content.publish`; sửa chính sách website cần `sites.manage`. Nguồn duyệt được lưu ở `approval_source` (`user` / `auto_policy`).

Ngoài cổng, lúc thực thi bản render phải khớp `content_hash` đã chốt khi duyệt; khác → job quay về READY_FOR_REVIEW.

## 4. Đăng idempotent

1. **Claim nguyên tử**: `UPDATE … SET locked_until` chỉ khi job `PUBLISHING`, khóa hết hạn và tới giờ thử → hai worker không bao giờ gửi cùng một job.
2. **Mapping ổn định** `wordpress_post_mappings` (bài ⇄ post) với `idempotency_key` cố định theo (site, external_content_id). Plugin cũng chống trùng theo khóa này (`_sitex_post_idempotency_key`) → thử lại không bao giờ tạo bài thứ hai.
3. Ảnh tải lên trước (idempotent theo khóa mapping + asset), sau đó thay placeholder `{{sitex-image:KEY}}` / `{{sitex-image-id:KEY}}`.
4. **Kết quả không rõ** (timeout, 5xx): tra cứu WordPress theo khóa (`GET /posts/lookup?idempotency_key=…`) **trước khi** quyết định; nếu bài đã được ghi bởi chính lần gửi này (`written_hash = content_hash`, sửa đổi trong cửa sổ thời gian của lần thử) → ghi nhận `reconciled` là đã đăng; ngược lại thử lại với backoff. Xem `PUBLISHING-RECOVERY.md`.

## 5. Xung đột — sửa lỗi P0 Phase 2

Trước Phase 6, cập nhật bài có thể ghi đè nội dung người dùng sửa tay trên WordPress. Nay có **hai lớp** kiểm tra:

**Cloud (trước khi ghi)** — đọc trạng thái có ký `GET /posts/{id}/state` và so với mapping (`Support/ConflictDetector`):

| Loại | Điều kiện |
|---|---|
| `manual_edit` | `content_hash` trên WP ≠ `last_synced_hash` |
| `slug_changed` | slug WP ≠ slug đã ghi |
| `seo_modified` | hash SEO meta (Yoast/Rank Math/tích hợp) ≠ `last_seo_hash` |
| `revision_changed` | kèm loại khác, revision WP đã đổi |
| `domain_changed` | host website khác host lúc đăng |
| `post_missing` | bài không còn trên WP |

Có xung đột → mở `publishing_conflicts` (một bản mở cho mỗi mapping), job sang `UPDATE_PENDING`, **không gửi gì**.

**Plugin (nguyên tử, lúc ghi)** — `PostService::conflicts()`: plugin lưu `_sitex_post_written_hash` sau mỗi lần ghi; cập nhật bị từ chối **409 `sitex_conflict`** nếu nội dung hiện tại khác hash đã ghi, hoặc khác trạng thái `expected` (content_hash, slug, seo_hash) Cloud gửi kèm. Bài ghi trước Phase 6 (chưa có hash) được so bằng thời điểm sửa. Body lỗi 409 không được ký → Cloud đọc lại state có ký rồi mới mở xung đột.

### 5 cách giải quyết (chỉ con người, quyền `content.publish`)

| Resolution | Kết quả |
|---|---|
| `keep_wordpress` | Giữ bản WordPress là chuẩn; mapping cập nhật theo trạng thái WP; hủy cập nhật đang chờ |
| `overwrite` | Ghi đè có chủ đích. Lần gửi kế tiếp mang `force` + `expected.content_hash` = trạng thái WP lúc quyết định → nếu WP **lại** bị sửa sau quyết định, plugin vẫn từ chối. Không cho phép với `post_missing` / `domain_changed` |
| `merge_manually` | Biên tập viên tự gộp trên WordPress / Writer; hủy cập nhật, mapping cập nhật theo WP |
| `new_draft` | Không đụng bài cũ; mapping mới (khóa mới) → gửi nội dung đã duyệt thành **bản nháp mới** |
| `cancel_update` | Không thay đổi gì, hủy cập nhật |

Mọi quyết định được audit (`publishing.conflict_resolved`). Kiểm tra đồng bộ thủ công: `POST /publishing/mappings/{id}/check` (job `CheckMappingSync`, lưu `wordpress_sync_states`).

## 6. Lịch đăng

- `scheduled_at` lưu UTC; `publishing_schedules` lưu kèm `timezone` và `local_time` của website (lấy từ hồ sơ site Phase 2).
- `ScheduleRules` kiểm tra: khung giờ (`window_start_hour`–`window_end_hour`, `window_days`), `max_posts_per_day`, `max_posts_per_campaign_per_day`, `min_minutes_between_posts`, `max_schedule_days_ahead`, giới hạn workspace. `nextSlot()` tìm khe hợp lệ kế tiếp (dùng cho auto và bulk).
- `sitex:publishing:dispatch` chạy mỗi phút; `sitex:publishing:auto` chạy mỗi giờ (`routes/console.php`).

## 7. Đăng hàng loạt

`publishing_batches`: xem trước (`POST /batches/preview`, tối đa `max_bulk_items`, mặc định 50) → tạo batch (Idempotency-Key) → mỗi bài là một job riêng với trạng thái riêng; lỗi một bài không chặn bài khác. Batch có thể **tạm dừng / tiếp tục / hủy**; job của batch tạm dừng không được gửi.

## 8. Xem trước

`GET /publishing/articles/{article}/preview` trả HTML đã render + snippet SEO; UI hiển thị trong iframe sandbox (DOMPurify) với nhãn **"Mô phỏng"** — không phải giao diện theme thật.

## 9. Chính sách mặc định (`Support/SitePolicy`)

`default_mode=manual`, `auto_publish_enabled=false`, `require_seo_pass=true`, `require_media_ready=true`, `allow_update_published=true`, `max_posts_per_day=5`, `max_posts_per_campaign_per_day=3`, `min_minutes_between_posts=60`, khung 7h–22h, `max_schedule_days_ahead=90`, `max_attempts=5`, `missed_grace_minutes=180`.

Giới hạn nền tảng (Super Admin, `publishing_limits` scope `global` / `ws:<id>`; mặc định từ `config/sitex.php` → `publishing`): `max_publishes_per_day=50`, `max_bulk_items=50`, `max_schedule_days_ahead=180`, `auto_publish_allowed`, `publishing_enabled` (kill switch).

## 10. Hợp đồng plugin bổ sung (plugin 1.1.0)

| Method | Path | Ghi chú |
|---|---|---|
| GET | `/posts/{id}/state` | Trạng thái có ký: status, slug, url, modified_gmt, `content_hash`, `written_hash`, `revision_id`, SEO hiện tại + `seo_hash`, `seo_adapter` |
| GET | `/posts/lookup?idempotency_key=` | Tìm bài theo `idempotency_key` (đối soát sau timeout) |
| GET | `/media` | Liệt kê Media Library (chỉ tham chiếu, phân trang ≤ 100) |
| POST/PUT | `/posts`, `/posts/{id}` | Thêm `expected`, `force`, `schema` (JSON-LD), featured image là ảnh bất kỳ trong Media Library |

Plugin vẫn tương thích PHP 7.4 / WP ≥ 6.4; mọi endpoint ký HMAC như Phase 2.
