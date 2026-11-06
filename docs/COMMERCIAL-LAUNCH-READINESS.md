# Đánh giá sẵn sàng ra mắt thương mại — Phase 9

Ngày: 06/11/2026. Kết luận ngắn: **chưa sẵn sàng bán cho khách trả tiền.** Phần mềm đã đủ chức năng và đã qua kiểm thử tự động, kiểm thử trình duyệt, cài đặt thật trên MySQL/Redis. Nhưng mọi tích hợp tạo ra giá trị cho khách (AI, WordPress, Google Search Console, thanh toán) **chưa từng chạy với hệ thống thật**, và còn một số việc pháp lý/vận hành bắt buộc. Có thể mở **beta nội bộ hoặc beta có kiểm soát (không thu tiền)** trên staging ngay khi có credentials.

Thang đánh giá: **SẴN SÀNG** · **SẴN SÀNG CÓ ĐIỀU KIỆN** (làm xong mục nêu kèm) · **CHƯA SẴN SÀNG**.

## 1. Bảng đánh giá

| Lĩnh vực | Đánh giá | Căn cứ | Việc phải làm trước khi bán |
|---|---|---|---|
| Cài đặt và triển khai (aaPanel) | SẴN SÀNG CÓ ĐIỀU KIỆN | `/install` chạy thật trên MySQL 8.0.46 + Redis; khóa sau cài; mẫu Nginx/Supervisor/Cron | Cài thử một lần trên máy chủ Ubuntu + aaPanel thật với MySQL 8.4 / PHP-FPM 8.4; chốt domain (Q2) |
| Bảo mật ứng dụng | SẴN SÀNG CÓ ĐIỀU KIỆN | SECURITY-AUDIT: không còn P0/P1; cô lập 3 tenant; composer/npm audit sạch | Pentest độc lập; CSP cho SPA; email thông báo bảo mật |
| Cô lập dữ liệu khách | SẴN SÀNG | Test tự liệt kê mọi route tenant + test 3 tenant + E2E trình duyệt | — |
| AI viết bài | CHƯA SẴN SÀNG | Chỉ chạy với `FakeProvider`; chưa đánh giá chất lượng tiếng Việt của model thật; chưa đối chiếu chi phí với hóa đơn provider | Khóa API thật, ngân sách nhỏ; viết thử 20–50 bài, đánh giá theo `CONTENT-QUALITY-RUBRIC.md`; đối chiếu credit với hóa đơn |
| Đăng WordPress | CHƯA SẴN SÀNG | E2E thật chỉ ở Phase 2 (82/82); kịch bản xung đột Phase 6 chưa chạy với WP thật; Yoast/RankMath/Woo thật chưa thử; plugin chưa chạy PHP 7.4 thật | E2E trên WP 6.4 + mới nhất với plugin SEO thật; Plugin Check; URL Cloud mặc định trong plugin |
| SEO Analytics (GSC) | CHƯA SẴN SÀNG | Chỉ `Http::fake`; dashboard ~0,7 s p50 với 148k dòng (PERF-1) | Kết nối property thật, đối chiếu số liệu với Search Console; index + tổng hợp cho site lớn |
| Thanh toán và credit | CHƯA SẴN SÀNG | Ledger, webhook ký, đối soát có test; chưa có cổng/ngân hàng thật; giá chưa nhập; `enforcement` đang `off` | Chọn dịch vụ báo giao dịch (Q1), chạy giao dịch thật nhỏ; nhập giá (Q4); bật `enforcement`; quyết định hóa đơn GTGT (chứng từ hiện tại không phải hóa đơn) |
| Vận hành (giám sát, sao lưu, nâng cấp) | SẴN SÀNG CÓ ĐIỀU KIỆN | Health 16 mục, cảnh báo có cooldown, sao lưu mã hóa + restore-test chạy thật trên MySQL, upgrade pre-flight | Thử SMTP/Telegram thật; uptime monitor bên ngoài; bản sao lưu ngoài máy chủ (S3/SFTP); diễn tập rollback trên staging |
| Hiệu năng và tải | CHƯA SẴN SÀNG | Chỉ đo in-process, một người dùng; chưa load test | Staging giống production, chạy `scripts/perf/k6-smoke.js`, theo dõi p95 |
| Giao diện | SẴN SÀNG CÓ ĐIỀU KIỆN | 79 màn hình mở được trên trình duyệt thật, không tràn ở 390 px; tiếng Việt đầy đủ; có loading/error/empty | Bản tiếng Anh nếu bán ra ngoài Việt Nam; kiểm thử với người dùng thật |
| Pháp lý và dữ liệu cá nhân | CHƯA SẴN SÀNG | Xóa workspace chỉ đánh dấu, chưa xóa hẳn; chưa có điều khoản, chính sách quyền riêng tư | Job xóa dữ liệu theo hạn giữ + xuất dữ liệu (Nghị định 13/2023); điều khoản dịch vụ, chính sách quyền riêng tư, chính sách hoàn tiền (Q5) |
| Hỗ trợ khách | CHƯA SẴN SÀNG | Có impersonation chỉ đọc có audit; chưa có quy trình/kênh hỗ trợ | Kênh hỗ trợ, SLA, tài liệu hướng dẫn cho khách |

## 2. Lộ trình đề xuất

1. **Staging (1–2 tuần)**: máy chủ giống production, domain thật, SMTP thật, sao lưu ngoài máy chủ, uptime monitor. Chạy RELEASE-CHECKLIST mục C.
2. **Tích hợp thật**: khóa AI (ngân sách nhỏ), 2 site WordPress thật, 1 property GSC, 1 tài khoản nhận thanh toán (sandbox nếu có). Chạy lại các luồng 2–11 của E2E-TEST-REPORT, chuyển TESTED → PASS.
3. **Pháp lý**: điều khoản, quyền riêng tư, job xóa dữ liệu, quyết định hóa đơn GTGT.
4. **Beta có kiểm soát**: 3–5 khách quen, không thu tiền hoặc thu tượng trưng, `enforcement` bật, theo dõi chi phí AI/khách.
5. **Pentest + load test** trên staging, sửa phát hiện.
6. **Mở bán**: nhập giá, bật thanh toán thật, theo dõi 72 giờ đầu (RELEASE-CHECKLIST mục E).

## 3. Rủi ro kinh doanh cần lưu ý

- **Chi phí AI vượt doanh thu**: credit có giữ chỗ và báo cáo lợi nhuận (chỉ cảnh báo), nhưng chưa có số liệu chi phí thật cho một bài viết. Cần đo ở bước 2 trước khi định giá.
- **Thay đổi API của provider/Google/WordPress**: adapter dựng theo tài liệu ngày 05–06/11/2026; cần theo dõi và kiểm lại Model ID/giá trước khi bật.
- **Một máy chủ**: kiến trúc hiện tại chạy tốt trên một máy; tách worker được (DEPLOYMENT §8) nhưng chưa thử.
