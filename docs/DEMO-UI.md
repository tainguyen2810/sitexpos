# Giao diện demo (UI prototype)

Bản demo là **nguyên mẫu giao diện chạy hoàn toàn trong trình duyệt** cho mọi màn hình đã lên kế hoạch của SiteX Post: 18 trang khách hàng và 12 trang Super Admin. Mục đích: duyệt giao diện và luồng sử dụng trước khi xây backend cho từng phân hệ.

> Demo **không** gọi backend, **không** gọi API AI và **không** kết nối cổng thanh toán. Toàn bộ dữ liệu là dữ liệu mẫu, được gắn nhãn rõ ràng.

## Chạy demo

```bash
cd frontend
npm install
npm run demo          # app khách hàng   http://localhost:5173
npm run demo:admin    # app Super Admin  http://localhost:5174
```

Không cần backend, MySQL hay Redis. `npm run dev` / `npm run dev:admin` vẫn chạy app thật như trước.

Trên mỗi trang có:
- **Dải vàng "DEMO"** ở đầu trang và nút **Reset dữ liệu demo**.
- **Bảng demo** (nút trên topbar): chỉnh độ trễ giả lập, bật "mô phỏng lỗi API" để xem trạng thái lỗi, mở app còn lại, reset dữ liệu.

Dữ liệu được lưu trong `localStorage` của trình duyệt (khóa `sitex.demo.db`), mỗi app (cổng 5173 / 5174) có bản riêng. Vì vậy thao tác trong app admin (ví dụ khóa khách hàng) không hiện sang app khách hàng — đây là giới hạn của bản demo, không phải thiết kế thật.

## Bảo đảm chỉ chạy ở development

1. Cờ bật demo là `import.meta.env.DEV && import.meta.env.VITE_DEMO_MODE === 'true'`, viết **trực tiếp** trong `src/apps/*/main.ts`. `vite build` thay `import.meta.env.DEV` bằng `false`, nên nhánh demo và toàn bộ `src/demo/` bị loại khỏi bundle.
2. `VITE_DEMO_MODE=true` chỉ có trong `frontend/.env.demo` (dùng với `--mode demo`), không có trong `.env.example`.
3. `npm run check:no-demo` quét `dist/` và báo lỗi nếu còn dấu vết dữ liệu demo. Bước này đã thêm vào `scripts/check.sh` và `scripts/check.ps1` ngay sau bước build.

## Dữ liệu mẫu

Sinh tất định (seed cố định) bởi `src/demo/data/seed.ts`, tên trường và trạng thái theo `docs/DATABASE.md`:

| Đối tượng | Số lượng | Ghi chú |
|---|---|---|
| Khách hàng (workspace) | 5 | 5 ngành khác nhau, 1 đang dùng thử (để xem empty state) |
| Website WordPress | 10 | đủ trạng thái: đã kết nối, không ổn định, mất kết nối, chờ ghép nối |
| Chiến dịch SEO | 8 | gắn với website |
| Ý tưởng chủ đề | 150 | gắn với chiến dịch / cụm / từ khóa |
| Bài viết | 50 | mọi trạng thái của workflow, có lịch sử phiên bản |
| Báo cáo Fact-Check | 20 | gắn với bài viết; bài lên lịch/đã đăng luôn đã qua kiểm chứng |
| Từ khóa | 100 | metrics ghi rõ "giả lập"; từ khóa AI gợi ý thêm mới có metrics = "Chưa có dữ liệu" |
| Ảnh | 30 | ảnh minh họa vẽ bằng SVG (CC0), ảnh AI có nhãn "Ảnh AI · minh họa", không đại diện sản phẩm thật |
| Thanh toán, credit, AI usage, job, audit log, ticket | — | giả lập, liên kết theo workspace |

Nhãn: tên có hậu tố "(Demo)", tên miền `.example`, nguồn trích dẫn `nguon-mau.example`, số liệu có chữ "giả lập/mô phỏng". `tests/demoData.spec.ts` kiểm tra số lượng, tính tất định, nhãn và liên kết giữa các bảng.

## Cấu trúc mã

```
frontend/src/demo/
  index.ts, boot.ts, DemoApp.vue   # cờ demo + khởi tạo app demo
  store.ts                         # db reactive + localStorage, reset, độ trễ/lỗi giả lập, job giả lập
  data/                            # types, seed, analytics giả lập
  composables/                     # useDemoQuery, useTable (tìm kiếm/lọc/sắp xếp/phân trang), useForm (validation)
  ui/                              # bảng, bộ lọc, phân trang, drawer, tabs, biểu đồ SVG, banner demo...
  customer/                        # shell + 18 trang khách hàng
  admin/                           # shell + 12 trang Super Admin
```

Thay đổi ngoài `src/demo/` (đều là bổ sung, không đổi hành vi app thật): thêm icon vào `AppIcon.vue`, thêm tùy chọn `width` cho `AppModal.vue`, tách logic khởi tạo cũ của `main.ts` sang `bootstrap.ts`, script `demo`/`demo:admin`/`check:no-demo`, kiểu env trong `env.d.ts`.

## Ngoại lệ quy tắc i18n

CLAUDE.md yêu cầu mọi chuỗi hiển thị đi qua `vue-i18n`. Mã trong `src/demo/` **tạm thời** dùng chuỗi tiếng Việt trực tiếp vì đây là nguyên mẫu để duyệt bố cục và luồng. Khi một phân hệ được làm thật trong phase tương ứng, màn hình sẽ được viết lại trong app thật (`src/apps/<app>/`) với chuỗi chuyển sang `src/locales/vi/*.json`; không import component từ `src/demo/` vào app thật.

## Những gì demo không làm

- Không gọi API AI: "AI viết bài", "gợi ý chủ đề", "kiểm chứng" là tiến trình giả lập tạo nội dung mẫu cố định.
- Không thanh toán thật: form thẻ chỉ kiểm tra định dạng (Luhn, MM/YY, CVC). Thẻ thử `4242 4242 4242 4242`. Không nhập thẻ thật.
- Không gửi email, không kết nối WordPress hay Google Search Console thật.
