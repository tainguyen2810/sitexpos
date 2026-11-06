# Vòng đời gói dịch vụ (Phase 7)

Code: `backend/app/Modules/Billing/Subscriptions/`. Test: `tests/Feature/Billing/SubscriptionLifecycleTest.php`, `tests/Unit/Billing/BillingMathTest.php`.

## 1. Gói và phiên bản

- `plans`: mã UNIQUE, trạng thái draft/active/hidden/archived, `is_free`, `is_demo`. Không xóa được khi còn subscription dùng (409 `plan_in_use`) — chỉ ẩn/lưu trữ.
- `plan_versions`: giá tháng/năm, tiền tệ, ngày trial, credit trial, credit kèm theo, mức hỗ trợ, cờ tính năng, giới hạn (`plan_entitlements`, `-1` = không giới hạn, `0` = không có). **Bất biến sau khi publish** (409 `version_immutable`). Subscription trỏ tới version cụ thể → đổi giá chỉ áp cho khách mới/kỳ mới.

## 2. Trạng thái

| Trạng thái | Ý nghĩa | AI/tự động |
|---|---|---|
| `TRIALING` | Dùng thử, không thu tiền, không tự trừ tiền khi hết | Có |
| `ACTIVE` | Đã trả cho kỳ hiện tại | Có |
| `PAST_DUE` | Hết kỳ, có đơn gia hạn đang chờ | Theo `grace_allows_ai` |
| `GRACE_PERIOD` | Hết kỳ, trong `grace_days` | Theo `grace_allows_ai` |
| `PAUSED` | Staff tạm dừng | Không |
| `CANCELLED` | Khách hủy, vẫn dùng tới hết kỳ | Có tới hết kỳ |
| `EXPIRED` | Hết hạn | Không — **dữ liệu giữ nguyên**, đăng ký lại được |
| `SUSPENDED` | Staff đình chỉ | Không |

Chuyển hợp lệ khai báo trong `SubscriptionStatus::allowed()`; chuyển sai → `InvalidTransition`. Mọi chuyển ghi `subscription_events` (từ/đến, lý do, người thực hiện, loại actor). Một workspace chỉ có một subscription "đang sống" (`live_key` UNIQUE).

## 3. Trial

- Tắt mặc định (`trial_enabled = false`). Cần email đã xác minh (`trial_requires_verified_email`).
- Chống lạm dụng theo **người chủ**, không theo workspace: `trial_redemptions` lưu user id và hash email đã chuẩn hóa (bỏ dấu chấm và `+tag` của Gmail) → một người một lần (`trial_already_used`).
- Hết trial → `EXPIRED` (lý do `trial_expired`), không tạo khoản thu.

## 4. Đổi gói (`quote` → `change`)

- **Nâng cấp**: áp ngay. Nếu `proration_enabled`: `charge = max(0, floor((giá mới − giá cũ) × thời gian còn lại / độ dài kỳ))` tính theo giây, làm tròn xuống (có lợi cho khách); giữ nguyên `current_period_end` và id subscription. Có tiền phải trả → tạo đơn `upgrade`, áp khi đơn `PAID`.
- **Hạ cấp**: luôn lên lịch cuối kỳ (`subscription_changes.status = scheduled`), áp ở lần gia hạn; khách hủy được lịch.
- Chính sách credit khi đổi gói hiển thị trong quote (`credits_policy`).

## 5. Gia hạn (không tự trừ tiền)

Chỉ có thanh toán QR, nên không auto-debit. `sitex:billing:lifecycle` (15'):
1. Trial hết hạn → `EXPIRED`.
2. `PAST_DUE`/`GRACE_PERIOD` quá `grace_ends_at` → `EXPIRED`.
3. Hết kỳ: `CANCELLED` → `EXPIRED`; gói miễn phí (hoặc hạ xuống miễn phí) → tự lăn kỳ mới; gói trả phí → có đơn gia hạn đang chờ thì `PAST_DUE`, không thì `GRACE_PERIOD` (ân hạn `grace_days`). Thanh toán đơn gia hạn → `ACTIVE` kỳ mới (áp thay đổi đã lên lịch).
4. Nhắc gia hạn trước `renewal_notice_days` (7/3/1), mỗi mốc một lần (khách tắt được).

## 6. Hành động của staff

`pause`, `resume`, `suspend`, `unsuspend`, `extend` (n ngày; trial thì gia hạn trial), `cancel` — lý do bắt buộc, audit `billing.subscription_<action>`. Trạng thái trước khi tạm giữ lưu ở `status_before_hold` để khôi phục.

## 7. Entitlement

`EntitlementEngine::effective(ws)`: nguồn giới hạn = subscription đang sống → gói mặc định (`default_plan_code`) → không có. Khi `enforcement = enforce`:
- Không có gói và vượt giới hạn → 402 `subscription_required`; có gói → 402 `plan_limit_reached`.
- Gói không cho dùng trả phí (EXPIRED, SUSPENDED…) → 402 `subscription_inactive`.
- Tính năng tắt → 402 `feature_unavailable`.
Tài nguyên: `websites`, `members`, `automation_schedules`, `storage_mb` (tổng), `research_runs`, `keywords`, `articles`, `ai_images`, `publishing_jobs` (theo tháng, đếm trong `usage_meters` với `consume`/`release` idempotent). Khi `off`: không chặn gì (hành vi Phase 1–6 giữ nguyên).
