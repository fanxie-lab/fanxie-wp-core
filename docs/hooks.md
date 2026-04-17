# Fanxie WP Core — Hooks Reference

Every custom action and filter exposed by the plugin lives here. Keep this file in sync with the code. Naming convention: `fanxie_wp_core/<area>/<verb>`.

Last updated for Phase 0.1.

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
