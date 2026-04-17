// TypeScript contracts for the Security Headers module.
//
// These types mirror the PHP schema served by the `security-headers/*` AJAX
// sub-actions. Shape is frozen — coordinate any change with the WordPress
// development agent (see CLAUDE.md §3.4).

/** CSP enforcement mode. `off` suppresses the header entirely. */
export type CspMode = 'off' | 'report-only' | 'enforce';

/**
 * Map of CSP directive name → source list.
 * Keys are directive names like `default-src`, `script-src`, `connect-src`.
 * Values are arrays of source-list tokens (`'self'`, URLs, `'unsafe-inline'`, etc.).
 */
export type CspDirectiveMap = Record<string, string[]>;

/** Non-CSP header configuration. */
export interface SecurityHeadersConfig {
  headers: {
    hsts: {
      enabled: boolean;
      max_age: number;
      include_subdomains: boolean;
    };
    xfo: {
      enabled: boolean;
      value: 'SAMEORIGIN' | 'DENY';
    };
    xcto: {
      enabled: boolean;
    };
    referrer: {
      enabled: boolean;
      value: string;
    };
    permissions: {
      enabled: boolean;
      value: string;
    };
    cache_control: {
      enabled: boolean;
      value?: string;
    };
  };
  csp: {
    mode: CspMode;
    learning_mode: boolean;
    directives: CspDirectiveMap;
    /** Derived on the PHP side. Shown read-only in the UI. */
    report_uri: string;
  };
}

/** Runtime status — derived on the PHP side from request + emitted headers. */
export interface SecurityHeadersStatus {
  is_https: boolean;
  hsts_detected: boolean;
  csp_detected: boolean;
  report_endpoint: string;
}

/** One row from the CSP violation log. */
export interface ViolationRecord {
  id: number;
  /** ISO-8601 timestamp of first occurrence. */
  created_at: string;
  /** ISO-8601 timestamp of most recent occurrence. */
  last_seen_at: string;
  directive: string;
  blocked_uri: string;
  document_uri: string;
  source_file: string | null;
  line_number: number | null;
  user_agent: string | null;
  count: number;
}

/** Preset catalogue entry. */
export interface Preset {
  id: 'woocommerce' | 'ga-gtm' | 'meta-pixel';
  label: string;
  description: string;
}

/** Response envelope for `get-config`, `save-config`, `apply-preset`. */
export interface ConfigResponse {
  enabled: boolean;
  settings: SecurityHeadersConfig;
  status: SecurityHeadersStatus;
}

/** Response envelope for `list-violations`. */
export interface ViolationsResponse {
  rows: ViolationRecord[];
  total: number;
  page: number;
  per_page: number;
}

/** Response envelope for `purge-violations`. */
export interface PurgeResponse {
  deleted: number;
}

/** Filters applied when listing violations. */
export interface ViolationFilters {
  directive?: string;
  since?: string;
  until?: string;
}

/**
 * Static preset catalogue — derived from PRD §3.3. Kept client-side so the UI
 * can render preset choices without a round-trip. The actual directive merging
 * still happens server-side via `security-headers/apply-preset`.
 */
export const PRESETS: readonly Preset[] = [
  {
    id: 'woocommerce',
    label: 'WooCommerce + payment gateways',
    description:
      'Allows Stripe, PayPal, and WooCommerce extension scripts commonly used in checkout.',
  },
  {
    id: 'ga-gtm',
    label: 'Google Analytics & GTM',
    description:
      'Permits Google Tag Manager, GA4, and the associated data-collection endpoints.',
  },
  {
    id: 'meta-pixel',
    label: 'Meta Pixel',
    description:
      'Permits Facebook/Meta Pixel tracking scripts and connection endpoints.',
  },
] as const;
