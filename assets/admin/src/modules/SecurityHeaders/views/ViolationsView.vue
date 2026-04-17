<script setup lang="ts">
import { computed, ref } from 'vue';
import { ChevronLeft, ChevronRight, RefreshCw, Trash2 } from 'lucide-vue-next';
import { Select, TextField } from '@/components';
import type { SelectOption } from '@/components';
import { useSecurityHeadersStore } from '../stores/securityHeaders';

/**
 * ViolationsView — paginated CSP violation log with filters and purge action.
 *
 * Table uses native <table> markup for assistive-tech support. The filter
 * dropdown is populated from distinct directive values on the current page
 * only — server-side "distinct values" is out of scope for phase 1.1.
 */

const store = useSecurityHeadersStore();

/** Pending filter values — applied when the user clicks Apply. */
const pendingDirective = ref<string>('');
const pendingSince = ref<string>('');
const pendingUntil = ref<string>('');

const confirmPurge = ref<boolean>(false);

const totalPages = computed<number>(() => {
  const { total, per_page } = store.violations;
  if (per_page <= 0) return 1;
  return Math.max(1, Math.ceil(total / per_page));
});

const currentPage = computed<number>(() => store.violations.page);

// SelectOption is resolved via a barrel export from a .vue SFC; eslint's
// `strictTypeChecked` sometimes treats the local array as `any[]` through
// that re-export, so we locally widen via a return-type annotation and
// suppress on the return statement itself. Keep this isolated to one spot.
const distinctDirectives = computed<SelectOption[]>(() => {
  const set = new Set<string>();
  for (const row of store.violations.rows) {
    set.add(row.directive);
  }
  const names: string[] = [...set].sort((a, b) => a.localeCompare(b));
  const options: SelectOption[] = [{ value: '', label: 'All directives' }];
  for (const name of names) {
    options.push({ value: name, label: name });
  }
  // eslint-disable-next-line @typescript-eslint/no-unsafe-return
  return options;
});

const dateFormatter = new Intl.DateTimeFormat(undefined, {
  year: 'numeric',
  month: 'short',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
});

function formatDate(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return dateFormatter.format(d);
}

async function applyFilters(): Promise<void> {
  await store.loadViolations(1, {
    directive: pendingDirective.value || undefined,
    since: pendingSince.value || undefined,
    until: pendingUntil.value || undefined,
  });
}

async function clearFilters(): Promise<void> {
  pendingDirective.value = '';
  pendingSince.value = '';
  pendingUntil.value = '';
  await store.clearViolationFilters();
}

async function prevPage(): Promise<void> {
  if (currentPage.value <= 1) return;
  await store.loadViolations(currentPage.value - 1);
}

async function nextPage(): Promise<void> {
  if (currentPage.value >= totalPages.value) return;
  await store.loadViolations(currentPage.value + 1);
}

async function purgeAll(): Promise<void> {
  confirmPurge.value = false;
  await store.purgeViolations({ all: true });
}

/** Reload the current page of violations with the active filters intact. */
async function refresh(): Promise<void> {
  await store.loadViolations(currentPage.value);
}
</script>

<template>
  <section class="fx-violations" aria-labelledby="fx-violations-title">
    <header class="fx-violations__header">
      <div class="fx-violations__header-text">
        <h3 id="fx-violations-title" class="fx-violations__title">
          CSP violations
        </h3>
        <p class="fx-violations__subtitle">
          {{
            store.violations.total === 1
              ? '1 violation recorded.'
              : `${String(store.violations.total)} violations recorded.`
          }}
        </p>
      </div>
      <div v-if="!confirmPurge" class="fx-violations__header-actions">
        <button
          type="button"
          class="fx-violations__button"
          :disabled="store.loading.violations"
          :aria-busy="store.loading.violations"
          @click="refresh"
        >
          <RefreshCw
            :size="14"
            aria-hidden="true"
            focusable="false"
            :class="{ 'fx-violations__icon--spin': store.loading.violations }"
          />
          Refresh
        </button>
        <button
          type="button"
          class="fx-violations__button fx-violations__button--danger"
          :disabled="store.violations.total === 0 || store.loading.violations"
          @click="confirmPurge = true"
        >
          <Trash2 :size="14" aria-hidden="true" focusable="false" />
          Purge all
        </button>
      </div>
      <div
        v-else
        class="fx-violations__confirm"
        role="alertdialog"
        aria-labelledby="fx-violations-confirm-title"
      >
        <p id="fx-violations-confirm-title" class="fx-violations__confirm-text">
          Delete every logged violation? This cannot be undone.
        </p>
        <div class="fx-violations__confirm-actions">
          <button
            type="button"
            class="fx-violations__button"
            @click="confirmPurge = false"
          >
            Cancel
          </button>
          <button
            type="button"
            class="fx-violations__button fx-violations__button--danger"
            @click="purgeAll"
          >
            Delete all
          </button>
        </div>
      </div>
    </header>

    <div class="fx-violations__filters">
      <Select
        v-model="pendingDirective"
        label="Directive"
        :options="distinctDirectives"
      />
      <TextField
        v-model="pendingSince"
        type="text"
        label="From"
        placeholder="YYYY-MM-DD"
        autocomplete="off"
      />
      <TextField
        v-model="pendingUntil"
        type="text"
        label="To"
        placeholder="YYYY-MM-DD"
        autocomplete="off"
      />
      <div class="fx-violations__filter-actions">
        <button
          type="button"
          class="fx-violations__button"
          @click="clearFilters"
        >
          Clear
        </button>
        <button
          type="button"
          class="fx-violations__button fx-violations__button--primary"
          @click="applyFilters"
        >
          Apply
        </button>
      </div>
    </div>

    <div
      v-if="store.loading.violations && store.violations.rows.length === 0"
      class="fx-violations__empty"
      role="status"
    >
      Loading violations…
    </div>
    <div
      v-else-if="store.violations.rows.length === 0"
      class="fx-violations__empty"
    >
      No CSP violations logged yet.
    </div>

    <div
      v-else
      class="fx-violations__table-wrapper"
      role="region"
      aria-label="CSP violations table"
      tabindex="0"
    >
      <table class="fx-violations__table">
        <thead>
          <tr>
            <th scope="col">Directive</th>
            <th scope="col">Blocked URI</th>
            <th scope="col">Document</th>
            <th scope="col" class="fx-violations__numeric">Count</th>
            <th scope="col">First seen</th>
            <th scope="col">Last seen</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in store.violations.rows" :key="row.id">
            <td>
              <code>{{ row.directive }}</code>
            </td>
            <td class="fx-violations__truncate" :title="row.blocked_uri">
              {{ row.blocked_uri }}
            </td>
            <td class="fx-violations__truncate" :title="row.document_uri">
              {{ row.document_uri }}
            </td>
            <td class="fx-violations__numeric">{{ row.count }}</td>
            <td>{{ formatDate(row.created_at) }}</td>
            <td>{{ formatDate(row.last_seen_at) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <nav
      v-if="store.violations.rows.length > 0"
      class="fx-violations__pagination"
      aria-label="Violations pagination"
    >
      <button
        type="button"
        class="fx-violations__button"
        :disabled="currentPage <= 1 || store.loading.violations"
        @click="prevPage"
      >
        <ChevronLeft :size="14" aria-hidden="true" focusable="false" />
        Previous
      </button>
      <span class="fx-violations__pagination-status" aria-live="polite">
        Page {{ currentPage }} of {{ totalPages }}
      </span>
      <button
        type="button"
        class="fx-violations__button"
        :disabled="currentPage >= totalPages || store.loading.violations"
        @click="nextPage"
      >
        Next
        <ChevronRight :size="14" aria-hidden="true" focusable="false" />
      </button>
    </nav>
  </section>
</template>

<style scoped>
.fx-violations {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-4);
  padding: var(--fx-space-5);
  background: var(--fx-color-surface);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
  box-shadow: var(--fx-shadow-sm);
}

.fx-violations__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--fx-space-4);
  flex-wrap: wrap;
}

.fx-violations__header-text {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-1);
}

.fx-violations__title {
  margin: 0;
  font-family: var(--fx-font-heading);
  font-size: var(--fx-font-size-xl);
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-text);
}

.fx-violations__subtitle {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
}

.fx-violations__header-actions,
.fx-violations__filter-actions,
.fx-violations__confirm-actions {
  display: flex;
  gap: var(--fx-space-2);
  align-items: end;
}

.fx-violations__confirm {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
  padding: var(--fx-space-3);
  background: var(--fx-color-critical-bg);
  border: 1px solid var(--fx-color-critical);
  border-radius: var(--fx-radius-md);
  max-width: 24rem;
}

.fx-violations__confirm-text {
  margin: 0;
  color: var(--fx-color-critical);
  font-size: var(--fx-font-size-sm);
  font-weight: var(--fx-font-weight-medium);
}

.fx-violations__filters {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) auto;
  gap: var(--fx-space-3);
  align-items: end;
}

.fx-violations__button {
  display: inline-flex;
  align-items: center;
  gap: var(--fx-space-1);
  padding: var(--fx-space-2) var(--fx-space-3);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  background: transparent;
  color: var(--fx-color-text);
  font-family: var(--fx-font-body);
  font-size: var(--fx-font-size-sm);
  font-weight: var(--fx-font-weight-medium);
  cursor: pointer;
  transition:
    background var(--fx-transition-fast),
    border-color var(--fx-transition-fast),
    color var(--fx-transition-fast);
}

.fx-violations__button:hover:not(:disabled) {
  background: var(--fx-color-elevated);
  border-color: var(--fx-color-border-strong);
}

.fx-violations__button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.fx-violations__button--primary {
  background: var(--fx-color-button-primary);
  border-color: var(--fx-color-button-primary);
  color: var(--fx-color-button-primary-text);
  font-family: var(--fx-font-heading);
}

.fx-violations__button--primary:hover:not(:disabled) {
  background: var(--fx-color-button-primary-hover);
  border-color: var(--fx-color-button-primary-hover);
}

.fx-violations__button--danger {
  background: transparent;
  border-color: var(--fx-color-critical);
  color: var(--fx-color-critical);
}

.fx-violations__button--danger:hover:not(:disabled) {
  background: var(--fx-color-critical);
  color: var(--fx-color-text-inverse);
}

.fx-violations__empty {
  padding: var(--fx-space-5);
  text-align: center;
  color: var(--fx-color-text-muted);
  background: var(--fx-color-canvas);
  border: 1px dashed var(--fx-color-border);
  border-radius: var(--fx-radius-md);
}

.fx-violations__table-wrapper {
  overflow-x: auto;
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
}

.fx-violations__table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--fx-font-size-sm);
}

.fx-violations__table thead {
  background: var(--fx-color-elevated);
}

.fx-violations__table th,
.fx-violations__table td {
  padding: var(--fx-space-2) var(--fx-space-3);
  text-align: left;
  border-bottom: 1px solid var(--fx-color-border);
  vertical-align: top;
}

.fx-violations__table th {
  font-weight: var(--fx-font-weight-semibold);
  color: var(--fx-color-text);
  white-space: nowrap;
}

.fx-violations__table tbody tr:last-child td {
  border-bottom: none;
}

.fx-violations__numeric {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.fx-violations__truncate {
  max-width: 20rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.fx-violations__table code {
  font-family: var(--fx-font-mono);
  font-size: 0.9em;
  background: var(--fx-color-elevated);
  padding: 0 var(--fx-space-1);
  border-radius: var(--fx-radius-sm);
}

.fx-violations__pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--fx-space-3);
}

.fx-violations__pagination-status {
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
}

.fx-violations__icon--spin {
  animation: fx-violations-spin 0.9s linear infinite;
}

@keyframes fx-violations-spin {
  to {
    transform: rotate(360deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .fx-violations__icon--spin {
    animation: none;
  }
}

@media (max-width: 900px) {
  .fx-violations__filters {
    grid-template-columns: minmax(0, 1fr);
  }

  .fx-violations__filter-actions {
    justify-content: flex-end;
  }
}
</style>
