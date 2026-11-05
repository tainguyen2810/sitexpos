# Báo cáo Phase 3 — AI Gateway & Queue (05/11/2026)

Phạm vi: module **AiGateway** (adapter OpenAI/Gemini/Claude, registry, giá có version, routing, ngân sách, circuit breaker), module **Workflows** (state machine trên DB, lease, khôi phục, dead letter), cấu hình Redis/Horizon/Supervisor, API tenant + admin, giao diện, test và tài liệu.

- Nhánh: `feature/phase3-ai-gateway`, tách từ `feature/phase2-wp-connector` tại commit `d15d731` (Phase 2 vẫn đang chờ nghiệm thu nên Phase 3 nằm chồng lên Phase 2).
- **Không** merge nhánh nào, **không** đụng `feature/demo-ui`, **không** deploy production, **không** bắt đầu Phase 4.
- Thứ tự phase đã đổi theo yêu cầu (ADR-012): Phase 3 = AI Gateway & Queue, gói/quota/thanh toán chuyển sang **Phase 4**. `ROADMAP.md` đã cập nhật.

## 1. Kết quả kiểm tra (đã chạy thật)

| Lệnh | Kết quả |
|---|---|
| `scripts/check.sh` | **All checks passed** (exit 0) |
| ├ `vendor/bin/pint --test` | passed |
| ├ `vendor/bin/phpstan analyse` (Larastan level 6) | `[OK] No errors` |
| ├ `vendor/bin/pest` (SQLite) | **232 passed, 1 skipped** (1109 assertions). Phase 2 là 149. Test bị skip là race test đa tiến trình, chỉ chạy trên MySQL |
| ├ `npm run lint` / `typecheck` | 0 lỗi |
| ├ `npm run test` (Vitest) | **31 passed** (5 file). Phase 2 là 22 |
| ├ `npm run build` | build `dist/customer` và `dist/admin` thành công |
| └ `wordpress-plugin/scripts/build-plugin.sh` | thành công (plugin không đổi ở Phase 3) |
| `pest` với `DB_CONNECTION=mysql` (MySQL 8.0) | **233 passed**, gồm race test ngân sách |
| `composer audit` / `npm audit --omit=dev` | không có cảnh báo / 0 lỗ hổng |
| **E2E restart worker** `scripts/e2e/phase3-worker-restart.sh` (MySQL + Redis + `queue:work` thật) | **PASSED** (xem §3) |
| **Smoke test giao diện** `scripts/e2e/ai.js` (Playwright + Vite + Laravel + worker thật) | **PASSED** (xem §3) |

## 2. Đã làm gì

### AI Gateway (`backend/app/Modules/AiGateway`)
- **Adapter** gọi bằng HTTP thuần (ADR-011, vì SDK tự retry sẽ phá chính sách không retry lời gọi tốn phí):
  - OpenAI Responses API `POST /v1/responses` (structured output `text.format` json_schema, công cụ `web_search`), ảnh `POST /v1/images/generations`.
  - Gemini `models/{model}:generateContent` (header `x-goog-api-key`, `responseJsonSchema`, `google_search`).
  - Claude Messages API `POST /v1/messages` (`x-api-key`, `anthropic-version: 2026-10-21` (đổi được qua `ANTHROPIC_API_VERSION`), `output_config.format` json_schema, công cụ `web_search_20260209`).
  - Định dạng request/response đã đối chiếu tài liệu chính thức ngày 05/11/2026 và được kiểm bằng `Http::fake` (`tests/Unit/AiGateway/ProviderAdaptersTest.php`).
  - `FakeProvider` để chạy offline cho dev/test/E2E.
- **Phân loại lỗi**: 429/5xx/không kết nối được = an toàn để retry; `response_timeout` = **không chắc đã bị tính phí**; 4xx khác, `refused` = dừng ngay.
- **Registry + capabilities**: `ai_providers`, `ai_models` (structured_output, web_search, vision, image_output, reasoning, context_window, max_output_tokens). `sitex:ai:sync-registry` (và `db:seed`) tạo danh mục mặc định, chỉ chèn thêm, không ghi đè chỉnh sửa của admin.
- **Bảng giá có version** (`ai_model_prices`): micro-USD/1M token, giá cache, giá ngữ cảnh dài, giá ảnh, giá tìm kiếm; version đã hiệu lực thì không sửa/xóa được, mỗi `ai_requests` lưu `price_id` đã áp dụng (ADR-014).
- **Routing theo task** (`ai_task_routes`): model chính, model tiết kiệm, chuỗi fallback, queue, timeout, `max_attempts`, `allow_paid_retry`. Bỏ qua model bị tắt, thiếu capability, chưa có giá, circuit đang mở.
- **Đo usage**: input/cached/cache-write/output/reasoning token, số ảnh, số lượt tìm kiếm, chi phí, độ trễ. Mỗi lần thử một dòng `ai_requests`.
- **Timeout, retry, circuit breaker, fallback**: retry trong provider chỉ với lỗi an toàn (backoff có trần), circuit breaker theo provider+model, deadline toàn lời gọi 240 s (< timeout job 300 s).
- **Log không lộ secret**: không lưu prompt/output gốc, chỉ `prompt_hash`; thông báo lỗi qua `SecretRedactor`. Khóa API chỉ ở `.env`, API admin chỉ trả `has_api_key`.
- **Ngân sách**: theo workspace và toàn hệ thống, theo ngày/tháng; giữ chỗ nguyên tử → commit/release/uncertain/expired; cảnh báo ngưỡng; hành động `alert`/`pause_batch`/`pause_all`.
- **Chống gọi trùng**: reservation khóa theo `call_key`; nếu job bị chạy lại và reservation đã tồn tại → `duplicate_call`, **không** gọi provider lần hai.
- **Super Admin**: quản lý provider, model, version giá, route, ngân sách; reset circuit; xem usage, log request.

### Queue
- Redis + Horizon: queue `research`, `writing`, `factcheck`, `media`, `publishing`, `analytics`, `billing`, `notifications` (cùng `critical`, `default`, `connector` có sẵn), 6 supervisor Horizon.
- Supervisor mẫu: `deploy/supervisor/sitexpost-horizon.conf` và phương án không Horizon `deploy/supervisor/sitexpost-queue-work.conf`.
- Bất biến thời gian: timeout job 300 s < `retry_after` 330 s < lease 360 s (có test `QueueConfigTest`).

### Workflow engine (`backend/app/Modules/Workflows`, ADR-013)
- State machine lưu DB (`workflow_runs`, `workflow_steps`, `workflow_events`), compare-and-set theo `version`, checkpoint từng bước.
- Idempotency key (UNIQUE theo workspace + hash đầu vào; cùng key khác nội dung → 409).
- Exponential backoff có jitter; giới hạn đồng thời theo workspace (mặc định 2).
- Hủy (dừng ở ranh giới bước); khôi phục sau restart bằng lease + `sitex:workflows:recover` mỗi phút.
- Failed jobs → dead letter; admin retry (mở cycle mới) hoặc discard; có audit.
- Bước tốn phí bị gián đoạn → `failed` với `interrupted_paid_call`, không tự gọi lại, trừ khi route bật `allow_paid_retry`.

### API
- Tenant: `GET/POST /workspaces/{ws}/ai/tasks` (bắt buộc `Idempotency-Key`, trả 202 ngay), `GET …/ai/tasks/{run}`, `POST …/cancel`, `POST …/retry`, `GET …/ai/usage`. Rate limit `ai-tasks` 20/phút theo user + workspace.
- Admin: `/api/admin/v1/ai/*` (usage, requests, providers, models, prices, routes, budgets) và `/api/admin/v1/jobs/*` (overview, workflows, dead letters).
- Quyền mới: `platform.ai.manage` (SUPER_ADMIN), `platform.ai.view_costs` (SUPER_ADMIN, BILLING_ADMIN), `platform.jobs.manage` (SUPER_ADMIN, SUPPORT).

### Giao diện
- Khách hàng: trang **Tác vụ AI** (`/w/:id/ai`): gửi tác vụ thử (tùy chọn JSON có cấu trúc, tìm kiếm web), thẻ sử dụng và ngân sách, danh sách có lọc trạng thái, chi tiết tự cập nhật 3 s với kết quả/token/chi phí/lỗi dễ hiểu, hủy và thử lại.
- Admin: **Chi phí AI** (`/ai/usage`: tổng, biểu đồ theo ngày, theo provider/model/task/workspace, lỗi, log request), **Cấu hình AI** (`/ai/settings`: provider, model và version giá, route, ngân sách), **Theo dõi job** (`/jobs`: tổng quan, queue, workflow, dead letter, tự làm mới 10 s).
- Tiếng Việt mặc định, có bản tiếng Anh; kiểm tra trên mobile.

## 3. Nghiệm thu E2E

**`scripts/e2e/phase3-worker-restart.sh`** (MySQL và Redis thật, worker `queue:work` thật, lease 60 s để chạy nhanh):
- Kịch bản A: `kill -9` worker **giữa lúc đang gọi provider** (bước tốn phí). Sau khi recovery chạy: run chuyển `failed` với `interrupted_paid_call`, provider **không** bị gọi lần hai, có dead letter; một run khác đang xếp hàng vẫn hoàn thành bình thường khi worker mới chạy; reservation ngân sách bị treo được giải phóng (`expired`).
- Kịch bản B: cùng tình huống nhưng route bật `allow_paid_retry` → run được tiếp tục và `succeeded`.

**`scripts/e2e/ai.js`** (Playwright):
- Khách hàng gửi tác vụ: API trả 202 sau khoảng 230 ms (không chờ AI); worker xử lý; trang hiển thị kết quả, token và chi phí.
- Admin bật 2FA, xem Chi phí AI, Cấu hình AI, Theo dõi job; giao diện mobile.

**Race test ngân sách** (`tests/Feature/AiGateway/BudgetTest.php`, MySQL): 8 tiến trình `pcntl_fork` cùng giữ chỗ 3.000 trên ngân sách 10.000 → **đúng 3** thành công, không vượt, không trừ hai lần.

## 4. Lỗi tìm thấy và đã sửa trong quá trình làm
1. **Deadlock MySQL** khi nhiều tiến trình cùng giữ chỗ ngân sách (gap lock khi tạo counter trong transaction). Sửa: tạo counter ngoài transaction (`insertOrIgnore`), khóa hàng bằng `FOR UPDATE` khớp chính xác khóa duy nhất, bỏ khóa khi tra reservation đã có, transaction retry 5 lần.
2. **`SecretRedactor` để lộ token Bearer**: quy tắc `key=value` chung "ăn" chữ `Bearer` trước khi quy tắc Bearer chạy. Đổi thứ tự, có unit test.
3. **Circuit breaker dùng `time()`** nên không theo thời gian giả lập trong test và lệch với `now()`. Đổi sang `now()`.
4. **Job chạy lại có thể gọi provider hai lần** cho cùng một `call_key`. Thêm chặn `duplicate_call` dựa trên reservation.
5. **Deadline lời gọi** có thể vượt timeout job khi fallback qua nhiều model. Thêm deadline 240 s và lỗi `deadline_exceeded` (retry được, chưa gọi provider).
6. Giới hạn timeout route/provider tối đa 180 s để không vượt deadline.

## 5. Sai khác so với thiết kế Phase 0

| Chủ đề | Thiết kế Phase 0 | Phase 3 làm | Ghi chú |
|---|---|---|---|
| Thứ tự phase | Phase 3 thanh toán, Phase 4 AI | Phase 3 AI Gateway & Queue, Phase 4 thanh toán | ADR-012, theo yêu cầu |
| Giá model | cột giá trên `ai_models` | bảng `ai_model_prices` có version | ADR-014 |
| Route | 1 model chính + 1 fallback | chính + tiết kiệm + chuỗi fallback, queue, `allow_paid_retry` | DATABASE §3.14 |
| Queue AI | `ai-interactive`, `ai-batch` | `writing`, `research`, `factcheck` | Theo yêu cầu Phase 3; ARCHITECTURE §10 |
| Idempotency | bảng `idempotency_keys` chung | UNIQUE ngay trên `workflow_runs` | Bảng chung xét lại ở Phase 4 |
| Prompt template | `prompt_templates` có version | **chưa làm** | Chưa có pipeline nội dung; để Phase 5–6 |
| Lưu prompt/response | lưu tạm 30 ngày để debug | không lưu, chỉ hash | An toàn hơn; debug dựa trên mã lỗi |

## 6. Hạn chế và vấn đề còn tồn tại (trung thực)
1. **Chưa gọi API thật của OpenAI, Gemini hay Claude** vì chưa có khóa. Mọi kiểm thử dùng `FakeProvider` và `Http::fake` dựng theo tài liệu. Cần khóa thật (đặt trong `.env`) để chạy thử trực tiếp trước khi bật cho khách.
2. Chưa xác minh bằng khóa thật: tên trường usage của OpenAI trong mọi trường hợp, giá ảnh OpenAI theo kích thước/chất lượng (model ảnh **tắt** mặc định), Gemini `google_search` (capability tìm kiếm của Gemini **tắt** mặc định).
3. Gemini `generateContent` được Google ghi là "legacy but supported"; chưa chuyển sang Interactions API mới.
4. Giá mặc định đã đối chiếu trang giá chính thức ngày 05/11/2026 (gồm hai thay đổi giá Gemini đã được Google công bố, nhập sẵn dưới dạng version tương lai). Super Admin nên kiểm tra lại trước khi bật provider thật.
5. Race test đa tiến trình chỉ chạy trên MySQL (SQLite bỏ qua).
6. Biến `HORIZON_ROLE` để tách server worker AI **chưa** làm; tạm thời dùng file `queue:work` mẫu (DEPLOYMENT §8).
7. E2E restart worker chạy bằng `queue:work` trên Redis, **chưa** chạy dưới Horizon + Supervisor thật (môi trường này không có Supervisor). Logic khôi phục dựa vào DB nên không phụ thuộc cách chạy worker.
8. Menu admin luôn tô sáng "Tổng quan" trên mọi trang. Lỗi có sẵn từ Phase 1 (`ShellLayout`), chưa sửa vì ngoài phạm vi.
9. Chưa có prompt template có version (xem §5).
10. Cảnh báo ngưỡng ngân sách hiện chỉ ghi `alerted_at` và `Log::warning`; chưa gửi email cho admin (chưa có module thông báo).

## 7. File thay đổi
Tổng: 126 file code (107 mới, 19 sửa) trong hai commit code, cùng commit tài liệu.

**Backend, mới**
- `app/Modules/AiGateway/**`: service provider, routes, Console (`SyncAiRegistry`, `ExpireAiReservations`, `PruneAiRequests`), migration, Domain (provider, model, price, route, request, budget, counter, reservation), Gateway (`AiGateway`, `BudgetManager`, `CircuitBreaker`, `CostCalculator`, `JsonSchemaValidator`, `AiTask`, `AiResult`…), Providers (OpenAI, Gemini, Anthropic, Fake, `HttpProvider`, `ProviderException`…), Http (controller tenant + 6 controller admin, 6 request, resource), `Queries/AiUsageReport`, `Registry/AiRegistry`, `Workflows/AiTestWorkflow`.
- `app/Modules/Workflows/**`: engine (`WorkflowEngine`, `WorkflowStateMachine`, `ConcurrencyLimiter`, `WorkflowRegistry`…), Domain, Enums, `Jobs/ProcessWorkflow`, `Listeners/RecordFailedJob`, Console (`RecoverWorkflows`, `PruneWorkflows`), `AdminJobController`, resources, policy, `Support/Queues`, migration.
- `app/Kernel/Logging/SecretRedactor.php`; `lang/{vi,en}/{ai,workflows}.php`.
- Tests: `tests/Unit/AiGateway/*` (3), `tests/Unit/Kernel/SecretRedactorTest.php`, `tests/Unit/Workflows/QueueConfigTest.php`, `tests/Feature/AiGateway/*` (4), `tests/Feature/Workflows/WorkflowEngineTest.php`.

**Backend, sửa**: `.env.example`, `Identity/Enums/PlatformRole.php`, `Platform/Enums/PlatformPermission.php`, `bootstrap/providers.php`, `config/horizon.php`, `config/sitex.php`, `database/seeders/DatabaseSeeder.php`, `routes/console.php`, `tests/Pest.php`, `tests/Unit/ArchitectureTest.php`, `tests/Feature/Tenancy/CrossTenantIsolationTest.php`.

**Frontend**
- Mới: `src/apps/customer/pages/AiTasksPage.vue`, `src/apps/admin/pages/{AiUsage,AiSettings,Jobs}Page.vue`, `src/shared/api/ai.ts`, `src/shared/components/ai/*` (3), `tests/ai.spec.ts`.
- Sửa: router và shell của customer/admin, `locales/{vi,en}.ts`, `shared/api/client.ts`, `shared/ui/AppIcon.vue`.

**Script, deploy, tài liệu**
- Mới: `scripts/e2e/phase3-helper.php`, `scripts/e2e/phase3-worker-restart.sh`, `scripts/e2e/ai.js`, `deploy/supervisor/sitexpost-horizon.conf`, `deploy/supervisor/sitexpost-queue-work.conf`, `docs/PHASE3-REPORT.md`.
- Sửa: `docs/ROADMAP.md`, `docs/ARCHITECTURE.md` (§8, §10, §10.1, ADR-011…014), `docs/DATABASE.md` (§3.14, §3.14b), `docs/SECURITY.md`, `docs/API-CONTRACTS.md`, `docs/DEPLOYMENT.md`, `CLAUDE.md`.

## 8. Checklist nghiệm thu Phase 3

| Tiêu chí | Trạng thái | Bằng chứng |
|---|---|---|
| Gửi được tác vụ AI thử | ✅ (fake provider) | `AiTaskApiTest`, `scripts/e2e/ai.js` |
| Token và chi phí được ghi nhận | ✅ | `AiGatewayTest`, `CostCalculatorTest`, trang Chi phí AI |
| Job nền không chặn HTTP | ✅ | API trả 202 ~230 ms; AI chỉ chạy trên queue |
| Restart worker không mất workflow | ✅ | `phase3-worker-restart.sh` kịch bản A, B; `WorkflowEngineTest` (recovery) |
| Có test đồng thời | ✅ | race test ngân sách MySQL; test giới hạn đồng thời tenant, CAS, lease |
| Có test lỗi | ✅ | lỗi provider, timeout, circuit breaker, fallback, sai schema, vượt ngân sách, dead letter, retry thủ công |
| Không retry không kiểm soát lời gọi tốn phí | ✅ | `interrupted_paid_call`, `duplicate_call`, `allow_paid_retry` có test |
| Giữ chỗ quota nguyên tử, không trừ hai lần | ✅ | `BudgetTest` |
| Log không lộ secret | ✅ | `SecretRedactorTest`, không lưu prompt |
| Super Admin quản lý provider/model | ✅ | `AdminAiApiTest`, trang Cấu hình AI |
| Ngân sách tenant và toàn hệ thống | ✅ | `BudgetTest`, `AiGatewayTest` |
| Gọi API thật của 3 provider | ⏳ **chưa** | cần khóa API (§6.1) |
| Không phá chức năng Phase 1–2 | ✅ | toàn bộ test cũ vẫn đạt, `CrossTenantIsolationTest` mở rộng |

## 9. Cách chạy lại
```bash
scripts/check.sh                                      # toàn bộ quality gate
cd backend && DB_CONNECTION=mysql vendor/bin/pest     # gồm race test (cần MySQL)
scripts/e2e/phase3-worker-restart.sh                  # cần MySQL, DB sitexpost_e2e
node scripts/e2e/ai.js                                # cần API (php -S), worker, Vite đang chạy
```

## 10. Phase 3E — DeepSeek và quản lý model linh hoạt (bổ sung 05/11/2026)

Làm theo báo cáo khảo sát `DEEPSEEK-GAP-REPORT.md`, đã được chủ dự án duyệt kèm giá theo khung giờ. Không viết lại AI Gateway: chỉ thêm adapter, cột DB, endpoint admin và giao diện. Phase 1, 2 không bị đụng tới.

### 10.1 Kết quả kiểm tra (đã chạy thật)
| Kiểm tra | Kết quả |
|---|---|
| Pint, Larastan level 6 | Không lỗi |
| Pest (SQLite) | 253 passed, 1 skipped (race test chỉ chạy MySQL) |
| Pest (MySQL 8.4) | 254 passed |
| Frontend lint, typecheck, Vitest, build | OK, 33 test |
| `scripts/check.sh` (gồm plugin WP) | All checks passed |
| E2E mới `scripts/e2e/ai-providers.js` (Chromium, admin thật + mock OpenAI-compatible) | 9/9 bước qua |
| E2E cũ `scripts/e2e/ai.js` (hồi quy Phase 3) | 8/8 bước qua |

E2E mới đi qua: thấy provider DeepSeek; nhập khóa DeepSeek, giao diện chỉ hiện `…7788`; Test Connection DeepSeek báo "Không kết nối được" (đúng, vì mạng sandbox chặn); thêm provider "Mock LLM" OpenAI-compatible, kết nối OK, thấy 2 model; thêm model + giá cao/thấp điểm; Test Model trả JSON, token và chi phí $0.000022 (khớp giá thấp điểm lúc 12:43 UTC thứ Năm: 100 × 0.15 + 12 × 0.60 = 22.2 micro-USD); đổi Model ID; quét 23 response API admin không thấy khóa; giao diện trên điện thoại.

### 10.2 Đã làm gì
- **DeepSeek** (`DeepSeekProvider`): `POST /chat/completions`, `GET /models`, Bearer. Model ID lấy từ api-docs.deepseek.com (đọc 05/11/2026): `deepseek-flash` và `deepseek-v4-pro`; không dùng tên cũ `deepseek-chat`, `deepseek-reasoner`. Structured output dùng `json_object` (DeepSeek không hỗ trợ `json_schema`), kết quả vẫn qua `JsonSchemaValidator`. `thinking` bật/tắt và `reasoning_effort` (low/high/max) cấu hình theo model; khi bật thinking thì bỏ `temperature`. Usage đọc `prompt_cache_hit_tokens` và `reasoning_tokens`. `content_filter` → `refused`; `insufficient_system_resource` → `provider_overloaded`. Hai model seed ở trạng thái **tắt**, kèm giá cao điểm/thấp điểm theo trang giá chính thức.
- **Provider tùy chỉnh** (`OpenAiCompatibleProvider`, driver `openai_compatible`): base URL qua SsrfGuard lúc lưu và mỗi lần gọi, ghim IP, không redirect; chọn chế độ JSON (`json_schema`/`json_object`/`none`); chỉ văn bản.
- **Khóa API trong DB** (ADR-015): mã hóa bằng APP_KEY, ghi-một-chiều, API chỉ trả 4 ký tự cuối và nguồn (`database`/`env`); khóa DB ưu tiên hơn `.env`; audit không chứa khóa.
- **Quản lý model**: sửa Model ID, mức suy luận, capabilities; xóa model chưa dùng; xóa provider tùy chỉnh chưa có model.
- **Giá theo khung giờ**: `offpeak_*` + `peak_windows` (UTC). Chi phí tính theo thời điểm gọi; giữ chỗ ngân sách luôn dùng giá cao điểm.
- **Test Connection** (danh sách model, miễn phí) và **Test Model** (lời gọi thật ≤ 200 token ra, áp ngân sách global, được cả với model đang tắt); 10 lần/phút/admin; có audit.
- Lỗi mạng: proxy từ chối (`CONNECT tunnel failed`) → `connect_failed`; GET danh sách model bị timeout → `network_error` (không còn báo "có thể đã bị tính phí" cho lời gọi miễn phí).

### 10.3 Lỗi tìm thấy và đã sửa
1. Validate khung giờ dùng `distinct` trên `peak_windows.*.days.*`, Laravel so trùng **giữa các khung**, nên hai khung cùng thứ 2–6 (đúng như lịch DeepSeek) bị từ chối. Chỉ lộ ra khi chạy giao diện thật. Đã bỏ `distinct` và sửa test để dùng hai khung.
2. Test Connection DeepSeek qua proxy bị báo `response_timeout` ("có thể đã bị tính phí"), gây hiểu nhầm. Đã phân loại lại (xem trên) + test.
3. Dòng `ai_requests` của Test Model (workspace null) không có ulid vì bỏ qua event; đã tự sinh ulid.
4. Phụ đề trang Cấu hình AI vẫn ghi "khóa chỉ đặt trong biến môi trường"; đã sửa.

### 10.4 Hạn chế (trung thực)
1. **Chưa gọi DeepSeek thật**: chưa có khóa, và sandbox chặn api.deepseek.com. Request/response dựng đúng theo tài liệu và kiểm bằng `Http::fake`. Cần chạy Test Connection + Test Model bằng khóa thật trước khi bật model.
2. Chưa xác minh bằng khóa thật vị trí trường `reasoning_tokens` trong usage của DeepSeek.
3. Ngày lễ Trung Quốc (DeepSeek tính giá cao điểm cả ngày) **không** được mô hình hóa: có thể tính phí cao hơn thực tế ở những ngày đó, không bao giờ thấp hơn.
4. Tài liệu DeepSeek ghi output tối đa 384K cho cả hai model; với `deepseek-v4-pro` chưa thấy ghi rõ riêng, nên seed theo con số chung.
5. Đổi APP_KEY làm khóa trong DB không giải mã được, cần nhập lại (đã ghi trong DEPLOYMENT).
6. Provider tùy chỉnh chỉ hỗ trợ văn bản (chưa có tạo ảnh).

### 10.5 File thay đổi
Backend: migration `2026_11_08_000100_add_custom_providers_and_offpeak_prices.php`; mới `DeepSeekProvider`, `OpenAiCompatibleProvider`, `StoreAiProviderRequest`, `TestAiModelRequest`; sửa `AiProvider`, `AiModel`, `AiModelPrice`, `AiTaskRoute`, `AiGateway`, `AiTask`, `TaskRouter`, `CostCalculator`, `HttpProvider`, các adapter OpenAI/Anthropic/Gemini/Fake (`apiKey()`, `listModels()`), `ProviderAdapter`, `ProviderRegistry`, `ProviderRequest`, `AiRegistry`, hai controller admin, các request, `routes.php`, `AiGatewayServiceProvider`, `config/sitex.php`, `.env.example`, `lang/*/ai.php`. Test mới `OpenAiCompatibleAdaptersTest` (8), `CustomProvidersTest` (11), thêm 2 test trong `CostCalculatorTest`.
Frontend: `shared/api/ai.ts`, `AiSettingsPage.vue`, `locales/vi.ts`, `locales/en.ts`, `tests/ai.spec.ts`.
Script: `scripts/e2e/ai-providers.js`, `scripts/e2e/mock-openai-compatible.js`.
Tài liệu: ARCHITECTURE (§8, ADR-015), SECURITY §5, DATABASE §3.14, API-CONTRACTS, DEPLOYMENT, ROADMAP (3E), CLAUDE.md, báo cáo này.

### 10.6 Checklist nghiệm thu 3E
- [x] Kiểm tra code hiện có, báo cáo trước khi sửa, được duyệt
- [x] Không viết lại AI Gateway, không ảnh hưởng Phase 1/2 (toàn bộ test cũ vẫn qua)
- [x] DeepSeek qua kiến trúc adapter, Model ID theo tài liệu chính thức
- [x] Model, giá, route quản lý động trong DB (đã có từ Phase 3, nay sửa/xóa được)
- [x] Super Admin: thêm/sửa/bật/tắt model, Model ID, giá token, API key, model mặc định theo task
- [x] Custom OpenAI-compatible API
- [x] Fallback khi provider lỗi
- [x] Test Connection và Test Model thật
- [x] API key mã hóa, chỉ ở backend
- [x] Migration và test
- [ ] Gọi DeepSeek thật bằng khóa của chủ dự án (chờ khóa)

Chạy lại E2E mới: khởi động API, `node scripts/e2e/mock-openai-compatible.js`, Vite admin (cổng 5174), rồi `node scripts/e2e/ai-providers.js <thư-mục-ảnh>` trên DB vừa `migrate:fresh --seed` + `DemoSeeder` (cần `SITEX_SSRF_ALLOW_HOSTS=127.0.0.1 SITEX_SSRF_ALLOW_HTTP=true`).
