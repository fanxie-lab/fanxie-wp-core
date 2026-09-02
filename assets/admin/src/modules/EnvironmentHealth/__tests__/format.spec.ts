import { describe, expect, it } from 'vitest';
import {
  abandonedBandsInverted,
  buildCountsSentence,
  CHECK_GROUP_LABELS,
  CHECK_GROUP_ORDER,
  formatAbsoluteTime,
  formatIsoTime,
  formatLastChecked,
  isSafeUrl,
  normalizeThreshold,
  statusPill,
  THRESHOLD_BOUNDS,
  worstStatus,
  worstStatusFromCounts,
} from '../format';
import type { CheckStatus, HealthCheck } from '../types';

function check(status: CheckStatus, id: string = status): HealthCheck {
  return {
    id,
    group: 'versions',
    label: id,
    status,
    value: '',
    summary: '',
  };
}

describe('statusPill', () => {
  it('gives every status a text label so colour is never the only signal', () => {
    const statuses: CheckStatus[] = ['ok', 'warning', 'critical', 'unknown'];
    for (const status of statuses) {
      const pill = statusPill(status);
      expect(pill.label.length).toBeGreaterThan(0);
      expect(pill.srPrefix.length).toBeGreaterThan(0);
    }
  });

  it('maps ok / warning / critical onto their semantic pill variants', () => {
    expect(statusPill('ok').variant).toBe('ok');
    expect(statusPill('warning').variant).toBe('warn');
    expect(statusPill('critical').variant).toBe('critical');
  });

  it('renders unknown as a neutral "could not check", distinct from ok and critical', () => {
    const unknown = statusPill('unknown');
    expect(unknown.variant).toBe('neutral');
    expect(unknown.variant).not.toBe(statusPill('ok').variant);
    expect(unknown.variant).not.toBe(statusPill('critical').variant);
    expect(unknown.label).toBe("Couldn't check");
    expect(unknown.srPrefix).toContain('could not be checked');
  });
});

describe('worstStatus', () => {
  it('returns unknown for an empty set', () => {
    expect(worstStatus([])).toBe('unknown');
  });

  it('ranks critical above everything', () => {
    expect(
      worstStatus([check('ok'), check('critical'), check('warning')]),
    ).toBe('critical');
  });

  it('ranks warning above unknown — a check that could not run is not a finding', () => {
    expect(worstStatus([check('unknown'), check('warning')])).toBe('warning');
  });

  it('ranks unknown above ok so it still surfaces', () => {
    expect(worstStatus([check('ok'), check('unknown')])).toBe('unknown');
  });

  it('returns ok when every check passed', () => {
    expect(worstStatus([check('ok', 'a'), check('ok', 'b')])).toBe('ok');
  });
});

describe('worstStatusFromCounts', () => {
  it('follows the same ranking as worstStatus', () => {
    expect(
      worstStatusFromCounts({ ok: 4, warning: 1, critical: 1, unknown: 1 }),
    ).toBe('critical');
    expect(
      worstStatusFromCounts({ ok: 4, warning: 1, critical: 0, unknown: 1 }),
    ).toBe('warning');
    expect(
      worstStatusFromCounts({ ok: 4, warning: 0, critical: 0, unknown: 1 }),
    ).toBe('unknown');
    expect(
      worstStatusFromCounts({ ok: 4, warning: 0, critical: 0, unknown: 0 }),
    ).toBe('ok');
  });

  it('reports unknown when nothing ran at all', () => {
    expect(
      worstStatusFromCounts({ ok: 0, warning: 0, critical: 0, unknown: 0 }),
    ).toBe('unknown');
  });
});

describe('buildCountsSentence', () => {
  it('reads as one sentence rather than four loose numbers', () => {
    expect(
      buildCountsSentence({ ok: 6, warning: 2, critical: 1, unknown: 1 }),
    ).toBe(
      '10 checks: 6 OK, 2 warnings, 1 critical issue, 1 that could not be checked.',
    );
  });

  it('singularises correctly', () => {
    expect(
      buildCountsSentence({ ok: 0, warning: 1, critical: 0, unknown: 0 }),
    ).toBe('1 check: 1 warning.');
  });

  it('omits empty buckets', () => {
    const sentence = buildCountsSentence({
      ok: 3,
      warning: 0,
      critical: 0,
      unknown: 0,
    });
    expect(sentence).toBe('3 checks: 3 OK.');
    expect(sentence).not.toContain('warning');
  });

  it('handles a report with no checks', () => {
    expect(
      buildCountsSentence({ ok: 0, warning: 0, critical: 0, unknown: 0 }),
    ).toBe('No environment checks have run yet.');
  });

  it('names the unknown bucket in words, not just a number', () => {
    expect(
      buildCountsSentence({ ok: 0, warning: 0, critical: 0, unknown: 2 }),
    ).toContain('could not be checked');
  });
});

describe('formatLastChecked', () => {
  const NOW_MS = 1_760_000_000_000;
  const NOW_S = NOW_MS / 1000;

  it('says "just now" under a minute', () => {
    expect(formatLastChecked(NOW_S - 30, NOW_MS)).toBe('just now');
  });

  it('counts minutes, hours and days', () => {
    expect(formatLastChecked(NOW_S - 60, NOW_MS)).toBe('1 minute ago');
    expect(formatLastChecked(NOW_S - 300, NOW_MS)).toBe('5 minutes ago');
    expect(formatLastChecked(NOW_S - 3600, NOW_MS)).toBe('1 hour ago');
    expect(formatLastChecked(NOW_S - 7200, NOW_MS)).toBe('2 hours ago');
    expect(formatLastChecked(NOW_S - 86_400, NOW_MS)).toBe('1 day ago');
    expect(formatLastChecked(NOW_S - 3 * 86_400, NOW_MS)).toBe('3 days ago');
  });

  it('clamps clock skew instead of rendering a future timestamp', () => {
    expect(formatLastChecked(NOW_S + 500, NOW_MS)).toBe('just now');
  });

  it('reports "never" for an absent timestamp', () => {
    expect(formatLastChecked(0, NOW_MS)).toBe('never');
  });
});

describe('formatAbsoluteTime / formatIsoTime', () => {
  it('produces an ISO string for the <time datetime> attribute', () => {
    expect(formatIsoTime(1_760_000_000)).toBe('2025-10-09T08:53:20.000Z');
  });

  it('returns empty strings when there is no timestamp', () => {
    expect(formatIsoTime(0)).toBe('');
    expect(formatAbsoluteTime(0)).toBe('');
  });

  it('produces a non-empty locale string for a real timestamp', () => {
    expect(formatAbsoluteTime(1_760_000_000).length).toBeGreaterThan(0);
  });
});

describe('isSafeUrl', () => {
  it('accepts http and https', () => {
    expect(isSafeUrl('https://wordpress.org/plugins/akismet/')).toBe(true);
    expect(isSafeUrl('http://example.test/docs')).toBe(true);
  });

  it('rejects javascript: and data: URLs', () => {
    expect(isSafeUrl('javascript:alert(1)')).toBe(false);
    expect(isSafeUrl('data:text/html,<script>alert(1)</script>')).toBe(false);
  });

  it('rejects unparseable input', () => {
    expect(isSafeUrl('')).toBe(false);
    expect(isSafeUrl('   ')).toBe(false);
  });
});

describe('group metadata', () => {
  it('labels every group in the render order', () => {
    for (const group of CHECK_GROUP_ORDER) {
      expect(CHECK_GROUP_LABELS[group]).toBeTruthy();
    }
    expect(CHECK_GROUP_ORDER).toEqual([
      'versions',
      'cron',
      'debug',
      'plugins_themes',
    ]);
  });
});

describe('THRESHOLD_BOUNDS', () => {
  it('never allows 0, which the PHP absint sanitiser would happily accept', () => {
    // absint('') === 0, and 0 is harmful for every one of these settings, so
    // the floor has to come from the client.
    for (const bounds of Object.values(THRESHOLD_BOUNDS)) {
      expect(bounds.min).toBeGreaterThan(0);
      expect(bounds.max).toBeGreaterThan(bounds.min);
    }
  });

  it('covers every threshold in the config type', () => {
    expect(Object.keys(THRESHOLD_BOUNDS).sort()).toEqual([
      'abandoned_critical_days',
      'abandoned_warning_days',
      'cron_overdue_minutes',
      'ssl_expiry_warning_days',
    ]);
  });

  it('brackets each PHP default rather than clamping it on first load', () => {
    const defaults = {
      ssl_expiry_warning_days: 30,
      cron_overdue_minutes: 60,
      abandoned_warning_days: 365,
      abandoned_critical_days: 730,
    } as const;

    for (const [key, value] of Object.entries(defaults)) {
      const bounds = THRESHOLD_BOUNDS[key as keyof typeof defaults];
      expect(normalizeThreshold(value, bounds)).toBe(value);
    }
  });
});

describe('normalizeThreshold', () => {
  const bounds = { min: 30, max: 3650, step: 1 };

  it('passes an in-range integer through untouched', () => {
    expect(normalizeThreshold(365, bounds)).toBe(365);
    expect(normalizeThreshold('365', bounds)).toBe(365);
  });

  it('returns null for values that must never reach the store', () => {
    // Each of these would become 0 server-side via absint.
    expect(normalizeThreshold('', bounds)).toBeNull();
    expect(normalizeThreshold('   ', bounds)).toBeNull();
    expect(normalizeThreshold('abc', bounds)).toBeNull();
    expect(normalizeThreshold(NaN, bounds)).toBeNull();
    expect(normalizeThreshold(Infinity, bounds)).toBeNull();
    expect(normalizeThreshold(-Infinity, bounds)).toBeNull();
    expect(normalizeThreshold(null, bounds)).toBeNull();
    expect(normalizeThreshold(undefined, bounds)).toBeNull();
    expect(normalizeThreshold(true, bounds)).toBeNull();
  });

  it('clamps to the bounds instead of rejecting', () => {
    expect(normalizeThreshold(0, bounds)).toBe(30);
    expect(normalizeThreshold(-500, bounds)).toBe(30);
    expect(normalizeThreshold(99_999, bounds)).toBe(3650);
  });

  it('floors fractions — the schema is integer-only', () => {
    expect(normalizeThreshold(365.9, bounds)).toBe(365);
    expect(normalizeThreshold('42.7', bounds)).toBe(42);
  });

  it('never returns NaN for any input', () => {
    const inputs = ['', 'x', '1e999', '-0', 12.5, NaN, null, {}, []];
    for (const input of inputs) {
      const result = normalizeThreshold(input, bounds);
      expect(result === null || Number.isInteger(result)).toBe(true);
    }
  });
});

describe('abandonedBandsInverted', () => {
  it('is false when critical sits above warning', () => {
    expect(abandonedBandsInverted(365, 730)).toBe(false);
  });

  it('is false when they are equal', () => {
    expect(abandonedBandsInverted(365, 365)).toBe(false);
  });

  it('is true when critical would never be reached', () => {
    expect(abandonedBandsInverted(730, 365)).toBe(true);
  });
});
