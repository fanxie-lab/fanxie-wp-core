# Fanxie WP Core — Hooks Reference

Every custom action and filter exposed by the plugin lives here. Keep this file in sync with the code. Naming convention: `fanxie_wp_core/<area>/<verb>`.

Last updated for Phase 1.1.

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

## Adding a new hook

When you introduce a new action or filter:

1. Document it here using the same format (name, type, since, description, params, example).
2. Prefix the name with `fanxie_wp_core/<area>/`.
3. Add a `@since` docblock annotation at the hook's emission site.
4. If it is part of a user-facing API surface, note the contract stability in your PR description.
