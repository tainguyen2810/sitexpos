# Phase 4 — SEO Planner: AI Topic Discovery & SEO Planning

**Branch:** `feature/phase4-topic-discovery` (based on `feature/phase3-ai-gateway`)
**Status:** implemented, **awaiting acceptance**. Phase 5 has not started.
**Date:** 05/11/2026

> Change in phase order (ADR-016): the owner requested Phase 4 = Topic Discovery & SEO Planner. Plans/billing (previously Phase 4) moves to a later phase. Phase 4 uses its own quota/budget (`research_limits`, `research_usage_counters`) built on the same atomic reservation mechanism as the AI Gateway.

---

## 1. Summary of results

SiteX Post finds SEO topics **from real data**, not by sending one prompt to an AI:

1. **Reads the website**: synced WordPress posts, pages, categories, tags and WooCommerce products; public pages (sitemap, title, H1–H3, questions in the content) with a polite crawler.
2. **Builds the Website Knowledge Profile**: industry, product lines, services, customers, region, differentiators and topics already covered. Each claim carries evidence and a confidence score, and the customer can confirm, edit or reject it. **The AI never overwrites the customer's decisions.**
3. **Extracts keywords** with Vietnamese-language rules (accent-aware, no splitting of compound words such as "tai nghe" or "bàn phím"), from the campaign setup, WordPress, WooCommerce, the website, competitors (headings only), Search Console, DataForSEO (when configured) and AI expansion (suggestions only, **no figures**).
4. **Analyses**: search intent, content type (9 types), coverage on existing posts (cannibalization/duplication), topic clusters, content gaps and internal links.
5. **Opportunity score** is transparent. It has 8 components; a component without data shows "Chưa có dữ liệu" and is not counted. A confidence factor depends on the number of sources, and a penalty applies when an existing post has the same intent.
6. **Topic ideas**: the rules produce ideas from the evidence. The AI only rephrases titles and **is validated** (an off-topic title is discarded). If the AI fails, the rule-based result is kept. No idea duplicates an existing post or a previous idea (fingerprint).
7. **Content calendar**: proposes, approves, pauses, drag & drop. **It never creates or publishes posts.**

---

## 2. Modules & code

| Part | Location |
|---|---|
| Backend module | `backend/app/Modules/Research/` |
| ├ Crawler | `Crawler/{PublicFetcher, RobotsPolicy, SitemapReader, HtmlExtractor}` — SSRF guard, robots.txt RFC 9309, crawl-delay, timeout, page size limit, redirect check |
| ├ Sources | `Sources/{WebsiteCollector, CompetitorCollector, DataForSeoClient}`, `SearchConsole/GoogleSearchConsole` |
| ├ Analysis | `Analysis/{KeywordExtractor, IntentClassifier, ResearchAnalyzer, OpportunityScorer, IdeaPlanner, InternalLinkPlanner, CalendarPlanner}`, `Support/Text` |
| ├ AI | `Ai/ResearchAi` — calls through the AI Gateway (routes `research.profile`, `keywords.expand`, `topics.ideas`; model chosen by the registry/Super Admin, **no hardcoding**) |
| ├ Workflow | `Workflows/ResearchDiscoveryWorkflow` — 12 steps on the Phase 3 workflow engine (checkpoint, retry, cancel) |
| ├ Quota | `Support/QuotaManager` — reserve/commit/release of runs, concurrency, AI budget per run |
| ├ API | `Http/Controllers/*`, `Http/Controllers/Admin/AdminResearchController`, `routes.php` |
| └ Commands | `sitex:research:schedule` (every 15 min), `sitex:research:sync` (every 5 min), `sitex:research:prune` (daily) |
| DEMO data | `backend/database/seeders/ResearchDemoSeeder.php` |
| Customer UI | `frontend/src/apps/customer/pages/research/` (CampaignsPage, CampaignPage + 8 tabs) |
| Super Admin UI | `frontend/src/apps/admin/pages/ResearchAdminPage.vue` |
| API client | `frontend/src/shared/api/research.ts`, `researchAdmin.ts` |

### Migration

`app/Modules/Research/Database/Migrations/2026_11_09_000100_create_research_tables.php` creates 23 tables, **all carrying `workspace_id`** (except the platform configuration tables `research_limits` and `research_source_settings`):

`website_profiles`, `website_knowledge`, `seo_campaigns`, `campaign_settings`, `research_schedules`, `research_runs`, `research_sources`, `source_snapshots`, `topic_clusters`, `keywords`, `keyword_metrics`, `content_ideas`, `content_idea_keywords`, `competitor_websites`, `competitor_findings`, `content_gaps`, `internal_link_suggestions`, `content_calendar_items`, `search_console_connections`, `research_notifications`, `research_usage_counters`, `research_limits`, `research_source_settings`.

The migration only adds tables. It does not modify Phase 1–3 tables.

### Main APIs

Customer API: `/api/v1/workspaces/{ws}/research/...`
- campaigns (CRUD, overview, settings, schedule)
- intelligence (+ knowledge CRUD)
- runs (start with `Idempotency-Key`, view, snapshots, cancel, retry)
- keywords (filter/sort/cursor pagination, bulk)
- ideas (+ brief)
- clusters, gaps, internal-links
- competitors (+ findings)
- calendar (+ auto-plan)
- search-console (connect/properties/property/disconnect)
- usage, notifications

Admin API: `/api/admin/v1/research/{sources, settings, limits, runs, costs, failures, usage}`. Full details are in `docs/API-CONTRACTS.md` §2.5 and §3.

All write APIs have validation, a policy check (Owner/Admin/Editor operate, Viewer read-only, impersonation read-only) and an audit log.

---

## 3. Real data vs DEMO

| Data | Real | DEMO |
|---|---|---|
| WordPress/WooCommerce content | ✅ from Phase 2 sync | — |
| Public website pages, sitemap | ✅ crawler | — |
| Competitors (title/headings, URL + time) | ✅ crawler, robots compliant | — |
| Search Console (impressions/clicks/position) | ✅ once OAuth is configured | — |
| Search volume / KD / CPC | ✅ only from DataForSEO once a key is configured | **Never created**, shows "Chưa có dữ liệu" |
| AI topic expansion / title phrasing | ✅ via the AI Gateway | — |
| DEMO data | — | `ResearchDemoSeeder`: "TechZone (DEMO)" store, 100 keywords, 50 ideas, 5 clusters, 3 campaigns, 1 calendar; everything is labelled `[DEMO]`/`source=demo`, **0 metric rows** |

The DEMO seeder **refuses to run in production**. It only writes into the workspace "Cửa hàng A (Demo)" (`is_demo=true`) and is idempotent. The production frontend bundle passes `check:no-demo`.

---

## 4. Tests run

| Command | Result |
|---|---|
| `php artisan test` (backend, SQLite in-memory) | **300 passed, 1 skipped** (1,751 assertions); 25 tests in `tests/Feature/Research` |
| `./vendor/bin/pint` | passed |
| `npm run typecheck` | passed |
| `npm run lint` | passed |
| `npm run test` (Vitest) | **43 passed**, including `tests/research.spec.ts` |
| `npm run build` (customer + admin) | passed |
| `npm run check:no-demo` | passed |

Phase 4 test coverage:
- **Cross-tenant isolation:** the CrossTenantIsolationTest scans every Research route; workspace A cannot read B's rows, even through A's own campaign.
- **No duplicate topics:** a re-run, retry or replay creates no duplicate ideas, keywords or calendar items.
- **No fabricated metrics:** with no provider configured, `keyword_metrics` stays empty and the UI shows "Chưa có dữ liệu".
- **DataForSEO:** real metrics come with their provider and collection time.
- **Limits:** run quota, concurrency (409), and the AI budget cap per run.
- **Failure handling:** missing Search Console, AI errors (falls back to rules), off-topic AI output is discarded, WordPress/website offline.
- **Competitors:** robots.txt is respected; SSRF attempts (127.0.0.1, 169.254.169.254, own domain) are rejected; article bodies are not stored.
- **Calendar and briefs:** the calendar never calls `/wp-json/`; a brief needs no AI.
- **Super Admin:** secrets never appear in any admin or customer API, and tenant limits are enforced.
- **Data sanity:** DEMO data counts are correct, internal links have readable labels, and the Vietnamese tokenizer and scorer are unit tested.

**Not run:**
- **Static analysis (PHPStan/Larastan):** not run in this environment. Downloading larastan was blocked with a 403, so it was temporarily removed from the local `composer.json`; that change is **not** committed.
- **E2E against real WordPress and DataForSEO/Google:** not run, because these require credentials.

---

## 5. Data sources

| Source | Status |
|---|---|
| WordPress / WooCommerce (synced through the plugin) | ✅ working |
| Public pages of the customer website | ✅ working |
| Competitor websites (titles/headings only) | ✅ working |
| AI expansion & title phrasing (AI Gateway) | ✅ working (uses the Fake provider when no key is set) |
| Google Search Console | ⚙️ needs `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` (default `{APP_URL}/api/v1/oauth/google/callback`), plus enabling the Search Console API in Google Cloud |
| DataForSEO (search volume, KD, CPC, suggestions) | ⚙️ needs a `login:password` key entered by Super Admin under **Nghiên cứu SEO → Nguồn & nhà cung cấp** (stored encrypted, write-only) |
| Google Trends | ⛔ not integrated. Google has no public official API; it is turned off and labelled "requires an authorised official API". No scraping is done |

---

## 6. User guide

### Customers (Owner/Admin/Editor)
1. Go to **SEO Planner** in the menu and click **Tạo chiến dịch**. Choose a connected website, then enter the industry, products, services, target customers and goals.
2. Click **Chạy nghiên cứu**. The job runs in the background and the progress bar updates live, so you can leave the page.
3. **Hiểu website**: review what SiteX Post has learned. Click **Đúng** to confirm, **Loại bỏ** to reject, or edit, and add your own claims.
4. **Từ khóa**: filter by intent, source, cluster or "has data"; select many to approve or reject in bulk. Click a keyword to see its metrics (or "Chưa có dữ liệu"), the score breakdown, evidence and the existing posts that cover it.
5. **Ý tưởng chủ đề**: approve or reject, edit the title or angle, create a **Content Brief** (no AI call), and schedule ideas.
6. **Cụm chủ đề**: view the pillar, keywords and ideas in each cluster, open content gaps (move to plan or dismiss), and internal link suggestions.
7. **Lịch nội dung**: drag ideas from the "approved, not yet scheduled" list onto a day, drag to change the date, and approve, pause or remove items. **Tự xếp lịch** spreads approved ideas across the publishing days you chose.
8. **Cài đặt**: number of ideas per run, focus and excluded topics, page limits, AI cost cap, posting days per week, scheduled research, competitors, and Search Console. The auto-create and auto-publish switches stay disabled in Phase 4.

Viewers have read-only access. Super Admin in impersonation mode also has read-only access.

### Super Admin
- **Nghiên cứu SEO → Nguồn & nhà cung cấp**: turn sources on or off, set the rate limit and timeout, enter or remove a key (it is never shown again), and reset failure counters.
- **Cài đặt chung**: bot user-agent, crawl delay, page size, sitemap limit, turn customer-site or competitor crawling on or off, and snapshot retention.
- **Giới hạn tenant**: global or per-workspace limits for campaigns, runs per month, ideas per run, competitors, calendar items, concurrent runs, and the AI cap per run.
- **Giám sát nghiên cứu / Chi phí / Lỗi nguồn / Sử dụng theo tenant**: cross-tenant views (read-only).

### Operations
- Scheduler: `php artisan schedule:work` (or cron `schedule:run`) runs `sitex:research:schedule`, `sitex:research:sync` and `sitex:research:prune`.
- Queue: uses the Phase 3 worker/Horizon; no new queue is needed.
- DEMO (development only): `php artisan db:seed --class=DemoSeeder`.

---

## 7. Acceptance checklist

- [x] Website Intelligence: profile + editable knowledge; the AI does not overwrite user decisions (test)
- [x] Industry research with scope/excluded topics and campaign limits
- [x] Keyword discovery from WordPress, WooCommerce, website, competitors, Search Console, DataForSEO, AI (suggestions only)
- [x] No fabricated search volume/KD; shows "Chưa có dữ liệu" (backend + UI tests)
- [x] 9 content types; configurable number of ideas per run (limited by tenant limits)
- [x] Topic clusters, content gaps, cannibalization/overlap, internal links, no duplicate posts
- [x] Competitors: robots, rate limit, timeout, SSRF, URL + time stored, no content copied (test)
- [x] Transparent opportunity score (components + "Chưa có dữ liệu" + confidence + penalty)
- [x] Calendar: drag & drop, approve/pause, auto-plan; no content created or published (test)
- [x] Automation on the Phase 3 queue: tenant isolation, concurrency, retry, cancel, idempotency, quota & AI budget
- [x] All tenant tables carry `workspace_id`, with fail-closed scope
- [x] Customer UI: campaigns, overview, website understanding, research center, keywords, ideas, clusters, calendar, settings
- [x] Super Admin: sources, providers, job monitor, costs, limits, failures, global settings; keys never exposed (test)
- [x] API: validation, authorization, pagination, filter, sort, audit
- [x] DEMO data labelled and separate from production
- [ ] **Owner** tests against a real WordPress + Search Console + DataForSEO (needs credentials)
- [ ] **Owner** confirms before Phase 5 starts

## 8. Risks & notes
- Rule-based titles (when AI is off or fails) are still formulaic; with AI turned on, titles are rephrased and checked to stay on topic.
- When neither DataForSEO nor Search Console is configured, the score relies only on internal signals (relevance, intent, coverage, product fit), so it is labelled "Điểm ước tính nội bộ".
- Competitor crawling only reads a limited number of pages per run (`max_competitor_pages`) and respects crawl-delay, so a first run can be slow on large sites.
- `backend/composer.json`: larastan was removed only locally to work around the 403; the commit keeps the original file.
