=== SiteX Post ===
Contributors: sitexpost
Tags: seo, ai, publishing, content, woocommerce
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects your WordPress site to SiteX Post Cloud so it can publish SEO content safely, without your password.

== Description ==

SiteX Post connects this website to SiteX Post Cloud. Once you approve the connection, SiteX Post Cloud can:

* read basic site information (WordPress, PHP and plugin versions, active SEO plugin, WooCommerce),
* read categories, tags, posts, pages and WooCommerce products (titles, links, excerpts, prices),
* create posts and pages as the account you choose, and update only the posts it created,
* upload images (featured and inline) to the media library,
* write the SEO title, description, focus keyword, canonical URL and noindex flag through Yoast SEO, Rank Math, or built-in meta tags when neither is active.

It cannot change settings, plugins, themes, users or content it did not create.

= Security =

* No WordPress password is ever asked for or stored. You pair the site with a one-time code (valid 15 minutes) and confirm in wp-admin.
* Each site gets its own key. Every request from SiteX Post Cloud is signed with HMAC-SHA256 over the method, route, query, body hash, timestamp and a single-use nonce; requests older than 5 minutes or replayed are rejected. Responses are signed too.
* Keys can be rotated from SiteX Post Cloud and revoked from either side. After you disconnect, the key is deleted and no further writes are accepted.
* Content is sanitized (scripts, event handlers, iframes, forms and javascript: URLs are removed) and uploads must be real JPEG, PNG, GIF or WebP images.
* No AI provider API key is ever sent to or stored on this site.

== Installation ==

1. Upload the `sitex-post` folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. In SiteX Post Cloud, open Websites → Add website, enter this site address and copy the pairing code.
4. In wp-admin open SiteX Post, paste the code, review the request, choose the publishing account (a dedicated Author or Editor account is recommended), tick the confirmation box and click "Confirm and connect".

The site must be reachable over HTTPS. Pretty permalinks are recommended; with plain permalinks the plugin falls back to `?rest_route=`.

= Advanced =

* `define( 'SITEX_POST_CLOUD_URL', 'https://…' );` in wp-config.php points the plugin at another SiteX Post Cloud (staging).
* `SITEX_POST_ALLOW_INSECURE` allows plain HTTP, but only when `WP_ENVIRONMENT_TYPE` is `local` or `development`.
* The `sitex_post_allowed_html` filter adjusts the HTML allow-list for published content.

== Frequently Asked Questions ==

= Does SiteX Post need my admin password? =

No. Pairing uses a one-time code and your confirmation in wp-admin.

= What happens to my posts if I disconnect? =

Nothing. Posts stay exactly as they are; SiteX Post Cloud just loses access.

= Can SiteX Post edit my existing posts? =

No. It can only update posts it created itself (marked with a private meta field).

== Changelog ==

= 1.0.0 =
* First release: pairing wizard, signed REST API, content and taxonomy sync, image upload, idempotent publishing, Yoast SEO / Rank Math / built-in SEO adapters, logs and diagnostics.
