import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import type { DOMWrapper, VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import EnvironmentHealth from '../EnvironmentHealth.vue';
import { useEnvironmentHealthStore } from '../stores/environmentHealth';
import type {
  EnvironmentHealthConfig,
  HealthCheck,
  HealthCounts,
  HealthReport,
} from '../types';

function makeCheck(overrides: Partial<HealthCheck> = {}): HealthCheck {
  return {
    id: 'php_version',
    group: 'versions',
    label: 'PHP version',
    status: 'ok',
    value: '8.2.14',
    summary: 'Running a supported PHP release.',
    ...overrides,
  };
}

function makeCounts(overrides: Partial<HealthCounts> = {}): HealthCounts {
  return { ok: 1, warning: 0, critical: 0, unknown: 0, ...overrides };
}

function makeReport(overrides: Partial<HealthReport> = {}): HealthReport {
  return {
    generated_at: Math.floor(Date.now() / 1000) - 120,
    cached_until: Math.floor(Date.now() / 1000) + 180,
    counts: makeCounts(),
    checks: [makeCheck()],
    ...overrides,
  };
}

function makeConfig(
  overrides: Partial<EnvironmentHealthConfig> = {},
): EnvironmentHealthConfig {
  // Mirrors get_default_config() in the PHP module.
  return {
    checks: {
      versions: true,
      cron: true,
      debug: true,
      plugins_themes: true,
      ...(overrides.checks ?? {}),
    },
    wporg_scan_enabled: overrides.wporg_scan_enabled ?? true,
    ssl_check_enabled: overrides.ssl_check_enabled ?? true,
    dashboard_widget: overrides.dashboard_widget ?? true,
    thresholds: {
      ssl_expiry_warning_days: 30,
      cron_overdue_minutes: 60,
      abandoned_warning_days: 365,
      abandoned_critical_days: 730,
      ...(overrides.thresholds ?? {}),
    },
  };
}

async function mountWithStore(
  seed: (store: ReturnType<typeof useEnvironmentHealthStore>) => void,
) {
  setActivePinia(createPinia());
  const store = useEnvironmentHealthStore();
  vi.spyOn(store, 'load').mockResolvedValue();
  seed(store);

  const wrapper = mount(EnvironmentHealth, { attachTo: document.body });
  await flushPromises();
  return { store, wrapper };
}

describe('<EnvironmentHealth>', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('calls store.load() on mount', async () => {
    const store = useEnvironmentHealthStore();
    const loadSpy = vi.spyOn(store, 'load').mockResolvedValue();

    mount(EnvironmentHealth);
    await flushPromises();

    expect(loadSpy).toHaveBeenCalledTimes(1);
  });

  it('renders a loading panel before the first report arrives', async () => {
    setActivePinia(createPinia());
    const store = useEnvironmentHealthStore();
    vi.spyOn(store, 'load').mockImplementation(() => {
      store.loading.initial = true;
      return new Promise(() => {
        /* never resolves */
      });
    });

    const wrapper = mount(EnvironmentHealth);
    await flushPromises();

    expect(wrapper.get('.fx-eh__panel[role="status"]').text()).toContain(
      'Loading',
    );
  });

  it('renders one card per populated group, in canonical order', async () => {
    const { wrapper } = await mountWithStore((store) => {
      store.config = makeConfig();
      store.report = makeReport({
        counts: makeCounts({ ok: 2, warning: 1, unknown: 1 }),
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
    const { wrapper } = await mountWithStore((store) => {
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

  it('shows the counts summary as a screen-reader sentence and a last-checked stamp', async () => {
    const { wrapper } = await mountWithStore((store) => {
      store.config = makeConfig();
      store.report = makeReport({
        generated_at: Math.floor(Date.now() / 1000) - 3600,
        counts: { ok: 3, warning: 1, critical: 0, unknown: 2 },
      });
    });

    expect(wrapper.text()).toContain(
      '6 checks: 3 OK, 1 warning, 2 that could not be checked.',
    );
    expect(wrapper.get('.fx-eh-summary__meta').text()).toContain(
      'Last checked 1 hour ago',
    );
  });

  it('shows the worst status in the header pill', async () => {
    const { wrapper } = await mountWithStore((store) => {
      store.config = makeConfig();
      store.report = makeReport({
        counts: { ok: 1, warning: 0, critical: 1, unknown: 0 },
        checks: [makeCheck({ status: 'critical' })],
      });
    });

    expect(wrapper.get('.fx-eh__title-row .fx-pill').text()).toContain(
      'Critical',
    );
  });

  describe('refresh', () => {
    it('calls store.refresh() when the button is clicked', async () => {
      const { store, wrapper } = await mountWithStore((s) => {
        s.config = makeConfig();
        s.report = makeReport();
      });
      const refreshSpy = vi.spyOn(store, 'refresh').mockResolvedValue();

      await wrapper.get('.fx-eh__refresh').trigger('click');

      expect(refreshSpy).toHaveBeenCalledTimes(1);
    });

    it('disables the button and announces the busy state while refreshing', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig();
        store.report = makeReport();
        store.loading.refreshing = true;
      });

      const button = wrapper.get('.fx-eh__refresh');
      expect(button.attributes('disabled')).toBeDefined();
      expect(button.text()).toContain('Re-running');

      const live = wrapper.get('span[role="status"][aria-live="polite"]');
      expect(live.text()).toBe('Re-running environment checks…');
    });

    it('announces completion once the refresh settles', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig();
        store.report = makeReport({
          counts: { ok: 2, warning: 0, critical: 0, unknown: 0 },
        });
      });

      const live = wrapper.get('span[role="status"][aria-live="polite"]');
      expect(live.text()).toContain('Checks up to date.');
      expect(live.text()).toContain('2 checks: 2 OK.');
    });

    it('marks the group list aria-busy while a refresh is in flight', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig();
        store.report = makeReport();
        store.loading.refreshing = true;
      });

      expect(wrapper.get('.fx-eh__groups').attributes('aria-busy')).toBe(
        'true',
      );
    });
  });

  describe('failure and empty states', () => {
    it('renders a retry panel when the report could not be loaded at all', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.error = 'Not allowed.';
      });

      const panel = wrapper.get('.fx-eh__panel--error');
      expect(panel.text()).toContain('could not be loaded');
      expect(panel.text()).toContain('Not allowed.');
      expect(panel.find('button').exists()).toBe(true);
    });

    it('retries through store.refresh() from the failure panel', async () => {
      const { store, wrapper } = await mountWithStore((s) => {
        s.error = 'Not allowed.';
      });
      const refreshSpy = vi.spyOn(store, 'refresh').mockResolvedValue();

      await wrapper.get('.fx-eh__panel--error button').trigger('click');

      expect(refreshSpy).toHaveBeenCalledTimes(1);
    });

    it('keeps a cached report on screen when a later refresh errors', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig();
        store.report = makeReport();
        store.error = 'Probe timed out.';
      });

      expect(wrapper.find('.fx-eh__panel--error').exists()).toBe(false);
      expect(wrapper.text()).toContain('PHP version');
    });

    it('explains an empty report instead of rendering nothing', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig();
        store.report = makeReport({
          checks: [],
          counts: { ok: 0, warning: 0, critical: 0, unknown: 0 },
        });
      });

      expect(wrapper.findAll('.fx-eh-group')).toHaveLength(0);
      expect(wrapper.text()).toContain('No checks ran.');
    });
  });

  describe('settings surface', () => {
    /** Find a Toggle by its visible label text and return its switch element. */
    function switchFor(
      wrapper: VueWrapper,
      label: string,
    ): DOMWrapper<Element> {
      const toggle = wrapper
        .findAll('.fx-toggle')
        .find((t) => t.text().includes(label));
      if (!toggle) throw new Error(`No toggle labelled "${label}"`);
      // `find` (not `get`) so the return type keeps `.exists()` on it.
      return toggle.find('[role="switch"]');
    }

    it('renders a control for every setting the PHP schema persists', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig();
        store.report = makeReport();
      });

      const labels = [
        'Check software versions',
        'Check scheduled tasks',
        'Check debug settings',
        'Check plugins and themes',
        'Check plugin freshness on wordpress.org',
        'Check the TLS certificate',
        'Show the dashboard widget',
      ];
      for (const label of labels) {
        expect(switchFor(wrapper, label).exists()).toBe(true);
      }

      // Four numeric thresholds.
      expect(wrapper.findAll('input[type="number"]')).toHaveLength(4);
    });

    it('renders the TLS toggle readme.txt names, so the documented setting is reachable', async () => {
      const { store, wrapper } = await mountWithStore((s) => {
        s.config = makeConfig({ ssl_check_enabled: true });
        s.report = makeReport();
      });

      const tls = switchFor(wrapper, 'Check the TLS certificate');
      expect(tls.attributes('aria-checked')).toBe('true');

      await tls.trigger('click');

      expect(store.config?.ssl_check_enabled).toBe(false);
    });

    it('binds each check-group toggle to its own config branch', async () => {
      const { store, wrapper } = await mountWithStore((s) => {
        s.config = makeConfig();
        s.report = makeReport();
      });

      await switchFor(wrapper, 'Check scheduled tasks').trigger('click');

      expect(store.config?.checks.cron).toBe(false);
      // And only that branch moved.
      expect(store.config?.checks.versions).toBe(true);
      expect(store.config?.checks.debug).toBe(true);
      expect(store.config?.checks.plugins_themes).toBe(true);
    });

    it('binds the dashboard widget toggle', async () => {
      const { store, wrapper } = await mountWithStore((s) => {
        s.config = makeConfig();
        s.report = makeReport();
      });

      await switchFor(wrapper, 'Show the dashboard widget').trigger('click');

      expect(store.config?.dashboard_widget).toBe(false);
    });

    it('wires every toggle help text to the switch, never to the wrapper div', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig();
        store.report = makeReport();
      });

      const switches = wrapper.findAll('[role="switch"]');
      expect(switches.length).toBe(7);

      for (const sw of switches) {
        const describedBy = sw.attributes('aria-describedby');
        expect(describedBy).toBeTruthy();
        // The referenced element must actually exist and carry the help copy.
        expect(wrapper.find(`#${describedBy!}`).exists()).toBe(true);
      }

      // A raw aria-describedby would have landed on the wrapper instead.
      for (const div of wrapper.findAll('.fx-toggle')) {
        expect(div.attributes('aria-describedby')).toBeUndefined();
      }
    });

    it('states plainly that the wp.org and TLS checks leave the server', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig();
        store.report = makeReport();
      });

      const wporg = wrapper.get('#fx-eh-wporg-scan-help');
      expect(wporg.text()).toContain('api.wordpress.org');
      expect(wporg.text()).toContain('Nothing about you or your visitors');

      const ssl = wrapper.get('#fx-eh-ssl-check-help');
      expect(ssl.text()).toContain('connection to this site');
    });

    it('seeds the threshold fields from config and commits numbers back', async () => {
      const { store, wrapper } = await mountWithStore((s) => {
        s.config = makeConfig();
        s.report = makeReport();
      });

      const numbers = wrapper.findAll('input[type="number"]');
      const values = numbers.map((n) => (n.element as HTMLInputElement).value);
      expect(values).toEqual(['30', '60', '365', '730']);

      await numbers[1]!.setValue('120');

      expect(store.config?.thresholds.cron_overdue_minutes).toBe(120);
    });

    it('never lets a cleared threshold field write NaN or an empty string', async () => {
      const { store, wrapper } = await mountWithStore((s) => {
        s.config = makeConfig();
        s.report = makeReport();
      });

      const field = wrapper.findAll('input[type="number"]')[0]!;
      await field.setValue('');

      // absint('') would be 0 server-side — the store must still hold the
      // last good value instead.
      const value = store.config?.thresholds.ssl_expiry_warning_days;
      expect(value).toBe(30);
      expect(Number.isInteger(value)).toBe(true);

      await field.trigger('blur');
      expect((field.element as HTMLInputElement).value).toBe('30');
    });

    it('clamps an out-of-range threshold to the bounds', async () => {
      const { store, wrapper } = await mountWithStore((s) => {
        s.config = makeConfig();
        s.report = makeReport();
      });

      const sslField = wrapper.findAll('input[type="number"]')[0]!;
      await sslField.setValue('99999');
      expect(store.config?.thresholds.ssl_expiry_warning_days).toBe(365);

      await sslField.setValue('0');
      expect(store.config?.thresholds.ssl_expiry_warning_days).toBe(1);
    });

    it('blocks the save when the abandoned bands are inverted', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig({
          thresholds: {
            ssl_expiry_warning_days: 30,
            cron_overdue_minutes: 60,
            abandoned_warning_days: 730,
            abandoned_critical_days: 365,
          },
        });
        store.pristine = makeConfig();
        store.report = makeReport();
      });

      expect(wrapper.find('#fx-eh-bands-error').exists()).toBe(true);
      const save = wrapper
        .findAll('.fx-save-bar__button')
        .find((b) => b.text().includes('Save'));
      expect(save!.attributes('disabled')).toBeDefined();
    });

    it('allows the save once the bands are the right way round', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig({
          thresholds: {
            ssl_expiry_warning_days: 30,
            cron_overdue_minutes: 60,
            abandoned_warning_days: 200,
            abandoned_critical_days: 400,
          },
        });
        store.pristine = makeConfig();
        store.report = makeReport();
      });

      expect(wrapper.find('#fx-eh-bands-error').exists()).toBe(false);
      const save = wrapper
        .findAll('.fx-save-bar__button')
        .find((b) => b.text().includes('Save'));
      expect(save!.attributes('disabled')).toBeUndefined();
    });

    it('wires the save bar to store.save() and store.reset()', async () => {
      const { store, wrapper } = await mountWithStore((s) => {
        s.config = makeConfig({ wporg_scan_enabled: false });
        s.pristine = makeConfig({ wporg_scan_enabled: true });
        s.report = makeReport();
      });
      const saveSpy = vi.spyOn(store, 'save').mockResolvedValue();
      const resetSpy = vi.spyOn(store, 'reset').mockImplementation(() => {
        /* noop */
      });

      const buttons = wrapper.findAll('.fx-save-bar__button');
      expect(buttons.length).toBeGreaterThan(0);
      for (const button of buttons) {
        await button.trigger('click');
      }

      expect(saveSpy).toHaveBeenCalledTimes(1);
      expect(resetSpy).toHaveBeenCalledTimes(1);
    });

    it('hides the settings cards until the config has loaded', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.report = makeReport();
      });

      expect(wrapper.find('#fx-eh-settings').exists()).toBe(false);
      expect(wrapper.find('#fx-eh-checks-heading').exists()).toBe(false);
      expect(wrapper.find('#fx-eh-thresholds').exists()).toBe(false);
    });
  });

  it('toasts a clipboard failure raised from a remediation snippet', async () => {
    const { store, wrapper } = await mountWithStore((s) => {
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
    await flushPromises();

    expect(store.toast?.variant).toBe('error');
    expect(store.toast?.message).toContain('Copy failed.');
  });
});
