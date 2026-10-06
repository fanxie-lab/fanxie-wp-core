# Fanxie WP Core — Product Requirements Document

**Version:** 0.5  
**Date:** April 16, 2026  
**Author:** Fanxie Lab  
**Status:** Draft  

**Plugin slug:** `fanxie-wp-core`  
**Text domain:** `fanxie-wp-core`  

---

## 1. Overview

### 1.1 Problem Statement

WordPress sites managed by Fanxie Lab consistently fail automated audits in the same structural categories: missing security headers, excessive render-blocking resources, unoptimized images, information leakage, outdated software versions, database bloat, and no media offloading strategy. These are infrastructure-level concerns that are site-agnostic — yet existing solutions require installing 5–6 separate plugins (security plugin, hardening plugin, asset optimizer, image compressor, database cleaner, cloud offloader), each with its own configuration surface, potential conflicts, and performance overhead.

### 1.2 Proposed Solution

A single WordPress plugin that consolidates the most impactful, automatable audit fixes into a modular architecture. Each module can be enabled independently, configured per-site, and deployed across all Fanxie Lab client sites with minimal per-site customization.

### 1.3 Target Users

- **Primary:** Fanxie Lab team deploying to client WordPress sites
- **Secondary:** WordPress developers and agencies seeking an all-in-one hardening + optimization toolkit
- **Tertiary:** WordPress.org community (public release planned)

### 1.4 Branding Strategy

**Hybrid approach:**
- **Plugin name:** Fanxie WP Core
- **Plugin slug:** `fanxie-wp-core`
- **Author:** Fanxie Lab (visible in wp.org listing and plugin headers)
- **Admin UI:** Fanxie Lab branding in settings page header
- **Optional badge:** "Secured by Fanxie Lab" that site owners can display (toggleable)
- **Support/docs:** Link to Fanxie Lab resources
- **Positioning:** Essential WordPress infrastructure — security, performance, and maintenance in one opinionated, modular plugin. Not targeted exclusively at developers; friendly to site owners and agencies alike.

### 1.5 Out of Scope

The plugin does **not** address:

- SEO content issues (meta descriptions, Open Graph tags, canonical URLs, sitemaps) — these belong in Yoast/RankMath
- Theme-level structural issues (heading hierarchy, empty hrefs, whitespace in titles)
- Server-level compression (gzip/brotli) — handled at Cloudflare or web server; plugin surfaces a notice if not detected
- Page caching — too many variables depending on hosting stack; recommend Cloudflare APO or server-level caching
- CSS/JS minification and bundling — high risk of breakage, better left to build tools or dedicated plugins
- Web Application Firewall (WAF) — recommend Cloudflare WAF or Wordfence for this layer
- Two-factor authentication (2FA) — deferred to v2 or dedicated plugin

---

## 2. Architecture

### 2.1 Plugin Structure

```
fanxie-wp-core/
├── fanxie-wp-core.php             # Bootstrap, module loader
├── readme.txt                     # wp.org format
├── composer.json
├── languages/                     # i18n .pot/.po/.mo files
├── includes/
│   ├── class-plugin.php           # Core singleton, hooks registration
│   ├── class-module-base.php      # Abstract base for all modules
│   ├── class-admin-settings.php   # Settings page (WP Settings API)
│   └── modules/
│       ├── class-security-headers.php
│       ├── class-hardening.php
│       ├── class-login-protection.php
│       ├── class-turnstile.php
│       ├── class-environment-health.php
│       ├── class-database-maintenance.php
│       ├── class-activity-log.php
│       ├── class-asset-manager.php
│       ├── class-media-optimizer.php
│       └── class-cloud-storage.php
├── admin/
│   ├── views/                     # Settings page templates
│   └── assets/                    # Admin CSS/JS (minimal, unminified source included)
└── cli/
    └── class-cli-commands.php     # WP-CLI commands for bulk operations
```

### 2.2 Module System

Each module extends `Module_Base` and implements:

- `id()` — unique slug (e.g., `security-headers`)
- `name()` — human-readable name (translatable)
- `is_enabled()` — checks stored option
- `register_hooks()` — runs only if enabled
- `get_settings_fields()` — returns fields for the admin UI
- `get_default_config()` — sensible defaults per module

The core `Plugin` class loads all modules, checks `is_enabled()`, and calls `register_hooks()` on `plugins_loaded`. Disabled modules have zero runtime cost.

### 2.3 Dependencies

- **PHP:** 8.1+ (required for AVIF support in GD, modern language features)
- **WordPress:** 6.4+
- **WooCommerce:** Not required, but Asset Manager and Turnstile are WooCommerce-aware
- **Action Scheduler:** Bundled with WooCommerce; if not active, plugin bundles its own copy
- **Imagick extension:** Required for Media Optimizer (GD as fallback with reduced quality)
- **S3-compatible client:** Lightweight, GPL-compatible dependency for Cloud Storage module

### 2.4 WordPress.org Compliance

From day one, the plugin is built for wp.org submission:

- **Code standards:** Full WPCS (WordPress Coding Standards) compliance
- **Sanitization/escaping:** All input sanitized, all output escaped
- **Internationalization:** Every user-facing string wrapped in `__()` / `esc_html__()` / `esc_attr__()` with consistent text domain
- **Dependencies:** GPL-compatible only; no minified code without unminified source
- **No external calls without consent:** Any telemetry or external API calls require explicit opt-in
- **readme.txt:** Proper wp.org format with changelog, FAQ, screenshots, tested-up-to version

---

## 3. Module: Security Headers

### 3.1 Purpose

Add missing HTTP security headers that audits flag as high/medium severity. Lowest-risk, highest-reward module — no visual impact, immediate audit score improvement.

### 3.2 Headers Managed

| Header | Default Value | Notes |
|---|---|---|
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | Only sent over HTTPS |
| `Content-Security-Policy` | Configurable; starts with report-only | See §3.3 |
| `X-Frame-Options` | `SAMEORIGIN` | Skip if already present |
| `X-Content-Type-Options` | `nosniff` | Skip if already present |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Configurable |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=()` | Configurable per-site |
| `Cache-Control` | `public, max-age=3600` | Non-authenticated pages only |

### 3.3 Content-Security-Policy Handling

CSP can break inline scripts, third-party widgets, and payment gateways. The plugin:

1. Defaults to `Content-Security-Policy-Report-Only` mode
2. Provides "learning mode" that logs violations via `/wp-json/fanxie-wp-core/v1/csp-report`
3. Surfaces violations in admin for whitelist building
4. Offers presets for common stacks (WooCommerce + payment gateways, GA/GTM, Meta Pixel)

### 3.4 Hook

`send_headers` action or `wp_headers` filter. Check for existing headers to avoid duplicates.

---

## 4. Module: Hardening (Information Leakage Prevention)

### 4.1 Purpose

Eliminate information disclosure vectors and close common attack surfaces that make WordPress sites easy targets.

### 4.2 Features

#### 4.2.1 User Enumeration Protection

- Block `/?author=1` redirect for unauthenticated users
- Optionally block `/wp-json/wp/v2/users` for unauthenticated requests
- Toggle for sites that legitimately use author archives

#### 4.2.2 XML-RPC Disable/Restrict

- **Option A (default):** Fully disable XML-RPC
- **Option B:** Block only dangerous methods (`system.multicall`, `pingback.ping`)
- **Option C:** Restrict to specific IPs (for Jetpack legacy users)
- Remove `X-Pingback` header

#### 4.2.3 PHP Version Header Removal

- Attempt `header_remove('X-Powered-By')` on `send_headers`
- Surface warning with php.ini instructions if header still detected

#### 4.2.4 WordPress Version Hiding

- Remove generator meta tag from `<head>`
- Remove version from RSS feeds
- Strip `?ver=` query strings from script/style URLs
- Block access to `readme.html`, `license.txt` via 404 redirect

#### 4.2.5 Directory Listing Prevention

- Detect if `/wp-content/uploads/` has directory listing enabled
- Auto-fix: drop `index.php` into uploads directory
- Surface warning with server config instructions if detection fails

#### 4.2.6 REST API User Endpoint Protection

- Return 401 for unauthenticated requests to `/wp-json/wp/v2/users`

#### 4.2.7 Login Error Message Obfuscation

- Replace specific error messages with generic "Invalid username or password"

#### 4.2.8 Block PHP Execution in Uploads (NEW)

**Problem:** Uploaded PHP files in `/wp-content/uploads/` can be executed directly. Common malware attack vector.

**Solution:**
- Drop `.htaccess` in uploads directory with:
  ```apache
  <FilesMatch "\.(?:php|phtml|php[3-7]?|phar)$">
      Require all denied
  </FilesMatch>
  ```
- For nginx, surface warning with config snippet:
  ```nginx
  location ~* /wp-content/uploads/.*\.php$ {
      deny all;
  }
  ```
- Detection: attempt to access a test `.php` file in uploads via HTTP to verify protection

#### 4.2.9 Disable File Editing (NEW)

**Problem:** Dashboard plugin/theme editor allows code injection if an admin account is compromised.

**Solution:**
- Check if `DISALLOW_FILE_EDIT` is defined
- If not, surface warning with recommendation to add to `wp-config.php`:
  ```php
  define( 'DISALLOW_FILE_EDIT', true );
  ```
- Option to force-disable editor via runtime filter (less secure than constant, but immediate):
  ```php
  add_filter( 'file_mod_allowed', function( $allowed, $context ) {
      if ( $context === 'edit_themes' || $context === 'edit_plugins' ) {
          return false;
      }
      return $allowed;
  }, 10, 2 );
  ```

#### 4.2.10 Application Passwords (NEW)

**Problem:** WP 5.6+ added application passwords for REST API auth. Most sites don't use them; they're another credential vector.

**Solution:**
- Toggle to disable application passwords entirely:
  ```php
  add_filter( 'wp_is_application_passwords_available', '__return_false' );
  ```
- Only show this option if no application passwords currently exist

---

## 5. Module: Login Protection

### 5.1 Purpose

Protect the login system from brute-force attacks without requiring 2FA (deferred to v2).

### 5.2 Features

#### 5.2.1 Login Attempt Limiting

- Track failed login attempts by IP and username
- Default thresholds:
  - 5 failures → 15 minute lockout
  - 10 failures → 1 hour lockout
  - 20 failures → 24 hour lockout
- Configurable thresholds and lockout durations
- Whitelist for trusted IPs (office, VPN)
- Storage: transients for short lockouts, options table for persistent bans

#### 5.2.2 Hide wp-login.php

- Custom login slug (e.g., `/portal`, `/signin`, `/access`)
- Original `/wp-login.php` returns 404
- `/wp-admin` redirects unauthenticated users to custom slug
- Handles `wp-login.php?action=` variations (logout, lostpassword, register)

#### 5.2.3 Force Strong Passwords

- Minimum requirements (configurable):
  - 12+ characters
  - Mixed case
  - At least one number
  - At least one special character
- Clear error messaging explaining requirements
- 2FA Enforcement — Install WP's 2FA plugin

#### 5.2.4 Admin Session Timeout

- Auto-logout after inactivity period (default: 30 minutes for admins, 2 hours for other roles)
- Configurable per-role
- Hook: `auth_cookie_expiration` filter + JavaScript heartbeat check

---

## 6. Module: Turnstile (Cloudflare CAPTCHA)

> **DEFERRED — not in the v1.0 release (decided 2026-09-03).** This section is
> retained as a written spec for a future version; nothing in it is built and
> the module does not appear in the admin UI. See
> [`checklist-fanxie-wp-core.md`](./checklist-fanxie-wp-core.md) for the rationale.

### 6.1 Purpose

Protect forms from bots using Cloudflare Turnstile — privacy-friendly, GDPR-compliant, no tracking cookies, free tier available.

### 6.2 Configuration

- **Site Key:** Obtained from Cloudflare dashboard
- **Secret Key:** For server-side validation (recommend `wp-config.php` constants)
- **Widget Mode:**
  - Managed (default) — Cloudflare decides when to show challenge
  - Non-interactive — always shows widget but usually auto-solves
  - Invisible — no visible widget, challenge only if suspicious
- **Theme:** Light, Dark, Auto (follows system preference)
- **Language:** Auto-detect or force specific locale

```php
// Recommended wp-config.php approach
define( 'FX_CORE_TURNSTILE_SITE_KEY', 'your-site-key' );
define( 'FX_CORE_TURNSTILE_SECRET_KEY', 'your-secret-key' );
```

### 6.3 Protected Forms

#### 6.3.1 WordPress Native Forms

| Form | Hook | Notes |
|------|------|-------|
| Login | `login_form` | Also covers custom login slug |
| Registration | `register_form` | Only if registration enabled |
| Password Reset | `lostpassword_form` | Request form only |
| Comments | `comment_form_after_fields` | Skip for logged-in users (optional) |

#### 6.3.2 WooCommerce Forms (if detected)

| Form | Hook |
|------|------|
| Checkout | `woocommerce_review_order_before_submit` |
| Login (My Account) | `woocommerce_login_form` |
| Registration (My Account) | `woocommerce_register_form` |

#### 6.3.3 Form Plugin Integrations

Auto-detect and integrate with popular form plugins:

| Plugin | Detection | Integration Method |
|--------|-----------|-------------------|
| Contact Form 7 | `class_exists('WPCF7')` | `wpcf7_form_elements` filter + custom validation |
| WPForms | `function_exists('wpforms')` | `wpforms_display_submit_before` hook |
| Gravity Forms | `class_exists('GFForms')` | `gform_submit_button` filter |
| Ninja Forms | `function_exists('Ninja_Forms')` | Action hook on form render |
| Formidable Forms | `class_exists('FrmAppHelper')` | `frm_submit_button_html` filter |

### 6.4 Implementation

#### 6.4.1 Frontend (JavaScript)

```php
// Enqueue only on pages with protected forms
add_action( 'wp_enqueue_scripts', function() {
    if ( fanxie_page_has_protected_form() ) {
        wp_enqueue_script(
            'cloudflare-turnstile',
            'https://challenges.cloudflare.com/turnstile/v0/api.js',
            [],
            null,
            [ 'strategy' => 'defer' ]
        );
    }
});
```

```html
<!-- Widget markup (injected into forms) -->
<div class="cf-turnstile" 
     data-sitekey="<?php echo esc_attr( FANXIE_TURNSTILE_SITE_KEY ); ?>"
     data-theme="auto"
     data-callback="fanxieTurnstileCallback">
</div>
```

#### 6.4.2 Server-Side Validation

```php
function fanxie_verify_turnstile( $token ) {
    if ( empty( $token ) ) {
        return false;
    }
    
    $response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'body' => [
            'secret'   => FANXIE_TURNSTILE_SECRET_KEY,
            'response' => $token,
            'remoteip' => fanxie_get_client_ip(),
        ],
        'timeout' => 10,
    ]);
    
    if ( is_wp_error( $response ) ) {
        // Fail open or closed? Configurable.
        return get_option( 'fanxie_turnstile_fail_open', false );
    }
    
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    
    return ! empty( $body['success'] );
}

// Hook into login
add_filter( 'authenticate', function( $user, $username, $password ) {
    if ( empty( $username ) || empty( $password ) ) {
        return $user;
    }
    
    $token = $_POST['cf-turnstile-response'] ?? '';
    
    if ( ! fanxie_verify_turnstile( $token ) ) {
        return new WP_Error( 'turnstile_failed', __( 'CAPTCHA verification failed. Please try again.', 'plugin-textdomain' ) );
    }
    
    return $user;
}, 25, 3 );
```

### 6.5 Caching Considerations

- Pages with Turnstile tokens should not be cached
- Inject `Cache-Control: no-store, no-cache, must-revalidate` on protected pages
- If popular caching plugins detected (WP Super Cache, W3 Total Cache, WP Rocket, LiteSpeed Cache), surface integration guidance

### 6.6 Admin UI

- Enable/disable per form type (checkboxes)
- Test mode: validate setup without blocking real users
- Stats: show verification success/failure counts (stored in transient, 7-day rolling window)

---

## 7. Module: Environment Health

### 7.1 Purpose

Monitor software versions, server configuration, and site hygiene to ensure the site runs on supported, secure infrastructure.

### 7.2 Version Checks

| Component | Data Source | Warning | Critical |
|---|---|---|---|
| WordPress | `get_bloginfo('version')` vs `api.wordpress.org` | 1 minor behind | 2+ minors or security release available |
| PHP | `phpversion()` vs hardcoded support matrix | < 8.2 (security-only) | < 8.1 (EOL) |
| MySQL/MariaDB | `$wpdb->db_version()` vs support matrix | 1 minor behind | Unsupported/EOL |
| SSL Certificate | `stream_context_get_params()` | Expires in < 30 days | Expired or missing |
| HTTPS | `is_ssl()` | — | Not enforced |

### 7.3 Cron Health Check

**Detection:**
- Is `DISABLE_WP_CRON` defined?
- Check `_transient_doing_cron` timestamp
- Compare `wp_next_scheduled()` for core events against current time
- Flag if scheduled events are > 1 hour overdue

**Recommendations (copy-paste ready, no auto-fix):**
```php
// Add to wp-config.php
define( 'DISABLE_WP_CRON', true );
```
```bash
# Add to crontab (crontab -e)
*/5 * * * * wget -q -O - https://example.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

### 7.4 Debug Mode Detection (NEW)

**Checks:**
- `WP_DEBUG` enabled in production
- `WP_DEBUG_DISPLAY` enabled (exposes errors to visitors)
- `WP_DEBUG_LOG` enabled without proper log file protection
- `SCRIPT_DEBUG` enabled (loads unminified core scripts)
- PHP `display_errors` enabled
- PHP `error_reporting` includes notices/warnings in production

**Implementation:**
```php
function fanxie_check_debug_mode() {
    $issues = [];
    
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        $issues[] = 'WP_DEBUG is enabled';
    }
    
    if ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) {
        $issues[] = 'WP_DEBUG_DISPLAY is enabled — errors visible to visitors';
    }
    
    if ( ini_get( 'display_errors' ) ) {
        $issues[] = 'PHP display_errors is enabled';
    }
    
    if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
        $issues[] = 'SCRIPT_DEBUG is enabled — unminified scripts loading';
    }
    
    return $issues;
}
```

### 7.5 Plugin/Theme Health (NEW)

#### 7.5.1 Inactive Plugin Detection

- List plugins that are installed but not active
- Each inactive plugin is attack surface with no benefit
- Recommendation: delete or activate

#### 7.5.2 Inactive Theme Detection

- WP requires at least one default theme as fallback
- Flag non-default themes that are inactive
- Recommendation: delete unused themes, keep one default (Twenty Twenty-Four)

#### 7.5.3 Abandoned Plugin Detection

- Query wp.org API for each active plugin's `last_updated` date
- Warning: not updated in 1+ years
- Critical: not updated in 2+ years
- Note: does not apply to premium plugins (no wp.org listing)

```php
function fanxie_check_plugin_freshness( $plugin_slug ) {
    $response = wp_remote_get( "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug={$plugin_slug}" );
    
    if ( is_wp_error( $response ) ) {
        return 'unknown';
    }
    
    $data = json_decode( wp_remote_retrieve_body( $response ) );
    
    if ( empty( $data->last_updated ) ) {
        return 'not_on_wporg'; // Premium or custom plugin
    }
    
    $last_updated = strtotime( $data->last_updated );
    $age_days = ( time() - $last_updated ) / DAY_IN_SECONDS;
    
    if ( $age_days > 730 ) {
        return 'critical'; // 2+ years
    } elseif ( $age_days > 365 ) {
        return 'warning'; // 1+ years
    }
    
    return 'ok';
}
```

### 7.6 Admin UI

Dashboard widget + dedicated tab showing all checks with status, current values, and recommended actions.

---

## 8. Module: Database Maintenance

### 8.1 Purpose

Clean up database bloat that accumulates over time: excess revisions, expired transients, orphaned metadata, auto-drafts, trashed posts, and spam comments.

### 8.2 Features

#### 8.2.1 Post Revisions

**Problem:** WordPress stores unlimited revisions by default. A frequently-edited post can have hundreds of revisions.

**Settings:**
- Limit revisions per post (default: 10, configurable 0-50)
- Applies to new revisions only; doesn't delete existing

**Cleanup:**
- Show total revision count and estimated database size
- One-click purge: keep only N most recent revisions per post
- WP-CLI: `wp fanxie db revisions --keep=5 --dry-run`

```php
// Limit future revisions
add_filter( 'wp_revisions_to_keep', function( $num, $post ) {
    return get_option( 'fanxie_revision_limit', 10 );
}, 10, 2 );

// Cleanup excess revisions
function fanxie_cleanup_revisions( $keep = 5 ) {
    global $wpdb;
    
    $posts_with_revisions = $wpdb->get_col( "
        SELECT DISTINCT post_parent 
        FROM {$wpdb->posts} 
        WHERE post_type = 'revision' AND post_parent > 0
    " );
    
    $deleted = 0;
    
    foreach ( $posts_with_revisions as $post_id ) {
        $revisions = $wpdb->get_col( $wpdb->prepare( "
            SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'revision' AND post_parent = %d 
            ORDER BY post_date DESC
        ", $post_id ) );
        
        $to_delete = array_slice( $revisions, $keep );
        
        foreach ( $to_delete as $rev_id ) {
            wp_delete_post_revision( $rev_id );
            $deleted++;
        }
    }
    
    return $deleted;
}
```

#### 8.2.2 Transients

**Problem:** Expired transients linger in `wp_options` until accessed. Sites can accumulate thousands.

**Cleanup:**
- Delete all expired transients
- Optionally delete all transients (nuclear option)
- Show count and size before deletion

```php
function fanxie_cleanup_transients( $include_valid = false ) {
    global $wpdb;
    
    // Expired transients
    $deleted = $wpdb->query( "
        DELETE a, b FROM {$wpdb->options} a
        INNER JOIN {$wpdb->options} b ON b.option_name = CONCAT('_transient_timeout_', SUBSTRING(a.option_name, 12))
        WHERE a.option_name LIKE '_transient_%'
        AND a.option_name NOT LIKE '_transient_timeout_%'
        AND b.option_value < UNIX_TIMESTAMP()
    " );
    
    if ( $include_valid ) {
        $deleted += $wpdb->query( "
            DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_%' 
            OR option_name LIKE '_site_transient_%'
        " );
    }
    
    return $deleted;
}
```

#### 8.2.3 Orphaned Metadata

**Problem:** Postmeta, usermeta, termmeta, and commentmeta rows can remain after their parent is deleted.

**Cleanup:**
```php
function fanxie_cleanup_orphaned_postmeta() {
    global $wpdb;
    
    return $wpdb->query( "
        DELETE pm FROM {$wpdb->postmeta} pm
        LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE p.ID IS NULL
    " );
}

function fanxie_cleanup_orphaned_usermeta() {
    global $wpdb;
    
    return $wpdb->query( "
        DELETE um FROM {$wpdb->usermeta} um
        LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id
        WHERE u.ID IS NULL
    " );
}

function fanxie_cleanup_orphaned_termmeta() {
    global $wpdb;
    
    return $wpdb->query( "
        DELETE tm FROM {$wpdb->termmeta} tm
        LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id
        WHERE t.term_id IS NULL
    " );
}

function fanxie_cleanup_orphaned_commentmeta() {
    global $wpdb;
    
    return $wpdb->query( "
        DELETE cm FROM {$wpdb->commentmeta} cm
        LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
        WHERE c.comment_ID IS NULL
    " );
}
```

#### 8.2.4 Auto-Drafts

**Problem:** WordPress creates auto-draft posts when you click "Add New." If you navigate away without saving, they linger.

**Cleanup:**
- Delete auto-drafts older than X days (default: 7)
- WP core has a cleanup but only runs on upgrade

```php
function fanxie_cleanup_auto_drafts( $days = 7 ) {
    global $wpdb;
    
    $date_threshold = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
    
    $auto_drafts = $wpdb->get_col( $wpdb->prepare( "
        SELECT ID FROM {$wpdb->posts} 
        WHERE post_status = 'auto-draft' 
        AND post_date < %s
    ", $date_threshold ) );
    
    foreach ( $auto_drafts as $post_id ) {
        wp_delete_post( $post_id, true );
    }
    
    return count( $auto_drafts );
}
```

#### 8.2.5 Trashed Posts

**Problem:** Trashed posts remain in database indefinitely unless manually emptied.

**Cleanup:**
- Delete trashed posts older than X days (default: 30)
- WordPress constant `EMPTY_TRASH_DAYS` can control this, but defaults to 30 and only runs on cron

```php
function fanxie_cleanup_trash( $days = 30 ) {
    global $wpdb;
    
    $date_threshold = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
    
    $trashed = $wpdb->get_col( $wpdb->prepare( "
        SELECT ID FROM {$wpdb->posts} 
        WHERE post_status = 'trash' 
        AND post_modified < %s
    ", $date_threshold ) );
    
    foreach ( $trashed as $post_id ) {
        wp_delete_post( $post_id, true );
    }
    
    return count( $trashed );
}
```

#### 8.2.6 Spam Comments

**Problem:** Spam comments accumulate if not regularly purged.

**Cleanup:**
- Delete spam comments older than X days (default: 15)

```php
function fanxie_cleanup_spam_comments( $days = 15 ) {
    global $wpdb;
    
    $date_threshold = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
    
    $spam = $wpdb->get_col( $wpdb->prepare( "
        SELECT comment_ID FROM {$wpdb->comments} 
        WHERE comment_approved = 'spam' 
        AND comment_date < %s
    ", $date_threshold ) );
    
    foreach ( $spam as $comment_id ) {
        wp_delete_comment( $comment_id, true );
    }
    
    return count( $spam );
}
```

### 8.3 Scheduled Cleanup

- Optional: run cleanup automatically via Action Scheduler
- Frequency: daily (off-peak hours) or weekly
- Configurable per-item (some users may want to keep revisions but purge transients)

### 8.4 Admin UI

| Item | Count | Size | Action |
|------|-------|------|--------|
| Post Revisions | 1,247 | 4.2 MB | Purge (keep 5 per post) |
| Expired Transients | 89 | 120 KB | Purge all |
| Orphaned Postmeta | 342 | 85 KB | Purge all |
| Orphaned Usermeta | 0 | 0 KB | — |
| Auto-Drafts | 12 | 15 KB | Purge all |
| Trashed Posts | 8 | 45 KB | Purge all |
| Spam Comments | 156 | 78 KB | Purge all |

**Total recoverable:** ~4.6 MB

Buttons: "Purge Selected" / "Purge All"

### 8.5 WP-CLI Commands

| Command | Description |
|---------|-------------|
| `wp fanxie db status` | Show counts and sizes for all cleanup items |
| `wp fanxie db clean --all` | Run all cleanup tasks |
| `wp fanxie db revisions --keep=5` | Purge revisions, keep 5 per post |
| `wp fanxie db transients` | Purge expired transients |
| `wp fanxie db orphans` | Purge all orphaned metadata |
| `wp fanxie db trash --days=30` | Purge trashed posts older than 30 days |
| `wp fanxie db spam --days=15` | Purge spam comments older than 15 days |

---

## 9. Module: Activity Log

### 9.1 Purpose

Log critical admin actions for security forensics, compliance, and debugging.

### 9.2 Events Logged

**Plugins:** install, activate, deactivate, delete, update  
**Themes:** install, activate, delete, update  
**Users:** create, delete, role change, profile update (admins)  
**Core:** WordPress update  
**Authentication:** successful login (admins), failed login (all), password reset request  
**Content:** permanent post/page deletion  
**Settings:** changes to `users_can_register`, `default_role`, `permalink_structure`, this plugin's settings

### 9.3 Storage

Custom table `{prefix}_fanxie_activity_log` with columns: id, timestamp, user_id, user_login, user_ip, action, object_type, object_id, object_name, details (JSON).

**Retention:** 90 days default, configurable, auto-pruned via Action Scheduler.

### 9.4 Admin UI

Searchable, filterable log viewer with CSV export.

---

## 10. Module: Asset Manager

### 10.1 Purpose

Reduce render-blocking resources and eliminate unnecessary scripts/styles.

### 10.2 Features

#### 10.2.1 Script Defer/Async

- Hook: `script_loader_tag`
- Per-handle toggles: `defer`, `async`, `delay`, `no change`
- Delay: load on user interaction (mouseover/scroll/keydown/touchstart)
- Safelist: jQuery and `wp-*` default to `no change`

#### 10.2.2 Conditional Unloading

- Hook: `wp_enqueue_scripts` at priority 999
- Rule engine: disable handles by page type, post type, URL pattern
- Discovery helper: admin bar tool listing enqueued handles

#### 10.2.3 Missing Image Dimensions

- Inject missing `width`/`height` from attachment metadata

#### 10.2.4 Lazy Loading

- Ensure `loading="lazy"` on below-the-fold images
- Exclude first N images

#### 10.2.5 Heartbeat API Control (NEW)

**Problem:** WordPress Heartbeat API polls every 15-60 seconds on admin pages. Resource-intensive on shared hosting.

**Settings:**
- Disable on frontend (rarely needed)
- Disable on post editor (most impactful)
- Reduce frequency (60s, 120s, 300s)
- Disable everywhere except post editor

```php
add_action( 'init', function() {
    $settings = get_option( 'fanxie_heartbeat_settings', [] );
    
    // Disable on frontend
    if ( ! empty( $settings['disable_frontend'] ) && ! is_admin() ) {
        wp_deregister_script( 'heartbeat' );
        return;
    }
    
    // Disable in admin (except post editor)
    if ( ! empty( $settings['disable_admin'] ) && is_admin() ) {
        global $pagenow;
        if ( ! in_array( $pagenow, [ 'post.php', 'post-new.php' ] ) ) {
            wp_deregister_script( 'heartbeat' );
            return;
        }
    }
});

// Reduce frequency
add_filter( 'heartbeat_settings', function( $settings ) {
    $interval = get_option( 'fanxie_heartbeat_interval', 60 );
    $settings['interval'] = $interval;
    return $settings;
});
```

#### 10.2.6 Disable Emoji Scripts (NEW)

**Problem:** `wp-emoji-release.min.js` and related resources load on every page. Most sites don't need them.

```php
add_action( 'init', function() {
    if ( ! get_option( 'fanxie_disable_emojis', true ) ) {
        return;
    }
    
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
    remove_action( 'admin_print_styles', 'print_emoji_styles' );
    remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
    remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
    remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
    
    add_filter( 'tiny_mce_plugins', function( $plugins ) {
        return array_diff( $plugins, [ 'wpemoji' ] );
    });
    
    add_filter( 'wp_resource_hints', function( $urls, $relation_type ) {
        if ( $relation_type === 'dns-prefetch' ) {
            $urls = array_filter( $urls, function( $url ) {
                return strpos( $url, 'https://s.w.org/images/core/emoji/' ) === false;
            });
        }
        return $urls;
    }, 10, 2 );
});
```

#### 10.2.7 Disable Embeds (NEW)

**Problem:** `wp-embed.min.js` loads for oEmbed functionality. Most sites don't embed other WP posts.

```php
add_action( 'init', function() {
    if ( ! get_option( 'fanxie_disable_embeds', true ) ) {
        return;
    }
    
    // Remove oEmbed discovery
    remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
    remove_action( 'wp_head', 'wp_oembed_add_host_js' );
    
    // Remove oEmbed REST API endpoint
    remove_action( 'rest_api_init', 'wp_oembed_register_route' );
    
    // Disable oEmbed auto-discovery
    add_filter( 'embed_oembed_discover', '__return_false' );
    
    // Remove embed rewrite rules
    add_filter( 'rewrite_rules_array', function( $rules ) {
        foreach ( $rules as $rule => $rewrite ) {
            if ( strpos( $rewrite, 'embed=true' ) !== false ) {
                unset( $rules[ $rule ] );
            }
        }
        return $rules;
    });
    
    // Dequeue embed script
    add_action( 'wp_footer', function() {
        wp_dequeue_script( 'wp-embed' );
    });
});
```

#### 10.2.8 Disable jQuery Migrate (NEW)

**Problem:** `jquery-migrate.min.js` loads for backward compatibility with old plugins. Modern plugins don't need it.

**Caution:** This can break older plugins/themes. Only enable if tested.

```php
add_action( 'wp_default_scripts', function( $scripts ) {
    if ( ! is_admin() && get_option( 'fanxie_disable_jquery_migrate', false ) ) {
        $scripts->remove( 'jquery' );
        $scripts->add( 'jquery', false, [ 'jquery-core' ], '3.7.1' );
    }
});
```

**Setting:** Disabled by default with warning that it may break things.

---

## 11. Module: Media Optimizer

> **DEFERRED — not in the v1.0 release (decided 2026-09-03).** This section is
> retained as a written spec for a future version; nothing in it is built and
> the module does not appear in the admin UI. See
> [`checklist-fanxie-wp-core.md`](./checklist-fanxie-wp-core.md) for the rationale.

### 11.1 Purpose

Reduce image file sizes through compression and modern format conversion (WebP, AVIF).

### 11.2 Features

- **On-upload compression:** Compress original + all sizes, backup original
- **WebP/AVIF generation:** Alongside original, stored in postmeta
- **Frontend delivery:** Rewrite `<img>` to `<picture>` with sources
- **Bulk processing:** WP-CLI + Action Scheduler, 3 jobs max concurrency
- **Quality defaults:** JPEG 82, WebP 80, AVIF 65

---

## 12. Module: Cloud Storage (R2 Offload)

> **DEFERRED — not in the v1.0 release (decided 2026-09-03).** This section is
> retained as a written spec for a future version; nothing in it is built and
> the module does not appear in the admin UI. See
> [`checklist-fanxie-wp-core.md`](./checklist-fanxie-wp-core.md) for the rationale.

### 12.1 Purpose

Offload media to Cloudflare R2 (or S3-compatible) to reduce origin disk usage and serve from edge CDN.

### 12.2 Features

- **Configuration:** Bucket, endpoint, credentials (recommend `wp-config.php` constants), CDN domain
- **On-upload offload:** After optimization, upload all sizes to R2
- **URL rewriting:** Hook attachment URLs + content filter
- **Local cleanup:** Keep indefinitely, delete after grace period, or delete immediately
- **Bulk offload:** WP-CLI with `--dry-run`

---

## 13. Admin Interface

### 13.1 Settings Page

- Location: **Settings → Fanxie WP Core**
- Tabbed by module (10 tabs)
- Each tab: enable/disable toggle, settings, status summary

### 13.2 Dashboard Widget

- Environment health overview
- Hardening checklist status
- Database maintenance summary (bloat size)
- Recent activity log entries (last 5)

### 13.3 Activity Log Page

- Submenu under Settings or top-level
- Searchable, filterable, CSV export

### 13.4 Media Library Integration

- Status column: optimization status, savings %
- Detail modal: sizes, formats, R2 status

### 13.5 Branding

- Settings page header: Fanxie Lab logo + plugin name
- Footer: "Built by Fanxie Lab" with links
- Optional badge: "Secured by Fanxie Lab" (toggleable)

---

## 14. WP-CLI Commands

**Namespace:** `wp fanxie` (short and memorable)

| Command | Description |
|---------|-------------|
| `wp fanxie status` | Overview of all modules |
| `wp fanxie hardening check` | Run hardening checks |
| `wp fanxie hardening fix --all` | Apply safe fixes |
| `wp fanxie environment check` | Run environment checks |
| `wp fanxie cron check` | Cron health + overdue events |
| `wp fanxie db status` | Database bloat summary |
| `wp fanxie db clean --all` | Run all cleanup tasks |
| `wp fanxie db revisions --keep=5` | Purge revisions |
| `wp fanxie log list` | Recent activity log |
| `wp fanxie log export --format=csv` | Export log |
| `wp fanxie media optimize` | Bulk optimize |
| `wp fanxie storage offload` | Bulk offload to R2 |
| `wp fanxie audit` | Full audit report |

---

## 15. Rollout Strategy

| Phase | Modules | Duration | Notes |
|-------|---------|----------|-------|
| 1 | Security Headers, Hardening, Login Protection | Week 1–2 | Core security, minimal risk |
| 2 | Turnstile, Environment Health | Week 3 | External integration, version checks |
| 3 | Database Maintenance, Activity Log | Week 4–5 | Custom tables, scheduled jobs |
| 4 | Asset Manager | Week 6–7 | Per-site QA required |
| 5 | Media Optimizer | Week 8–9 | Quality validation on product images |
| 6 | Cloud Storage | Week 10–11 | Migration from AMO |
| 7 | wp.org Submission | Week 12+ | Final audit, readme, screenshots |

---

## 16. Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| CSP breaks third-party scripts | High | Report-only default |
| jQuery Migrate removal breaks plugins | High | Disabled by default, warning in UI |
| Turnstile blocks legitimate users | Medium | Test mode, fail-open option |
| Database cleanup deletes wanted data | Medium | Dry-run mode, confirmation, backups reminder |
| Custom login slug forgotten | Medium | Email on change, WP-CLI recovery |
| Heartbeat disable breaks autosave | Medium | Keep enabled on post editor by default |
| Activity log bloats database | Low | Auto-prune, indexed columns |

---

## 17. Success Metrics

- **Security audit score:** ≥90/100
- **Hardening checklist:** All checks passing
- **Environment health:** All components on supported versions
- **Database bloat:** <50 MB on typical sites
- **Speed audit score:** ≥70/100
- **CrUX LCP:** <2.5s
- **CrUX CLS:** <0.1
- **Media savings:** ≥30% average
- **Deployment time:** <15 min per new site
- **wp.org (post-launch):** Active installs, rating, support response time

---

## 18. Scope after first build is done (all of the above)
- **Email notifications:** Alerts for failed login spikes, EOL software, SSL expiry
- **Telemetry:** Integrate with BeaconStat (full opt-in)
- **Google Fonts local hosting:** Detect Google Fonts CDN, offer download + local serving (GDPR)
- **SMTP check:** Detect PHP `mail()` vs proper SMTP, surface warning
- **Multisite support:** Network-wide settings with per-site overrides

## 19. Future Considerations (v2)

- **Two-factor authentication:** TOTP-based 2FA for admin users
- **DNS prefetch / preconnect hints:** Auto-detect third-party domains, inject resource hints
- **Gravatar privacy:** Option to disable or cache locally
- **REST API exposure:** Authenticated endpoints for health checks
- **White-labeling:** Allow agencies to fully rebrand
