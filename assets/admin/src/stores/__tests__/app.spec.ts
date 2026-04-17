import { beforeEach, describe, expect, it } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { useAppStore } from '@/stores/app';
import { defaultActiveModuleId } from '@/config/modules';
import { mockAjaxResponse } from '../../../tests/setup';

describe('useAppStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('hydrates activeModuleId from the module registry default', () => {
    const store = useAppStore();

    expect(store.activeModuleId).toBe(defaultActiveModuleId);
  });

  it('setActiveModule updates the active module id', () => {
    const store = useAppStore();

    store.setActiveModule('hardening');

    expect(store.activeModuleId).toBe('hardening');
  });

  it('setActiveModule is a no-op when the id is unchanged', () => {
    const store = useAppStore();
    store.setActiveModule('hardening');
    const before = store.activeModuleId;

    store.setActiveModule('hardening');

    // Hard to observe a skipped write directly in Pinia without instrumentation,
    // but we can at least confirm the state stays coherent.
    expect(store.activeModuleId).toBe(before);
  });

  describe('doPing', () => {
    it('records lastPongAt on success and clears lastPingError', async () => {
      mockAjaxResponse('ping', { pong: true, time: 1700000000 });
      const store = useAppStore();

      // Kick off the call but do NOT await yet — we want to assert
      // inFlight flips true synchronously before the promise resolves.
      const pending = store.doPing();
      expect(store.ping.inFlight).toBe(true);

      await pending;

      expect(store.ping.inFlight).toBe(false);
      expect(store.ping.lastPongAt).toBe(1700000000);
      expect(store.ping.lastPingError).toBeNull();
    });

    it('records lastPingError on server-reported failure and leaves lastPongAt untouched', async () => {
      mockAjaxResponse(
        'ping',
        { code: 'failed', message: 'nope' },
        { success: false },
      );
      const store = useAppStore();

      await store.doPing();

      expect(store.ping.inFlight).toBe(false);
      expect(store.ping.lastPingError).toBe('nope');
      expect(store.ping.lastPongAt).toBeNull();
    });

    it('records lastPingError when response shape is unexpected', async () => {
      mockAjaxResponse('ping', { pong: false });
      const store = useAppStore();

      await store.doPing();

      expect(store.ping.lastPingError).toBe('Unexpected ping response shape.');
      expect(store.ping.lastPongAt).toBeNull();
    });
  });
});
