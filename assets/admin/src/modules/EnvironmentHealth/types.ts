// TypeScript contracts for the Environment Health module.
//
// These types mirror the PHP schema served by the `environment-health/*` AJAX
// sub-actions. Shape is FROZEN — coordinate any change with the WordPress
// development agent (see CLAUDE.md §3.4 and PRD §7).
//
// Wire contract:
//   environment-health/get-report   → HealthReport
//   environment-health/refresh      → HealthReport   (busts the server cache)
//   environment-health/get-config   → EnvironmentHealthConfig
//   environment-health/save-config  → HealthReport   (payload: { settings })
//
// Every human-readable string on a HealthCheck (`label`, `summary`, `detail`,
// and a Remediation's `label`) arrives ALREADY TRANSLATED from PHP. The SPA
// renders them verbatim and never re-translates or re-words them.

/**
 * Verdict for a single check.
 *
 * `unknown` is a first-class state, not an error: some checks legitimately
 * cannot run on some hosts (SSL expiry needs an outbound socket; the wp.org
 * scan needs an outbound HTTP request). It must read as "couldn't check" and
 * stay visually distinct from both `ok` and `critical`.
 */
export type CheckStatus = 'ok' | 'warning' | 'critical' | 'unknown';

/** Card grouping. Mirrors PRD §7.2–§7.5. */
export type CheckGroup = 'versions' | 'cron' | 'debug' | 'plugins_themes';

/** A single recommended action attached to a check. */
export interface Remediation {
  kind: 'snippet' | 'link';
  /** Present on snippets; drives the language badge on the code block. */
  language?: 'php' | 'bash' | 'nginx';
  /** Present on snippets: the copy-paste-ready body. */
  code?: string;
  /** Present on links. */
  url?: string;
  /** Visible link text. Falls back to the raw URL when absent. */
  label?: string;
}

/** One row in a group card. */
export interface HealthCheck {
  /** Stable snake_case key, e.g. `php_version`. Used as the render key. */
  id: string;
  group: CheckGroup;
  /** Pre-translated by PHP. */
  label: string;
  status: CheckStatus;
  /** Display-ready observed value, e.g. `8.2.14`. May be empty. */
  value: string;
  /** One-line verdict. Pre-translated by PHP. */
  summary: string;
  /** Longer explanation shown in the expanded region. Pre-translated by PHP. */
  detail?: string;
  remediation?: Remediation[];
  /**
   * Extra structured data about the finding, keyed by whatever the emitting
   * check chose to send.
   *
   * Deliberately loose, and deliberately NOT to be trusted as declared: this
   * arrives as decoded JSON from PHP, so the declared union describes the
   * contract, not a guarantee. Read it through the narrowing helpers in
   * `format.ts` (`readMetaNames` / `readMetaCount`) rather than indexing it
   * directly — a key that arrives with the wrong type must degrade to
   * "render nothing", never throw and take the whole report down with it.
   *
   * `string[]` is in the union so a check can hand over a real list of names.
   * Names routinely contain commas ("Foo, Inc. Toolkit"), which is exactly why
   * the wire format is an array and not a pre-joined string — nothing on this
   * side may re-join or re-split it. Lists are capped server-side; the
   * companion `*_omitted` count says how many were dropped.
   *
   * Keys currently sent (see `Runtime/PluginThemeInspector.php`):
   *   inactive_plugins  → names, names_omitted
   *   inactive_themes   → names, names_omitted
   *   abandoned_plugins → critical_names, critical_omitted,
   *                       warning_names,  warning_omitted
   */
  meta?: Record<string, string | number | boolean | null | string[]>;
}

/** Per-status tallies across the whole report. */
export interface HealthCounts {
  ok: number;
  warning: number;
  critical: number;
  unknown: number;
}

/** Full report envelope. */
export interface HealthReport {
  /** Unix timestamp (seconds) the report was generated. */
  generated_at: number;
  /** Unix timestamp (seconds) the server-side cache expires. */
  cached_until: number;
  counts: HealthCounts;
  checks: HealthCheck[];
}

/**
 * Persisted settings for the module.
 *
 * Mirrors `get_default_config()` / `get_settings_fields()` in
 * `src/Modules/EnvironmentHealth/EnvironmentHealth.php`. The PHP schema
 * addresses these with dotted ids (`checks.versions`,
 * `thresholds.cron_overdue_minutes`), which is why the nesting here is not
 * cosmetic — flattening it would break the server-side sanitiser's path
 * lookup. `sanitize_config()` drops unknown keys and fills missing paths from
 * the schema default, so this interface must stay exhaustive or a save would
 * silently reset whatever it omits.
 */
export interface EnvironmentHealthConfig {
  /** Per-group master switches. A group that is off contributes no checks. */
  checks: {
    versions: boolean;
    cron: boolean;
    debug: boolean;
    plugins_themes: boolean;
  };
  /**
   * When on, the abandoned-plugin scan queries api.wordpress.org for each
   * active plugin's `last_updated` date (PRD §7.5.3). One of the module's two
   * outbound requests, so the UI states that plainly.
   */
  wporg_scan_enabled: boolean;
  /**
   * When on, the TLS expiry probe opens a short-lived connection to this site
   * to read its certificate. Named in readme.txt as "Check the TLS
   * certificate" — the label here must keep matching that wording.
   */
  ssl_check_enabled: boolean;
  /** When on, the report is mirrored into a wp-admin dashboard widget. */
  dashboard_widget: boolean;
  /**
   * Tuning knobs for the checks that grade on a scale. Sanitised server-side
   * with `absint`, which means a non-numeric value silently becomes 0 — so the
   * client is responsible for never sending one (see `normalizeThreshold`).
   */
  thresholds: {
    ssl_expiry_warning_days: number;
    cron_overdue_minutes: number;
    abandoned_warning_days: number;
    abandoned_critical_days: number;
  };
}

/** Dotted key of a single threshold, matching the PHP schema field ids. */
export type ThresholdKey = keyof EnvironmentHealthConfig['thresholds'];
