# Serpzilla Overlay for WordPress

A lightweight WordPress plugin that injects contextual and rental links into front-end pages. It is a port of the `serpzilla-worker` Cloudflare Worker architecture, adapted to run natively inside WordPress.

The plugin fetches a remote feed in the background, caches it with the Transients API, and inserts links into safe text nodes on the page. External API calls never block page rendering.

## How it works

- **Background caching** — the feed is stored in a transient with a configurable stale threshold (`SERPZILLA_MAX_STALE`). A `wp_schedule_single_event` cron job refreshes it asynchronously.
- **Safe feed parsing** — serialized feeds are unserialized with `allowed_classes => false` and depth limits, avoiding PHP object injection.
- **DOM-safe injection** — links are inserted into `<article class="prose">` text nodes only. Nested `<a>`, `<script>`, `<style>`, `<button>` and form elements are skipped.
- **Cache plugin compatible** — rendering uses `wp_footer` with a shutdown output buffer fallback so it works alongside page cache plugins such as LiteSpeed.
- **Remote control** — bearer-token protected `admin_post` endpoints support sync and status checks. A `WP_Widget` provides sidebar placement.

## Requirements

- WordPress 5.8 or newer
- PHP 7.4 or newer (PHP 8.x recommended)

The plugin includes polyfills for `str_starts_with` and `str_ends_with` so it runs on older PHP versions.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate **Serpzilla Overlay** from the Plugins menu.
3. Open **Settings → Serpzilla Overlay** and set:
   - User Key
   - Admin Token
   - Site Host
   - Cache TTL (max stale, default `10800` seconds)
4. Click **Sync Feed Now** to fetch and cache the first snapshot.

## WordPress APIs used

- HTTP API (`wp_remote_get`)
- Transients API (`set_transient`, `get_transient`)
- Options API (`get_option`, `update_option`)
- WP-Cron (`wp_schedule_single_event`)
- Widgets API (`WP_Widget`)
- Admin post hooks (`admin_post_*`, `admin_post_nopriv_*`)

## Notes

- The plugin checks that the site host matches the domain configured for the User Key, so cached data is not served to the wrong site.
- The default transient lifetime is three hours and can be changed in the settings page.
- Debug messages are written to `error_log()` during feed sync, admin sync and status checks.

## Changelog

### 1.3.0
- Replaced global widget state with per-instance options (`serpzilla_widget_done_{id_base}`, `serpzilla_mandatory_done_{id_base}`) so multiple widget instances work without conflict.
- Added numeric validation for the `max_stale` setting with a fallback to `10800`.
- Added `error_log()` calls in sync and status handlers for production debugging.
- Added `TEXT_DOMAIN` and `load_plugin_textdomain()` for future translation support.
- Removed duplicate polyfill definitions.
- Improved settings save with proper sanitization.

### 1.2.0
- Initial port from the Cloudflare Worker architecture.
- Added rental link injection, contextual link replacement, `wp_footer` rendering and output buffer fallback.
- Added the admin settings page.

## Author

Erik Rizki Aldianto  
WordPress developer focused on PHP, JavaScript and web performance.
