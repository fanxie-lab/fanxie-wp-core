=== Fanxie Warden ===
Contributors: fanxielab
Tags: security, performance, maintenance, optimization, hardening
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modular security, hardening, maintenance, and performance toolkit — replaces 5-6 separate plugins with one opinionated core.

== Description ==

Fanxie Warden consolidates the infrastructure fixes every WordPress site needs — security headers, hardening, login protection, environment health, database maintenance, activity logging, and asset management — into a single modular plugin. Each module can be enabled independently and has zero runtime cost when disabled, so you only pay for what you use.

Built for Fanxie Lab client deployments and released openly for the wider WordPress community. The plugin is opinionated in defaults, conservative in destructive operations, and transparent about trade-offs (every potentially-disruptive toggle ships with a clear warning and a dry-run path where applicable).

**Modules (rolled out progressively):**

* Security Headers — HSTS, CSP (report-only by default), X-Frame-Options, Referrer-Policy, Permissions-Policy, and more.
* Hardening — user enumeration protection, XML-RPC control, version hiding, uploads directory PHP execution lockdown, DISALLOW_FILE_EDIT surfacing, application-password toggle.
* Login Protection — tiered lockouts, custom login slug, strong password enforcement, role-aware session timeout.
* Environment Health — version checks, cron health, debug-mode scanner, inactive plugin/theme detection, abandoned plugin warnings.
* Database Maintenance — revisions, transients, orphaned meta, auto-drafts, trash, and spam cleanup with an optional WP-Cron schedule and a WP-CLI command.
* Activity Log — auditable custom-table log of admin actions with CSV export and auto-pruning.
* Asset Manager — script defer/async/delay engine, conditional unloading, image dimension injection, Heartbeat / emoji / embed controls.

== External services ==

This plugin can contact one third-party service. It is named here, with what is sent and when, so you can decide before it happens.

**api.wordpress.org (WordPress.org Plugin Information API)**

* **What it is used for:** the Environment Health module reports when an active plugin looks abandoned. To do that it reads each plugin's `last_updated` date from the official WordPress.org plugin directory.
* **What is sent:** the directory slug of each *active* plugin on this site (for example `akismet`), one slug per request, plus this plugin's version and your site URL in the request's user-agent header. No personal data, no visitor data, no site content, and no data about plugins that are installed but not active is ever transmitted.
* **When it is sent:** on a background schedule only — a few plugins at a time, at most once every 24 hours per plugin, and never while a page is being rendered for a visitor. Requests also happen when an administrator presses "Re-run checks" on the Environment Health screen.
* **Is it optional:** yes. The setting **Check plugin freshness on wordpress.org** (Environment Health → Settings) is on by default and can be switched off at any time. Turning it off stops all requests immediately and deletes every result already cached.
* **Service terms:** https://wordpress.org/about/privacy/ and https://wordpress.org/about/privacy/cookies/

No other module in this plugin contacts an external service. Version, cron, debug, and plugin/theme checks are performed entirely on your own server, and the WordPress core version check reads the update information WordPress has already fetched for itself rather than making a request of its own.

The Environment Health module also opens a short-lived TLS connection **to your own site** to read the expiry date of its certificate. That connection never leaves your infrastructure, sends no data, and can be switched off with the **Check the TLS certificate** setting.

== Installation ==

1. Upload the `fanxie-warden` folder to `/wp-content/plugins/`, or install via the Plugins screen in WordPress.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Visit **Settings → Fanxie Warden** to enable and configure individual modules.

== Frequently Asked Questions ==

= Will enabling every module break my site? =

No module is enabled out of the box. Each module ships with conservative defaults and clear warnings on any toggle that could affect site behaviour (e.g., CSP enforcement, jQuery Migrate removal). Destructive database operations require a confirmation and offer a dry-run path.

= Is my data deleted when I uninstall the plugin? =

No, not by default. The plugin leaves all options and tables intact on uninstall unless you explicitly opt in via the admin setting or by defining `FX_WARDEN_DELETE_ALL_DATA` in `wp-config.php`. This protects you from accidentally wiping configuration when re-installing.

= Why is the revision limit greyed out? =

Your wp-config.php sets `WP_POST_REVISIONS`, which takes precedence. Remove it to manage the limit here.

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
