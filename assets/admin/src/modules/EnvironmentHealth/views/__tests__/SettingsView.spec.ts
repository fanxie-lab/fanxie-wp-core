import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import type { DOMWrapper, VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { makeConfig, makeReport, mountAt } from '../../__tests__/harness';

const SETTINGS = '/environment-health/settings';

/** Find a Toggle by its visible label text and return its switch element. */
function switchFor(wrapper: VueWrapper, label: string): DOMWrapper<Element> {
  const toggle = wrapper
    .findAll('.fx-toggle')
    .find((t) => t.text().includes(label));
  if (!toggle) throw new Error(`No toggle labelled "${label}"`);
  // `find` (not `get`) so the return type keeps `.exists()` on it.
  return toggle.find('[role="switch"]');
}

function seeded(overrides = {}) {
  return (store: { config: unknown; pristine: unknown; report: unknown }) => {
    store.config = makeConfig(overrides);
    store.pristine = makeConfig(overrides);
    store.report = makeReport();
  };
}

describe('<SettingsView>', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  describe('controls', () => {
    it('renders a control for every setting the PHP schema persists', async () => {
      const { wrapper } = await mountAt(SETTINGS, seeded());

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

      expect(wrapper.findAll('input[type="number"]')).toHaveLength(4);
    });

    it('renders the TLS toggle readme.txt names, so the documented setting is reachable', async () => {
      const { store, wrapper } = await mountAt(
        SETTINGS,
        seeded({ ssl_check_enabled: true }),
      );

      const tls = switchFor(wrapper, 'Check the TLS certificate');
      expect(tls.attributes('aria-checked')).toBe('true');

      await tls.trigger('click');

      expect(store.config?.ssl_check_enabled).toBe(false);
    });

    it('binds each check-group toggle to its own config branch', async () => {
      const { store, wrapper } = await mountAt(SETTINGS, seeded());

      await switchFor(wrapper, 'Check scheduled tasks').trigger('click');

      expect(store.config?.checks.cron).toBe(false);
      expect(store.config?.checks.versions).toBe(true);
      expect(store.config?.checks.debug).toBe(true);
      expect(store.config?.checks.plugins_themes).toBe(true);
    });

    it('binds the dashboard widget toggle', async () => {
      const { store, wrapper } = await mountAt(SETTINGS, seeded());

      await switchFor(wrapper, 'Show the dashboard widget').trigger('click');

      expect(store.config?.dashboard_widget).toBe(false);
    });

    it('hides everything until the config has loaded', async () => {
      const { wrapper } = await mountAt(SETTINGS, (store) => {
        store.report = makeReport();
      });

      expect(wrapper.find('#fx-eh-settings').exists()).toBe(false);
      expect(wrapper.find('#fx-eh-checks-heading').exists()).toBe(false);
      expect(wrapper.find('#fx-eh-thresholds').exists()).toBe(false);
    });
  });

  describe('help standard (CLAUDE.md §3.4)', () => {
    it('wires every toggle help text to the switch, never to the wrapper div', async () => {
      const { wrapper } = await mountAt(SETTINGS, seeded());

      const switches = wrapper.findAll('[role="switch"]');
      expect(switches.length).toBe(7);

      for (const sw of switches) {
        const describedBy = sw.attributes('aria-describedby');
        expect(describedBy).toBeTruthy();
        expect(wrapper.find(`#${describedBy!}`).exists()).toBe(true);
      }

      // A raw aria-describedby would have landed on the wrapper instead.
      for (const div of wrapper.findAll('.fx-toggle')) {
        expect(div.attributes('aria-describedby')).toBeUndefined();
      }
    });

    it('states plainly that the wp.org and TLS checks leave the server', async () => {
      const { wrapper } = await mountAt(SETTINGS, seeded());

      const wporg = wrapper.get('#fx-eh-wporg-scan-help');
      expect(wporg.text()).toContain('api.wordpress.org');
      expect(wporg.text()).toContain('Nothing about you or your visitors');

      const ssl = wrapper.get('#fx-eh-ssl-check-help');
      expect(ssl.text()).toContain('connection to this site');
    });
  });

  describe('thresholds', () => {
    it('seeds the fields from config and commits numbers back', async () => {
      const { store, wrapper } = await mountAt(SETTINGS, seeded());

      const numbers = wrapper.findAll('input[type="number"]');
      expect(numbers.map((n) => (n.element as HTMLInputElement).value)).toEqual(
        ['30', '60', '365', '730'],
      );

      await numbers[1]!.setValue('120');

      expect(store.config?.thresholds.cron_overdue_minutes).toBe(120);
    });

    it('keeps min/max on the input itself, not the wrapper', async () => {
      const { wrapper } = await mountAt(SETTINGS, seeded());

      const first = wrapper.findAll('input[type="number"]')[0]!;
      expect(first.attributes('min')).toBe('1');
      expect(first.attributes('max')).toBe('365');
      expect(
        wrapper.findAll('.fx-text-field')[0]!.attributes('min'),
      ).toBeUndefined();
    });

    it('never lets a cleared field write NaN or an empty string', async () => {
      const { store, wrapper } = await mountAt(SETTINGS, seeded());

      const field = wrapper.findAll('input[type="number"]')[0]!;
      await field.setValue('');

      const value = store.config?.thresholds.ssl_expiry_warning_days;
      expect(value).toBe(30);
      expect(Number.isInteger(value)).toBe(true);

      await field.trigger('blur');
      expect((field.element as HTMLInputElement).value).toBe('30');
    });

    it('clamps an out-of-range value to the bounds', async () => {
      const { store, wrapper } = await mountAt(SETTINGS, seeded());

      const sslField = wrapper.findAll('input[type="number"]')[0]!;
      await sslField.setValue('99999');
      expect(store.config?.thresholds.ssl_expiry_warning_days).toBe(365);

      await sslField.setValue('0');
      expect(store.config?.thresholds.ssl_expiry_warning_days).toBe(1);
    });

    it('blocks the save when the abandoned bands are inverted', async () => {
      const { wrapper } = await mountAt(SETTINGS, (store) => {
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
      const { wrapper } = await mountAt(SETTINGS, (store) => {
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
  });

  describe('save bar', () => {
    it('wires to store.save() and store.reset()', async () => {
      const { store, wrapper } = await mountAt(SETTINGS, (s) => {
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
  });

  describe('unsaved-changes guard', () => {
    it('leaves a pristine form without prompting', async () => {
      const { router, wrapper } = await mountAt(SETTINGS, seeded());

      await router.push({ name: 'environment-health.checks' });
      await flushPromises();

      expect(wrapper.find('[role="alertdialog"]').exists()).toBe(false);
      expect(router.currentRoute.value.name).toBe('environment-health.checks');
    });

    it('prompts before leaving to the sibling tab with unsaved edits', async () => {
      const { router, wrapper } = await mountAt(SETTINGS, seeded());

      await wrapper.findAll('input[type="number"]')[1]!.setValue('120');
      expect(wrapper.find('[role="alertdialog"]').exists()).toBe(false);

      void router.push({ name: 'environment-health.checks' });
      await flushPromises();

      const dialog = wrapper.get('[role="alertdialog"]');
      expect(dialog.text()).toContain('Discard unsaved settings?');
      // Navigation is held until the user answers.
      expect(router.currentRoute.value.name).toBe(
        'environment-health.settings',
      );
    });

    it('prompts before leaving to a different module too', async () => {
      const { router, wrapper } = await mountAt(SETTINGS, seeded());

      await wrapper.findAll('input[type="number"]')[1]!.setValue('120');
      void router.push({ name: 'hardening' });
      await flushPromises();

      expect(wrapper.find('[role="alertdialog"]').exists()).toBe(true);
      expect(router.currentRoute.value.name).toBe(
        'environment-health.settings',
      );
    });

    it('stays put and keeps the edits when the user keeps editing', async () => {
      const { router, wrapper, store } = await mountAt(SETTINGS, seeded());

      await wrapper.findAll('input[type="number"]')[1]!.setValue('120');
      void router.push({ name: 'environment-health.checks' });
      await flushPromises();

      const cancel = wrapper
        .findAll('[role="alertdialog"] button')
        .find((b) => b.text().includes('Keep editing'));
      await cancel!.trigger('click');
      await flushPromises();

      expect(router.currentRoute.value.name).toBe(
        'environment-health.settings',
      );
      expect(store.config?.thresholds.cron_overdue_minutes).toBe(120);
      expect(store.isDirty).toBe(true);
      expect(wrapper.find('[role="alertdialog"]').exists()).toBe(false);
    });

    it('navigates and actually discards the edits when the user confirms', async () => {
      const { router, wrapper, store } = await mountAt(SETTINGS, seeded());

      await wrapper.findAll('input[type="number"]')[1]!.setValue('120');
      void router.push({ name: 'environment-health.checks' });
      await flushPromises();

      const discard = wrapper
        .findAll('[role="alertdialog"] button')
        .find((b) => b.text().includes('Discard changes'));
      await discard!.trigger('click');
      await flushPromises();

      expect(router.currentRoute.value.name).toBe('environment-health.checks');
      // Discarding must really discard — otherwise coming back would show a
      // dirty form the user believes they threw away.
      expect(store.config?.thresholds.cron_overdue_minutes).toBe(60);
      expect(store.isDirty).toBe(false);
    });

    it('does not prompt again after a successful save', async () => {
      const { router, wrapper, store } = await mountAt(SETTINGS, seeded());

      await wrapper.findAll('input[type="number"]')[1]!.setValue('120');
      // Stand in for a save: config persisted, baseline moved forward.
      store.pristine = JSON.parse(
        JSON.stringify(store.config),
      ) as typeof store.pristine;
      expect(store.isDirty).toBe(false);

      await router.push({ name: 'environment-health.checks' });
      await flushPromises();

      expect(wrapper.find('[role="alertdialog"]').exists()).toBe(false);
      expect(router.currentRoute.value.name).toBe('environment-health.checks');
    });
  });
});
