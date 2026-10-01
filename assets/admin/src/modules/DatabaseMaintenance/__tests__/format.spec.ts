import { describe, expect, it } from 'vitest';
import { formatBytes, formatRows } from '../format';

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
