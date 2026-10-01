const UNITS = ['B', 'KB', 'MB', 'GB'] as const;

/** Approximate human size, e.g. 4404019 → "4.2 MB". Always prefixed by "≈" in the UI, not here. */
export function formatBytes(bytes: number): string {
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
  let value = bytes;
  let unit = 0;
  while (value >= 1024 && unit < UNITS.length - 1) {
    value /= 1024;
    unit += 1;
  }
  const rounded = unit === 0 ? Math.round(value) : Math.round(value * 10) / 10;
  return `${String(rounded)} ${UNITS[unit] ?? 'B'}`;
}

/** "1 row" / "1,247 rows". */
export function formatRows(n: number): string {
  return `${n.toLocaleString()} ${n === 1 ? 'row' : 'rows'}`;
}

const SITE_TIME =
  /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::\d{2}(?:\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/;

/**
 * Site-local wall time from an ISO string carrying the site's offset, e.g.
 * "Sun, Oct 4, 03:00 (site time, UTC−05:00)". Deliberately not converted to the
 * browser zone: the schedule is defined in site time.
 */
export function formatSiteTime(iso: string): string {
  const m = SITE_TIME.exec(iso);
  if (!m) return iso;
  const [, y, mo, d, h, mi, tz = 'Z'] = m;
  // UTC is used only as a neutral calendar to name the weekday and month.
  const day = new Date(Date.UTC(Number(y), Number(mo) - 1, Number(d)));
  const date = day.toLocaleDateString('en-US', {
    timeZone: 'UTC',
    weekday: 'short',
    month: 'short',
    day: 'numeric',
  });
  const offset = tz === 'Z' ? '+00:00' : tz.replace('-', '\u2212');
  return `${date}, ${String(h)}:${String(mi)} (site time, UTC${offset})`;
}
