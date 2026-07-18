// TypeScript contracts for the Hardening module.
//
// These types mirror the PHP schema served by the `hardening/*` AJAX
// sub-actions. Shape is frozen — coordinate any change with the WordPress
// development agent (see CLAUDE.md §3.4).

/** XML-RPC enforcement mode. `off` leaves WP behaviour untouched. */
export type XmlRpcMode =
  | 'disabled'
  | 'restrict_methods'
  | 'restrict_ips'
  | 'off';

/** Detected web-server family. `unknown` when detection is inconclusive. */
export type ServerType = 'apache' | 'nginx' | 'litespeed' | 'iis' | 'unknown';

/** Toggle payload persisted under `fanxie_wp_core_hardening_settings`. */
export interface HardeningConfig {
  user_enumeration: {
    block_author_archive: boolean;
    block_rest_users_endpoint: boolean;
  };
  xmlrpc: {
    mode: XmlRpcMode;
    allowed_ips: string[];
  };
  version_hiding: {
    remove_powered_by: boolean;
    remove_wp_generator: boolean;
    remove_rss_generator: boolean;
    strip_version_query: boolean;
    block_readme_license: boolean;
  };
  uploads: {
    drop_index: boolean;
    block_php_execution: boolean;
  };
  login: {
    obfuscate_errors: boolean;
  };
  file_editing: {
    runtime_enforce: boolean;
  };
  application_passwords: {
    disable: boolean;
  };
}

/** Runtime status — derived on the PHP side from current settings. */
export interface HardeningStatus {
  /** True when at least one hardening feature is active. */
  active: boolean;
  /** Server-translated summary, e.g. "8 of 12 active". */
  summary: string;
  /** Non-fatal warnings surfaced by the server (string messages). */
  warnings: string[];
}

/**
 * Probe results from `StatusInspector`. Represents observed state of the
 * site at detection time. Boolean-or-null fields use `null` to signal an
 * inconclusive probe (e.g., the uploads probe couldn't reach the host).
 */
export interface ChecksResult {
  server_type: ServerType;
  x_powered_by_present: boolean;
  disallow_file_edit_defined: boolean;
  disallow_file_edit_value: boolean;
  uploads_dir_listable: boolean | null;
  uploads_php_executable: boolean | null;
  uploads_htaccess_exists: boolean;
  uploads_index_exists: boolean;
  application_passwords_count: number;
  /** Users holding ≥1 Application Password, site-wide. */
  application_passwords_users: { user_login: string; count: number }[];
  /**
   * Front-door probe for /readme.html. `true` = blocked, `false` = still
   * served, `null` = probe could not reach the host (inconclusive).
   */
  readme_blocked: boolean | null;
  /** Front-door probe for /license.txt. Same tri-state as readme_blocked. */
  license_blocked: boolean | null;
  /** Unix timestamp (seconds) of the probe. */
  probed_at: number;
}

/** Envelope returned by every Hardening AJAX action. */
export interface HardeningResponse {
  settings: HardeningConfig;
  status: HardeningStatus;
  checks: ChecksResult;
}

/** Target identifier accepted by `hardening/apply-fix`. */
export type ApplyFixTarget = 'uploads_index' | 'uploads_htaccess';

/**
 * Target identifier accepted by `hardening/drop-upload-guard` and
 * `hardening/remove-upload-guard`. Mirrors `ApplyFixTarget` intentionally —
 * the drop/remove endpoints supersede the one-way "fix" endpoint with
 * explicit bidirectional control.
 */
export type UploadsGuardTarget = 'uploads_index' | 'uploads_htaccess';

/**
 * Derived per-row visual state for the checklist UI. Lives in the shared
 * types module (not on ChecklistItem.vue) so strict-type-checked lint
 * resolves it cleanly — type re-export through a .vue SFC currently breaks
 * under typescript-eslint strictTypeChecked.
 */
export type ChecklistStatus = 'active' | 'inactive' | 'warning';
