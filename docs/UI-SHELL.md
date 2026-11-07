# Giao diện ứng dụng (v1.0.10)

Bản làm lại giao diện của cả hai SPA theo bản demo (`npm run demo`) đã được người dùng duyệt. Không đổi API hay database.

## Khung app
- `shared/layouts/ShellLayout.vue`: nhận `groups: NavGroup[]` (`{ label, items: NavItem[] }`, nhãn rỗng = không có tiêu đề nhóm). `NavItem` có `badge`, `badgeTone` (`brand` | `danger`) và `exact`. Các slot là `banner`, `sidebar-top`, `sidebar-bottom`, `topbar`, `topbar-right` và `floating`.
- Khách hàng (`apps/customer/components/CustomerShell.vue`):
  - Menu nhóm, kèm badge lấy từ store `stores/attention.ts`.
  - Thanh trên có `GlobalSearch.vue` (tìm trang + bài viết, Ctrl+K), nút "Viết bài mới" và `NotificationsMenu.vue`.
  - Cuối menu hiện số credit còn lại.
- Admin (`apps/admin/components/AdminShell.vue`): menu nhóm, chỉ hiện mục mà quyền `platform.*` cho phép xem.

## Bộ đếm "Việc cần bạn xử lý"
`stores/attention.ts` chỉ gọi các endpoint tenant sẵn có:
- writer overview
- publishing jobs FAILED
- publishing conflicts open
- agent tasks awaiting_approval
- billing overview
- sites

Mỗi lời gọi độc lập: lỗi hoặc 403 thì bộ đếm là `null` và không hiển thị. Kết quả lưu đệm 60 giây. Backend vẫn là nơi kiểm tra quyền. `composables/useAttentionItems.ts` dựng danh sách dùng chung cho chuông thông báo và trang Tổng quan.

## Trang Tổng quan
- Khách hàng (`pages/DashboardPage.vue`): mỗi khối tải riêng và có đủ loading/empty/error. Biểu đồ lượt nhấp chỉ dùng số liệu GSC thật; chưa kết nối thì hiện trạng thái trống.
- Admin (`apps/admin/pages/DashboardPage.vue`): KPI, chi phí AI theo ngày, sức khỏe hệ thống, lối tắt. Mỗi khối được bảo vệ bằng quyền nền tảng.

## Thành phần dùng chung
- `shared/ui/charts/*`: LineChart, BarChart, DonutChart, Sparkline, ChartLegend (SVG, không thư viện ngoài).
- `shared/ui/SubNav.vue`: tab con. Quá `max` thì dồn vào menu "Thêm"; tab đang mở luôn hiện.
- `styles.css`:
  - Kiểu mặc định cho ô nhập gốc (base layer).
  - Lớp `.data-table` cho mọi bảng. Lớp này để ngoài layer để thắng padding cũ trên từng ô.
- `shared/utils/format.ts` → `formatRelative()`.

## Sửa lỗi kèm theo
Commit Phase 9 `53a158c` đã vô tình xóa class bố cục (để lại `class=""`) trên khoảng 30 phần tử, gồm lưới thư viện media, các `<dl>` chi tiết và tổng quan nghiên cứu. Bản này khôi phục chúng từ `53a158c^`.
