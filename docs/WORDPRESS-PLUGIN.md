# Plugin WordPress "SiteX Post" — hướng dẫn cài đặt & kết nối

Plugin: **SiteX Post**, slug & text domain `sitex-post`, phiên bản 1.0.0. Yêu cầu WordPress ≥ 6.4, PHP ≥ 7.4, website chạy HTTPS.

## 1. Cài đặt

**Cách A — tải file zip lên wp-admin (khuyến nghị cho khách hàng)**
1. Lấy file `sitex-post-1.0.0.zip` (đội SiteX build bằng `wordpress-plugin/scripts/build-plugin.sh`, file nằm ở `wordpress-plugin/dist/`).
2. wp-admin → **Plugin → Cài mới → Tải plugin lên** → chọn file zip → **Cài đặt** → **Kích hoạt**.

**Cách B — FTP/SFTP**
1. Giải nén zip, tải thư mục `sitex-post/` lên `wp-content/plugins/`.
2. wp-admin → **Plugin** → kích hoạt **SiteX Post**.

Sau khi kích hoạt, menu **SiteX Post** xuất hiện ở thanh bên trái và có thông báo "SiteX Post đã được cài nhưng chưa kết nối".

## 2. Kết nối với SiteX Post Cloud (không cần mật khẩu WordPress)

1. Trên **SiteX Post Cloud**: menu **Website → Thêm website**, nhập tên hiển thị và địa chỉ trang chủ (HTTPS). Cloud hiển thị **mã kết nối** dạng `XXXX-XXXX`, hiệu lực 15 phút, dùng một lần.
2. Trên **wp-admin** (tài khoản Quản trị viên): mở **SiteX Post**, dán mã, bấm **Tiếp tục**.
3. Màn hình **Kiểm tra & xác nhận** hiển thị workspace, tên website, địa chỉ đã đăng ký và đúng những quyền Cloud sẽ có. Kiểm tra kỹ tên workspace.
4. Chọn **tài khoản đăng bài** (nên tạo riêng một tài khoản Tác giả hoặc Biên tập viên cho SiteX Post), đánh dấu ô xác nhận, bấm **Xác nhận và kết nối**.
5. Trang plugin chuyển sang "Đã kết nối". Trên Cloud, trạng thái website chuyển sang **Đã kết nối** sau vài giây (Cloud tự kiểm tra kết nối).

Nếu báo lỗi:
| Thông báo | Cách xử lý |
|---|---|
| Mã sai/hết hạn | Tạo mã mới trên Cloud (nút **Tạo mã kết nối** trong trang website). Mỗi mã chỉ được nhập sai 5 lần. |
| Địa chỉ website không khớp | Địa chỉ khai báo trên Cloud phải trùng **Cài đặt → Tổng quan → Địa chỉ trang (URL)** của WordPress (kể cả `www`). |
| Thao tác quá nhanh | Giới hạn 10 lần ghép nối/giờ cho mỗi IP; đợi rồi thử lại. |
| Không liên lạc được SiteX Post Cloud | Máy chủ WordPress phải gọi được ra `https://api.<domain>` (tường lửa/hosting chặn kết nối ra ngoài?). |

## 3. Sử dụng hằng ngày

Trên Cloud, trang chi tiết website có: **Kiểm tra kết nối**, **Đồng bộ ngay** (chuyên mục, thẻ, bài, trang, sản phẩm WooCommerce), **Đăng bài thử** (tạo một bài nháp có 2 ảnh và SEO, chạy lại nhiều lần vẫn chỉ một bài), **Đổi khóa kết nối**, **Ngắt kết nối**, **Xóa website**, cùng các tab Nội dung, Chuyên mục & thẻ, Nhật ký kết nối, Lịch sử kiểm tra. Cloud tự kiểm tra kết nối mỗi 30 phút.

Trên WordPress, trang **SiteX Post** có các tab:
- **Kết nối**: workspace, tài khoản đăng bài, thời điểm kết nối, yêu cầu gần nhất từ Cloud, đuôi mã khóa; nút **Kiểm tra kết nối tới Cloud** và **Ngắt kết nối**.
- **Nhật ký**: yêu cầu bị từ chối, bài/ảnh được tạo, đổi khóa… (giữ 500 dòng gần nhất).
- **Chẩn đoán**: phiên bản PHP/WordPress, HTTPS, REST API, permalink, thư mục uploads, adapter SEO, WooCommerce, quyền của tài khoản đăng bài.

SEO: nếu có **Yoast SEO** hoặc **Rank Math**, plugin ghi tiêu đề SEO, mô tả, từ khóa chính, canonical và noindex vào plugin đó; nếu không, plugin tự in thẻ meta.

## 4. Ngắt kết nối & gỡ cài đặt

- Ngắt từ WordPress: **SiteX Post → Ngắt kết nối**. Khóa bị xóa ngay; Cloud được báo và không thể ghi vào website nữa.
- Ngắt từ Cloud: **Website → (chọn site) → Ngắt kết nối**. Plugin nhận lệnh và xóa khóa; nếu website tạm thời không liên lạc được, khóa phía Cloud vẫn bị thu hồi nên mọi lệnh sau đều bị website từ chối.
- Bài đã đăng **không** bị xóa khi ngắt kết nối hay gỡ plugin.
- Gỡ plugin (Plugin → Xóa) xóa option kết nối, bảng nonce và bảng nhật ký của plugin.

## 5. Bảo mật (tóm tắt cho khách hàng)

- Không bao giờ hỏi hay lưu mật khẩu WordPress; không lưu khóa API AI trên website.
- Mỗi website có khóa riêng; mọi yêu cầu từ Cloud được ký HMAC-SHA256 kèm thời gian và mã dùng một lần — yêu cầu cũ hơn 5 phút hoặc gửi lại đều bị từ chối. Phản hồi của plugin cũng được ký.
- Cloud chỉ được: đọc thông tin cơ bản và danh sách nội dung, tạo bài/trang bằng tài khoản đã chọn, sửa **bài do chính nó tạo**, tải ảnh, ghi SEO. Không đổi cài đặt, plugin, giao diện, người dùng hay nội dung khác.
- HTML được lọc (bỏ script, iframe, form, thuộc tính `on*`, URL `javascript:`); ảnh phải là JPEG/PNG/GIF/WebP thật.
Chi tiết kỹ thuật: `docs/SECURITY.md` §6, `docs/API-CONTRACTS.md` §4–5.

## 6. Cấu hình nâng cao (wp-config.php)

```php
define( 'SITEX_POST_CLOUD_URL', 'https://api.staging.example.com' ); // trỏ tới Cloud khác (staging)
define( 'SITEX_POST_ALLOW_INSECURE', true ); // CHỈ có tác dụng khi WP_ENVIRONMENT_TYPE là local/development
```
Filter `sitex_post_allowed_html` để điều chỉnh danh sách thẻ HTML được phép.

## 7. Dành cho đội phát triển

```bash
wordpress-plugin/scripts/build-plugin.sh              # php -l, kiểm tra PHP 7.4, unit test chữ ký, bản dịch, đóng gói zip
php wordpress-plugin/tests/unit/SignerTest.php        # test vector chữ ký dùng chung với Cloud
php wordpress-plugin/scripts/i18n.php pot wordpress-plugin/sitex-post wordpress-plugin/sitex-post/languages/sitex-post.pot
```
Bản dịch tiếng Việt nằm ở `wordpress-plugin/scripts/translations/vi.php` (nguồn) → `.po`/`.mo` do script build sinh ra.

E2E với WordPress thật: xem `wordpress-plugin/tests/e2e/README.md`.
