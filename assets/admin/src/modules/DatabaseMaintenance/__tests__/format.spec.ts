import { describe, expect, it } from 'vitest';
import { formatBytes, formatRows, formatSiteTime } from '../format';

describe('formatBytes', () => {
  it.each([
    [0, '0 B'],
    [-5, '0 B'],
    [512, '512 B'],
    [1536, '1.5 KB'],
    [4404019, '4.2 MB'],
    [5 * 1024 ** 4, '5120 GB'],
  ])('%d → %s', (input, expected) => {
    expect(formatBytes(input)).toBe(expected);
  });
});

describe('formatRows', () => {
  it('pluralises', () => {
    expect(formatRows(1)).toBe('1 row');
    expect(formatRows(1247)).toBe(`${(1247).toLocaleString()} rows`);
  });
});

describe('formatSiteTime', () => {
  it.each([
    [
      '2026-10-04T03:00:00-05:00',
      'Sun, Oct 4, 03:00 (site time, UTC\u221205:00)',
    ],
    ['2026-10-04T14:30:00+05:30', 'Sun, Oct 4, 14:30 (site time, UTC+05:30)'],
    ['2026-10-04T03:00:00+00:00', 'Sun, Oct 4, 03:00 (site time, UTC+00:00)'],
    ['2026-10-04T03:00:00Z', 'Sun, Oct 4, 03:00 (site time, UTC+00:00)'],
  ])('%s keeps the site wall time', (iso, expected) => {
    expect(formatSiteTime(iso)).toBe(expected);
  });

  it('falls back to the raw value when unparseable', () => {
    expect(formatSiteTime('soon')).toBe('soon');
  });
});
