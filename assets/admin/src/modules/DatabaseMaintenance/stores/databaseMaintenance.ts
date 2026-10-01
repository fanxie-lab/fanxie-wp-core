// Per-module Pinia store for Database Maintenance. Components never call the
// ajax client directly (mirrors useEnvironmentHealthStore).

import { defineStore } from 'pinia';
import { ajax, AjaxError } from '@/api/ajaxClient';
import type {
  ConfigResponse,
  DbSettings,
  PreviewResponse,
  StatusResponse,
  TaskId,
} from '../types';

export type DbToastVariant = 'success' | 'error' | 'info';

export interface DbToast {
  variant: DbToastVariant;
  message: string;
  stamp: number;
}

interface State {
  status: StatusResponse | null;
  settings: DbSettings | null;
  pristine: DbSettings | null;
  revisionsConstant: number | boolean | null;
  nextRun: string | null;
  previews: Partial<Record<TaskId, PreviewResponse>>;
  loading: {
    initial: boolean;
    refreshing: boolean;
    saving: boolean;
    preview: TaskId | null;
  };
  error: string | null;
  toast: DbToast | null;
}

function message(err: unknown): string {
  if (err instanceof AjaxError || err instanceof Error) return err.message;
  return 'An unknown error occurred.';
}

function clone(s: DbSettings): DbSettings {
  return JSON.parse(JSON.stringify(s)) as DbSettings;
}

export const useDatabaseMaintenanceStore = defineStore('databaseMaintenance', {
  state: (): State => ({
    status: null,
    settings: null,
    pristine: null,
    revisionsConstant: null,
    nextRun: null,
    previews: {},
    loading: {
      initial: false,
      refreshing: false,
      saving: false,
      preview: null,
    },
    error: null,
    toast: null,
  }),

  getters: {
    isDirty(state): boolean {
      if (!state.settings || !state.pristine) return false;
      return JSON.stringify(state.settings) !== JSON.stringify(state.pristine);
    },
    isLocked(state): boolean {
      return state.revisionsConstant !== null;
    },
    purgeableIds(state): TaskId[] {
      return (state.status?.items ?? [])
        .filter((i) => i.count > 0)
        .map((i) => i.id);
    },
  },

  actions: {
    notify(variant: DbToastVariant, msg: string): void {
      this.toast = { variant, message: msg, stamp: Date.now() };
    },

    applyConfig(cfg: ConfigResponse): void {
      this.settings = clone(cfg.settings);
      this.pristine = clone(cfg.settings);
      this.revisionsConstant = cfg.revisions_constant;
      this.nextRun = cfg.next_run;
    },

    async load(): Promise<void> {
      this.loading.initial = true;
      this.error = null;
      try {
        const [status, config] = await Promise.all([
          ajax<StatusResponse>('database-maintenance/get-status'),
          ajax<ConfigResponse>('database-maintenance/get-config'),
        ]);
        this.status = status;
        this.applyConfig(config);
      } catch (err) {
        this.error = message(err);
      } finally {
        this.loading.initial = false;
      }
    },

    async refreshStatus(): Promise<void> {
      this.loading.refreshing = true;
      try {
        this.status = await ajax<StatusResponse>(
          'database-maintenance/get-status',
        );
        this.previews = {};
      } catch (err) {
        this.notify('error', message(err));
      } finally {
        this.loading.refreshing = false;
      }
    },

    async loadPreview(id: TaskId): Promise<void> {
      this.loading.preview = id;
      try {
        this.previews[id] = await ajax<PreviewResponse>(
          'database-maintenance/preview',
          { task: id },
        );
      } catch (err) {
        this.notify('error', message(err));
      } finally {
        this.loading.preview = null;
      }
    },

    async save(): Promise<void> {
      if (!this.settings) return;
      this.loading.saving = true;
      try {
        const cfg = await ajax<ConfigResponse>(
          'database-maintenance/save-config',
          {
            settings: clone(this.settings),
          },
        );
        this.applyConfig(cfg);
        // Age limits and the keep-N value change what is purgeable.
        await this.refreshStatus();
        this.notify('success', 'Settings saved.');
      } catch (err) {
        this.notify('error', message(err));
      } finally {
        this.loading.saving = false;
      }
    },

    reset(): void {
      if (this.pristine) this.settings = clone(this.pristine);
    },
  },
});
