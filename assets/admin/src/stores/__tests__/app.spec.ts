import { beforeEach, describe, expect, it } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { useAppStore } from '@/stores/app';
import { mockAjaxResponse } from '../../../tests/setup';

describe('useAppStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
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
