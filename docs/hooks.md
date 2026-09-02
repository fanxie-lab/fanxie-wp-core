# Fanxie WP Core — Hooks Reference

Every custom action and filter exposed by the plugin lives here. Keep this file in sync with the code. Naming convention: `fanxie_wp_core/<area>/<verb>`.

Last updated for Phase 1.2.

---

## Actions

### `fanxie_wp_core/module/registered`

- **Type:** Action
- **Since:** 0.1.0
- **Fires:** Immediately after a module has been added to `ModuleRegistry`, regardless of whether it is enabled.
- **Params:**
  - `FanxieLab\WPCore\Modules\ModuleBase $module` — the freshly-registered module instance.
- **Example:**

  ```php
  add_action( 'fanxie_wp_core/module/registered', function ( $module ) {
      error_log( 'Fanxie module registered: ' . $module->id() );
  } );
  ```

---

### `fanxie_wp_core/security_headers/violation_recorded`

- **Type:** Action
- **Since:** 0.1.0-dev
- **Fires:** Inside `ViolationRepository::record()` after a CSP violation has been inserted or deduped into `{$wpdb->prefix}fanxie_core_csp_violations`.
- **Params:**
  - `FanxieLab\WPCore\Modules\SecurityHeaders\ViolationRecord $record` — the violation that was just persisted.
- **Example:**

  ```php
  add_action( 'fanxie_wp_core/security_headers/violation_recorded', function ( $record ) {
      // Forward critical directive violations to an external SIEM.
      if ( 'script-src' === $record->directive ) {
          my_siem_send( $record->to_array() );
      }
  } );
  ```

---

## Filters

### `fanxie_wp_core/admin/bootstrap`

- **Type:** Filter
- **Since:** 0.1.0
- **Fires:** Just before the bootstrap payload is serialised into `window.fanxieWPCore` for the Vue SPA.
- **Params:**
  - `array $bootstrap` — the payload. Keys include: `version`, `ajaxUrl`, `adminUrl`, `restUrl`, `nonce`, `assetsUrl`, `user`, `modules`, `i18n`.
- **Returns:** `array` — the (possibly mutated) payload. Do **not** remove keys the frontend relies on.
- **Example:**

  ```php
  add_filter( 'fanxie_wp_core/admin/bootstrap', function ( array $bootstrap ): array {
      $bootstrap['featureFlags'] = [ 'experimentalReports' => true ];
      return $bootstrap;
  } );
  ```

---

### `fanxie_wp_core/ajax/sub_actions`

- **Type:** Filter
- **Since:** 0.1.0
- **Fires:** Inside `AjaxRouter::dispatch()` before a sub-action handler is resolved.
- **Params:**
  - `array<string, array{callback: callable, cap: ?string}> $map` — sub-action slug → `[callback, cap]`.
- **Returns:** `array` — the (possibly mutated) handler map.
- **Example:**

  ```php
  add_filter( 'fanxie_wp_core/ajax/sub_actions', function ( array $map ): array {
      $map['my_extension_status'] = [
          'callback' => 'my_extension_status_handler',
          'cap'      => 'manage_options',
      ];
      return $map;
  } );
  ```

---

### `fanxie_wp_core/security_headers/headers`

- **Type:** Filter
- **Since:** 0.1.0-dev
- **Fires:** Inside `HeaderEmitter::build_headers()` immediately before the headers are sent — last chance for integrators to inject / remove / rewrite values.
- **Params:**
  - `array<string, string> $headers` — name → value map of headers about to be emitted.
  - `array<string, mixed> $config` — the sanitised module config that produced `$headers`.
- **Returns:** `array<string, string>` — the (possibly mutated) header map.
- **Example:**

  ```php
  add_filter( 'fanxie_wp_core/security_headers/headers', function ( array $headers ): array {
      $headers['X-Fanxie-Served-By'] = 'edge-eu-1';
      return $headers;
  } );
  ```

---

### `fanxie_wp_core/security_headers/csp_directives`

- **Type:** Filter
- **Since:** 0.1.0-dev
- **Fires:** Inside `CspPolicy::serialise()` right before the directive map becomes a header string.
- **Params:**
  - `array<string, array<int, string>> $directives` — directive name → list of values.
  - `string $mode` — active CSP mode (`off`, `report-only`, or `enforce`).
- **Returns:** `array<string, array<int, string>>` — the (possibly mutated) directive map.
- **Example:**

  ```php
  add_filter( 'fanxie_wp_core/security_headers/csp_directives', function ( array $directives ): array {
      $directives['connect-src'][] = 'https://api.example.com';
      return $directives;
  } );
  ```

---

### `fanxie_wp_core/security_headers/csp_presets`

- **Type:** Filter
- **Since:** 0.1.0-dev
- **Fires:** Inside `CspPresetLibrary::all()` — lets integrators add their own presets or replace built-in ones.
- **Params:**
  - `array<string, FanxieLab\WPCore\Modules\SecurityHeaders\Csp\Preset> $presets` — preset id → `Preset`.
- **Returns:** `array<string, Preset>` — entries that are not `Preset` instances are silently dropped.
- **Example:**

  ```php
  add_filter( 'fanxie_wp_core/security_headers/csp_presets', function ( array $presets ): array {
      $presets['hotjar'] = new \FanxieLab\WPCore\Modules\SecurityHeaders\Csp\Preset(
          'hotjar',
          'Hotjar',
          [ 'script-src' => [ 'https://static.hotjar.com' ] ],
      );
      return $presets;
  } );
  ```

---

### `fanxie_wp_core/security_headers/csp_emit_context`

- **Type:** Filter
- **Since:** 0.1.0-dev
- **Fires:** Inside `HeaderEmitter::emit()` before CSP headers are written out. Lets integrators override the default context guard that skips CSP on admin, AJAX, cron, and REST requests.
- **Params:**
  - `bool $should_emit` — current decision (`true` = CSP will be emitted). Defaults to `false` on admin / AJAX / cron / REST, `true` otherwise.
- **Returns:** `bool` — final decision.
- **Rationale:** The default public-site CSP does not allow `blob:` workers or `'unsafe-inline'` scripts. The WordPress admin (block editor workers, core inline scripts) would trip that policy and flood the violations table with non-actionable noise from trusted internals. AJAX, cron, and REST responses don't render an HTML document, so CSP is meaningless there.
- **Example:**

  ```php
  // Site has a tailored admin CSP — opt back into emission on admin.
  add_filter( 'fanxie_wp_core/security_headers/csp_emit_context', function ( bool $should_emit ): bool {
      if ( is_admin() ) {
          return true;
      }
      return $should_emit;
  } );
  ```

---

### `fanxie_wp_core/security_headers/rate_limit`

- **Type:** Filter
- **Since:** 0.1.0-dev
- **Fires:** Inside `CspReportController::handle()` before the per-IP rate-limit bucket is consulted.
- **Params:**
  - `array{0:int,1:int} $limits` — `[window_seconds, ceiling_per_window]`. Default `[60, 60]`.
- **Returns:** `array{0:int,1:int}` — the effective limit tuple.
- **Example:**

  ```php
  // Loosen the limit to 200 reports per minute per IP.
  add_filter( 'fanxie_wp_core/security_headers/rate_limit', function (): array {
      return [ 60, 200 ];
  } );
  ```

---

### `fanxie_wp_core/hardening/should_block_author_enum`

- **Type:** Filter
- **Since:** 0.2.0-dev
- **Fires:** Inside `UserEnumerationGuard::register_hooks()` when deciding whether to install the author-archive block for the current request.
- **Params:**
  - `bool $value` — current decision sourced from `user_enumeration.block_author_archive`.
- **Returns:** `bool` — final decision.
- **Example:**

  ```php
  // Allow author archives on a public blog only for a specific path.
  add_filter( 'fanxie_wp_core/hardening/should_block_author_enum', function ( bool $block ): bool {
      return ! str_starts_with( $_SERVER['REQUEST_URI'] ?? '', '/authors/' );
  } );
  ```

---

### `fanxie_wp_core/hardening/xmlrpc_allowed_ips`

- **Type:** Filter
- **Since:** 0.2.0-dev
- **Fires:** Inside `XmlRpcGate::allowed_ips()` when resolving the per-request IP allowlist in `restrict_ips` mode.
- **Params:**
  - `array<int, string> $clean` — sanitised allowlist from the module config.
  - `array<string, mixed> $config` — full module config snapshot.
- **Returns:** `array<int, string>` — final allowlist.
- **Example:**

  ```php
  // Pull the Jetpack IP list from a cached option at runtime.
  add_filter( 'fanxie_wp_core/hardening/xmlrpc_allowed_ips', function ( array $ips ): array {
      $dynamic = (array) get_option( 'my_jetpack_ip_cache', [] );
      return array_values( array_unique( array_merge( $ips, $dynamic ) ) );
  } );
  ```

---

### `fanxie_wp_core/hardening/uploads_dir`

- **Type:** Filter
- **Since:** 0.2.0-dev
- **Fires:** Inside `UploadsProtector::uploads_dir()` when resolving the protection target directory.
- **Params:**
  - `string $basedir` — directory resolved from `wp_upload_dir()['basedir']`.
- **Returns:** `string` — custom path (multisite / non-standard layouts).

---

### `fanxie_wp_core/hardening/root_htaccess_path`

- **Type:** Filter
- **Since:** 0.2.0-dev
- **Fires:** Inside `RootHtaccessWriter::__construct()` when resolving the root `.htaccess` path used for the readme/license server-layer block.
- **Params:**
  - `string $path` — default `ABSPATH . '/.htaccess'`.
- **Returns:** `string` — overridden absolute path (non-standard installs, subdirectory routing, etc.).
- **Example:**

  ```php
  // Point the writer at the parent-domain .htaccess on a subdir install.
  add_filter( 'fanxie_wp_core/hardening/root_htaccess_path', static function (): string {
      return '/var/www/html/.htaccess';
  } );
  ```

---

### `fanxie_wp_core/hardening/login_error_message`

- **Type:** Filter
- **Since:** 0.2.0-dev
- **Fires:** Inside `LoginErrorObfuscator::filter_message()` after a target error code has been detected and the generic message is about to be returned.
- **Params:**
  - `string $generic` — default replacement message.
  - `array<int, string> $codes` — WP error codes present on the current request.
- **Returns:** `string` — final message shown to the user.

---

### `fanxie_wp_core/uninstall/delete_data`

- **Type:** Filter
- **Since:** 0.1.0
- **Fires:** Inside `uninstall.php` after the user opt-in flag has been resolved.
- **Params:**
  - `bool $should_delete` — whether the plugin should wipe its options and custom tables.
- **Returns:** `bool` — overrides the opt-in decision programmatically.
- **Example:**

  ```php
  // Force a wipe regardless of the stored opt-in.
  add_filter( 'fanxie_wp_core/uninstall/delete_data', '__return_true' );
  ```

---

## Login Protection

### `fanxie_wp_core_login_protection_prune`

- **Type:** Action (WP-Cron event — flat name, not slash-namespaced)
- **Since:** 0.1.0-dev
- **Fires:** Daily via `wp_schedule_event()`. The module's handler prunes the
  `fanxie_core_login_log` table beyond the configured retention window and
  deletes expired rows from `fanxie_core_login_bans`.
- **Params:** none.
- **Note:** hook into this to run additional login-log/ban cleanup on the same
  daily schedule.

### AJAX sub-actions

All routed through the shared `fanxie_wp_core` admin-ajax action (nonce
`fanxie_wp_core_admin` + capability `manage_fanxie_wp_core` enforced by
`AjaxRouter`): `login_protection/get-config`, `save-config`, `get-log`,
`get-bans`, `add-ban`, `remove-ban`, `clear-lockout`.

### Recovery constant

- **`FX_CORE_LOGIN_SLUG`** — define in `wp-config.php` to override the stored
  custom login slug (recovers access if you're locked out); when set, the admin
  UI shows the slug read-only. Reveal the active slug with `wp fx-core login
  reveal`; clear a lockout/ban with `wp fx-core login unlock <ip|username>`.

---

## Adding a new hook

When you introduce a new action or filter:

1. Document it here using the same format (name, type, since, description, params, example).
2. Prefix the name with `fanxie_wp_core/<area>/`.
3. Add a `@since` docblock annotation at the hook's emission site.
4. If it is part of a user-facing API surface, note the contract stability in your PR description.
