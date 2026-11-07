# Changelog — SiteX Post Cloud

Format: `## [version] - YYYY-MM-DD`, one bullet per change. The Upgrade Wizard
shows the sections between the installed version and the deployed code.
Bump `config/sitex.php` → `version` together with this file.

## [1.0.11] - 2026-11-07
- **"Bước tiếp theo" on the article page:** one clear action for where the article is: approve (with the reasons when it cannot be approved yet) → "Đăng lên WordPress" (choose WordPress draft / publish now / schedule in a small dialog, draft by default) → progress, the reason when a publish is stuck, and a link to the post once it is on WordPress.
- **Fact-check is a warning, not a wall:** claims without a verifying source no longer block approval or publishing. The approver ticks "Tôi đã xem cảnh báo kiểm chứng…" and can approve; sections not re-checked after an edit are a warning. The approval stores the acknowledgement and warnings (audit). Auto-approval is unchanged and still refuses any unverified claim.
- **SEO score on the article page:** the SEO checklist score (0–100, errors and warnings) next to the AI budget, scored automatically for the current version, with "Chấm lại" and a link to the details. It is a technical checklist, not a ranking guarantee.

## [1.0.10] - 2026-11-07
- **New look for both apps** (customer and admin), matching the approved demo design. No API or database change.
- **App shell:** grouped side menu (Nội dung · Tối ưu & đăng bài · Website · Tài khoản; admin: Khách hàng · AI · Phân hệ · Vận hành) with live badges (articles awaiting review, failed publishes + conflicts, agent proposals, broken sites); search box for pages and articles (Ctrl+K); a bell listing what needs you; "Viết bài mới" button always visible; remaining credits in the sidebar; floating "Viết bài" button on mobile.
- **Customer overview:** "Việc cần bạn xử lý", a quick-start checklist, 4 KPI cards, real Google clicks chart (empty state when Search Console is not connected; no made-up numbers), article pipeline, recent articles and sites.
- **Admin overview:** workspaces, users, 30-day AI cost and failed jobs; AI cost per day chart; system health list (worst first); shortcuts. Each block appears only for staff allowed to see it.
- Section tabs: long tab bars keep the first tabs and move the rest into a "Thêm" menu.
- Tables and form fields share one style (spacing, header, hover, focus ring); the Websites page is a table with status, versions, synced content and last check.
- Links from the bell/badges open filtered lists (articles awaiting review, failed publishes).
- Fix: restored layout classes that a Phase 9 change had removed from about 30 elements (media library grid, detail lists, research overview), which made several pages look unstyled.

## [1.0.9] - 2026-11-07
- **AI Writer, simpler:** "Viết bài mới" is now one page with three questions (topic, style, length Short/Medium/Long), three plain-language switches (illustrations, look things up on the Internet, link to older posts) and an optional "Tùy chỉnh thêm" section where every field explains itself; empty means the AI decides. Default review mode is "review the final article". Tabs: Viết bài mới · Bài đã viết · Văn phong (Viết nhiều bài and Lịch sử moved to the right).
- **Styles page:** "Your styles" (cards with where each was learned from) and 7 **ready-made styles** (Thân thiện, Chuyên gia, Hướng dẫn từng bước, Bán hàng, Kể chuyện, Tin tức ngắn gọn, Trang trọng) that can be used directly or copied into your own style.
- **Teach the AI a style** in three steps: learn from one post, a category, an author, the whole blog, products or pasted text → posts are picked automatically (untick any) → the AI's summary in plain words, saved only when you click Save. One sample is now enough (was 2).
- Site content API: filters `filter[term]`, `filter[author]`, `filter[status]`; new `GET sites/{site}/authors`. Migration adds `site_content_items.author_wp_id/author_name` (additive).
- WordPress plugin **1.2.0**: content sync also sends each post's author (user id and public display name only). Update the plugin and run a sync to use "learn from an author".
- New article option `auto_images` overrides the style's image default for that article.

## [1.0.8] - 2026-11-07
- Admin › AI settings › Routes: each task shows the model that will really run ("Runs on"), whether that is the primary or fallback #n, and the numbered chain with skipped models struck through (no API key, disabled, no price, paused). Banner when tasks run on fallbacks or cannot run; filters (on primary / on fallback / cannot run), search, grouping by feature, Vietnamese task names.
- Route editor: ordered model list (drag or arrows, add/remove) with a live "this task will run on …" preview; advanced options folded.
- New admin page **Providers & models** (`/ai/providers`, permission `platform.ai.manage`): every provider in one list, keyed ones first; API key, connection test, settings (timeout, retries), custom OpenAI-compatible providers; each provider expands into its models with prices, an on/off switch, test call, edit and delete, plus "Add model for <provider>". The Providers and Models tabs moved here from AI settings.
- **AI settings** now shows only providers that have an API key, under "Providers in use", each with a switch (In use / Not in use) and the number of tasks it runs; providers without a key are named in one line with a link to enter a key. Tabs: Routes and Budgets. The route editor's model pickers list only models of keyed providers.
- Overview card "Tasks that can run" counts tasks on fallbacks and tasks that cannot run.

## [1.0.7] - 2026-11-07
- Every text AI task can now run with only an Anthropic (Claude) key: Claude Sonnet is added as the last fallback on the routes that had no Claude model (`writer.claims`, `writer.seo_review`, `writer.final_review`, `keywords.expand`, `research.profile`, `analytics.recommend`, `agent.plan`, `copilot.answer`). A migration appends it on existing installs; routes you already re-routed keep your choice. Providers without a key are skipped at no cost.

## [1.0.6] - 2026-11-07
- AI Writer no longer produces "empty" articles (tables full of "Cần xác minh", sentences like "bài này chưa có điều khoản chính thức trong tay"). When research finds fewer than 3 verbatim evidence excerpts (Super Admin setting `min_evidence_items`, 0 = off), the article stops before the brief with status "Chưa đủ nguồn" and a message saying exactly what is missing (sources not fetched, web search off, model without web search). The article page has a form to paste source URLs / turn on web search and **Research again**.
- Source discovery uses the model's web search whenever the article allows it (now on by default) and a model routed to `writer.source_discovery` supports it (Gemini, OpenAI, Claude). DeepSeek cannot search: discovery then falls back to guessing URLs instead of failing, and the research tab says so. Research warnings are shown in plain Vietnamese.
- **Self lookup:** if the first pass leaves the dossier below the minimum, the AI searches the web once more for the questions that still have no evidence (avoiding URLs already tried), then fetches and extracts the new sources (new research step "Tự tra cứu thêm nguồn").
- Claude Sonnet added as a fallback on `writer.research_questions`, `writer.source_discovery`, `writer.source_analysis` (migration appends it on existing installs), so a platform running only Claude can research and search the web.
- Super Admin › AI Writer: new switch **Cho phép AI tìm nguồn trên web** (`web_search_enabled`, default on, overridable per workspace). Off = no web search at all; each article keeps its own "Tìm kiếm web" toggle.
- Writer prompts forbid placeholder content; a section that still contains it is rewritten once. Placeholder text left in a version (also when pasted by hand) blocks approval and is flagged in the quality report.

## [1.0.5] - 2026-11-07
- AI Writer › Giọng văn & preset: new **Learn style**. Pick 2–20 of your own published posts (or paste samples); the AI writes a style guide (tone, addressing, sentence and paragraph rhythm, intros/conclusions, headings, formatting, words to use and avoid). It is a draft until you review and save it; then every article using the preset is written in that style. Copied sentences are stripped and sample text is never stored. New AI route `writer.style_profile` (copied from `writer.brief` on upgrade).
- Automatic images: when a draft is finished, the article gets at least 2 images (featured + inline). Matching images from the site's library are used first; AI images (always labelled) fill the rest, within the media limits and AI budget. Settings per preset (on/off, minimum 0–6); platform default `SITEX_MEDIA_AUTO_IMAGES=true`, `SITEX_MEDIA_MIN_ARTICLE_IMAGES=2`. The article media page shows the status; if images cannot be made (no image model, limits) the article is marked "missing images" and is not blocked.
- SEO Audit warns when an article has fewer images than the minimum.

## [1.0.4] - 2026-11-07
- AI Writer: a section no longer fails with "invalid output" when the model's answer is cut off at the output limit. The output budget per section is sized for Vietnamese HTML (2,000–8,000 tokens instead of 600–4,000), and a cut-off answer is retried once with twice the budget. Rewrites size their budget from the section length.
- AI requests: an answer cut off at `max_tokens` is logged as such, and failed writer calls show the tokens and cost actually billed instead of 0/0.

## [1.0.3] - 2026-11-06
- Admin › AI settings redesigned: overview cards (providers ready, models enabled, routed tasks, budget), one card per provider with a switch, key status and plain-language connection health, model table grouped by provider with search/filters and a "⋯" menu, routes shown as "primary → fallbacks", budgets as cards with an alert marker, and a one-line guide per tab.
- Whole UI (admin and customer apps): shared components restyled in the Tailwind UI look (slate palette, ring borders, buttons, inputs, badges with dots, tabs with counts, modals, sidebar and top bar). New shared `DropdownMenu` component.

## [1.0.2] - 2026-11-06
- Docker: `update.sh` without a version upgrades to the newest release (reads the version of the `:latest` image and pins it; does nothing when already current).
- Docker: the image ships `update.sh`; after a successful upgrade the script on the server replaces itself with the new one.
- Releases are created automatically when a merge to `main` bumps this version (`.github/workflows/release.yml`).

## [1.0.1] - 2026-11-06
- Docker: the API, customer app and admin domains are never indexed by search engines (`X-Robots-Tag: noindex, nofollow` and `robots.txt` → `Disallow: /`).
- Backups: verification accepts dumps made by `mariadb-dump` (Docker image); `sitex:backup:run --verify` exits with an error when verification fails.
- Customer app: "Download WordPress plugin" button (Websites page and pairing steps). The zip is served by `GET /api/v1/plugin/sitex-post.zip`, pre-configured with this server's API URL, so no `wp-config.php` edit is needed.
- Upgrade pre-flight reads the recorded version from the database, not from a stale settings cache (workers no longer wait up to 5 minutes after installation).

## [1.0.0] - 2026-11-06
- Phase 9: web installer (/install) protected by a one-time setup token, Setup Wizard, Upgrade Wizard, System Health, monitoring alerts (email/Telegram), encrypted backups with verification.
- Phase 8: Search Console analytics, content opportunities and decay, content refresh, before/after experiments, reports, SEO Copilot, autonomous SEO agent with a server-side policy engine.
- Phase 7: plans, subscriptions, AI credit ledger, VietQR payments, revenue and cost reports.
- Phase 6: AI images, SEO audit, WordPress publisher with conflict detection.
- Phase 5: AI writer with evidence research and fact-check.
- Phase 4: SEO planner (topic discovery).
- Phase 3: AI Gateway (OpenAI, Gemini, Anthropic, DeepSeek, custom), workflow queue.
- Phase 2: WordPress connector plugin with signed requests.
- Phase 1: accounts, workspaces, roles, Super Admin.
