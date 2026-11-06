# Media Engine (Phase 6)

Module `backend/app/Modules/Media`. Quản lý ảnh của bài: nguồn ảnh, ảnh AI, xử lý ảnh, infographic, vị trí ảnh trong bài, alt text.

## 1. Nguồn ảnh (`media_sources`, `media_assets.source_type`)

| source_type | Nguồn | License | Ghi chú |
|---|---|---|---|
| `wordpress` | Media Library của website (qua connector có ký, `GET /media`) | `wordpress_site` | Chỉ lưu **tham chiếu** (id, URL, kích thước, alt) — không sao chép bytes |
| `woocommerce` | Ảnh sản phẩm WooCommerce (`product.image_ids` khi đồng bộ nội dung) | `wordpress_site` | **Ảnh duy nhất** được dùng cho vị trí "ảnh sản phẩm thật" |
| `upload` | Người dùng tải lên | `owned` / `customer_provided` / `licensed_stock` (bắt buộc chọn) | Xử lý trong queue `media` |
| `ai` | Ảnh AI qua AI Gateway | `ai_generated` | Luôn `is_ai_generated = true`, chú thích "Ảnh minh họa tạo bằng AI" |
| `infographic` | Dựng từ dữ liệu có nguồn | `owned` | Renderer xác định, không dùng model ảnh |
| `stock` | (dự phòng) | — | Chưa tích hợp nhà cung cấp stock ở Phase 6 |

Ảnh `license = unknown` không bao giờ được đề xuất hay gán vào bài.

## 2. Ảnh AI (`GenerateImages`, `image_generation_jobs`)

- Đi qua AI Gateway Phase 3, task `media.image`. Chỉ model có capability **`image_generation`** trong registry được định tuyến — không giả định model văn bản (vd. DeepSeek) vẽ được ảnh. Adapter ảnh hiện có từ Phase 3: OpenAI `POST /v1/images/generations` (**chưa thử với API thật**).
- Luồng: xem trước prompt (`POST /media/generations/preview`, người dùng sửa được) → ước tính chi phí so với `budget_cap_micro_usd` của job và ngân sách AI workspace/nền tảng → gọi trong queue với `call_key` ổn định (gửi lặp không trả tiền hai lần) → từng ảnh trả về được kiểm tra và xử lý như ảnh tải lên.
- `ImagePromptBuilder` chỉ dùng dữ liệu bài của khách (tiêu đề, brief, section, đối tượng, màu thương hiệu); prompt sau khi sửa được lọc lại (email, số điện thoại, URL, chuỗi dài giống token).
- Tạo lại (`regenerate`) tạo phiên bản mới (`parent_job_id`, `version`).
- **Ảnh AI không bao giờ là ảnh sản phẩm thật**: `PlacementEngine::assertCompatible()` từ chối (`ai_not_product_photo`) cả ở đề xuất lẫn lựa chọn thủ công; frontend có cùng quy tắc (`placementAllowsAsset`).

## 3. Xử lý ảnh (`ImageProcessor`, `MediaPipeline`)

GD thuần PHP, không gọi shell:

1. Kiểm tra: MIME thật từ bytes (JPEG/PNG/GIF/WebP), khớp phần mở rộng, giới hạn kích thước (`max_upload_bytes` 10 MB, `max_dimension` 8000 px, `max_pixels` 40 MP), từ chối byte nghi vấn (`<?php`, `<script`, `<svg`, `<html`, `<iframe`, `<!doctype`) → chặn file thực thi ngụy trang và polyglot.
2. Re-encode bản gốc (bỏ EXIF/XMP và payload nối thêm).
3. Biến thể: `optimized` (≤ 1600 px), `webp`, `avif` (nếu GD hỗ trợ), `thumbnail` 400 px; `crop` khi người dùng cắt ảnh (`media_versions`).
4. Chống trùng trong workspace: chính xác (sha256) và gần giống (dHash 64-bit).

Lưu trữ riêng tư `media/<workspace>/<asset>/<kind>.<ext>` trên disk `SITEX_MEDIA_DISK` (mặc định `local`, không public). File chỉ phục vụ qua **URL ký có hạn 30 phút** (`/api/v1/media-files/...`, middleware `signed`).

## 4. Infographic (`CreateInfographic`, `InfographicRenderer`)

Chỉ từ dữ liệu có cấu trúc khách cung cấp **kèm nguồn** hoặc xác nhận rõ "dữ liệu do khách cung cấp". Renderer GD + font DejaVu Sans (đủ tiếng Việt), cùng đầu vào → cùng PNG; chỉ vẽ đúng giá trị được đưa, không nội suy, dòng nguồn bắt buộc.

## 5. Vị trí ảnh (`PlacementEngine`, `image_placements`)

Thận trọng: 1 ảnh đại diện; ảnh mở đầu chỉ cho bài > 800 từ; tối đa 1 ảnh mỗi H2 có ≥ 150 từ, bỏ FAQ/kết luận/nguồn; tổng không quá `số từ / 350` (tối đa 6); phần so sánh/bảng → ô infographic; ô "ảnh sản phẩm" chỉ khi có ảnh WooCommerce khớp chủ đề. Ô không có ứng viên tốt vẫn được đề xuất (rỗng) kèm lý do. Chạy lại đề xuất chỉ thay các dòng còn `suggested`; dòng người dùng đã chọn/sửa được giữ.

Alt text / caption (`AltText`) theo quy tắc: mô tả điều đã biết về ảnh (alt/tiêu đề gốc, section minh họa), không khẳng định thêm; ảnh AI luôn được mô tả là ảnh minh họa AI.

## 6. Giới hạn (`MediaLimits`, `media_limits`)

Ưu tiên: ghi đè workspace (`ws:<id>`) > dòng `global` > `config/sitex.php` → `media`. Mặc định: dung lượng 2 GB/workspace, 200 lượt tải/ngày, 30 ảnh AI/ngày, trần chi phí 0,5 USD/job ảnh AI, bật/tắt ảnh AI. Super Admin chỉnh ở trang Media Admin.

## 7. Quyền

Xem: `workspace.view`. Tải lên, sửa, tạo ảnh AI, infographic, chỉnh vị trí: `content.edit`. Mọi model dùng `BelongsToWorkspace`; route tenant có test cross-tenant tự động.
