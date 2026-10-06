import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { vi } from 'vitest';
import DatabaseMaintenance from '../DatabaseMaintenance.vue';
import CleanupView from '../views/CleanupView.vue';
import SettingsView from '../views/SettingsView.vue';
import { useDatabaseMaintenanceStore } from '../stores/databaseMaintenance';
import type {
  ConfigResponse,
  DbSettings,
  StatusItem,
  StatusResponse,
  TaskId,
} from '../types';

const LABELS: Record<TaskId, string> = {
  revisions: 'Post revisions',
  'expired-transients': 'Expired transients',
  'orphaned-postmeta': 'Orphaned post meta',
  'orphaned-usermeta': 'Orphaned user meta',
  'orphaned-termmeta': 'Orphaned term meta',
  'orphaned-commentmeta': 'Orphaned comment meta',
  'auto-drafts': 'Auto-drafts',
  'trashed-posts': 'Trashed posts',
  'spam-comments': 'Spam comments',
};

export function makeStatus(
  counts: Partial<Record<TaskId, number>> = {},
  objectCache = false,
): StatusResponse {
  const defaults: Partial<Record<TaskId, number>> = {
    revisions: 1247,
    'expired-transients': 89,
    'spam-comments': 156,
  };
  const items: StatusItem[] = (Object.keys(LABELS) as TaskId[]).map((id) => {
    const count = counts[id] ?? defaults[id] ?? 0;
    return { id, label: LABELS[id], count, bytes: count * 100 };
  });
  return {
    items,
    total_bytes: items.reduce((n, i) => n + i.bytes, 0),
    object_cache: objectCache,
  };
}

export function makeSettings(over: Partial<DbSettings> = {}): DbSettings {
  return {
    revision_limit_enabled: false,
    revisions_keep: 20,
    auto_draft_days: 7,
    trash_days: 30,
    spam_days: 15,
    schedule_enabled: false,
    schedule_frequency: 'weekly',
    schedule_hour: 3,
    schedule_tasks: {
      revisions: false,
      'expired-transients': true,
      'orphaned-postmeta': true,
      'orphaned-usermeta': true,
      'orphaned-termmeta': true,
      'orphaned-commentmeta': true,
      'auto-drafts': true,
      'trashed-posts': false,
      'spam-comments': true,
    },
    ...over,
  };
}

export function makeConfig(over: Partial<ConfigResponse> = {}): ConfigResponse {
  return {
    settings: makeSettings(),
    revisions_constant: null,
    next_run: null,
    ...over,
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
        path: '/database-maintenance',
        name: 'database-maintenance',
        component: DatabaseMaintenance,
        redirect: { name: 'database-maintenance.cleanup' },
        children: [
          {
            path: 'cleanup',
            name: 'database-maintenance.cleanup',
            component: CleanupView,
          },
          {
            path: 'settings',
            name: 'database-maintenance.settings',
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
  store: ReturnType<typeof useDatabaseMaintenanceStore>,
) => void;

/**
 * Mount the real nested route tree at `path`, with `load()` stubbed so the
 * seeded state is what renders.
 */
export async function mountAt(path: string, seed: StoreSeed = () => undefined) {
  setActivePinia(createPinia());
  const store = useDatabaseMaintenanceStore();
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
