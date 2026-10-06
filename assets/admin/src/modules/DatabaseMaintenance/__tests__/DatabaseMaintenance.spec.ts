import { describe, expect, it } from 'vitest';
import { makeConfig, makeStatus, mountAt } from './harness';

describe('DatabaseMaintenance shell', () => {
  it('redirects the module root to the Cleanup tab', async () => {
    const { router } = await mountAt('/database-maintenance', (s) => {
      s.status = makeStatus();
      s.applyConfig(makeConfig());
    });
    expect(router.currentRoute.value.name).toBe('database-maintenance.cleanup');
  });

  it('renders two tabs with the active one selected', async () => {
    const { wrapper } = await mountAt('/database-maintenance/settings', (s) => {
      s.status = makeStatus();
      s.applyConfig(makeConfig());
    });
    const tabs = wrapper.findAll('[role="tab"]');
    expect(tabs.map((t) => t.text())).toEqual(['Cleanup', 'Settings']);
    expect(tabs[1]?.attributes('aria-selected')).toBe('true');
  });

  it('calls load() once on mount', async () => {
    const { loadSpy } = await mountAt('/database-maintenance/cleanup');
    expect(loadSpy).toHaveBeenCalledTimes(1);
  });

  it('moves between tabs with the arrow keys', async () => {
    const { wrapper, router } = await mountAt(
      '/database-maintenance/cleanup',
      (s) => {
        s.status = makeStatus();
        s.applyConfig(makeConfig());
      },
    );
    await wrapper.get('[role="tab"]').trigger('keydown', { key: 'ArrowRight' });
    await new Promise((r) => setTimeout(r, 0));
    expect(router.currentRoute.value.name).toBe(
      'database-maintenance.settings',
    );
  });

  it('shows a retry state when loading failed and nothing is cached', async () => {
    const { wrapper } = await mountAt('/database-maintenance/cleanup', (s) => {
      s.error = 'Boom';
    });
    expect(wrapper.text()).toContain('could not be loaded');
    expect(wrapper.text()).toContain('Boom');
  });
});
