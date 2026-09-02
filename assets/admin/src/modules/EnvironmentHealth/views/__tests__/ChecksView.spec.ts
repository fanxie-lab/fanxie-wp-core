import { beforeEach, describe, expect, it } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import {
  makeCheck,
  makeConfig,
  makeReport,
  mountAt,
} from '../../__tests__/harness';

const CHECKS = '/environment-health/checks';

describe('<ChecksView>', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('renders one card per populated group, in canonical order', async () => {
    const { wrapper } = await mountAt(CHECKS, (store) => {
      store.config = makeConfig();
      store.report = makeReport({
        counts: { ok: 2, warning: 1, critical: 0, unknown: 1 },
        checks: [
          makeCheck({ id: 'inactive_plugins', group: 'plugins_themes' }),
          makeCheck({ id: 'wp_debug', group: 'debug', status: 'warning' }),
          makeCheck({ id: 'php_version', group: 'versions' }),
          makeCheck({ id: 'ssl_expiry', group: 'versions', status: 'unknown' }),
        ],
      });
    });

    const headings = wrapper
      .findAll('.fx-eh-group__title')
      .map((h) => h.text());
    expect(headings).toEqual(['Versions', 'Debug mode', 'Plugins and themes']);
    expect(wrapper.findAll('li.fx-eh-row')).toHaveLength(4);
  });

  it('surfaces every status including unknown', async () => {
    const { wrapper } = await mountAt(CHECKS, (store) => {
      store.config = makeConfig();
      store.report = makeReport({
        counts: { ok: 1, warning: 1, critical: 1, unknown: 1 },
        checks: [
          makeCheck({ id: 'a', status: 'ok' }),
          makeCheck({ id: 'b', status: 'warning' }),
          makeCheck({ id: 'c', status: 'critical' }),
          makeCheck({
            id: 'ssl_expiry',
            status: 'unknown',
            label: 'SSL certificate',
            summary: 'This host blocked the outbound connection.',
          }),
        ],
      });
    });

    const rows = wrapper.findAll('li.fx-eh-row');
    expect(rows.map((r) => r.classes().join(' '))).toEqual([
      expect.stringContaining('fx-eh-row--ok'),
      expect.stringContaining('fx-eh-row--warning'),
      expect.stringContaining('fx-eh-row--critical'),
      expect.stringContaining('fx-eh-row--unknown'),
    ]);
    expect(wrapper.text()).toContain("Couldn't check");
  });

  it('does not tint an ok row, and dashes an unknown one', async () => {
    // Guards the design-hook fix: full-border tint, warning/critical only,
    // unknown dashed, and never a border-left side tab.
    const { wrapper } = await mountAt(CHECKS, (store) => {
      store.config = makeConfig();
      store.report = makeReport({
        checks: [
          makeCheck({ id: 'a', status: 'ok' }),
          makeCheck({ id: 'b', status: 'unknown' }),
        ],
      });
    });

    const rows = wrapper.findAll('li.fx-eh-row');
    expect(rows[0]!.classes()).not.toContain('fx-eh-row--warning');
    expect(rows[0]!.classes()).not.toContain('fx-eh-row--critical');
    expect(rows[1]!.classes()).toContain('fx-eh-row--unknown');
  });

  it('marks the group list aria-busy while a refresh is in flight', async () => {
    const { wrapper } = await mountAt(CHECKS, (store) => {
      store.config = makeConfig();
      store.report = makeReport();
      store.loading.refreshing = true;
    });

    expect(wrapper.get('.fx-eh-checks__groups').attributes('aria-busy')).toBe(
      'true',
    );
  });

  it('explains an empty report and points at the Settings tab', async () => {
    const { wrapper } = await mountAt(CHECKS, (store) => {
      store.config = makeConfig();
      store.report = makeReport({
        checks: [],
        counts: { ok: 0, warning: 0, critical: 0, unknown: 0 },
      });
    });

    expect(wrapper.findAll('.fx-eh-group')).toHaveLength(0);
    const empty = wrapper.get('.fx-eh-checks__empty');
    expect(empty.text()).toContain('No checks ran.');
    // The old copy said "below"; the setting now lives on another tab.
    expect(empty.text()).toContain('Settings');
    expect(empty.text()).not.toContain('below');
  });

  it('toasts a clipboard failure raised from a remediation snippet', async () => {
    const { store, wrapper } = await mountAt(CHECKS, (s) => {
      s.config = makeConfig();
      s.report = makeReport({
        checks: [
          makeCheck({
            status: 'warning',
            remediation: [{ kind: 'snippet', language: 'php', code: 'x' }],
          }),
        ],
      });
    });

    Object.defineProperty(navigator, 'clipboard', {
      value: { writeText: () => Promise.reject(new Error('denied')) },
      configurable: true,
      writable: true,
    });

    await wrapper.get('.fx-eh-row__toggle').trigger('click');
    await wrapper.get('.fx-snippet__copy').trigger('click');
    await new Promise((r) => setTimeout(r, 0));

    expect(store.toast?.variant).toBe('error');
    expect(store.toast?.message).toContain('Copy failed.');
  });
});
