// Drives a purge as a series of time-budgeted `purge-step` calls (spec:
// "Execution model"). Kept out of the view so its loop is unit-testable.

import { ref, type Ref } from 'vue';
import { ajax, AjaxError } from '@/api/ajaxClient';
import type { PurgeStepResponse, TaskId } from '../types';

export type PurgeState = 'pending' | 'running' | 'done' | 'error' | 'cancelled';

export interface PurgeProgress {
  id: TaskId;
  deleted: number;
  failed: number;
  remaining: number;
  state: PurgeState;
  error: string | null;
}

export interface PurgeTotals {
  deleted: number;
  failed: number;
  cancelled: boolean;
}

export interface PurgeDeps {
  step: (id: TaskId) => Promise<PurgeStepResponse>;
  sleep: (ms: number) => Promise<void>;
}

export const BUSY_RETRY_MS = 2000;
export const BUSY_MAX_RETRIES = 5;

const defaultDeps: PurgeDeps = {
  step: (id) =>
    ajax<PurgeStepResponse>('database-maintenance/purge-step', { task: id }),
  sleep: (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
};

export function usePurgeRun(deps: PurgeDeps = defaultDeps): {
  progress: Ref<Record<string, PurgeProgress>>;
  running: Ref<boolean>;
  announcement: Ref<string>;
  run: (ids: TaskId[]) => Promise<PurgeTotals>;
  cancel: () => void;
} {
  const progress = ref<Record<string, PurgeProgress>>({});
  const running = ref(false);
  const announcement = ref('');
  let cancelRequested = false;

  // Function form: TS cannot see that cancel() flips the flag across awaits.
  function isCancelled(): boolean {
    return cancelRequested;
  }

  function cancel(): void {
    cancelRequested = true;
  }

  async function runOne(id: TaskId): Promise<void> {
    const entry = progress.value[id];
    if (!entry) return;
    entry.state = 'running';
    let busyTries = 0;

    for (;;) {
      if (isCancelled()) {
        entry.state = 'cancelled';
        return;
      }

      let res: PurgeStepResponse;
      try {
        res = await deps.step(id);
      } catch (err) {
        entry.state = 'error';
        entry.error =
          err instanceof AjaxError || err instanceof Error
            ? err.message
            : 'Request failed.';
        return;
      }

      if (res.busy) {
        busyTries += 1;
        if (busyTries > BUSY_MAX_RETRIES) {
          entry.state = 'error';
          entry.error =
            'Another cleanup of this item is already running. Try again in a minute.';
          return;
        }
        await deps.sleep(BUSY_RETRY_MS);
        continue;
      }

      entry.deleted += res.deleted;
      // Failed rows are retried (and fail again) in later steps, so a running
      // sum would count them repeatedly; the max is the distinct-failure count.
      entry.failed = Math.max(entry.failed, res.failed);
      entry.remaining = res.remaining;

      if (res.done) {
        entry.state = 'done';
        announcement.value = `${id}: deleted ${String(entry.deleted)}${entry.failed ? `, ${String(entry.failed)} could not be deleted` : ''}.`;
        return;
      }

      if (res.deleted === 0) {
        // Rows that keep failing across steps would otherwise loop forever.
        entry.state = 'error';
        entry.error = `${String(entry.failed)} rows could not be deleted.`;
        return;
      }

      if (isCancelled()) {
        entry.state = 'cancelled';
        return;
      }
    }
  }

  async function run(ids: TaskId[]): Promise<PurgeTotals> {
    cancelRequested = false;
    running.value = true;
    progress.value = Object.fromEntries(
      ids.map((id) => [
        id,
        {
          id,
          deleted: 0,
          failed: 0,
          remaining: 0,
          state: 'pending' as PurgeState,
          error: null,
        },
      ]),
    );

    try {
      for (const id of ids) {
        if (isCancelled()) {
          const skipped = progress.value[id];
          if (skipped) skipped.state = 'cancelled';
          continue;
        }
        await runOne(id);
      }
    } finally {
      running.value = false;
    }

    const all = Object.values(progress.value);
    return {
      deleted: all.reduce((n, p) => n + p.deleted, 0),
      failed: all.reduce((n, p) => n + p.failed, 0),
      cancelled: all.some((p) => p.state === 'cancelled'),
    };
  }

  return { progress, running, announcement, run, cancel };
}
