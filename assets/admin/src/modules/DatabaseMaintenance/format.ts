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
