=== Fanxie WP Core ===
Contributors: fanxielab
Tags: security, performance, maintenance, optimization, hardening
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modular security, hardening, maintenance, and performance toolkit — replaces 5-6 separate plugins with one opinionated core.

== Description ==

Fanxie WP Core consolidates the infrastructure fixes every WordPress site needs — security headers, hardening, login protection, bot mitigation, environment health, database maintenance, activity logging, asset management, media optimization, and cloud-storage offload — into a single modular plugin. Each module can be enabled independently and has zero runtime cost when disabled, so you only pay for what you use.

Built for Fanxie Lab client deployments and released openly for the wider WordPress community. The plugin is opinionated in defaults, conservative in destructive operations, and transparent about trade-offs (every potentially-disruptive toggle ships with a clear warning and a dry-run path where applicable).

**Modules (rolled out progressively):**

* Security Headers — HSTS, CSP (report-only by default), X-Frame-Options, Referrer-Policy, Permissions-Policy, and more.
* Hardening — user enumeration protection, XML-RPC control, version hiding, uploads directory PHP execution lockdown, DISALLOW_FILE_EDIT surfacing, application-password toggle.
* Login Protection — tiered lockouts, custom login slug, strong password enforcement, role-aware session timeout.
* Turnstile — Cloudflare CAPTCHA integration for native, WooCommerce, and major form-plugin endpoints.
* Environment Health — version checks, cron health, debug-mode scanner, inactive plugin/theme detection, abandoned plugin warnings.
* Database Maintenance — revisions, transients, orphaned meta, auto-drafts, trash, and spam cleanup with Action Scheduler.
* Activity Log — auditable custom-table log of admin actions with CSV export and auto-pruning.
* Asset Manager — script defer/async/delay engine, conditional unloading, image dimension injection, Heartbeat / emoji / embed controls.
* Media Optimizer — on-upload compression, WebP/AVIF generation, `<picture>` rewriting.
* Cloud Storage — R2/S3 offload with URL rewriting and retention policies.

== Installation ==

1. Upload the `fanxie-wp-core` folder to `/wp-content/plugins/`, or install via the Plugins screen in WordPress.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Visit **Settings → Fanxie WP Core** to enable and configure individual modules.

== Frequently Asked Questions ==

= Will enabling every module break my site? =

No module is enabled out of the box. Each module ships with conservative defaults and clear warnings on any toggle that could affect site behaviour (e.g., CSP enforcement, jQuery Migrate removal). Destructive database operations require a confirmation and offer a dry-run path.

= Is my data deleted when I uninstall the plugin? =

No, not by default. The plugin leaves all options and tables intact on uninstall unless you explicitly opt in via the admin setting or by defining `FANXIE_WP_CORE_DELETE_ALL_DATA` in `wp-config.php`. This protects you from accidentally wiping configuration when re-installing.

== Screenshots ==

1. Settings page overview with per-module toggles. (placeholder)
2. Security Headers configuration. (placeholder)
3. Database Maintenance cleanup summary. (placeholder)
4. Activity Log viewer with filters. (placeholder)

== Changelog ==

= 0.1.0-dev =
* Initial scaffold. Plugin bootstrap, module registry, admin settings page mount, AJAX dispatcher, and uninstall handler established.

== Upgrade Notice ==

= 0.1.0-dev =
First developer preview. Not intended for production sites.
