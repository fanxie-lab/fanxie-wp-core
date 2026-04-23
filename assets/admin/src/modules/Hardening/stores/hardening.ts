// Per-module Pinia store for the Hardening module.
//
// Owns:
//   - live config (seven feature groups) + derived status + probe results
//   - loading + error flags split by action (initial / saving / checks / fixing)
//   - toast channel for save-bar + fix-result feedback
//
// The store is the single source of truth for views; components never call
// the ajax client directly. Mirrors `useSecurityHeadersStore` so future
// modules can copy either template.

import { defineStore } from 'pinia';
import { ajax, AjaxError } from '@/api/ajaxClient';
import type {
  ApplyFixTarget,
  ChecksResult,
  HardeningConfig,
  HardeningResponse,
  HardeningStatus,
  UploadsGuardTarget,
} from '../types';

export type HardeningToastVariant = 'success' | 'error' | 'info';

export interface HardeningToast {
  variant: HardeningToastVariant;
  message: string;
  /** Epoch stamp used as Transition key so repeat toasts re-render. */
  stamp: number;
}

interface LoadingFlags {
  /** First-load spinner state. */
  initial: boolean;
  saving: boolean;
  checks: boolean;
  /**
   * Legacy flag kept for `applyFix` callers — also toggled by the newer
   * drop/remove guard actions so the global SaveBar keeps disabling itself
   * while any filesystem mutation is in flight.
   */
  fixing: boolean;
  /**
   * Per-target mutation flag for the uploads guard rows. Keyed by target so
   * the spinner renders only on the clicked button instead of every row.
   */
  guarding: Record<UploadsGuardTarget, boolean>;
}

interface HardeningState {
  config: HardeningConfig | null;
  /** Snapshot of `config` as last fetched/saved, for dirty tracking. */
  pristine: HardeningConfig | null;
  status: HardeningStatus | null;
  checks: ChecksResult | null;
  loading: LoadingFlags;
  error: string | null;
  toast: HardeningToast | null;
}

function extractErrorMessage(err: unknown): string {
  if (err instanceof AjaxError) return err.message;
  if (err instanceof Error) return err.message;
  return 'An unknown error occurred.';
}

/**
 * Deep-clone helper scoped to the config shape. JSON round-trip is the
 * pragmatic choice here: the config is plain JSON (no Dates, no Maps, no
 * functions) and `structuredClone` chokes on Pinia's reactive Proxy when
 * the source happens to live on the store itself (reset path).
 */
function cloneConfig(config: HardeningConfig): HardeningConfig {
  return JSON.parse(JSON.stringify(config)) as HardeningConfig;
}

export const useHardeningStore = defineStore('hardening', {
  state: (): HardeningState => ({
    config: null,
    pristine: null,
    status: null,
    checks: null,
    loading: {
      initial: false,
      saving: false,
      checks: false,
      fixing: false,
      guarding: {
        uploads_index: false,
        uploads_htaccess: false,
      },
    },
    error: null,
    toast: null,
  }),

  getters: {
    /** True when the current config differs from the last persisted snapshot. */
    isDirty(state): boolean {
      if (!state.config || !state.pristine) return false;
      return JSON.stringify(state.config) !== JSON.stringify(state.pristine);
    },
  },

  actions: {
    /** Load config + status + checks. Called on module mount. */
    async load(): Promise<void> {
      this.loading.initial = true;
      this.error = null;
      try {
        const data = await ajax<HardeningResponse>('hardening/get-config');
        this.applyEnvelope(data);
      } catch (err) {
        this.error = extractErrorMessage(err);
        this.pushToast(this.error, 'error');
      } finally {
        this.loading.initial = false;
      }
    },

    /** Persist the current config. Resets dirty state on success. */
    async save(): Promise<void> {
      if (!this.config) return;
      this.loading.saving = true;
      this.error = null;
      try {
        const data = await ajax<HardeningResponse>('hardening/save-config', {
          settings: this.config,
        });
        this.applyEnvelope(data);
        this.pushToast('Hardening settings saved.', 'success');
      } catch (err) {
        this.error = extractErrorMessage(err);
        this.pushToast(this.error, 'error');
      } finally {
        this.loading.saving = false;
      }
    },

    /** Discard unsaved changes by re-cloning the last pristine snapshot. */
    reset(): void {
      if (this.pristine) {
        this.config = cloneConfig(this.pristine);
      }
    },

    /** Re-run probes (bypasses the server-side 5-minute cache). */
    async runChecks(): Promise<void> {
      this.loading.checks = true;
      this.error = null;
      try {
        const data = await ajax<HardeningResponse>('hardening/run-checks');
        this.applyEnvelope(data);
        this.pushToast('Checks refreshed.', 'info');
      } catch (err) {
        this.error = extractErrorMessage(err);
        this.pushToast(this.error, 'error');
      } finally {
        this.loading.checks = false;
      }
    },

    /**
     * Apply a server-side fix (drops the uploads index.php or .htaccess).
     * Payload shape is flat per the wire contract — see CLAUDE.md §3.4.
     */
    async applyFix(target: ApplyFixTarget): Promise<void> {
      this.loading.fixing = true;
      this.error = null;
      try {
        const data = await ajax<HardeningResponse>('hardening/apply-fix', {
          target,
        });
        this.applyEnvelope(data);
        const label =
          target === 'uploads_index'
            ? 'Uploads index.php'
            : 'Uploads .htaccess';
        this.pushToast(`${label} applied.`, 'success');
      } catch (err) {
        this.error = extractErrorMessage(err);
        this.pushToast(this.error, 'error');
      } finally {
        this.loading.fixing = false;
      }
    },

    /**
     * Drop (write) the uploads guard for the given target. Backend endpoint:
     * `hardening/drop-upload-guard` with `{ target }`. The returned envelope
     * is the same shape as `apply-fix` (settings / status / checks) so
     * re-derived row status flips immediately — no second click needed.
     */
    async dropUploadGuard(target: UploadsGuardTarget): Promise<void> {
      this.loading.fixing = true;
      this.loading.guarding[target] = true;
      this.error = null;
      try {
        const data = await ajax<HardeningResponse>(
          'hardening/drop-upload-guard',
          { target },
        );
        this.applyEnvelope(data);
        this.pushToast('Protection restored.', 'success');
      } catch (err) {
        this.error = extractErrorMessage(err);
        this.pushToast(this.error, 'error');
      } finally {
        this.loading.guarding[target] = false;
        this.loading.fixing = false;
      }
    },

    /**
     * Remove the uploads guard for the given target. Backend endpoint:
     * `hardening/remove-upload-guard` with `{ target }`. Intended for the
     * "Remove" action on an already-protected row when the admin wants to
     * opt out of auto-maintain for that specific file.
     */
    async removeUploadGuard(target: UploadsGuardTarget): Promise<void> {
      this.loading.fixing = true;
      this.loading.guarding[target] = true;
      this.error = null;
      try {
        const data = await ajax<HardeningResponse>(
          'hardening/remove-upload-guard',
          { target },
        );
        this.applyEnvelope(data);
        this.pushToast('Protection removed.', 'success');
      } catch (err) {
        this.error = extractErrorMessage(err);
        this.pushToast(this.error, 'error');
      } finally {
        this.loading.guarding[target] = false;
        this.loading.fixing = false;
      }
    },

    /** Push a toast; views observe `toast` via a watcher. */
    pushToast(message: string, variant: HardeningToastVariant): void {
      this.toast = { variant, message, stamp: Date.now() };
    },

    /** Dismiss the active toast. */
    dismissToast(): void {
      this.toast = null;
    },

    /** Internal: apply a full server envelope to state. */
    applyEnvelope(data: HardeningResponse): void {
      this.config = data.settings;
      this.pristine = cloneConfig(data.settings);
      this.status = data.status;
      this.checks = data.checks;
    },
  },
});
