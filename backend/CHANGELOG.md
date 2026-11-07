# Changelog — SiteX Post Cloud

Format: `## [version] - YYYY-MM-DD`, one bullet per change. The Upgrade Wizard
shows the sections between the installed version and the deployed code.
Bump `config/sitex.php` → `version` together with this file.

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
