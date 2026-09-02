import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { vi } from 'vitest';
import EnvironmentHealth from '../EnvironmentHealth.vue';
import ChecksView from '../views/ChecksView.vue';
import SettingsView from '../views/SettingsView.vue';
import { useEnvironmentHealthStore } from '../stores/environmentHealth';
import type {
  EnvironmentHealthConfig,
  HealthCheck,
  HealthCounts,
  HealthReport,
} from '../types';

export function makeCheck(overrides: Partial<HealthCheck> = {}): HealthCheck {
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

export function makeCounts(
  overrides: Partial<HealthCounts> = {},
): HealthCounts {
  return { ok: 1, warning: 0, critical: 0, unknown: 0, ...overrides };
}

export function makeReport(
  overrides: Partial<HealthReport> = {},
): HealthReport {
  return {
    generated_at: Math.floor(Date.now() / 1000) - 120,
    cached_until: Math.floor(Date.now() / 1000) + 180,
    counts: makeCounts(),
    checks: [makeCheck()],
    ...overrides,
  };
}

export function makeConfig(
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

/**
 * Build a fresh in-memory router with the same nested-route shape the real app
 * uses. Memory history keeps tests deterministic and avoids window.location.
 */
export function makeTestRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/environment-health',
        name: 'environment-health',
        component: EnvironmentHealth,
        redirect: { name: 'environment-health.checks' },
        children: [
          {
            path: 'checks',
            name: 'environment-health.checks',
            component: ChecksView,
          },
          {
            path: 'settings',
            name: 'environment-health.settings',
            component: SettingsView,
          },
        ],
      },
      // Stand-in for navigating away to another module in the sidebar.
      {
        path: '/hardening',
        name: 'hardening',
        component: { template: '<div class="other-module" />' },
      },
    ],
  });
}

export type StoreSeed = (
  store: ReturnType<typeof useEnvironmentHealthStore>,
) => void;

/**
 * Mount the real nested route tree at `path`, with `load()` stubbed so the
 * seeded state is what renders.
 */
export async function mountAt(path: string, seed: StoreSeed = () => undefined) {
  setActivePinia(createPinia());
  const store = useEnvironmentHealthStore();
  const loadSpy = vi.spyOn(store, 'load').mockResolvedValue();
  seed(store);

  const router = makeTestRouter();
  await router.push(path);
  await router.isReady();

  const wrapper = mount(
    { template: '<router-view />' },
    { global: { plugins: [router] }, attachTo: document.body },
  );
  await flushPromises();

  return { router, wrapper, store, loadSpy };
}
