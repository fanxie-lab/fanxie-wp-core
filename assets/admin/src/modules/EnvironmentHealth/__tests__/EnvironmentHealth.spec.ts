import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { makeConfig, makeReport, mountAt } from './harness';

describe('<EnvironmentHealth> shell', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  describe('routing', () => {
    it('redirects the bare module path to the Checks tab', async () => {
      const { router } = await mountAt('/environment-health', (store) => {
        store.config = makeConfig();
        store.report = makeReport();
      });

      expect(router.currentRoute.value.name).toBe('environment-health.checks');
    });

    it('deep-links straight to Settings on a cold load', async () => {
      const { router, wrapper } = await mountAt(
        '/environment-health/settings',
        (store) => {
          store.config = makeConfig();
          store.report = makeReport();
        },
      );

      expect(router.currentRoute.value.name).toBe(
        'environment-health.settings',
      );
      // The Settings panel really rendered, not just the route.
      expect(wrapper.find('#fx-eh-thresholds').exists()).toBe(true);
      expect(wrapper.find('.fx-eh-checks__groups').exists()).toBe(false);
      // ...and the Settings tab is the selected one.
      const tabs = wrapper.findAll('[role="tab"]');
      expect(tabs[1]!.attributes('aria-selected')).toBe('true');
    });

    it('renders the Checks panel on the checks route', async () => {
      const { wrapper } = await mountAt(
        '/environment-health/checks',
        (store) => {
          store.config = makeConfig();
          store.report = makeReport();
        },
      );

      expect(wrapper.find('.fx-eh-checks__groups').exists()).toBe(true);
      expect(wrapper.find('#fx-eh-thresholds').exists()).toBe(false);
    });
  });

  describe('store loading', () => {
    it('calls load() once on mount', async () => {
      const { loadSpy } = await mountAt('/environment-health/checks');
      expect(loadSpy).toHaveBeenCalledTimes(1);
    });

    it('does not refetch when switching tabs — the shell never remounts', async () => {
      const { router, loadSpy, wrapper } = await mountAt(
        '/environment-health/checks',
        (store) => {
          store.config = makeConfig();
          store.report = makeReport();
        },
      );
      expect(loadSpy).toHaveBeenCalledTimes(1);

      await router.push({ name: 'environment-health.settings' });
      await flushPromises();
      await router.push({ name: 'environment-health.checks' });
      await flushPromises();

      expect(loadSpy).toHaveBeenCalledTimes(1);
      expect(wrapper.find('.fx-eh-checks__groups').exists()).toBe(true);
    });

    it('loads once on a cold deep link to Settings too', async () => {
      const { loadSpy } = await mountAt('/environment-health/settings');
      expect(loadSpy).toHaveBeenCalledTimes(1);
    });
  });

  describe('tablist', () => {
    it('renders both sub-tabs inside a labelled tablist', async () => {
      const { wrapper } = await mountAt(
        '/environment-health/checks',
        (store) => {
          store.config = makeConfig();
          store.report = makeReport();
        },
      );

      const tablist = wrapper.get('[role="tablist"]');
      expect(tablist.attributes('aria-label')).toBe(
        'Environment Health sections',
      );
      const tabs = tablist.findAll('[role="tab"]');
      expect(tabs.map((t) => t.text())).toEqual(['Checks', 'Settings']);
    });

    it('marks the active tab and gives it the only reachable tabindex', async () => {
      const { wrapper } = await mountAt(
        '/environment-health/checks',
        (store) => {
          store.config = makeConfig();
          store.report = makeReport();
        },
      );

      const tabs = wrapper.findAll('[role="tab"]');
      expect(tabs[0]!.attributes('aria-selected')).toBe('true');
      expect(tabs[0]!.attributes('tabindex')).toBe('0');
      expect(tabs[1]!.attributes('aria-selected')).toBe('false');
      expect(tabs[1]!.attributes('tabindex')).toBe('-1');
    });

    it('wires each tab to its panel', async () => {
      const { wrapper } = await mountAt(
        '/environment-health/checks',
        (store) => {
          store.config = makeConfig();
          store.report = makeReport();
        },
      );

      const activeTab = wrapper.findAll('[role="tab"]')[0]!;
      const panel = wrapper.get('[role="tabpanel"]');
      expect(panel.attributes('id')).toBe(
        activeTab.attributes('aria-controls'),
      );
      expect(panel.attributes('aria-labelledby')).toBe(
        activeTab.attributes('id'),
      );
    });

    it('navigates on click', async () => {
      const { router, wrapper } = await mountAt(
        '/environment-health/checks',
        (store) => {
          store.config = makeConfig();
          store.report = makeReport();
        },
      );

      await wrapper.findAll('[role="tab"]')[1]!.trigger('click');
      await flushPromises();

      expect(router.currentRoute.value.name).toBe(
        'environment-health.settings',
      );
    });

    it('moves between tabs with ArrowRight / ArrowLeft', async () => {
      const { router, wrapper } = await mountAt(
        '/environment-health/checks',
        (store) => {
          store.config = makeConfig();
          store.report = makeReport();
        },
      );

      await wrapper
        .findAll('[role="tab"]')[0]!
        .trigger('keydown', { key: 'ArrowRight' });
      await flushPromises();
      expect(router.currentRoute.value.name).toBe(
        'environment-health.settings',
      );

      await wrapper
        .findAll('[role="tab"]')[1]!
        .trigger('keydown', { key: 'ArrowLeft' });
      await flushPromises();
      expect(router.currentRoute.value.name).toBe('environment-health.checks');
    });

    it('wraps around at both ends', async () => {
      const { router, wrapper } = await mountAt(
        '/environment-health/checks',
        (store) => {
          store.config = makeConfig();
          store.report = makeReport();
        },
      );

      // Left from the first tab wraps to the last.
      await wrapper
        .findAll('[role="tab"]')[0]!
        .trigger('keydown', { key: 'ArrowLeft' });
      await flushPromises();
      expect(router.currentRoute.value.name).toBe(
        'environment-health.settings',
      );
    });

    it('supports Home and End', async () => {
      const { router, wrapper } = await mountAt(
        '/environment-health/checks',
        (store) => {
          store.config = makeConfig();
          store.report = makeReport();
        },
      );

      await wrapper
        .findAll('[role="tab"]')[0]!
        .trigger('keydown', { key: 'End' });
      await flushPromises();
      expect(router.currentRoute.value.name).toBe(
        'environment-health.settings',
      );

      await wrapper
        .findAll('[role="tab"]')[1]!
        .trigger('keydown', { key: 'Home' });
      await flushPromises();
      expect(router.currentRoute.value.name).toBe('environment-health.checks');
    });

    it('ignores unrelated keys', async () => {
      const { router, wrapper } = await mountAt(
        '/environment-health/checks',
        (store) => {
          store.config = makeConfig();
          store.report = makeReport();
        },
      );

      await wrapper
        .findAll('[role="tab"]')[0]!
        .trigger('keydown', { key: 'a' });
      await flushPromises();

      expect(router.currentRoute.value.name).toBe('environment-health.checks');
    });
  });

  describe('pinned summary', () => {
    it('shows the counts summary on the Checks tab', async () => {
      const { wrapper } = await mountAt(
        '/environment-health/checks',
        (store) => {
          store.config = makeConfig();
          store.report = makeReport({
            counts: { ok: 3, warning: 1, critical: 1, unknown: 0 },
          });
        },
      );

      expect(wrapper.get('.fx-eh-summary').text()).toContain('Last checked');
      expect(wrapper.text()).toContain(
        '5 checks: 3 OK, 1 warning, 1 critical issue.',
      );
    });

    it('keeps the summary on screen on the Settings tab', async () => {
      const { wrapper } = await mountAt(
        '/environment-health/settings',
        (store) => {
          store.config = makeConfig();
          store.report = makeReport({
            counts: { ok: 3, warning: 1, critical: 1, unknown: 0 },
          });
        },
      );

      // The whole point of pinning it in the shell: a critical stays visible
      // while a threshold is being edited.
      expect(wrapper.find('.fx-eh-summary').exists()).toBe(true);
      expect(wrapper.text()).toContain(
        '5 checks: 3 OK, 1 warning, 1 critical issue.',
      );
    });

    it('survives a tab switch without re-rendering from scratch', async () => {
      const { router, wrapper } = await mountAt(
        '/environment-health/checks',
        (store) => {
          store.config = makeConfig();
          store.report = makeReport();
        },
      );
      const before = wrapper.get('.fx-eh-summary').text();

      await router.push({ name: 'environment-health.settings' });
      await flushPromises();

      expect(wrapper.get('.fx-eh-summary').text()).toBe(before);
    });
  });

  describe('header', () => {
    it('shows the worst status in the header pill on both tabs', async () => {
      for (const path of [
        '/environment-health/checks',
        '/environment-health/settings',
      ]) {
        const { wrapper } = await mountAt(path, (store) => {
          store.config = makeConfig();
          store.report = makeReport({
            counts: { ok: 1, warning: 0, critical: 1, unknown: 0 },
          });
        });
        expect(wrapper.get('.fx-eh__title-row .fx-pill').text()).toContain(
          'Critical',
        );
      }
    });

    it('calls store.refresh() from the Re-run button', async () => {
      const { store, wrapper } = await mountAt(
        '/environment-health/checks',
        (s) => {
          s.config = makeConfig();
          s.report = makeReport();
        },
      );
      const refreshSpy = vi.spyOn(store, 'refresh').mockResolvedValue();

      await wrapper.get('.fx-eh__refresh').trigger('click');

      expect(refreshSpy).toHaveBeenCalledTimes(1);
    });

    it('announces the busy state while refreshing', async () => {
      const { wrapper } = await mountAt(
        '/environment-health/checks',
        (store) => {
          store.config = makeConfig();
          store.report = makeReport();
          store.loading.refreshing = true;
        },
      );

      const button = wrapper.get('.fx-eh__refresh');
      expect(button.attributes('disabled')).toBeDefined();
      expect(button.text()).toContain('Re-running');
      expect(
        wrapper.get('span[role="status"][aria-live="polite"]').text(),
      ).toBe('Re-running environment checks…');
    });

    it('announces completion once the refresh settles', async () => {
      const { wrapper } = await mountAt(
        '/environment-health/checks',
        (store) => {
          store.config = makeConfig();
          store.report = makeReport({
            counts: { ok: 2, warning: 0, critical: 0, unknown: 0 },
          });
        },
      );

      const live = wrapper.get('span[role="status"][aria-live="polite"]');
      expect(live.text()).toContain('Checks up to date.');
      expect(live.text()).toContain('2 checks: 2 OK.');
    });
  });

  describe('failure and loading states', () => {
    it('renders a retry panel — and no tablist — when nothing could load', async () => {
      const { wrapper } = await mountAt(
        '/environment-health/checks',
        (store) => {
          store.error = 'Not allowed.';
        },
      );

      const panel = wrapper.get('.fx-eh__panel--error');
      expect(panel.text()).toContain('could not be loaded');
      expect(panel.text()).toContain('Not allowed.');
      expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
    });

    it('retries through store.refresh() from the failure panel', async () => {
      const { store, wrapper } = await mountAt(
        '/environment-health/checks',
        (s) => {
          s.error = 'Not allowed.';
        },
      );
      const refreshSpy = vi.spyOn(store, 'refresh').mockResolvedValue();

      await wrapper.get('.fx-eh__panel--error button').trigger('click');

      expect(refreshSpy).toHaveBeenCalledTimes(1);
    });

    it('keeps a cached report and the tabs when a later refresh errors', async () => {
      const { wrapper } = await mountAt(
        '/environment-health/checks',
        (store) => {
          store.config = makeConfig();
          store.report = makeReport();
          store.error = 'Probe timed out.';
        },
      );

      expect(wrapper.find('.fx-eh__panel--error').exists()).toBe(false);
      expect(wrapper.find('[role="tablist"]').exists()).toBe(true);
      expect(wrapper.text()).toContain('PHP version');
    });
  });
});
