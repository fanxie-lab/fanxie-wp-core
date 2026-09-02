// Pure presentation helpers for the Environment Health module.
//
// Kept out of the components so the wording rules — which carry most of this
// module's accessibility weight — are unit-testable without mounting anything.

import type { StatusPillVariant } from '@/components';
import type {
  CheckGroup,
  CheckStatus,
  HealthCheck,
  HealthCounts,
  ThresholdKey,
} from './types';

/** Render order for the group cards. Mirrors PRD §7.2 → §7.5. */
export const CHECK_GROUP_ORDER: readonly CheckGroup[] = [
  'versions',
  'cron',
  'debug',
  'plugins_themes',
] as const;

/** Card headings. Our own chrome, so authored here rather than sent by PHP. */
export const CHECK_GROUP_LABELS: Readonly<Record<CheckGroup, string>> = {
  versions: 'Versions',
  cron: 'Cron',
  debug: 'Debug mode',
  plugins_themes: 'Plugins and themes',
};

/**
 * Severity ranking used to pick a group's (and the report's) headline status.
 *
 * `unknown` sits above `ok` but below `warning`: a check that could not run is
 * worth surfacing, but it is not evidence of a problem and must never
 * outrank a real warning or a critical finding.
 */
const STATUS_SEVERITY: Readonly<Record<CheckStatus, number>> = {
  ok: 0,
  unknown: 1,
  warning: 2,
  critical: 3,
};

/**
 * Pill presentation per status.
 *
 * Status is never communicated by colour alone — every pill carries text, and
 * StatusPill adds its own icon plus a screen-reader prefix. `unknown` maps to
 * the neutral variant so it reads as "we didn't get an answer", clearly apart
 * from both the green ok and the red critical.
 */
export function statusPill(status: CheckStatus): {
  variant: StatusPillVariant;
  label: string;
  srPrefix: string;
} {
  switch (status) {
    case 'ok':
      return { variant: 'ok', label: 'OK', srPrefix: 'Status: OK.' };
    case 'warning':
      return {
        variant: 'warn',
        label: 'Warning',
        srPrefix: 'Status: warning.',
      };
    case 'critical':
      return {
        variant: 'critical',
        label: 'Critical',
        srPrefix: 'Status: critical.',
      };
    default:
      return {
        variant: 'neutral',
        label: "Couldn't check",
        srPrefix: 'Status: could not be checked.',
      };
  }
}

/** Highest-severity status across a set of checks. Empty set → `unknown`. */
export function worstStatus(checks: readonly HealthCheck[]): CheckStatus {
  let worst: CheckStatus = 'unknown';
  let seen = false;
  for (const check of checks) {
    if (!seen || STATUS_SEVERITY[check.status] > STATUS_SEVERITY[worst]) {
      worst = check.status;
      seen = true;
    }
  }
  return worst;
}

/** Highest-severity status implied by a counts block. */
export function worstStatusFromCounts(counts: HealthCounts): CheckStatus {
  if (counts.critical > 0) return 'critical';
  if (counts.warning > 0) return 'warning';
  if (counts.unknown > 0) return 'unknown';
  if (counts.ok > 0) return 'ok';
  return 'unknown';
}

function plural(count: number, one: string, many: string): string {
  return count === 1 ? one : many;
}

/**
 * Turn the four tallies into one sentence a screen reader can read straight
 * through. Four bare numbers on chips are meaningless out of visual context,
 * so this string is what actually goes into the accessibility tree; the chips
 * are `aria-hidden` decoration of the same data.
 */
export function buildCountsSentence(counts: HealthCounts): string {
  const total = counts.ok + counts.warning + counts.critical + counts.unknown;
  if (total === 0) {
    return 'No environment checks have run yet.';
  }

  const parts: string[] = [];
  if (counts.ok > 0) parts.push(`${String(counts.ok)} OK`);
  if (counts.warning > 0) {
    parts.push(
      `${String(counts.warning)} ${plural(counts.warning, 'warning', 'warnings')}`,
    );
  }
  if (counts.critical > 0) {
    parts.push(
      `${String(counts.critical)} critical ${plural(counts.critical, 'issue', 'issues')}`,
    );
  }
  if (counts.unknown > 0) {
    parts.push(`${String(counts.unknown)} that could not be checked`);
  }

  const noun = plural(total, 'check', 'checks');
  return `${String(total)} ${noun}: ${parts.join(', ')}.`;
}

const MINUTE = 60;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

/**
 * Relative "last checked" wording.
 *
 * A cached report must never read as a live one, so the age is always spelled
 * out rather than left implicit.
 *
 * @param generatedAt Unix timestamp in SECONDS (as PHP sends it).
 * @param now         Current time in MILLISECONDS. Injectable for tests.
 */
export function formatLastChecked(
  generatedAt: number,
  now: number = Date.now(),
): string {
  if (!Number.isFinite(generatedAt) || generatedAt <= 0) {
    return 'never';
  }
  // Clock skew between PHP and the browser can push this negative; clamp so we
  // never render "in -3 minutes".
  const elapsed = Math.max(0, Math.floor(now / 1000) - Math.floor(generatedAt));

  if (elapsed < MINUTE) return 'just now';
  if (elapsed < HOUR) {
    const minutes = Math.floor(elapsed / MINUTE);
    return `${String(minutes)} ${plural(minutes, 'minute', 'minutes')} ago`;
  }
  if (elapsed < DAY) {
    const hours = Math.floor(elapsed / HOUR);
    return `${String(hours)} ${plural(hours, 'hour', 'hours')} ago`;
  }
  const days = Math.floor(elapsed / DAY);
  return `${String(days)} ${plural(days, 'day', 'days')} ago`;
}

/** Absolute local time, shown alongside the relative wording. */
export function formatAbsoluteTime(generatedAt: number): string {
  if (!Number.isFinite(generatedAt) || generatedAt <= 0) return '';
  return new Date(generatedAt * 1000).toLocaleString();
}

/** ISO-8601 value for a `<time datetime>` attribute. */
export function formatIsoTime(generatedAt: number): string {
  if (!Number.isFinite(generatedAt) || generatedAt <= 0) return '';
  return new Date(generatedAt * 1000).toISOString();
}

/**
 * Allow only http(s) remediation links.
 *
 * Remediation URLs come from PHP rather than a form field, but rendering an
 * href straight into the DOM is exactly the place where a `javascript:` or
 * `data:` URL would become executable, so the check happens at the point of
 * output regardless of how trusted the source is (CLAUDE.md §3.2).
 */
export function isSafeUrl(url: string): boolean {
  // A blank value would resolve against the origin and render a link back to
  // the current admin page — useless as a remediation, so reject it up front.
  if (url.trim() === '') return false;
  try {
    const parsed = new URL(url, window.location.origin);
    return parsed.protocol === 'http:' || parsed.protocol === 'https:';
  } catch {
    return false;
  }
}

// --- Meta lists -------------------------------------------------------------

/**
 * One named list of items lifted out of a check's `meta`.
 *
 * Exists because the summary sentence can only name a handful of items before
 * it stops being a sentence: PHP spells out three or fewer inline and falls
 * back to a bare count above that, so the expanded row is the *only* place a
 * long list can actually be read. Rendering it is the point, not a garnish.
 */
export interface MetaList {
  /** Stable render key — the `meta` key the items came from. */
  key: string;
  /** Heading for the list. Our own chrome, so authored here, not sent by PHP. */
  label: string;
  /**
   * Optional severity tint. Never the only signal: the label text itself says
   * "Critical" / "Warning", so a tone of `null` loses nothing but colour.
   */
  tone: 'warn' | 'critical' | null;
  /** The names, in the order PHP sent them. Never empty — see buildMetaLists. */
  items: string[];
  /** How many items the server dropped past its cap. Always >= 0. */
  omitted: number;
}

/** Where one renderable list lives inside a check's `meta`. */
interface MetaListSpec {
  namesKey: string;
  omittedKey: string;
  label: string;
  tone: 'warn' | 'critical' | null;
}

/**
 * Which `meta` keys carry a renderable list, per check id.
 *
 * A table rather than a naming convention because the convention is not
 * actually uniform on the wire (`names` pairs with `names_omitted`, but
 * `critical_names` pairs with `critical_omitted`), and because the heading has
 * to come from somewhere regardless. Adding a Phase 3 check is one row here —
 * the renderer itself knows nothing about plugins or themes.
 *
 * `abandoned_plugins` is split into two entries on purpose. Merging them would
 * throw away the one thing that check exists to say: some of these plugins are
 * further gone than others.
 */
const META_LIST_SPECS: Readonly<Record<string, readonly MetaListSpec[]>> = {
  inactive_plugins: [
    {
      namesKey: 'names',
      omittedKey: 'names_omitted',
      label: 'Inactive plugins',
      tone: null,
    },
  ],
  inactive_themes: [
    {
      namesKey: 'names',
      omittedKey: 'names_omitted',
      label: 'Unused themes',
      tone: null,
    },
  ],
  abandoned_plugins: [
    {
      namesKey: 'critical_names',
      omittedKey: 'critical_omitted',
      label: 'Critical',
      tone: 'critical',
    },
    {
      namesKey: 'warning_names',
      omittedKey: 'warning_omitted',
      label: 'Warning',
      tone: 'warn',
    },
  ],
};

/** Widen an unknown value to an array without letting `any` leak out of it. */
function asUnknownArray(value: unknown): unknown[] | null {
  return Array.isArray(value) ? (value as unknown[]) : null;
}

/**
 * Read a `meta` key as a list of names.
 *
 * `meta` is decoded JSON, so its declared type is a contract rather than a
 * guarantee — every branch here is a real possibility on the wire. A key that
 * is missing, holds a scalar where an array was promised, or holds an array of
 * the wrong thing all collapse to the same answer: an empty list, which the
 * caller renders as nothing at all. One malformed check must not take down the
 * whole report.
 *
 * Entries are trimmed and blanks dropped (PHP's own `array_filter` does the
 * same), but nothing is ever split: a name containing a comma is one item.
 */
export function readMetaNames(
  meta: Record<string, unknown> | undefined,
  key: string,
): string[] {
  if (meta === undefined) return [];

  const raw = asUnknownArray(meta[key]);
  if (raw === null) return [];

  const names: string[] = [];
  for (const entry of raw) {
    if (typeof entry !== 'string') continue;
    const trimmed = entry.trim();
    if (trimmed !== '') names.push(trimmed);
  }
  return names;
}

/**
 * Read a `meta` key as a non-negative integer count.
 *
 * Used for the `*_omitted` counters, which exist precisely so the UI never has
 * to derive "+N more" by subtracting a rendered list length from a display
 * string. Anything that is not a finite number reads as 0 — i.e. "nothing was
 * dropped", the same answer a missing key gives, which is the safe way to be
 * wrong: at worst the truncation notice is absent, never invented.
 */
export function readMetaCount(
  meta: Record<string, unknown> | undefined,
  key: string,
): number {
  if (meta === undefined) return 0;

  const raw = meta[key];
  if (typeof raw !== 'number' || !Number.isFinite(raw)) return 0;

  return Math.max(0, Math.floor(raw));
}

/**
 * Every renderable list on a check, in spec order.
 *
 * A list with no items is omitted entirely rather than returned empty: an
 * empty array on the wire is a real answer ("nothing to show"), and it must
 * produce no heading and no container, not an empty one.
 */
export function buildMetaLists(check: HealthCheck): MetaList[] {
  const specs = META_LIST_SPECS[check.id];
  if (specs === undefined) return [];

  const lists: MetaList[] = [];
  for (const spec of specs) {
    const items = readMetaNames(check.meta, spec.namesKey);
    if (items.length === 0) continue;

    lists.push({
      key: spec.namesKey,
      label: spec.label,
      tone: spec.tone,
      items,
      omitted: readMetaCount(check.meta, spec.omittedKey),
    });
  }
  return lists;
}

/**
 * Wording for the truncation notice, or `''` when nothing was dropped.
 *
 * Truncation is never silent: without this the rendered list would quietly
 * disagree with the count in the row's `value`, and the reader would have no
 * way to tell a short list from a capped one.
 */
export function formatOmitted(omitted: number): string {
  if (!Number.isFinite(omitted) || omitted <= 0) return '';
  return `and ${String(Math.floor(omitted))} more`;
}

// --- Threshold bounds -------------------------------------------------------

/** Min/max/step for one numeric threshold field. */
export interface ThresholdBounds {
  min: number;
  max: number;
  step: number;
}

/**
 * Client-side bounds for the four numeric thresholds.
 *
 * The server sanitises these with `absint`, which guarantees only "a
 * non-negative integer" — and turns anything non-numeric (an empty field, a
 * pasted word) into **0**. Zero is not a harmless default here: a
 * `cron_overdue_minutes` of 0 marks every scheduled event overdue the instant
 * it is due, and an `ssl_expiry_warning_days` of 0 never warns at all. So the
 * floor is 1 (or higher where a smaller number is meaningless), and the client
 * is what enforces it — the server has no upper bound to lean on either.
 *
 * Ceilings are chosen from what the value can usefully mean: a public TLS
 * certificate cannot be valid for more than ~398 days, cron overdue past a
 * full day is just "broken", and ten years covers any abandoned-plugin policy.
 */
export const THRESHOLD_BOUNDS: Readonly<Record<ThresholdKey, ThresholdBounds>> =
  {
    ssl_expiry_warning_days: { min: 1, max: 365, step: 1 },
    cron_overdue_minutes: { min: 1, max: 1440, step: 1 },
    abandoned_warning_days: { min: 30, max: 3650, step: 1 },
    abandoned_critical_days: { min: 30, max: 3650, step: 1 },
  };

/**
 * Coerce raw field input into an integer that is safe to put in the store.
 *
 * Returns `null` when the input cannot become a number at all (empty field,
 * `NaN`, `Infinity`, a pasted word). Callers treat `null` as "keep the last
 * committed value" rather than writing it — the store must never hold `NaN` or
 * `''` for a field the PHP side will run through `absint`.
 *
 * Anything numeric is floored to an integer (the schema is integer-only) and
 * clamped into range, so a committed value is always one the server will
 * accept unchanged.
 */
export function normalizeThreshold(
  raw: unknown,
  bounds: ThresholdBounds,
): number | null {
  if (typeof raw === 'string' && raw.trim() === '') return null;
  if (raw === null || raw === undefined || typeof raw === 'boolean')
    return null;

  const parsed = Number(raw);
  if (!Number.isFinite(parsed)) return null;

  const integer = Math.floor(parsed);
  if (integer < bounds.min) return bounds.min;
  if (integer > bounds.max) return bounds.max;
  return integer;
}

/**
 * True when the abandoned-plugin bands are inverted.
 *
 * A critical cut-off below the warning cut-off means the warning band swallows
 * the critical one and no plugin is ever flagged critical. The server accepts
 * both numbers happily (each passes `absint` on its own), so this cross-field
 * rule only exists here.
 */
export function abandonedBandsInverted(
  warningDays: number,
  criticalDays: number,
): boolean {
  return criticalDays < warningDays;
}
