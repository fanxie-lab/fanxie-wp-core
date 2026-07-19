// TypeScript contracts for the Login Protection module.
//
// These types mirror the PHP schema served by the `login_protection/*` AJAX
// sub-actions:
//   - config  → LoginProtection::get_default_config()
//   - log rows → LoginLogRepository
//   - ban rows → BanRepository
// Shape is frozen — coordinate any change with the WordPress development agent
// (see CLAUDE.md §3.4).

/** A single brute-force lockout tier: N failures → lock the subject for M minutes. */
export interface LockoutTier {
  threshold: number;
  lockout_minutes: number;
}

/** Toggle payload persisted under `fanxie_wp_core_login_protection_settings`. */
export interface LoginProtectionConfig {
  attempts: {
    enabled: boolean;
    trust_proxy: boolean;
    proxy_header: string;
    allowlist: string[];
    tiers: LockoutTier[];
    log_retention_days: number;
  };
  hide_login: {
    enabled: boolean;
    slug: string;
  };
  passwords: {
    enforce: boolean;
    min_length: number;
    require_mixed_case: boolean;
    require_number: boolean;
    require_symbol: boolean;
  };
  sessions: {
    enabled: boolean;
    /** Role slug → timeout in minutes. Always includes a `default` key. */
    timeouts: Record<string, number>;
  };
}

/**
 * Where the active login slug originates: the `FX_CORE_LOGIN_SLUG` wp-config
 * constant (`constant`) or the stored setting (`stored`).
 */
export type SlugSource = 'constant' | 'stored';

/** One row from the login-event history table. */
export interface LoginLogRow {
  id: number;
  event_type: string;
  ip: string;
  username: string;
  /** Resolved account id when the username matched a user, else null. */
  user_id: number | null;
  /** JSON-encoded context blob, or null when empty. */
  context: string | null;
  /** GMT datetime string (`Y-m-d H:i:s`). */
  created_at: string;
}

/** One row from the persistent ban table. */
export interface BanRow {
  id: number;
  subject_type: string;
  subject_value: string;
  reason: string | null;
  /** GMT expiry datetime, or null for an indefinite ban. */
  expires_at: string | null;
  created_at: string;
}

/** Subject dimension a ban / lockout action targets. */
export type BanSubjectType = 'ip' | 'username';

/** Envelope returned by `get-config` and `save-config`. */
export interface LoginProtectionConfigResponse {
  config: LoginProtectionConfig;
  slug_source: SlugSource;
  effective_slug: string;
}

/** Filters accepted by `login_protection/get-log`. */
export interface LoginLogFilters {
  event_type?: string;
  ip?: string;
  username?: string;
  /** Inclusive lower-bound datetime (GMT). */
  since?: string;
  /** Inclusive upper-bound datetime (GMT). */
  until?: string;
}

/** Envelope returned by `login_protection/get-log`. */
export interface LoginLogResponse {
  rows: LoginLogRow[];
  total: number;
  page: number;
  per_page: number;
}

/** Envelope returned by `add-ban` and `remove-ban`. */
export interface BanListResponse {
  rows: BanRow[];
  total: number;
}

/** Envelope returned by `login_protection/clear-lockout`. */
export interface ClearLockoutResponse {
  cleared: true;
}

/** Request payload for `login_protection/add-ban`. */
export interface AddBanPayload {
  subject_type: BanSubjectType;
  subject_value: string;
  reason?: string;
  /** Lifetime in minutes; omit for an indefinite ban. */
  ttl_minutes?: number;
}

/** Request payload for `remove-ban` and `clear-lockout`. */
export interface SubjectRef {
  subject_type: BanSubjectType;
  subject_value: string;
}
