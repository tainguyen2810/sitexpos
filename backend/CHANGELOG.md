# Changelog — SiteX Post Cloud

Format: `## [version] - YYYY-MM-DD`, one bullet per change. The Upgrade Wizard
shows the sections between the installed version and the deployed code.
Bump `config/sitex.php` → `version` together with this file.

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
