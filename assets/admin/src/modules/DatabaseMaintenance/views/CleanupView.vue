<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { ConfirmDialog, Tooltip } from '@/components';
import { useDatabaseMaintenanceStore } from '../stores/databaseMaintenance';
import { usePurgeRun } from '../composables/usePurgeRun';
import { formatBytes, formatRows } from '../format';
import type { StatusItem, TaskId } from '../types';

/**
 * Cleanup tab: one row per cleanable item with an on-demand preview, bulk
 * select and a confirmed, cancellable purge. Purge controls are disabled while
 * a run is in flight, so a stalled purge can never be re-entered from the UI
 * (the composable itself bails out of stalled loops).
 */

const store = useDatabaseMaintenanceStore();
const purge = usePurgeRun();

const selected = ref<Set<TaskId>>(new Set());
const expanded = ref<TaskId | null>(null);
const confirmOpen = ref(false);
const sectionRef = ref<HTMLElement | null>(null);
const selectedBtn = ref<HTMLButtonElement | null>(null);
const allBtn = ref<HTMLButtonElement | null>(null);
let askedFrom: 'selected' | 'all' = 'selected';
const pendingIds = ref<TaskId[]>([]);

const items = computed<StatusItem[]>(() => store.status?.items ?? []);
const totalBytes = computed(() => store.status?.total_bytes ?? 0);
const busy = computed(() => purge.running.value);

const pendingItems = computed(() =>
  items.value.filter((i) => pendingIds.value.includes(i.id)),
);

function toggleRow(id: TaskId, on: boolean): void {
  const next = new Set(selected.value);
  if (on) next.add(id);
  else next.delete(id);
  selected.value = next;
}

async function togglePreview(id: TaskId): Promise<void> {
  if (expanded.value === id) {
    expanded.value = null;
    return;
  }
  expanded.value = id;
  if (!store.previews[id]) await store.loadPreview(id);
}

function ask(ids: TaskId[], from: 'selected' | 'all'): void {
  askedFrom = from;
  pendingIds.value = ids;
  confirmOpen.value = true;
}

async function confirmPurge(): Promise<void> {
  confirmOpen.value = false;
  const ids = [...pendingIds.value];
  // Previews are cleared by the post-run refresh; don't leave a stale row open.
  expanded.value = null;
  const totals = await purge.run(ids);
  selected.value = new Set();

  const errored = Object.values(purge.progress.value).some(
    (p) => p.state === 'error',
  );
  const parts = [`Deleted ${formatRows(totals.deleted)}.`];
  if (totals.failed) {
    parts.push(`${formatRows(totals.failed)} could not be deleted.`);
  }
  if (totals.cancelled) parts.push('Cancelled before finishing.');
  if (errored) parts.push('Some items stopped with an error.');
  const variant = errored
    ? 'error'
    : totals.failed || totals.cancelled
      ? 'info'
      : 'success';
  store.notify(variant, parts.join(' '));
  await store.refreshStatus();
  await restoreFocus();
}

/** The Cancel button unmounts when a run ends; hand focus back to a control. */
async function restoreFocus(): Promise<void> {
  await nextTick();
  const order =
    askedFrom === 'all'
      ? [allBtn.value, selectedBtn.value]
      : [selectedBtn.value, allBtn.value];
  const target = order.find((b) => b && !b.disabled);
  (target ?? sectionRef.value)?.focus();
}

const announcement = computed<string>(() => {
  const finished = Object.values(purge.progress.value).filter((p) =>
    ['done', 'error', 'cancelled'].includes(p.state),
  );
  const last = finished.at(-1);
  if (!last) return '';
  const label = items.value.find((i) => i.id === last.id)?.label ?? last.id;
  if (last.state === 'done') {
    return `${label}: deleted ${formatRows(last.deleted)}.`;
  }
  if (last.state === 'error') return `${label}: ${last.error ?? 'failed.'}`;
  return `${label}: cancelled.`;
});

function progressPercent(id: TaskId): number {
  const p = purge.progress.value[id];
  if (!p) return 0;
  if (p.state === 'done') return 100;
  const total = p.deleted + p.remaining;
  return total > 0 ? Math.round((p.deleted / total) * 100) : 0;
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString();
}
</script>

<template>
  <section
    ref="sectionRef"
    class="fx-db-cleanup"
    tabindex="-1"
    aria-labelledby="fx-db-cleanup-title"
  >
    <h3 id="fx-db-cleanup-title" class="fx-visually-hidden">Cleanup</h3>

    <p v-if="!store.status" class="fx-db-cleanup__loading">Loading…</p>

    <div v-else class="fx-db-cleanup__table-wrap">
      <table class="fx-db-table">
        <thead>
          <tr>
            <th scope="col">
              <span class="fx-visually-hidden">Select</span>
            </th>
            <th scope="col">Item</th>
            <th scope="col" class="fx-db-num">Count</th>
            <th scope="col" class="fx-db-num">Size</th>
            <th scope="col">
              <span class="fx-visually-hidden">Preview</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <template v-for="item in items" :key="item.id">
            <tr :data-task="item.id">
              <td>
                <input
                  :id="`fx-db-sel-${item.id}`"
                  type="checkbox"
                  :checked="selected.has(item.id)"
                  :disabled="item.count === 0 || busy"
                  @change="
                    toggleRow(
                      item.id,
                      ($event.target as HTMLInputElement).checked,
                    )
                  "
                />
              </td>
              <td>
                <label :for="`fx-db-sel-${item.id}`">{{ item.label }}</label>
                <span
                  v-if="
                    item.id === 'expired-transients' &&
                    store.status.object_cache
                  "
                  class="fx-db-note"
                >
                  Transients are stored in this site's persistent object cache,
                  not the database.
                </span>
                <div
                  v-if="purge.progress.value[item.id]"
                  class="fx-db-progress"
                  role="progressbar"
                  :aria-label="`${item.label} progress`"
                  aria-valuemin="0"
                  aria-valuemax="100"
                  :aria-valuenow="progressPercent(item.id)"
                >
                  <span :style="{ width: `${progressPercent(item.id)}%` }" />
                </div>
                <span
                  v-if="purge.progress.value[item.id]?.error"
                  class="fx-db-error"
                  >{{ purge.progress.value[item.id]?.error }}</span
                >
              </td>
              <td class="fx-db-num">
                {{ item.count === 0 ? '—' : item.count.toLocaleString() }}
              </td>
              <td class="fx-db-num">
                {{ item.count === 0 ? '—' : `≈ ${formatBytes(item.bytes)}` }}
              </td>
              <td>
                <button
                  v-if="item.count > 0"
                  type="button"
                  class="fx-db-link"
                  data-action="preview"
                  :aria-label="`${expanded === item.id ? 'Hide preview' : 'Preview'} ${item.label}`"
                  :aria-expanded="expanded === item.id"
                  :aria-controls="`fx-db-prev-${item.id}`"
                  @click="togglePreview(item.id)"
                >
                  {{ expanded === item.id ? 'Hide preview' : 'Preview' }}
                </button>
              </td>
            </tr>
            <tr
              v-if="expanded === item.id"
              :id="`fx-db-prev-${item.id}`"
              class="fx-db-preview"
            >
              <td colspan="5">
                <p v-if="store.loading.preview === item.id">Loading preview…</p>
                <ul v-else-if="store.previews[item.id]?.sample.length">
                  <li
                    v-for="(row, i) in store.previews[item.id]?.sample"
                    :key="i"
                  >
                    <strong>{{ row.label }}</strong> — {{ row.detail }}
                    <time v-if="row.date" :datetime="row.date">{{
                      formatDate(row.date)
                    }}</time>
                  </li>
                </ul>
                <p v-else>Nothing to show.</p>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <div v-if="store.status" class="fx-db-footer">
      <p class="fx-db-footer__total">
        Total recoverable: <strong>≈ {{ formatBytes(totalBytes) }}</strong>
        <Tooltip
          label="About size estimates"
          text="Sizes are estimated from the stored text of each row. The space MySQL actually frees can differ."
        />
      </p>
      <div class="fx-db-footer__actions">
        <button
          v-if="busy"
          type="button"
          class="fx-db-btn"
          data-action="cancel"
          @click="purge.cancel()"
        >
          Cancel
        </button>
        <button
          ref="selectedBtn"
          type="button"
          class="fx-db-btn"
          data-action="purge-selected"
          :disabled="selected.size === 0 || busy"
          @click="ask([...selected], 'selected')"
        >
          Purge Selected
        </button>
        <button
          ref="allBtn"
          type="button"
          class="fx-db-btn fx-db-btn--primary"
          data-action="purge-all"
          :disabled="store.purgeableIds.length === 0 || busy"
          @click="ask(store.purgeableIds, 'all')"
        >
          Purge All
        </button>
      </div>
    </div>

    <div class="fx-visually-hidden" role="status" aria-live="polite">
      {{ announcement }}
    </div>

    <ConfirmDialog
      v-model:open="confirmOpen"
      title="Permanently delete these rows?"
      confirm-label="Delete permanently"
      tone="danger"
      @confirm="confirmPurge"
    >
      <p>This cannot be undone. The following will be deleted:</p>
      <ul>
        <li v-for="item in pendingItems" :key="item.id">
          {{ item.label }}: {{ formatRows(item.count) }}
        </li>
      </ul>
    </ConfirmDialog>
  </section>
</template>

<style scoped>
.fx-db-cleanup {
  outline: none;
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-4);
}

.fx-db-cleanup__table-wrap {
  overflow-x: auto;
  background: var(--fx-color-surface);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
}

.fx-db-table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--fx-font-size-md);
  color: var(--fx-color-text);
}

.fx-db-table th,
.fx-db-table td {
  padding: var(--fx-space-2) var(--fx-space-3);
  border-bottom: 1px solid var(--fx-color-border);
  text-align: left;
  vertical-align: top;
}

.fx-db-table th {
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-text-muted);
}

.fx-db-table tbody tr:last-child td {
  border-bottom: none;
}

.fx-db-table .fx-db-num {
  text-align: right;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.fx-db-note,
.fx-db-error {
  display: block;
  font-size: var(--fx-font-size-sm);
  color: var(--fx-color-text-muted);
}

.fx-db-error {
  color: var(--fx-color-critical);
}

.fx-db-progress {
  height: 4px;
  margin-top: var(--fx-space-1);
  overflow: hidden;
  background: var(--fx-color-elevated);
  border-radius: var(--fx-radius-sm);
}

.fx-db-progress span {
  display: block;
  height: 100%;
  background: var(--fx-color-primary-strong);
  transition: width var(--fx-transition-base);
}

.fx-db-preview td {
  background: var(--fx-color-canvas);
  font-size: var(--fx-font-size-sm);
}

.fx-db-preview ul {
  margin: 0;
  padding-left: var(--fx-space-4);
}

.fx-db-preview time {
  margin-left: var(--fx-space-2);
  color: var(--fx-color-text-muted);
}

.fx-db-link {
  padding: 0;
  border: none;
  background: none;
  color: var(--fx-color-primary-strong);
  font: inherit;
  text-decoration: underline;
  cursor: pointer;
}

.fx-db-link:focus-visible,
.fx-db-btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-db-footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--fx-space-3);
}

.fx-db-footer__total {
  margin: 0;
  display: inline-flex;
  align-items: center;
  gap: var(--fx-space-2);
}

.fx-db-footer__actions {
  display: flex;
  gap: var(--fx-space-2);
}

.fx-db-btn {
  padding: var(--fx-space-2) var(--fx-space-3);
  border: 1px solid var(--fx-color-border-strong);
  border-radius: var(--fx-radius-md);
  background: var(--fx-color-surface);
  color: var(--fx-color-text);
  font-family: var(--fx-font-body);
  font-size: var(--fx-font-size-md);
  font-weight: var(--fx-font-weight-medium);
  cursor: pointer;
}

.fx-db-btn--primary {
  border-color: var(--fx-color-button-primary);
  background: var(--fx-color-button-primary);
  color: var(--fx-color-button-primary-text);
}

.fx-db-btn:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

@media (prefers-reduced-motion: reduce) {
  .fx-db-progress span {
    transition: none;
  }
}
</style>
