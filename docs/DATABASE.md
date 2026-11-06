# SiteX Post — Thiết kế cơ sở dữ liệu

MySQL 8.4 LTS, InnoDB, `utf8mb4` / `utf8mb4_0900_ai_ci`. Múi giờ lưu trữ: **UTC** (`datetime` UTC, hiển thị theo `users.timezone`/`workspaces.timezone`).

## 1. Quy ước chung

| Quy ước | Chi tiết |
|---|---|
| Khóa chính | `id CHAR(26)` ULID (Laravel `HasUlids`). Bảng log khối lượng lớn (`audit_logs`, `ai_requests`, `usage_events`) dùng `BIGINT UNSIGNED AUTO_INCREMENT` + cột `ulid` công khai nếu cần |
| Tenant | Mọi bảng dữ liệu khách hàng có `workspace_id CHAR(26) NOT NULL` + FK `workspaces.id` + index bắt đầu bằng `workspace_id` |
| Thời gian | `created_at`, `updated_at`; soft delete `deleted_at` chỉ ở bảng ghi rõ |
| Tiền | Số nguyên đơn vị nhỏ nhất (`amount_minor BIGINT`) + `currency CHAR(3)`. Chi phí AI: `cost_micro_usd BIGINT` |
| Enum | `VARCHAR(32)` + PHP backed enum (không dùng MySQL ENUM để dễ migrate) |
| JSON | Cột `JSON` cho cấu hình/metadata; không lưu dữ liệu cần query thường xuyên trong JSON |
| Secret | Cột `*_encrypted TEXT` dùng cast `encrypted` của Laravel (APP_KEY) hoặc envelope encryption (xem SECURITY.md §5) |
| Demo | Bảng có thể chứa dữ liệu demo có `is_demo BOOLEAN DEFAULT 0` ở `workspaces`; seeder demo bị chặn trong production |
| Migration | Nằm trong `Modules/<X>/Database/Migrations`, tên file timestamp; chỉ thêm, không sửa migration đã phát hành |

**Isolation ở tầng DB cho bảng quan trọng**: bảng con lưu thêm `workspace_id` và có FK kép `(workspace_id, parent_id) → parent(workspace_id, id)` (parent có `UNIQUE(workspace_id, id)`). Áp dụng cho: `sites`, `articles`, `content_versions`, `publish_jobs`, `site_credentials`. Một bug ở tầng ứng dụng không thể gắn bài của tenant A vào site của tenant B.

## 2. Sơ đồ quan hệ chính

```
users ──< workspace_members >── workspaces ──< sites ──< site_credentials
  │                                  │            └──< site_health_checks
  │                                  ├──< subscriptions >── plans ──< plan_limits
  │                                  ├──< credit_ledger, usage_events
  │                                  ├──< business_profiles ──< offerings
  │                                  ├──< research_runs ──< research_sources
  │                                  ├──< keywords ──< keyword_metrics
  │                                  ├──< topic_clusters ──< calendar_items
  │                                  ├──< articles ──< content_versions
  │                                  │       ├──< claims ──< claim_evidence >── research_sources
  │                                  │       ├──< article_media >── media_assets
  │                                  │       └──< publish_jobs
  │                                  ├──< gsc_connections, search_performance_daily
  │                                  └──< job_runs, audit_logs, ai_requests
```

## 3. Bảng theo module

### 3.1 Identity

**users**
| Cột | Kiểu | Ghi chú |
|---|---|---|
| id | ULID PK | |
| name | varchar(120) | |
| email | varchar(190) UNIQUE | lưu lowercase |
| email_verified_at | datetime null | |
| password | varchar(255) | bcrypt/argon2id |
| platform_role | varchar(32) null | `SUPER_ADMIN`, `SUPPORT`, `BILLING_ADMIN`; null = khách hàng |
| two_factor_secret, two_factor_recovery_codes | text null | mã hóa (Fortify) |
| two_factor_confirmed_at | datetime null | bắt buộc với platform_role |
| locale | varchar(10) default `vi` | |
| timezone | varchar(64) default `Asia/Ho_Chi_Minh` | |
| last_workspace_id | ULID null | gợi ý điều hướng, **không** dùng để phân quyền |
| status | varchar(32) | `active`, `suspended` |
| last_login_at | datetime null | |
| remember_token, timestamps | | |

**sessions** (Laravel database session driver hoặc Redis), **password_reset_tokens** (Laravel mặc định).

### 3.2 Workspace

**workspaces**
| Cột | Kiểu | Ghi chú |
|---|---|---|
| id | ULID PK | |
| name | varchar(120) | |
| slug | varchar(80) UNIQUE | |
| type | varchar(32) | `standard`, `agency` |
| owner_id | ULID FK users | đồng bộ với membership OWNER |
| locale, timezone | | mặc định cho nội dung/hiển thị |
| status | varchar(32) | `active`, `suspended`, `pending_deletion` |
| is_demo | bool default 0 | |
| settings | JSON | cấu hình không nhạy cảm |
| timestamps, deleted_at | | soft delete, xóa cứng sau thời gian lưu giữ |

**workspace_members** — UNIQUE(`workspace_id`, `user_id`)
| Cột | Kiểu | Ghi chú |
|---|---|---|
| id | ULID PK | |
| workspace_id | FK | |
| user_id | FK | |
| role | varchar(32) | `OWNER`, `ADMIN`, `EDITOR`, `VIEWER` |
| status | varchar(32) | `active`, `suspended` |
| invited_by | ULID null | |
| joined_at | datetime | |

Ràng buộc nghiệp vụ: mỗi workspace có **đúng một** OWNER active (kiểm tra trong Action + test); chuyển quyền OWNER là Action riêng có audit.

**workspace_invitations**
| Cột | Ghi chú |
|---|---|
| id, workspace_id, email, role | role ≠ OWNER |
| token_hash | SHA-256 của token gửi qua email; token gốc không lưu |
| invited_by, expires_at (7 ngày), accepted_at, revoked_at | |

### 3.3 Platform & Audit

**audit_logs** (BIGINT PK, append-only, không có update/delete trong code)
| Cột | Ghi chú |
|---|---|
| id | BIGINT |
| workspace_id | null với hành động platform |
| actor_type | `user`, `system`, `connector`, `platform_staff` |
| actor_id | |
| impersonator_id | null — staff đang impersonate |
| action | `workspace.member.invited`, `site.connected`, `site.revoked`, `article.published`... |
| subject_type, subject_id | |
| changes | JSON (đã che secret) |
| ip, user_agent, request_id | |
| created_at | index (`workspace_id`, `created_at`) |

**impersonation_sessions**: id, staff_user_id, workspace_id, reason (bắt buộc), started_at, ended_at, read_only (bool, mặc định true).

**system_settings**: key UNIQUE, value JSON, updated_by — cấu hình toàn cục do Super Admin quản lý (không chứa API key; API key nằm trong `.env`).

### 3.4–3.5 Billing, credit & usage ✦ Phase 7 (đã triển khai — thay thiết kế `plans/plan_prices/plan_limits/invoices/payments/webhook_events/credit_packs/usage_counters/credit_ledger` dự kiến trước đây)

Migration `app/Modules/Billing/Database/Migrations/2026_11_12_000100_create_billing_tables.php` (28 bảng). Thiết kế chi tiết: `BILLING-ARCHITECTURE.md`, `CREDIT-LEDGER.md`, `PAYMENT-RECONCILIATION.md`, `SUBSCRIPTION-LIFECYCLE.md`. Tiền `*_minor`, giá vốn µUSD, credit milli-credit `*_mc` (BIGINT, 1 credit = 1000 mc). Bảng có `workspace_id` là bảng tenant (model dùng `BelongsToWorkspace`); bảng catalog/provider/setting là cấp nền tảng.

**Catalog (nền tảng)**
| Bảng | Cột chính | Ràng buộc |
|---|---|---|
| `billing_settings` | key, value JSON, updated_by | key PK; giá trị mặc định trong `BillingSettings::DEFAULTS` |
| `plans` | code, name, description, status (draft/active/hidden/archived), is_free, is_demo, display_order, current_version_id | code UNIQUE; không xóa khi có subscription |
| `plan_versions` | plan_id, version, status, currency, monthly/yearly_price_minor (null = không bán), trial_days, trial_credits_mc, included_credits_mc, support_level, feature_flags JSON, notes, published_at | UNIQUE(plan_id, version); bất biến sau publish |
| `plan_entitlements` | plan_version_id, resource_key, limit_value (-1 = không giới hạn), period (total/month) | UNIQUE(plan_version_id, resource_key) |
| `credit_packages` | code, name, credits_mc, bonus_credits_mc, price_minor, currency, validity_days, status, is_demo | code UNIQUE |
| `credit_policies` | version, micro_usd_per_credit, markup_bps, min_charge_mc, effective_from | version UNIQUE |
| `payment_provider_configs` | code, adapter (manual_bank/signed_webhook), name, is_enabled, settings JSON, credentials (encrypted) | code UNIQUE; credentials không bao giờ trả qua API |

**Subscription (tenant)**
| Bảng | Cột chính | Ràng buộc |
|---|---|---|
| `subscriptions` | plan_id, plan_version_id, status (8), interval, price_minor, currency, started_at, current_period_start/end, trial_ends_at, grace_ends_at, cancelled_at, ended_at, status_before_hold, renewal_reminders, live_key, lock_version | `live_key` UNIQUE → một subscription đang sống / workspace |
| `subscription_events` | event, from_status, to_status, plan_version_id, payment_order_id, actor_type, actor_id, reason, data | append-only |
| `subscription_changes` | kind (upgrade/downgrade), from/to_version_id, to_interval, status (scheduled/pending_payment/applied/cancelled), effective_at, amount_minor, quote JSON, payment_order_id | |
| `trial_redemptions` | user_id, email_hash, workspace_id, plan_id | UNIQUE(user_id), UNIQUE(email_hash) — một người một trial |
| `usage_meters` | resource_key, period_start, used | UNIQUE(workspace_id, resource_key, period_start) |
| `usage_events` | resource_key, period_start, amount (âm khi hoàn), idempotency_key | idempotency_key UNIQUE |

**Credit (tenant)** — xem `CREDIT-LEDGER.md`
| Bảng | Cột chính | Ràng buộc |
|---|---|---|
| `credit_wallets` | balance_mc, held_mc (projection), overdraft_limit_mc, low_balance_threshold_mc | UNIQUE(workspace_id) |
| `credit_grants` | wallet_id, source, amount_mc, remaining_mc, expires_at, source_ref, price_minor, expired_at | UNIQUE(workspace_id, source_ref) |
| `credit_reservations` | idempotency_key, purpose, category, policy_id, estimate_micro_usd, amount_mc, status, actual_micro_usd, charged_mc, uncollected_mc, needs_review, expires_at, settled_at | idempotency_key UNIQUE |
| `credit_ledger_entries` (BIGINT PK) | wallet_id, grant_id, entry_type, amount_mc (có dấu), balance_after_mc, idempotency_key, reference_type/id, reverses_entry_id, description, actor_id | append-only; idempotency_key UNIQUE |
| `credit_adjustments` | amount_mc, kind, reason, expires_at, ledger_entry_id, created_by | |

**Thanh toán** — xem `PAYMENT-RECONCILIATION.md`
| Bảng | Cột chính | Ràng buộc |
|---|---|---|
| `payment_orders` (tenant) | purpose, plan_version_id, interval, credit_package_id, subscription_id, subscription_change_id, description, amount_minor, currency, provider, reference, status (8), paid/refunded_amount_minor, fee_minor, idempotency_key, snapshot, expires_at, paid_at, fulfilled_at | reference UNIQUE; UNIQUE(workspace_id, idempotency_key) |
| `payment_webhook_events` (nền tảng) | provider, event_id, signature_valid, payload_hash, payload (đã rút gọn), status, error | UNIQUE(provider, event_id) — chống replay |
| `payment_transactions` (nền tảng; workspace_id gán khi khớp) | provider, external_id, direction, amount_minor, currency, description, counterparty, occurred_at, match_status, match_reason, payment_order_id, webhook_event_id, recorded_by | UNIQUE(provider, external_id) |
| `payment_reconciliations` | payment_transaction_id, payment_order_id, result, difference_minor, reviewed_by, note | append-only |
| `refunds` (tenant) | payment_order_id, amount_minor, credits_reversed_mc, status (requested/processing/succeeded/failed), reason, external_ref, failure_reason | |

**Chứng từ & kế toán**
| Bảng | Cột chính | Ràng buộc |
|---|---|---|
| `billing_profiles` (tenant) | company_name, tax_code, address, contact_name, email, phone | UNIQUE(workspace_id) |
| `billing_documents` (tenant) | type (receipt/refund_record), number, payment_order_id, refund_id, amount_minor, snapshot, issued_at, source_key | number UNIQUE, source_key UNIQUE; **không phải hóa đơn GTGT** |
| `billing_notifications` (tenant) | type, dedupe_key, data, sent_at | dedupe_key UNIQUE |
| `revenue_records` (tenant) | kind, payment_order_id, refund_id, plan_id, amount_minor, discount_minor, service_start/end, credit_grant_id, occurred_at, source_key | source_key UNIQUE |
| `cost_records` (tenant) | day, category, provider, model, requests, cost_micro_usd, uncertain_micro_usd | UNIQUE(workspace_id, day, category, provider, model) — gộp từ `ai_requests` trước khi prune |

Audit billing dùng chung `audit_logs` (action `billing.*`), không tạo bảng audit riêng.

### 3.6 Sites (WordPress) ✦ Phase 2

Migration: `backend/app/Modules/Sites/Database/Migrations/2026_11_06_000100_create_sites_tables.php`. Mọi bảng con có `workspace_id` + FK ghép `(workspace_id, site_id) → sites(workspace_id, id)` (không thể gắn dữ liệu của site sang workspace khác), model dùng `BelongsToWorkspace` (scope fail-closed, có arch test).

**Sai khác so với thiết kế Phase 0 (có chủ đích):**
- `sites` **không** có `deleted_at`: xóa site là xóa cứng (sau khi thu hồi khóa), dữ liệu con xóa theo `ON DELETE CASCADE`. Bài trên WordPress không bị ảnh hưởng. Lịch sử thao tác vẫn còn trong `audit_logs`.
- Không có trạng thái `disconnected`: ngắt kết nối từ bất kỳ phía nào → `revoked` (+ `revoked_by` = `cloud` | `wordpress`).
- `site_credentials` lưu **secret HMAC** (`secret_encrypted`, mã hóa bằng `Crypt`/`APP_KEY`) thay cho cặp khóa Ed25519 + envelope encryption (xem SECURITY §5, §6).
- `site_posts_index` đổi tên thành `site_content_items` (gồm cả trang và sản phẩm WooCommerce); thêm `site_publications`, `site_media`, `site_activity_logs`.

**sites**
| Cột | Ghi chú |
|---|---|
| id, workspace_id | UNIQUE(workspace_id, id) |
| name | |
| url | URL gốc đã chuẩn hóa (scheme + host + path, không query/fragment, không `/` cuối) |
| url_hash | sha256 của URL bỏ scheme; UNIQUE(workspace_id, url_hash) |
| status | `pending_pairing`, `connected`, `degraded`, `revoked` |
| content_language | `vi`, `en` |
| wp_version, php_version, plugin_version | báo cáo từ plugin |
| publish_author_wp_id, publish_author_name | tài khoản WP dùng để đăng (do quản trị WP chọn khi ghép nối) |
| approval_mode | `manual` (mặc định), `auto` — dùng từ Phase 8 |
| settings | JSON: `default_post_status`, `default_category_id` |
| capabilities | JSON từ health check: `rest_url`, `features`, `issues`, `author`, `limits` |
| last_error_code, last_error_message | lỗi kết nối gần nhất |
| last_seen_at, last_health_at, last_synced_at, connected_at, revoked_at, revoked_by | |
| created_by, timestamps | |

**site_pairings**: id, workspace_id, site_id, code_hash (HMAC-SHA256 với `APP_KEY`, UNIQUE), expires_at (15 phút), used_at, invalidated_at, attempts (tối đa 5), created_by.

**site_credentials** (tối đa một bản `active` / site)
| Cột | Ghi chú |
|---|---|
| id, workspace_id, site_id | |
| key_id | ULID, UNIQUE, gửi trong header `X-SiteX-Key-Id` |
| secret_encrypted | secret 32 byte (base64url) mã hóa bằng `Crypt`; không bao giờ trả qua API (trừ đúng một lần trong response `/connector/v1/pair`) |
| status | `active`, `rotated` (còn hiệu lực tới `grace_until`), `revoked` |
| grace_until, last_used_at, rotated_at, revoked_at, timestamps | |

**site_health_checks**: id, workspace_id, site_id, checked_at, ok, http_status, latency_ms, error_code, details JSON. Dọn sau `log_retention_days` (30 ngày) bằng `sitex:sites:prune`.

**site_taxonomies**: id, workspace_id, site_id, taxonomy (`category`, `post_tag`, `product_cat`, `product_tag`), wp_term_id, name, slug, parent_wp_id, count, synced_at. UNIQUE(site_id, taxonomy, wp_term_id). Term không còn trên WP bị xóa ở lần đồng bộ đầy đủ kế tiếp.

**site_content_items** (chỉ mục nội dung + map ID bài WP): id, workspace_id, site_id, post_type (`post`, `page`, `product`), wp_post_id, status, title, url (chỉ giữ URL cùng host với site), slug, excerpt, term_ids JSON, product JSON (sku, price, regular_price, sale_price, currency, stock_status, type), is_managed (do SiteX tạo), published_at, modified_at, synced_at. UNIQUE(site_id, post_type, wp_post_id).

**site_publications** (idempotency phía Cloud): id, workspace_id, site_id, idempotency_key, source_type, source_id, post_type, wp_post_id, wp_status, url, payload_hash, status (`pending`, `succeeded`, `failed`), attempts, last_error_code, published_at. UNIQUE(site_id, idempotency_key).

**site_media**: id, workspace_id, site_id, idempotency_key, sha256, mime, bytes, wp_media_id, url. UNIQUE(site_id, idempotency_key) — ảnh tải lại không tạo bản thứ hai.

**site_activity_logs** (nhật ký kết nối, không chứa secret/payload): id (bigint), workspace_id, site_id, direction (`in`/`out`), operation, level, http_status, error_code, message, latency_ms, request_id, created_at. Dọn sau 30 ngày.

**Phía WordPress (plugin):** option `sitex_post_connection` (autoload = no, chứa key_id/secret/tác giả/khóa cũ trong grace); bảng `{prefix}sitex_post_nonces` (key_id + nonce PRIMARY KEY, expires_at); bảng `{prefix}sitex_post_logs` (giữ 500 dòng); post meta `_sitex_post_managed`, `_sitex_post_idempotency_key`, `_sitex_post_source`, `_sitex_post_seo_*`. Gỡ plugin xóa option và hai bảng; post meta được giữ lại cùng bài viết (bài không bị xóa).

### 3.7–3.9 Research, Keywords & Planning ✦ Phase 4 (đã triển khai — thay thiết kế dự kiến trước đây)

Migration: `app/Modules/Research/Database/Migrations/2026_11_09_000100_create_research_tables.php`. Mọi bảng có `workspace_id` + global scope fail-closed, trừ `research_limits` và `research_source_settings` (cấu hình nền tảng).

| Bảng | Nội dung chính |
|---|---|
| `website_profiles` | hồ sơ website theo site: title, tagline, mô tả, ngôn ngữ, sitemap, thống kê, chủ đề đã có, trạng thái crawl |
| `website_knowledge` | nhận định theo `kind` (industry, product_line, audience, usp…), `source` (website/ai/user/demo), `confidence`, `evidence`, `status` (suggested/confirmed/rejected), unique theo fingerprint — quyết định của user không bị AI ghi đè |
| `seo_campaigns` · `campaign_settings` · `research_schedules` | chiến dịch theo site; cài đặt phạm vi/số ý tưởng/trần AI/lịch đăng; lịch nghiên cứu định kỳ (`next_run_at`) |
| `research_runs` · `research_sources` · `source_snapshots` | mỗi lần chạy (trạng thái, stage, stats, warnings, cost, `idempotency_key` unique); nguồn đã thu thập (URL, HTTP, robots, `fetched_at`); snapshot title/heading/câu hỏi (không lưu HTML) |
| `keywords` | unique `(campaign_id, fingerprint)`; intent + nguồn của intent; `sources` JSON; `evidence`; `opportunity_score` + `score_breakdown`; `covered_by` (bài đã có) |
| `keyword_metrics` | **chỉ ghi khi có dữ liệu thật**: `source` bắt buộc (dataforseo/search_console), volume/KD/CPC/impressions/clicks/position, `collected_at`. Không có cột nào do AI sinh |
| `topic_clusters` · `content_gaps` · `internal_link_suggestions` | cụm (pillar, độ phủ), khoảng trống (missing/competitor/unanswered/cannibalization/overlap/striking_distance), gợi ý liên kết — đều unique theo fingerprint |
| `content_ideas` · `content_idea_keywords` | ý tưởng (9 `content_type`, intent, ưu tiên, nguồn ai/rule/user/demo, evidence, brief, overlap), unique theo fingerprint; pivot role primary/secondary |
| `competitor_websites` · `competitor_findings` | đối thủ theo chiến dịch; finding = kind/title/URL/headings/`fetched_at` (không thân bài) |
| `content_calendar_items` | một ý tưởng tối đa một slot; `scheduled_for`, `position`, `status`, `source` |
| `search_console_connections` | token OAuth mã hóa, property, trạng thái |
| `research_notifications` | thông báo cơ hội/kết quả chạy |
| `research_usage_counters` · `research_limits` · `research_source_settings` | quota theo tháng (used/reserved); giới hạn global/workspace; cấu hình nguồn + khóa mã hóa + bộ đếm lỗi |

### 3.10 Content & Verification (thiết kế ban đầu — đã được thay bằng §3.10b ở Phase 5)

**articles**
| Cột | Ghi chú |
|---|---|
| id, workspace_id, site_id | FK kép tới sites |
| calendar_item_id null, primary_keyword_id null | |
| title, slug | |
| status | `idea`, `brief`, `sourcing`, `drafting`, `verifying`, `needs_revision`, `optimizing`, `media`, `ready_for_review`, `awaiting_approval`, `approved`, `scheduled`, `publishing`, `published`, `publish_failed`, `archived` |
| current_version_id | |
| verification_status | `pending`, `passed`, `failed`, `overridden` |
| seo_checklist | JSON kết quả kiểm tra (không phải "bảo đảm thứ hạng") |
| language | |
| scheduled_at, published_at | |
| wp_post_id, wp_url | sau khi đăng |
| created_by, approved_by, approved_at | |
| timestamps, deleted_at | |

**article_briefs**: id, workspace_id, article_id, outline JSON, search_intent, target_length, required_sources_count, notes, version.

**content_versions** (immutable — không UPDATE nội dung)
| Cột | Ghi chú |
|---|---|
| id, workspace_id, article_id | |
| version_no | UNIQUE(article_id, version_no) |
| title, meta_title, meta_description, slug | |
| body_html | đã sanitize (HTMLPurifier allowlist) |
| body_json | cấu trúc block (tùy chọn) |
| author_type | `ai`, `user` |
| author_id, ai_request_id null | |
| change_note | |
| created_at | |

**claims**: id, workspace_id, article_id, content_version_id, text, severity (`high`: số liệu/y tế/pháp lý/giá; `medium`; `low`), status (`supported`, `unsupported`, `contradicted`, `needs_human`), checked_at.

**claim_evidence**: id, workspace_id, claim_id, research_source_id, quote (trích nguyên văn từ nguồn), quote_found (bool — hệ thống đã kiểm tra quote có trong văn bản nguồn), stance (`supports`, `contradicts`).

**citations**: id, workspace_id, content_version_id, research_source_id, anchor_text, position.

**verification_reports**: id, workspace_id, article_id, content_version_id, result (`passed`,`failed`), score, summary JSON, overridden_by null, override_reason null.

### 3.10b Writer ✦ Phase 5 (đã triển khai — thay thiết kế §3.10 cho phần bài viết & kiểm chứng)

Một migration `app/Modules/Writer/Database/Migrations/2026_11_10_000100_create_writer_tables.php` tạo 25 bảng mới, không sửa bảng cũ. Mọi bảng có `workspace_id` (trừ `writer_settings` cho phép null = cấu hình nền tảng), model dùng `BelongsToWorkspace` (fail-closed). ID dạng ULID.

| Bảng | Vai trò |
|---|---|
| `writing_presets` | Brand voice: giọng văn, cụm từ cấm/ưu tiên, CTA, `facts[]` mỗi fact có `provenance` (`user_provided`, `website_extracted`, `source_verified`, `ai_inferred`) |
| `writing_batches`, `writing_batch_items` | Lô viết bài, trạng thái từng bài |
| `articles` | Bài viết: `status` (13 trạng thái của `ArticleStatus`), cấu hình wizard, ngân sách, `current_version_id`, `duplicate_check`, bộ đếm claim chưa xử lý |
| `article_versions`, `article_sections` | Phiên bản bất biến; section có `content_hash` để phát hiện section đổi |
| `research_dossiers`, `research_questions` | Hồ sơ nghiên cứu, câu hỏi |
| `evidence_sources`, `evidence_snapshots`, `evidence_items` | Nguồn (URL, loại, độ tin cậy, trạng thái tải/bị chặn), bản chụp văn bản đã tải (hash), đoạn trích nguyên văn |
| `content_briefs`, `article_outlines` | Brief và dàn ý (có trạng thái duyệt) |
| `article_claims`, `claim_evidence` | Khẳng định (risk, category, status, section) và liên kết bằng chứng (supports/contradicts) |
| `fact_check_runs`, `fact_check_results` | Lần kiểm chứng và verdict từng claim (đề xuất của model + verdict cuối + `rule_notes`, `reviewer_type`) |
| `article_revisions` | Đề xuất sửa (tự động hoặc AI rewrite) kèm diff, trạng thái apply/discard |
| `quality_reports` | Kết quả rubric theo phiên bản |
| `editorial_approvals` | Duyệt/từ chối; `invalidated_at` khi nội dung đổi |
| `article_workflow_runs` | Liên kết bài ↔ `workflow_runs` (kind: research/outline/draft/recheck/rewrite) |
| `article_ai_usage` | Chi phí AI theo bước (micro-USD, token, task key) |
| `writer_usage_counters` | Quota tháng theo workspace |
| `writer_settings` | Ghi đè cấu hình nền tảng/workspace |

Rollback: `php artisan migrate:rollback --step=1` (xóa toàn bộ dữ liệu writer). Dữ liệu DEMO: `WriterDemoSeeder` (cờ `is_demo`, tiêu đề `[DEMO]`, chặn ở production).

### 3.11–3.12 Media, Seo & Publishing ✦ Phase 6 (đã triển khai — thay thiết kế dự kiến trước đây)

Migration theo module: `Media/Database/Migrations/2026_11_11_000100_create_media_tables.php`, `Seo/.../2026_11_11_000200_create_seo_tables.php`, `Publishing/.../2026_11_11_000300_create_publishing_tables.php`. Mọi bảng tenant có `workspace_id` + `BelongsToWorkspace`, khóa ngoại tổng hợp `(workspace_id, id)`. Tiền: micro-USD số nguyên. Thời gian UTC.

**Media**
| Bảng | Cột chính | Ràng buộc |
|---|---|---|
| `media_sources` | site_id, type (`wordpress`/`woocommerce`/`upload`/`ai`/`stock`/`infographic`), enabled, status, config, items_count, last_synced_at | UNIQUE (site_id, type) |
| `media_assets` | site_id?, source_type, source_url, source_ref, original_filename, mime, width, height, bytes, sha256, dhash, duplicate_of_id, storage_path, license (`owned`/`customer_provided`/`licensed_stock`/`wordpress_site`/`ai_generated`/`unknown`), attribution, **is_ai_generated**, ai_provider, ai_model, ai_prompt, generation_job_id, title, alt_text, caption, metadata, status (`processing`/`ready`/`failed`/`rejected`), error_code, wp_media_id, usage_count, is_demo | UNIQUE (site_id, source_type, source_ref) |
| `media_versions` | media_asset_id, kind (`original`/`optimized`/`webp`/`avif`/`thumbnail`/`crop`), mime, width, height, bytes, sha256, storage_path, params | UNIQUE (media_asset_id, kind) |
| `image_generation_jobs` | site_id, article_id, parent_job_id, version, image_type, section_key, aspect_ratio, style, quality, image_count, brand_colors, prompt_template, prompt, prompt_edited, status (`queued`/`running`/`succeeded`/`failed`/`blocked`/`canceled`), budget_cap_micro_usd, estimated/cost_micro_usd, provider, model, ai_request_id, **call_key** UNIQUE, result_asset_ids, job_run_id | |
| `image_placements` | article_id, media_asset_id?, slot (`featured`/`intro`/`section`/`comparison`/`infographic`/`product`), section_key, section_heading, position, alt_text, caption, status (`suggested`/`accepted`/`rejected`), reason, origin (`engine`/`user`) | |
| `media_limits` (nền tảng) | scope_key (`global` / `ws:<id>`) UNIQUE, max_storage_bytes, max_uploads_per_day, max_ai_images_per_day, max_ai_cost_per_job_micro_usd, ai_images_enabled | |

**Seo**
| Bảng | Cột chính |
|---|---|
| `seo_metadata` | article_id, site_id, seo_title, meta_description, slug, canonical_url, focus_keyword, secondary_keywords, og_title, og_description, social_image_asset_id, robots_noindex, robots_nofollow, schema_types, **manual_fields**, source, **lock_version** |
| `seo_audit_runs` | article_id, site_id, article_version_id, content_hash, **rules_version**, status, score, pass/warning/error/blocked_count, links_checked, summary |
| `seo_audit_issues` | seo_audit_run_id, rule, category, severity, weight, points, message, explanation, fix, context |
| `seo_link_suggestions` | article_id, site_id, target_wp_post_id, target_post_type, target_url, target_title, anchor_text, section_key, paragraph_index, relevance, signals, reason, status, verified_at — UNIQUE (article_id, target_wp_post_id) |
| `schema_configs` | site_id, injection_enabled (mặc định false), enabled_types, organization_name, logo_url, breadcrumbs_supported, allow_canonical_override, allow_robots_override |

**Publishing**
| Bảng | Cột chính | Ràng buộc |
|---|---|---|
| `publishing_policies` | site_id, default_mode, auto_publish_enabled(+_by, _at), require_seo_pass, require_media_ready, allow_update_published, max_posts_per_day, max_posts_per_campaign_per_day, min_minutes_between_posts, window_start/end_hour, window_days, max_schedule_days_ahead, max_attempts, missed_grace_minutes | |
| `publishing_jobs` | site_id, article_id, campaign_id, publishing_batch_id, mapping_id, mode, status, **active_key**, article_version_id, **content_hash**, rendered_html, payload_meta, gate_results, scheduled_at, wp_target_status, attempts, max_attempts, next_attempt_at, last_error_*, blocked_reason, idempotency_key, approval_source, approved_by/at, published_at, **locked_until** | UNIQUE active_key (một job hoạt động / bài / site); UNIQUE (workspace_id, idempotency_key) |
| `publishing_attempts` | publishing_job_id, attempt_no, operation, outcome, error_code, http_status, retryable, payload_hash, wp_post_id, duration_ms, details | UNIQUE (publishing_job_id, attempt_no) |
| `wordpress_post_mappings` | site_id, article_id, external_content_id, **idempotency_key**, is_current, wp_post_id, wp_status, url, slug, site_host, last_published_version_id, last_payload_hash, **last_synced_hash**, **last_seo_hash**, last_modified_gmt, last_revision_id, last_published_at, last_checked_at | UNIQUE (site_id, external_content_id); UNIQUE (site_id, idempotency_key) |
| `wordpress_sync_states` | mapping_id, state (`in_sync`/`manual_edit`/`slug_changed`/`seo_modified`/`domain_changed`/`missing`/`unreachable`), wp_status, wp_slug, wp_content_hash, wp_seo_hash, wp_modified_gmt, wp_revision_id, details, checked_at | |
| `publishing_conflicts` | site_id, article_id, mapping_id, publishing_job_id, types, status (`open`/`resolved`), resolution, wp_state, expected_state, note, resolved_by/at | |
| `publishing_schedules` | site_id, publishing_job_id, scheduled_at (UTC), timezone, local_time, status (`pending`/`dispatched`/`done`/`missed`/`canceled`), dispatched_at, note | |
| `publishing_batches` | site_id, mode, status (`running`/`paused`/`completed`/`canceled`), start_at, interval_minutes, total, idempotency_key | UNIQUE (workspace_id, idempotency_key) |
| `publishing_limits` (nền tảng) | scope_key UNIQUE, max_publishes_per_day, max_bulk_items, max_schedule_days_ahead, auto_publish_allowed, publishing_enabled | |

Dữ liệu demo: `Phase6DemoSeeder` (gọi từ `DemoSeeder`, từ chối chạy ở production; cờ `is_demo`). Rollback: `php artisan migrate:rollback --step=3` (xóa dữ liệu Phase 6; file ảnh trong `storage/app/media` cần xóa tay).

### 3.13 Analytics & Agent ✦ Phase 8 (đã triển khai — thay thiết kế `gsc_connections` / `refresh_suggestions` dự kiến trước đây)

Kết nối Google **không** có bảng mới: dùng lại `search_console_connections` (Phase 4, token mã hóa). Mọi bảng dưới đây có `workspace_id` + trait `BelongsToWorkspace` (trừ `agent_policies` là hàng nền tảng), PK ULID, thời gian UTC, `is_demo` trên các bảng có dữ liệu mẫu. Migration: `app/Modules/Analytics/Database/Migrations/2026_11_13_000100_create_analytics_tables.php`, `app/Modules/Agent/Database/Migrations/2026_11_13_000200_create_agent_tables.php`.

**Analytics — thu thập**
| Bảng | Nội dung chính | Khóa / index |
|---|---|---|
| `analytics_settings` | Ngưỡng quy tắc, trọng số health, `backfill_days`, `raw_retention_days` theo workspace | UNIQUE(workspace_id) |
| `analytics_properties` | Property theo site + provider (`gsc`, `ga4` chưa dùng): `status`, `first_date`, `last_complete_date` (checkpoint), `last_sync_*`, `consecutive_failures` | UNIQUE(site_id, provider) |
| `analytics_sync_runs` | Lần đồng bộ: `kind` (incremental/backfill/manual), `status`, khoảng ngày, `checkpoint_date`, `progress`, `rows_upserted`, `api_requests`, `attempts`, `next_retry_at`, `idempotency_key` | (status, next_retry_at) |
| `search_performance_daily` | Tổng theo ngày × loại tìm kiếm × thiết bị × quốc gia (ISO-3) | UNIQUE(site_id, date, search_type, device, country) |
| `search_query_metrics` | Theo ngày × truy vấn (`query_hash` = sha1 chuẩn hóa) | UNIQUE(site_id, date, search_type, query_hash) |
| `search_page_metrics` | Theo ngày × URL chuẩn hóa (`page_hash`), `article_id` theo mapping | UNIQUE(site_id, date, search_type, page_hash) |
| `search_query_page_metrics` | Tổng cửa sổ truy vấn × trang (thay theo cửa sổ) | UNIQUE(site_id, period_end, search_type, query_hash, page_hash) |
| `analytics_url_mappings` | URL ↔ bài: `source` (publisher/publisher_history/canonical/inventory/manual), `wp_post_id`, `is_current`; URL tranh chấp → `article_id` null | UNIQUE(site_id, page_hash) |

**Analytics — phân tích & hành động**
| Bảng | Nội dung chính |
|---|---|
| `content_performance_snapshots` | Mỗi trang × `period_end` × `window_days`: clicks/impressions/vị trí kỳ này, kỳ trước, baseline, cùng kỳ năm trước, `classification`, `labels`, `reasons` |
| `seo_opportunities` | `rule_code`, `rule_version`, `fingerprint` (UNIQUE theo site), đối tượng, `evidence`, `detection_rule`, `severity`, `impact` + `impact_basis`, `confidence` + giải thích, `recommended_action`, chi phí ước tính, `status`, người duyệt, `detections` |
| `content_decay_events` | `status` (4 trạng thái), `open_key` (UNIQUE: một sự kiện mở mỗi trang), `decline_pct`, recent/previous/baseline/seasonal, thay đổi truy vấn/URL/nội dung, `signals`, quyết định |
| `seo_recommendations` | `type`, đối tượng, `evidence`, `rationale`, `priority`, `benefit_hypothesis`, chi phí, `risk`, `required_approval`, `status`, `suggested_value`, `source` (rule/ai/agent), `agent_run_id` |
| `seo_experiments` / `experiment_observations` | Theo dõi trước/sau (`method` = `before_after`), baseline/quan sát, `outcome`, `external_factors`; quan sát theo ngày |
| `content_refresh_jobs` | Quy trình làm mới: `status` (16 trạng thái), `active_key` (UNIQUE: một job mở mỗi bài), `wp_check`, `base_version_id`, brief, `revision_ids`, `new_version_id`, `diff`, `fact_check`, `seo_audit`, `publishing_job_id`, `experiment_id`, người yêu cầu/duyệt, `idempotency_key` |
| `seo_health_scores` | Điểm theo site × ngày (null khi không có thành phần), `components`, `weights` |
| `seo_reports` | `period_type`, khoảng ngày, `status`, `payload`, `emailed_at`, `recipients_count` |
| `copilot_queries` | Câu hỏi, `intent`, `answer` (facts/inferences/recommendations), `data_refs`, `mode`, model, chi phí |

**Agent**
| Bảng | Nội dung chính |
|---|---|
| `agent_policies` | Một hàng nền tảng: `kill_switch` (+ lý do, người bật), `max_autonomy_level`, `forbidden_tools`, giới hạn run/task, timeout |
| `agent_configs` | Theo site (UNIQUE): `enabled`, `autonomy_level` (mặc định 1), `emergency_stop`, allow/forbid, giới hạn ngày/tháng/ngân sách, khung giờ đăng, `timezone`, `approval_requirements`, `principal_user_id`, `level3_enabled_by/at`, `lock_version` |
| `automation_schedules` | `kind` (UNIQUE theo site), `frequency`, `day_of_week` (ISO), `day_of_month` (1–28), `local_time`, `timezone`, `status`, `next_run_at`, `last_*`, `consecutive_failures` |
| `agent_runs` | `trigger`, `kind`, `status`, `phase`, `active_key` (UNIQUE: một run hoạt động mỗi site), `autonomy_level`, `policy_snapshot`, chi phí ước tính/thực, observations/plan/summary, `deadline_at`, `idempotency_key` |
| `agent_tasks` | `sequence`, `tool`, `status`, `decision`, `decision_reasons`, `input`, `policy_snapshot`, chi phí, `output`, `evidence`, `attempts`/`max_attempts`, `next_attempt_at`, `timeout_at`, người duyệt, `idempotency_key` (UNIQUE) |
| `agent_action_logs` | Chỉ ghi thêm: `event`, `level`, `message`, `context`, `actor_type` (agent/user/system/admin) |
| `agent_memories` | `kind`, `content`, `evidence`, `verified`, `confidence`, `expires_at` |

Lưu trữ: dữ liệu thô GSC giữ `raw_retention_days` (mặc định 490), bộ nhớ Agent 180 ngày, nhật ký 365 ngày (`sitex:analytics:prune`).

### 3.14 AiGateway (Phase 3 — đã triển khai)

Tiền: số nguyên **micro-USD** (1 USD = 1.000.000); giá theo **micro-USD / 1 triệu token** (ADR-014). Migration: `backend/app/Modules/AiGateway/Database/Migrations/2026_11_07_000100_create_ai_gateway_tables.php`.

**ai_providers** (toàn hệ thống): id, code UNIQUE (`openai`, `gemini`, `anthropic`, `deepseek`, `fake`, hoặc mã provider tùy chỉnh), driver (adapter: `openai`, `gemini`, `anthropic`, `deepseek`, `openai_compatible`, `fake`), name, base_url NULL (chỉ provider tùy chỉnh), api_key_encrypted TEXT NULL (cast `encrypted`, ẩn khi serialize), api_key_last4, is_custom, is_enabled, priority, timeout_seconds, max_retries, circuit_failure_threshold, circuit_cooldown_seconds, settings JSON (`response_format`: `json_schema`/`json_object`/`none` cho provider tùy chỉnh). Migration `2026_11_08_000100` (Phase 3E).

**ai_models**: id, provider_id, model_key UNIQUE (`provider/model`), model_name, display_name, kind (`text`, `image`), tier, capabilities JSON, is_enabled, notes; UNIQUE(provider_id, model_name). Model mới tắt cho tới khi có giá.

**ai_model_prices** (bất biến khi đã hiệu lực): id, model_id, version, effective_from, currency (`USD`), input_per_mtok, cached_input_per_mtok, cache_write_per_mtok, output_per_mtok, long_context_threshold + long_* (giá ngữ cảnh dài), offpeak_input_per_mtok / offpeak_cached_input_per_mtok / offpeak_output_per_mtok NULL + peak_windows JSON NULL (`[{days:[1..7 ISO], from:"HH:MM", to:"HH:MM"}]`, UTC; ngoài các khung này dùng giá thấp điểm; Phase 3E), per_image, source_url, notes, created_by; UNIQUE(model_id, version). Chỉ xóa được version chưa hiệu lực.

**ai_task_routes**: id, task_key UNIQUE, description, queue, priority, primary_model_id, economy_model_id null, fallback_model_ids JSON, max_input_tokens, max_output_tokens, temperature, timeout_seconds, max_attempts, allow_paid_retry, is_enabled.

**ai_requests** (tenant, `BelongsToWorkspace`; mỗi lần thử một dòng): id (BIGINT), ulid, workspace_id, task_key, provider, model, model_id, price_id, workflow_run_id, call_key, attempt, is_fallback, status, error_code, error_message (đã lọc secret), http_status, billing_uncertain, input/cached_input/cache_write/output/reasoning_tokens, image_count, search_count, cost_micro_usd, estimated_cost_micro_usd, latency_ms, prompt_hash (SHA-256, **không lưu prompt**), provider_request_id, finish_reason, created_at. Xóa sau `SITEX_AI_REQUEST_LOG_RETENTION_DAYS` (mặc định 90) (`sitex:ai:prune`).

**ai_budgets**: id, scope (`global`, `workspace`), workspace_id null, scope_key, period (`day`, `month`), limit_micro_usd, alert_threshold_pct, action_on_exceed (`alert`, `pause_batch`, `pause_all`), is_enabled, updated_by; UNIQUE(scope_key, period).

**ai_budget_counters**: scope_key, period, period_start, reserved_micro_usd, spent_micro_usd, alerted_at; UNIQUE(scope_key, period, period_start). Cập nhật trong transaction với `SELECT … FOR UPDATE` khớp chính xác khóa duy nhất.

**ai_budget_reservations**: id, idempotency_key UNIQUE (= `call_key`), workspace_id, status (`reserved`, `committed`, `released`, `uncertain`, `expired`), amount_micro_usd, actual_micro_usd, counter_ids JSON, expires_at, settled_at.

> `prompt_templates` (template có version) **chưa** làm ở Phase 3: tác vụ thử dùng system prompt cố định trong code. Sẽ bổ sung ở Phase 5–6 khi có pipeline nội dung.

### 3.14b Workflows (Phase 3 — đã triển khai)

Migration: `backend/app/Modules/Workflows/Database/Migrations/2026_11_07_000200_create_workflow_tables.php`.

**workflow_runs** (tenant): id, workspace_id, type, queue, status (`pending`, `running`, `retry_scheduled`, `succeeded`, `failed`, `canceled`), idempotency_key, request_hash, input JSON, output JSON, current_step, attempts (trong cycle hiện tại), max_attempts, cycle (+1 mỗi lần retry thủ công), version (compare-and-set), next_attempt_at, dispatched_at, locked_by, locked_until (lease), cancel_requested_at, error_code, error_message, job_run_id, created_by, started_at, finished_at; UNIQUE(workspace_id, idempotency_key); index (workspace_id, status, created_at), (status, locked_until), (status, next_attempt_at).

**workflow_steps** (tenant): id, workspace_id, workflow_run_id, key, sequence, status, attempts, output JSON (checkpoint), error_code, error_message, started_at, finished_at; UNIQUE(workflow_run_id, key).

**workflow_events** (tenant, append-only): id, workspace_id, workflow_run_id, event, from_status, to_status, details JSON, created_at.

**dead_letters** (toàn hệ thống, chỉ admin): id, workspace_id null, source (`workflow`, `job`), workflow_run_id null, job_class, queue, failed_job_uuid, error_code, error_message, attempts, status (`open`, `retried`, `discarded`), resolved_by, resolved_at.

### Phạm vi migration Phase 3
- Hai migration mới ở trên, chỉ **tạo bảng mới**; không sửa bảng Phase 1–2. Rollback: `php artisan migrate:rollback --step=2` (xóa toàn bộ dữ liệu AI/workflow).
- Seed danh mục provider/model/giá/route mặc định bằng `php artisan sitex:ai:sync-registry` (chỉ tạo nếu chưa có, không ghi đè thay đổi của admin).

### 3.15 Hạ tầng dùng chung

**job_runs**: id, workspace_id null, type, subject_type, subject_id, status (`queued`, `running`, `succeeded`, `failed`, `canceled`), progress (0–100), step, error_code, error_message (an toàn để hiển thị), started_at, finished_at, created_by. Dùng cho UI theo dõi tiến độ.

**notifications** (Laravel), **jobs/failed_jobs/job_batches** (Laravel), **cache/locks** (nếu không dùng Redis).

**idempotency_keys** *(chưa tạo — Phase 3 dùng UNIQUE(workspace_id, idempotency_key) ngay trên `workflow_runs`; bảng chung sẽ xét lại ở Phase 4 cho thanh toán)*: id, workspace_id, user_id, key, request_hash, response_status, response_body, expires_at, UNIQUE(workspace_id, user_id, key).

## 4. Index & hiệu năng

- Mọi bảng tenant: index ghép bắt đầu bằng `workspace_id` cho các truy vấn danh sách (`(workspace_id, status, created_at)`).
- Danh sách lớn dùng **cursor pagination** theo `(created_at, id)`.
- `search_performance_daily` phân vùng theo tháng (RANGE trên `date`) khi vượt ~50 triệu dòng.
- `audit_logs`, `ai_requests`, `usage_events`: chính sách lưu giữ + archive định kỳ.

## 5. Phạm vi migration của Phase 1

Chỉ tạo: `users` (+ cột platform_role, 2FA, locale, timezone, status), `sessions`, `password_reset_tokens`, `workspaces`, `workspace_members`, `workspace_invitations`, `audit_logs`, `impersonation_sessions`, `system_settings`, `job_runs`, bảng hệ thống của Laravel (`jobs`, `failed_jobs`, `job_batches`, `cache`, `notifications`). Các bảng khác thuộc phase sau (xem ROADMAP.md).

## 6. Phạm vi migration của Phase 2

Một migration của module Sites tạo 9 bảng ở §3.6. Không sửa bảng của Phase 1. Đã chạy được trên MySQL 8.0 (tên index ≤ 64 ký tự) và SQLite (test).

## Phase 9 — System

| Bảng | Mục đích |
|---|---|
| `system_settings` (+ cột `is_secret`) | Cấu hình toàn cục do Super Admin quản lý. `is_secret=1` (mật khẩu SMTP, Google client secret, Telegram token) → giá trị mã hóa bằng `Crypt`/APP_KEY, API chỉ trả `<field>_configured`. Khóa dùng trong Phase 9: `general.*`, `mail.*`, `google.*`, `alerts.*`, `backup.*`, `system.version`, `system.installed_at`, `system.heartbeat_at`, `system.ssl`, `monitor.state`, `setup.*` |
| `system_backups` | Mỗi lần sao lưu: `status` (running/succeeded/failed), `trigger` (schedule/manual/pre_upgrade), file, kích thước, SHA-256, `offsite_status`, `verify_status`, lỗi (đã che secret) |
| `system_alerts` | Cảnh báo đã gửi: `check_key`, `severity` (warning/critical/recovered), tóm tắt, kết quả từng kênh; dùng cho cooldown |
| `system_upgrade_runs` | Mỗi lần nâng cấp từ web: phiên bản từ/đến, trạng thái, migration đã chạy, các bước, lỗi |

Installer không có bảng riêng: trạng thái nằm ở `storage/app/installer/` (`installed.json` 0600, cấu hình tạm mã hóa bằng `pending.key` 0600, nhật ký đã che secret) và ở `system.installed_at`.
