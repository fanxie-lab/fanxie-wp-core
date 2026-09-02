// Per-module Pinia store for the Environment Health module.
//
// Owns:
//   - the latest HealthReport (checks + counts + cache stamps)
//   - the module settings (currently just the wp.org scan toggle) + dirty tracking
//   - loading flags split by action (initial / refreshing / saving)
//   - toast channel for refresh + save feedback
//
// Components never call the ajax client directly. Mirrors `useHardeningStore`.

import { defineStore } from 'pinia';
import { ajax, AjaxError } from '@/api/ajaxClient';
import {
  buildCountsSentence,
  CHECK_GROUP_ORDER,
  worstStatusFromCounts,
} from '../format';
import type {
  CheckGroup,
  CheckStatus,
  EnvironmentHealthConfig,
  HealthCheck,
  HealthCounts,
  HealthReport,
} from '../types';

export type EnvironmentHealthToastVariant = 'success' | 'error' | 'info';

export interface EnvironmentHealthToast {
  variant: EnvironmentHealthToastVariant;
  message: string;
  /** Epoch stamp used as a render key so repeat toasts re-render. */
  stamp: number;
}

interface LoadingFlags {
  /** First-load spinner state. */
  initial: boolean;
  /** A `refresh` round-trip is in flight. */
  refreshing: boolean;
  saving: boolean;
}

interface EnvironmentHealthState {
  report: HealthReport | null;
  config: EnvironmentHealthConfig | null;
  /** Snapshot of `config` as last fetched/saved, for dirty tracking. */
  pristine: EnvironmentHealthConfig | null;
  loading: LoadingFlags;
  error: string | null;
  toast: EnvironmentHealthToast | null;
}

/** One rendered card: a group plus the checks that belong to it. */
export interface CheckGroupBucket {
  group: CheckGroup;
  checks: HealthCheck[];
}

const EMPTY_COUNTS: HealthCounts = {
  ok: 0,
  warning: 0,
  critical: 0,
  unknown: 0,
};

function extractErrorMessage(err: unknown): string {
  if (err instanceof AjaxError) return err.message;
  if (err instanceof Error) return err.message;
  return 'An unknown error occurred.';
}

/**
 * Deep-clone helper scoped to the config shape. JSON round-trip rather than
 * `structuredClone`, which chokes on Pinia's reactive Proxy when the source
 * lives on the store itself (the reset path). Same call the Hardening store
 * makes — see the note there.
 */
function cloneConfig(config: EnvironmentHealthConfig): EnvironmentHealthConfig {
  return JSON.parse(JSON.stringify(config)) as EnvironmentHealthConfig;
}

export const useEnvironmentHealthStore = defineStore('environmentHealth', {
  state: (): EnvironmentHealthState => ({
    report: null,
    config: null,
    pristine: null,
    loading: {
      initial: false,
      refreshing: false,
      saving: false,
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

    counts(state): HealthCounts {
      return state.report?.counts ?? EMPTY_COUNTS;
    },

    totalChecks(): number {
      const c = this.counts;
      return c.ok + c.warning + c.critical + c.unknown;
    },

    /** True once a report has arrived but contains no checks at all. */
    isEmptyReport(state): boolean {
      return state.report !== null && state.report.checks.length === 0;
    },

    /** Screen-reader sentence for the counts summary. */
    countsSentence(): string {
      return buildCountsSentence(this.counts);
    },

    /** Headline status for the page header pill. */
    overallStatus(): CheckStatus {
      return worstStatusFromCounts(this.counts);
    },

    /**
     * Checks bucketed into cards, in the canonical group order. Groups with no
     * checks are dropped so we never render an empty card. Any group id we do
     * not know about (a PHP-side addition landing before a SPA release) is
     * appended rather than silently discarded.
     */
    checksByGroup(state): CheckGroupBucket[] {
      const buckets = new Map<CheckGroup, HealthCheck[]>();
      for (const check of state.report?.checks ?? []) {
        const existing = buckets.get(check.group);
        if (existing) {
          existing.push(check);
        } else {
          buckets.set(check.group, [check]);
        }
      }

      const rank = (group: CheckGroup): number => {
        const index = CHECK_GROUP_ORDER.indexOf(group);
        return index === -1 ? Number.MAX_SAFE_INTEGER : index;
      };

      return [...buckets.entries()]
        .map(([group, checks]) => ({ group, checks }))
        .sort((a, b) => rank(a.group) - rank(b.group));
    },
  },

  actions: {
    /**
     * Load settings and the (possibly cached) report. Called on module mount.
     *
     * `allSettled` rather than `all`: a failing settings call must not blank
     * the report, and vice versa — either half is independently useful.
     */
    async load(): Promise<void> {
      this.loading.initial = true;
      this.error = null;
      try {
        const [configResult, reportResult] = await Promise.allSettled([
          ajax<EnvironmentHealthConfig>('environment-health/get-config'),
          ajax<HealthReport>('environment-health/get-report'),
        ]);

        if (configResult.status === 'fulfilled') {
          this.applyConfig(configResult.value);
        } else {
          this.error = extractErrorMessage(configResult.reason);
        }

        if (reportResult.status === 'fulfilled') {
          this.report = reportResult.value;
        } else {
          this.error = extractErrorMessage(reportResult.reason);
        }

        if (this.error !== null) {
          this.pushToast(this.error, 'error');
        }
      } finally {
        this.loading.initial = false;
      }
    },

    /** Re-run every check server-side, bypassing the cache. */
    async refresh(): Promise<void> {
      this.loading.refreshing = true;
      this.error = null;
      try {
        const report = await ajax<HealthReport>('environment-health/refresh');
        this.report = report;
        this.pushToast('Environment checks refreshed.', 'success');
      } catch (err) {
        this.error = extractErrorMessage(err);
        this.pushToast(this.error, 'error');
      } finally {
        this.loading.refreshing = false;
      }
    },

    /**
     * Persist settings.
     *
     * `save-config` answers with a fresh HealthReport rather than the stored
     * settings, because changing which checks run changes the report. But the
     * settings we sent went through the PHP schema sanitiser on the way in, so
     * our copy is *not* authoritative — `AjaxController` says so explicitly and
     * tells clients to follow up with `get-config`. We do exactly that: adopting
     * the optimistic copy as the new baseline would leave the form looking clean
     * while showing a value the server had rewritten (an `absint` that floored
     * something, or a key the schema dropped).
     *
     * The re-fetch is best-effort. If it fails the save itself still stood, so
     * we fall back to the sent copy rather than pretending nothing was written.
     */
    async save(): Promise<void> {
      if (!this.config) return;
      const sent = cloneConfig(this.config);
      this.loading.saving = true;
      this.error = null;
      try {
        const report = await ajax<HealthReport>(
          'environment-health/save-config',
          { settings: sent },
        );
        this.report = report;

        try {
          const canonical = await ajax<EnvironmentHealthConfig>(
            'environment-health/get-config',
          );
          this.applyConfig(canonical);
        } catch {
          this.config = sent;
          this.pristine = cloneConfig(sent);
        }

        this.pushToast('Environment Health settings saved.', 'success');
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

    /** Push a toast; views observe `toast` via a watcher. */
    pushToast(message: string, variant: EnvironmentHealthToastVariant): void {
      this.toast = { variant, message, stamp: Date.now() };
    },

    /** Dismiss the active toast. */
    dismissToast(): void {
      this.toast = null;
    },

    /** Internal: adopt a settings payload as both live config and baseline. */
    applyConfig(config: EnvironmentHealthConfig): void {
      this.config = config;
      this.pristine = cloneConfig(config);
    },
  },
});
