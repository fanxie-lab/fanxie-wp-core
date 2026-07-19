// Per-module Pinia store for the Login Protection module.
//
// Owns:
//   - live config (attempts / hide-login / passwords / sessions) + pristine
//     snapshot for dirty tracking
//   - the effective login slug and where it comes from (constant vs stored)
//   - the paginated login-event log (with filters) and the persistent ban list
//   - loading flags split by action, an error channel with field-level detail,
//     and a toast channel for save-bar + mutation feedback
//
// The store is the single source of truth for views; components never call the
// ajax client directly. Mirrors `useHardeningStore` so the module family stays
// consistent.

import { defineStore } from 'pinia';
import { ajax, AjaxError } from '@/api/ajaxClient';
import type {
  AddBanPayload,
  BanListResponse,
  BanRow,
  ClearLockoutResponse,
  LoginLogFilters,
  LoginLogResponse,
  LoginLogRow,
  LoginProtectionConfig,
  LoginProtectionConfigResponse,
  SlugSource,
  SubjectRef,
} from '../types';

export type LoginProtectionToastVariant = 'success' | 'error' | 'info';

export interface LoginProtectionToast {
  variant: LoginProtectionToastVariant;
  message: string;
  /** Epoch stamp used as a Transition key so repeat toasts re-render. */
  stamp: number;
}

/** A surfaced field-level validation error — tells a form which input to flag. */
export interface FieldError {
  /** Dotted config/payload path, e.g. `hide_login.slug` or `subject_value`. */
  field: string;
  message: string;
}

interface LoadingFlags {
  /** First-load spinner state. */
  initial: boolean;
  saving: boolean;
  log: boolean;
  bans: boolean;
  lockout: boolean;
}

interface LogState {
  rows: LoginLogRow[];
  total: number;
  page: number;
  per_page: number;
  filters: LoginLogFilters;
}

interface BansState {
  rows: BanRow[];
  total: number;
}

interface LoginProtectionState {
  config: LoginProtectionConfig | null;
  /** Snapshot of `config` as last fetched/saved, for dirty tracking. */
  pristine: LoginProtectionConfig | null;
  slugSource: SlugSource | null;
  effectiveSlug: string;
  log: LogState;
  bans: BansState;
  loading: LoadingFlags;
  error: string | null;
  fieldError: FieldError | null;
  toast: LoginProtectionToast | null;
}

const DEFAULT_PAGE_SIZE = 25;

/**
 * The shared `ajaxClient` collapses the server's WP_Error envelope down to
 * `{ code, message }`, discarding the nested `data.field` the AjaxController
 * sends. Until the client preserves structured error data, map the known
 * validation codes back to the field they target so a form can highlight the
 * offending input. Values mirror the `data.field` the PHP handlers emit
 * (AjaxController::handle_save_config / read_subject_* / handle_add_ban).
 */
const FIELD_BY_ERROR_CODE: Readonly<Record<string, string>> = {
  invalid_slug: 'hide_login.slug',
  invalid_ip: 'subject_value',
  invalid_subject_type: 'subject_type',
  invalid_subject_value: 'subject_value',
};

function extractErrorMessage(err: unknown): string {
  if (err instanceof AjaxError) return err.message;
  if (err instanceof Error) return err.message;
  return 'An unknown error occurred.';
}

/**
 * Deep-clone helper scoped to the config shape. JSON round-trip is the
 * pragmatic choice: the config is plain JSON (no Dates, Maps, or functions)
 * and `structuredClone` chokes on Pinia's reactive Proxy when the source lives
 * on the store itself (the reset path).
 */
function cloneConfig(config: LoginProtectionConfig): LoginProtectionConfig {
  return JSON.parse(JSON.stringify(config)) as LoginProtectionConfig;
}

export const useLoginProtectionStore = defineStore('login-protection', {
  state: (): LoginProtectionState => ({
    config: null,
    pristine: null,
    slugSource: null,
    effectiveSlug: '',
    log: {
      rows: [],
      total: 0,
      page: 1,
      per_page: DEFAULT_PAGE_SIZE,
      filters: {},
    },
    bans: {
      rows: [],
      total: 0,
    },
    loading: {
      initial: false,
      saving: false,
      log: false,
      bans: false,
      lockout: false,
    },
    error: null,
    fieldError: null,
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
    /** Load config + slug metadata. Called on module mount. */
    async load(): Promise<void> {
      this.loading.initial = true;
      this.error = null;
      try {
        const data = await ajax<LoginProtectionConfigResponse>(
          'login_protection/get-config',
        );
        this.applyConfigEnvelope(data);
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
      this.fieldError = null;
      try {
        const data = await ajax<LoginProtectionConfigResponse>(
          'login_protection/save-config',
          { config: this.config },
        );
        this.applyConfigEnvelope(data);
        this.pushToast('Login protection settings saved.', 'success');
      } catch (err) {
        this.captureError(err);
      } finally {
        this.loading.saving = false;
      }
    },

    /** Discard unsaved changes by re-cloning the last pristine snapshot. */
    reset(): void {
      if (this.pristine) {
        this.config = cloneConfig(this.pristine);
      }
      this.fieldError = null;
    },

    /**
     * Load a page of the login-event log. Merges `filters` into the current
     * state so callers can page without re-passing the active filter set.
     */
    async fetchLog(page = 1, filters?: LoginLogFilters): Promise<void> {
      this.loading.log = true;
      this.error = null;
      if (filters) {
        this.log.filters = { ...this.log.filters, ...filters };
      }
      try {
        const payload: Record<string, unknown> = {
          page,
          per_page: this.log.per_page,
        };
        const { event_type, ip, username, since, until } = this.log.filters;
        if (event_type) payload.event_type = event_type;
        if (ip) payload.ip = ip;
        if (username) payload.username = username;
        if (since) payload.since = since;
        if (until) payload.until = until;

        const data = await ajax<LoginLogResponse>(
          'login_protection/get-log',
          payload,
        );
        this.log.rows = data.rows;
        this.log.total = data.total;
        this.log.page = data.page;
        this.log.per_page = data.per_page;
      } catch (err) {
        this.error = extractErrorMessage(err);
        this.pushToast(this.error, 'error');
      } finally {
        this.loading.log = false;
      }
    },

    /** Reset log filters to empty and reload page 1. */
    async clearLogFilters(): Promise<void> {
      this.log.filters = {};
      await this.fetchLog(1);
    },

    /**
     * Load a page of the persistent ban list. Called on mount so the ban table
     * and every row's Ban/Unban affordance are correct on first render, before
     * any mutation. Applies the response through the same `applyBans` setter
     * that `addBan`/`removeBan` use, and mirrors `fetchLog`'s loading/error
     * handling.
     */
    async fetchBans(page = 1): Promise<void> {
      this.loading.bans = true;
      this.error = null;
      try {
        const data = await ajax<BanListResponse>('login_protection/get-bans', {
          page,
        });
        this.applyBans(data);
      } catch (err) {
        this.error = extractErrorMessage(err);
        this.pushToast(this.error, 'error');
      } finally {
        this.loading.bans = false;
      }
    },

    /** Add a ban. Applies the server's fresh ban list on success. */
    async addBan(payload: AddBanPayload): Promise<void> {
      this.loading.bans = true;
      this.error = null;
      this.fieldError = null;
      try {
        const body: Record<string, unknown> = {
          subject_type: payload.subject_type,
          subject_value: payload.subject_value,
        };
        if (payload.reason) body.reason = payload.reason;
        if (typeof payload.ttl_minutes === 'number') {
          body.ttl_minutes = payload.ttl_minutes;
        }

        const data = await ajax<BanListResponse>(
          'login_protection/add-ban',
          body,
        );
        this.applyBans(data);
        this.pushToast('Ban added.', 'success');
      } catch (err) {
        this.captureError(err);
      } finally {
        this.loading.bans = false;
      }
    },

    /** Remove a ban. Applies the server's fresh ban list on success. */
    async removeBan(payload: SubjectRef): Promise<void> {
      this.loading.bans = true;
      this.error = null;
      this.fieldError = null;
      try {
        const data = await ajax<BanListResponse>(
          'login_protection/remove-ban',
          {
            subject_type: payload.subject_type,
            subject_value: payload.subject_value,
          },
        );
        this.applyBans(data);
        this.pushToast('Ban removed.', 'success');
      } catch (err) {
        this.captureError(err);
      } finally {
        this.loading.bans = false;
      }
    },

    /** Clear a live transient lockout for a subject (does not touch bans). */
    async clearLockout(payload: SubjectRef): Promise<void> {
      this.loading.lockout = true;
      this.error = null;
      this.fieldError = null;
      try {
        await ajax<ClearLockoutResponse>('login_protection/clear-lockout', {
          subject_type: payload.subject_type,
          subject_value: payload.subject_value,
        });
        this.pushToast('Lockout cleared.', 'success');
      } catch (err) {
        this.captureError(err);
      } finally {
        this.loading.lockout = false;
      }
    },

    /** Push a toast; views observe `toast` via a watcher. */
    pushToast(message: string, variant: LoginProtectionToastVariant): void {
      this.toast = { variant, message, stamp: Date.now() };
    },

    /** Dismiss the active toast. */
    dismissToast(): void {
      this.toast = null;
    },

    /** Internal: apply a config envelope (config + slug metadata) to state. */
    applyConfigEnvelope(data: LoginProtectionConfigResponse): void {
      this.config = data.config;
      this.pristine = cloneConfig(data.config);
      this.slugSource = data.slug_source;
      this.effectiveSlug = data.effective_slug;
    },

    /** Internal: apply a ban-list envelope to state. */
    applyBans(data: BanListResponse): void {
      this.bans.rows = data.rows;
      this.bans.total = data.total;
    },

    /**
     * Internal: record an error to `error` + toast, promoting a known
     * validation code to a `fieldError` so the offending input can be flagged.
     */
    captureError(err: unknown): void {
      const message = extractErrorMessage(err);
      this.error = message;
      if (err instanceof AjaxError) {
        const field = FIELD_BY_ERROR_CODE[err.code];
        if (field) {
          this.fieldError = { field, message };
        }
      }
      this.pushToast(message, 'error');
    },
  },
});
