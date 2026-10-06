import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { useDatabaseMaintenanceStore } from '../databaseMaintenance';
import * as client from '@/api/ajaxClient';
import { makeConfig, makeStatus } from '../../__tests__/harness';

describe('useDatabaseMaintenanceStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.restoreAllMocks();
  });

  it('load() fetches status and config', async () => {
    const spy = vi
      .spyOn(client, 'ajax')
      .mockImplementation((action: string) =>
        Promise.resolve(
          action.endsWith('get-status') ? makeStatus() : makeConfig(),
        ),
      );
    const store = useDatabaseMaintenanceStore();
    await store.load();

    expect(spy).toHaveBeenCalledWith('database-maintenance/get-status');
    expect(spy).toHaveBeenCalledWith('database-maintenance/get-config');
    expect(store.status?.items.length).toBeGreaterThan(0);
    expect(store.isDirty).toBe(false);
  });

  it('isLocked follows revisions_constant', async () => {
    vi.spyOn(client, 'ajax').mockImplementation((action: string) =>
      Promise.resolve(
        action.endsWith('get-status')
          ? makeStatus()
          : makeConfig({ revisions_constant: 5 }),
      ),
    );
    const store = useDatabaseMaintenanceStore();
    await store.load();
    expect(store.isLocked).toBe(true);
  });

  it('purgeableIds excludes zero-count rows', async () => {
    vi.spyOn(client, 'ajax').mockImplementation((action: string) =>
      Promise.resolve(
        action.endsWith('get-status') ? makeStatus() : makeConfig(),
      ),
    );
    const store = useDatabaseMaintenanceStore();
    await store.load();
    expect(store.purgeableIds).not.toContain('orphaned-termmeta');
  });

  it('save() posts settings and resets dirty state', async () => {
    const spy = vi
      .spyOn(client, 'ajax')
      .mockImplementation(
        (action: string, payload?: Record<string, unknown>) => {
          if (action.endsWith('save-config')) {
            return Promise.resolve(
              makeConfig({ settings: payload!.settings as never }),
            );
          }
          return Promise.resolve(
            action.endsWith('get-status') ? makeStatus() : makeConfig(),
          );
        },
      );
    const store = useDatabaseMaintenanceStore();
    await store.load();
    store.settings!.trash_days = 60;
    expect(store.isDirty).toBe(true);

    await store.save();

    expect(spy).toHaveBeenCalledWith('database-maintenance/save-config', {
      settings: expect.objectContaining({ trash_days: 60 }),
    });
    expect(store.isDirty).toBe(false);
    expect(store.toast?.variant).toBe('success');
  });

  it('save() refreshes status so counts reflect the new limits', async () => {
    const spy = vi
      .spyOn(client, 'ajax')
      .mockImplementation(
        (action: string, payload?: Record<string, unknown>) => {
          if (action.endsWith('save-config')) {
            return Promise.resolve(
              makeConfig({ settings: payload!.settings as never }),
            );
          }
          return Promise.resolve(
            action.endsWith('get-status') ? makeStatus() : makeConfig(),
          );
        },
      );
    const store = useDatabaseMaintenanceStore();
    await store.load();
    store.previews = { revisions: { id: 'revisions' } as never };
    spy.mockClear();

    await store.save();

    expect(spy).toHaveBeenCalledWith('database-maintenance/get-status');
    expect(store.previews).toEqual({});
  });
});
