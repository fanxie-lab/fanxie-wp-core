import { defineStore } from 'pinia';
import { ajax } from '@/api/ajaxClient';
import type { FanxieBootstrap } from '@/types/global';

export interface PingResponse {
  pong: boolean;
  time: number;
}

export interface PingState {
  lastPongAt: number | null;
  lastPingError: string | null;
  inFlight: boolean;
}

interface AppState extends FanxieBootstrap {
  ping: PingState;
}

// Deep-ish freeze of the initial bootstrap snapshot at module load time.
// Pinia needs a plain object we can spread into state — we copy defensively.
function snapshotBootstrap(): FanxieBootstrap {
  // `main.ts` verifies presence before the Vue app mounts, so by the time any
  // component instantiates this store the bootstrap is guaranteed.
  const src = window.fanxieWarden;
  if (!src) {
    throw new Error(
      '[fanxie-warden] useAppStore was called before bootstrap was verified.',
    );
  }
  return {
    version: src.version,
    ajaxUrl: src.ajaxUrl,
    adminUrl: src.adminUrl,
    restUrl: src.restUrl,
    nonce: src.nonce,
    assetsUrl: src.assetsUrl,
    user: {
      id: src.user.id,
      caps: { ...src.user.caps },
    },
    modules: Object.fromEntries(
      Object.entries(src.modules).map(([id, mod]) => [
        id,
        { enabled: mod.enabled, config: { ...mod.config } },
      ]),
    ),
    i18n: { locale: src.i18n.locale },
  };
}

export const useAppStore = defineStore('app', {
  state: (): AppState => ({
    ...snapshotBootstrap(),
    ping: {
      lastPongAt: null,
      lastPingError: null,
      inFlight: false,
    },
  }),

  getters: {
    /** Whether the current user has the given WP capability. */
    isCapable(state): (cap: string) => boolean {
      return (cap: string): boolean => state.user.caps[cap] === true;
    },

    /** Config blob for a given module id, or undefined if the module is absent. */
    moduleConfig(state): (id: string) => Record<string, unknown> | undefined {
      return (id: string): Record<string, unknown> | undefined =>
        state.modules[id]?.config;
    },

    /** Whether a module is enabled. Missing modules return false. */
    isModuleEnabled(state): (id: string) => boolean {
      return (id: string): boolean => state.modules[id]?.enabled === true;
    },
  },

  actions: {
    /**
     * Proof-of-life call against the PHP AjaxRouter's `ping` sub-action.
     * Updates `ping` state but never throws.
     */
    async doPing(): Promise<void> {
      this.ping.inFlight = true;
      this.ping.lastPingError = null;
      try {
        const data = await ajax<PingResponse>('ping');
        if (data.pong && typeof data.time === 'number') {
          this.ping.lastPongAt = data.time;
        } else {
          this.ping.lastPingError = 'Unexpected ping response shape.';
        }
      } catch (err) {
        this.ping.lastPingError =
          err instanceof Error ? err.message : 'Ping failed.';
      } finally {
        this.ping.inFlight = false;
      }
    },
  },
});
