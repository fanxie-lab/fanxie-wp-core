// Per-module Pinia store for the Security Headers module.
//
// Owns:
//   - live config (headers + CSP) + derived status
//   - paginated violation log with filters
//   - loading + error flags
//
// The store is the single source of truth for views; components never call
// the ajax client directly.

import { defineStore } from 'pinia';
import { ajax, AjaxError } from '@/api/ajaxClient';
import type {
  ConfigResponse,
  PurgeResponse,
  SecurityHeadersConfig,
  SecurityHeadersStatus,
  ViolationFilters,
  ViolationRecord,
  ViolationsResponse,
} from '../types';

interface LoadingFlags {
  config: boolean;
  violations: boolean;
  saving: boolean;
}

interface ViolationsState {
  rows: ViolationRecord[];
  total: number;
  page: number;
  per_page: number;
  filters: ViolationFilters;
}

interface SecurityHeadersState {
  config: SecurityHeadersConfig | null;
  status: SecurityHeadersStatus | null;
  violations: ViolationsState;
  loading: LoadingFlags;
  /** Human-readable error message from the last failed call. */
  error: string | null;
  /** Snapshot of `config` as last fetched/saved, for dirty-tracking. */
  pristine: SecurityHeadersConfig | null;
  /** Last toast message the store wants to surface. */
  toast: {
    variant: 'success' | 'error' | 'info';
    message: string;
    stamp: number;
  } | null;
}

const DEFAULT_PAGE_SIZE = 20;

function extractErrorMessage(err: unknown): string {
  if (err instanceof AjaxError) return err.message;
  if (err instanceof Error) return err.message;
  return 'An unknown error occurred.';
}

/**
 * Deep-clone helper scoped to the config shape — shallow clone misses the
 * nested `csp.directives` arrays. Structured clone is available in all
 * supported targets (browsers + happy-dom).
 */
function cloneConfig(config: SecurityHeadersConfig): SecurityHeadersConfig {
  return structuredClone(config);
}

export const useSecurityHeadersStore = defineStore('security-headers', {
  state: (): SecurityHeadersState => ({
    config: null,
    status: null,
    violations: {
      rows: [],
      total: 0,
      page: 1,
      per_page: DEFAULT_PAGE_SIZE,
      filters: {},
    },
    loading: {
      config: false,
      violations: false,
      saving: false,
    },
    error: null,
    pristine: null,
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
    /** Load config + first page of violations. Called on module mount. */
    async load(): Promise<void> {
      this.loading.config = true;
      this.error = null;
      try {
        const data = await ajax<ConfigResponse>('security-headers/get-config');
        this.config = data.settings;
        this.pristine = cloneConfig(data.settings);
        this.status = data.status;
      } catch (err) {
        this.error = extractErrorMessage(err);
        this.pushToast('error', this.error);
      } finally {
        this.loading.config = false;
      }

      // Fire-and-forget initial violations load. Errors surface via
      // `loadViolations`'s own handling — we don't want a violations failure
      // to mask a successful config load.
      await this.loadViolations(1);
    },

    /** Persist the current config. Resets dirty state on success. */
    async save(): Promise<void> {
      if (!this.config) return;
      this.loading.saving = true;
      this.error = null;
      try {
        const payload = {
          settings: this.config,
        };
        const data = await ajax<ConfigResponse>(
          'security-headers/save-config',
          payload,
        );
        this.config = data.settings;
        this.pristine = cloneConfig(data.settings);
        this.status = data.status;
        this.pushToast('success', 'Security headers saved.');
      } catch (err) {
        this.error = extractErrorMessage(err);
        this.pushToast('error', this.error);
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

    /** Apply a preset. Server merges directives and returns the new config. */
    async applyPreset(id: string): Promise<void> {
      this.loading.saving = true;
      this.error = null;
      try {
        const data = await ajax<ConfigResponse>(
          'security-headers/apply-preset',
          { preset_id: id },
        );
        this.config = data.settings;
        this.pristine = cloneConfig(data.settings);
        this.status = data.status;
        this.pushToast('success', 'Preset applied.');
      } catch (err) {
        this.error = extractErrorMessage(err);
        this.pushToast('error', this.error);
      } finally {
        this.loading.saving = false;
      }
    },

    /** Load a page of violations. Merges `filters` into the current state. */
    async loadViolations(page = 1, filters?: ViolationFilters): Promise<void> {
      this.loading.violations = true;
      if (filters) {
        this.violations.filters = { ...this.violations.filters, ...filters };
      }
      try {
        const payload: Record<string, unknown> = {
          page,
          per_page: this.violations.per_page,
        };
        const { directive, since, until } = this.violations.filters;
        if (directive) payload.directive = directive;
        if (since) payload.since = since;
        if (until) payload.until = until;

        const data = await ajax<ViolationsResponse>(
          'security-headers/list-violations',
          payload,
        );
        this.violations.rows = data.rows;
        this.violations.total = data.total;
        this.violations.page = data.page;
        this.violations.per_page = data.per_page;
      } catch (err) {
        this.error = extractErrorMessage(err);
        this.pushToast('error', this.error);
      } finally {
        this.loading.violations = false;
      }
    },

    /** Reset filters to empty and reload page 1. */
    async clearViolationFilters(): Promise<void> {
      this.violations.filters = {};
      await this.loadViolations(1);
    },

    /**
     * Purge violations. Pass `{ all: true }` to wipe everything, or
     * `{ older_than_days: N }` to delete rows older than N days.
     * Reloads page 1 on success.
     */
    async purgeViolations(
      payload: { all: true } | { older_than_days: number },
    ): Promise<void> {
      this.loading.violations = true;
      try {
        const data = await ajax<PurgeResponse>(
          'security-headers/purge-violations',
          payload as Record<string, unknown>,
        );
        this.pushToast(
          'success',
          data.deleted === 1
            ? '1 violation purged.'
            : `${String(data.deleted)} violations purged.`,
        );
        await this.loadViolations(1);
      } catch (err) {
        this.error = extractErrorMessage(err);
        this.pushToast('error', this.error);
      } finally {
        this.loading.violations = false;
      }
    },

    /** Internal: push a toast; views observe `toast` via a watcher. */
    pushToast(variant: 'success' | 'error' | 'info', message: string): void {
      this.toast = { variant, message, stamp: Date.now() };
    },

    /** Dismiss the active toast. */
    dismissToast(): void {
      this.toast = null;
    },
  },
});
