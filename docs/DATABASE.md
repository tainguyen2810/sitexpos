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

### 3.4 Billing

**plans**: id, code UNIQUE (`starter`, `pro`, `agency`...), name (JSON đa ngôn ngữ), is_public, is_active, sort_order, trial_days.

**plan_prices**: id, plan_id, interval (`month`/`year`), currency, amount_minor, gateway_price_ref (null), is_active.

**plan_limits**: id, plan_id, limit_key, limit_value (int, -1 = không giới hạn), period (`month`, `lifetime`). Limit keys: `sites`, `seats`, `articles`, `images_ai`, `images_stock`, `research_runs`, `keyword_lookups`, `refresh_suggestions`.

**subscriptions**
| Cột | Ghi chú |
|---|---|
| id, workspace_id | một subscription `active`/`trialing` tại một thời điểm |
| plan_id, plan_price_id | |
| status | `trialing`, `active`, `past_due`, `canceled`, `expired` |
| gateway, gateway_subscription_ref | |
| current_period_start, current_period_end | chu kỳ quota |
| cancel_at_period_end, canceled_at | |

**invoices**: id, workspace_id, subscription_id null, number UNIQUE, status (`draft`,`open`,`paid`,`void`,`refunded`), currency, subtotal_minor, tax_minor, total_minor, gateway, gateway_invoice_ref, issued_at, paid_at, line_items JSON, billing_snapshot JSON.

**payments**: id, workspace_id, invoice_id, gateway, gateway_payment_ref UNIQUE(gateway, ref), amount_minor, currency, status, raw_payload_hash.

**webhook_events**: id, provider, event_id, UNIQUE(provider, event_id), signature_valid, received_at, processed_at, status, payload JSON (đã che dữ liệu nhạy cảm) — chống xử lý trùng/replay.

**credit_packs**: id, code, credits, price_minor, currency, is_active.

### 3.5 Usage

**usage_events** (BIGINT PK, append-only)
| Cột | Ghi chú |
|---|---|
| workspace_id, site_id null | |
| metric | trùng limit_key |
| quantity | int (âm khi hoàn) |
| state | `reserved`, `committed`, `released` |
| reservation_id | ULID, nhóm reserve→commit/release |
| source_type, source_id | ví dụ `article`, id |
| period_start | chu kỳ áp dụng |
| idempotency_key | UNIQUE(workspace_id, idempotency_key) |
| created_at | |

**usage_counters**: (workspace_id, metric, period_start) UNIQUE, reserved, committed — bảng tổng hợp cập nhật trong transaction với `SELECT ... FOR UPDATE` để kiểm tra quota không race.

**credit_ledger** (append-only): id, workspace_id, delta (int), balance_after, reason (`purchase`, `grant`, `consume`, `refund`, `expire`), reference_type, reference_id, expires_at null, created_by.

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

### 3.7 BusinessProfile

**business_profiles**: id, workspace_id, site_id null (null = dùng chung workspace), industry, description, target_markets JSON (quốc gia/khu vực/ngôn ngữ), audiences JSON, tone_of_voice, brand_terms JSON, banned_terms JSON, competitors JSON (domain do khách khai báo), compliance_notes.

**offerings**: id, workspace_id, business_profile_id, type (`product`,`service`), name, description, url, attributes JSON, has_real_photos (bool).

### 3.8 Research

**research_runs**: id, workspace_id, site_id, type (`industry`, `competitor`, `serp`, `article_sources`), status, input JSON, summary JSON, job_run_id, started_at, finished_at.

**research_sources**
| Cột | Ghi chú |
|---|---|
| id, workspace_id, research_run_id | |
| url, canonical_url, domain | |
| title, author, published_at | trích từ trang, null nếu không có |
| fetched_at, http_status, content_hash | bằng chứng đã tải thật |
| content_text_path | file văn bản trích xuất trên storage (không nhét vào DB) |
| source_kind | `web`, `serp`, `customer_provided`, `gsc` |
| trust_tier | `high`, `medium`, `low`, `blocked` |
| provider | adapter đã trả kết quả |

**competitors**: id, workspace_id, site_id, domain, source (`customer`, `serp_discovered`), notes, last_analyzed_at.

### 3.9 Keywords & Planning

**keywords**: id, workspace_id, site_id, keyword, normalized UNIQUE(site_id, normalized), language, country, intent (null nếu chưa phân loại), status (`suggested`,`accepted`,`rejected`,`used`), origin (`ai_suggested`, `serp`, `gsc`, `customer`).

**keyword_metrics** — chỉ ghi khi có dữ liệu thật
| Cột | Ghi chú |
|---|---|
| id, workspace_id, keyword_id | |
| provider | `dataforseo`, `gsc`... **bắt buộc** |
| search_volume, cpc_minor, competition, difficulty | null nếu provider không trả |
| location_code, language_code | |
| fetched_at | |

AI chỉ được đề xuất từ khóa (`origin=ai_suggested`) — **không** có cột số liệu do AI sinh.

**title_ideas**: id, workspace_id, site_id, keyword_id, title, angle, status.

**topic_clusters**: id, workspace_id, site_id, name, pillar_keyword_id, description, status.

**topic_cluster_items**: id, workspace_id, topic_cluster_id, keyword_id, role (`pillar`, `cluster`), title_idea_id null.

**calendar_items**: id, workspace_id, site_id, topic_cluster_id null, title_idea_id null, article_id null, planned_date, status (`planned`,`in_progress`,`done`,`skipped`).

### 3.10 Content & Verification

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

### 3.11 Media

**media_assets**
| Cột | Ghi chú |
|---|---|
| id, workspace_id | |
| kind | `stock_photo`, `customer_upload`, `ai_image`, `infographic` |
| is_ai_generated | bool, bắt buộc true với `ai_image`/`infographic` do AI |
| represents_real_product | bool — **không bao giờ** true với ảnh AI (ràng buộc trong Action + CHECK) |
| storage_disk, path, mime, width, height, bytes, sha256 | |
| source_provider, source_url, license, attribution | với ảnh stock |
| alt_text, caption | |
| moderation_status | `pending`, `approved`, `rejected` |

**article_media**: id, workspace_id, article_id, media_asset_id, role (`featured`, `inline`), position, wp_media_id null.

### 3.12 Seo & Publishing

**internal_link_suggestions**: id, workspace_id, article_id, target_post_index_id, anchor_text, status (`suggested`,`applied`,`rejected`).

**publish_jobs**: id, workspace_id, site_id, article_id, content_version_id, action (`create`, `update`, `unpublish`), wp_status (`draft`, `publish`, `future`), run_at, status (`queued`, `running`, `succeeded`, `failed`, `canceled`), attempts, last_error, wp_post_id, idempotency_key UNIQUE.

**approval_requests**: id, workspace_id, article_id, content_version_id, requested_by, decided_by, decision (`approved`, `changes_requested`, `rejected`), comment, decided_at.

### 3.13 Analytics

**gsc_connections**: id, workspace_id, site_id, google_account_email, property_uri, refresh_token_encrypted, scopes, status, connected_by, last_synced_at, revoked_at.

**search_performance_daily**: workspace_id, site_id, date, page_url_hash, page_url, query_hash null, query null, clicks, impressions, ctr, position, PK(site_id, date, page_url_hash, query_hash). Dữ liệu thật từ GSC.

**refresh_suggestions**: id, workspace_id, site_id, article_id null, wp_post_id, reason (`declining_clicks`, `outdated_facts`, `low_ctr`...), evidence JSON (số liệu GSC dẫn chứng), status (`open`, `accepted`, `dismissed`, `done`).

### 3.14 AiGateway

**ai_providers**: id, code (`openai`, `gemini`, `anthropic`), is_enabled, priority. (API key ở `.env`, không ở DB.)

**ai_models**: id, provider_id, model_name, capabilities JSON, input_price_micro_usd_per_mtok, output_price_micro_usd_per_mtok, image_price_micro_usd, is_enabled, effective_from.

**ai_task_routes**: id, task_key UNIQUE, primary_model_id, fallback_model_id null, max_input_tokens, max_output_tokens, temperature, prompt_template_id.

**prompt_templates**: id, key, version, UNIQUE(key, version), system_prompt, user_template, output_schema JSON, is_active, created_by.

**ai_requests** (BIGINT PK): workspace_id null, task_key, provider, model, job_run_id, input_tokens, output_tokens, cost_micro_usd, latency_ms, status, error_code, prompt_hash, created_at. Nội dung prompt/response đầy đủ chỉ lưu trên storage có thời hạn (mặc định 30 ngày) để debug.

**ai_budgets**: id, scope (`global`, `workspace`), workspace_id null, period (`day`, `month`), limit_micro_usd, alert_threshold_pct, action_on_exceed (`alert`, `pause_batch`, `pause_all`).

### 3.15 Hạ tầng dùng chung

**job_runs**: id, workspace_id null, type, subject_type, subject_id, status (`queued`, `running`, `succeeded`, `failed`, `canceled`), progress (0–100), step, error_code, error_message (an toàn để hiển thị), started_at, finished_at, created_by. Dùng cho UI theo dõi tiến độ.

**notifications** (Laravel), **jobs/failed_jobs/job_batches** (Laravel), **cache/locks** (nếu không dùng Redis).

**idempotency_keys** *(hoãn sang Phase 3 — Phase 1 chưa có endpoint tiêu tốn quota/credit)*: id, workspace_id, user_id, key, request_hash, response_status, response_body, expires_at, UNIQUE(workspace_id, user_id, key).

## 4. Index & hiệu năng

- Mọi bảng tenant: index ghép bắt đầu bằng `workspace_id` cho các truy vấn danh sách (`(workspace_id, status, created_at)`).
- Danh sách lớn dùng **cursor pagination** theo `(created_at, id)`.
- `search_performance_daily` phân vùng theo tháng (RANGE trên `date`) khi vượt ~50 triệu dòng.
- `audit_logs`, `ai_requests`, `usage_events`: chính sách lưu giữ + archive định kỳ.

## 5. Phạm vi migration của Phase 1

Chỉ tạo: `users` (+ cột platform_role, 2FA, locale, timezone, status), `sessions`, `password_reset_tokens`, `workspaces`, `workspace_members`, `workspace_invitations`, `audit_logs`, `impersonation_sessions`, `system_settings`, `job_runs`, bảng hệ thống của Laravel (`jobs`, `failed_jobs`, `job_batches`, `cache`, `notifications`). Các bảng khác thuộc phase sau (xem ROADMAP.md).

## 6. Phạm vi migration của Phase 2

Một migration của module Sites tạo 9 bảng ở §3.6. Không sửa bảng của Phase 1. Đã chạy được trên MySQL 8.0 (tên index ≤ 64 ký tự) và SQLite (test).
