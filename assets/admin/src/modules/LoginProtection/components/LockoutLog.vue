<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { Select, TextField } from '@/components';
import type { SelectOption } from '@/components';
import { useLoginProtectionStore } from '../stores/loginProtection';
import type { AddBanPayload, BanSubjectType } from '../types';

/**
 * LockoutLog — the Login Protection module's event history + ban manager.
 *
 * Renders a paginated, filterable native <table> of recent login events and,
 * per row, offers the meaningful moderation actions: ban the offending IP or
 * username, unban a currently-banned subject, or clear an active lockout. A
 * manual "add ban" form and the live ban list round out the surface.
 *
 * Follows ViolationsView's paginated-table + filter conventions. Destructive
 * operations (unban, clear-lockout) route through a single inline
 * role="alertdialog" confirm (mirroring ViolationsView's purge confirm) so a
 * misclick can't silently weaken protection. Banning is additive and reversible
 * so it dispatches directly.
 *
 * The ban list is hydrated on mount via `store.fetchBans` (alongside the log
 * fetch) and refreshed as a side effect of add/remove responses, so every log
 * row's per-row Ban/Unban affordance reflects the current ban state on first
 * render.
 */

const store = useLoginProtectionStore();

// --- Pending filter values (applied on demand, like ViolationsView) --------
const pendingEvent = ref<string>('');
const pendingIp = ref<string>('');
const pendingUsername = ref<string>('');
const pendingSince = ref<string>('');
const pendingUntil = ref<string>('');

// --- Manual add-ban form ----------------------------------------------------
const addType = ref<string>('ip');
const addValue = ref<string>('');
const addReason = ref<string>('');
const addTtl = ref<string>('');

const subjectTypeOptions: SelectOption[] = [
  { value: 'ip', label: 'IP address' },
  { value: 'username', label: 'Username' },
];

// --- Inline confirm for destructive ops ------------------------------------
type ConfirmKind = 'unban' | 'clear';

interface PendingConfirm {
  kind: ConfirmKind;
  subjectType: BanSubjectType;
  subjectValue: string;
  message: string;
}

const pendingConfirm = ref<PendingConfirm | null>(null);

/**
 * Narrow the server's free-form `subject_type` string down to the two-member
 * union the store actions require. The ban/subject tables only ever store
 * 'ip' or 'username'; anything unexpected is treated as an IP.
 */
function asSubjectType(value: string): BanSubjectType {
  return value === 'username' ? 'username' : 'ip';
}

function subjectNoun(type: BanSubjectType): string {
  return type === 'ip' ? 'IP' : 'username';
}

// --- Derived display state --------------------------------------------------

/**
 * Event-type filter options derived from the current page's distinct values
 * (server-side "distinct events" is out of scope), humanised for display while
 * keeping the raw value on the wire. Mirrors ViolationsView's directive filter.
 */
const eventOptions = computed<SelectOption[]>(() => {
  const set = new Set<string>();
  for (const row of store.log.rows) {
    if (row.event_type) set.add(row.event_type);
  }
  const names: string[] = [...set].sort((a, b) => a.localeCompare(b));
  const options: SelectOption[] = [{ value: '', label: 'All events' }];
  for (const name of names) {
    options.push({ value: name, label: humanizeEvent(name) });
  }
  // SelectOption[] is resolved via a barrel re-export from a .vue SFC; eslint's
  // strictTypeChecked can treat the array as any[] through that path (see
  // ViolationsView), so suppress on the return statement itself.
  // eslint-disable-next-line @typescript-eslint/no-unsafe-return
  return options;
});

/** Set of `${subject_type}:${subject_value}` keys for O(1) banned lookups. */
const bannedKeys = computed<Set<string>>(() => {
  const set = new Set<string>();
  for (const ban of store.bans.rows) {
    set.add(`${ban.subject_type}:${ban.subject_value}`);
  }
  return set;
});

function isBanned(type: BanSubjectType, value: string): boolean {
  if (!value) return false;
  return bannedKeys.value.has(`${type}:${value}`);
}

const totalPages = computed<number>(() => {
  const { total, per_page } = store.log;
  if (per_page <= 0) return 1;
  return Math.max(1, Math.ceil(total / per_page));
});

const currentPage = computed<number>(() => store.log.page);

const hasRows = computed<boolean>(() => store.log.rows.length > 0);

// --- Formatting -------------------------------------------------------------

const dateFormatter = new Intl.DateTimeFormat(undefined, {
  year: 'numeric',
  month: 'short',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
});

/**
 * `created_at` arrives as a GMT `Y-m-d H:i:s` string with no zone marker. Treat
 * it as UTC (append `Z`) before formatting so the local-time render is correct;
 * fall back to the raw value if it can't be parsed.
 */
function formatDate(value: string): string {
  const iso = value.includes('T') ? value : `${value.replace(' ', 'T')}Z`;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return value;
  return dateFormatter.format(d);
}

function humanizeEvent(value: string): string {
  if (!value) return 'Unknown';
  const spaced = value.replace(/_/g, ' ');
  return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

// --- Actions ----------------------------------------------------------------

async function applyFilters(): Promise<void> {
  await store.fetchLog(1, {
    event_type: pendingEvent.value || undefined,
    ip: pendingIp.value.trim() || undefined,
    username: pendingUsername.value.trim() || undefined,
    since: pendingSince.value.trim() || undefined,
    until: pendingUntil.value.trim() || undefined,
  });
}

async function clearFilters(): Promise<void> {
  pendingEvent.value = '';
  pendingIp.value = '';
  pendingUsername.value = '';
  pendingSince.value = '';
  pendingUntil.value = '';
  await store.clearLogFilters();
}

async function prevPage(): Promise<void> {
  if (currentPage.value <= 1) return;
  await store.fetchLog(currentPage.value - 1);
}

async function nextPage(): Promise<void> {
  if (currentPage.value >= totalPages.value) return;
  await store.fetchLog(currentPage.value + 1);
}

/** Banning is additive + reversible, so it dispatches without a confirm. */
async function banSubject(type: BanSubjectType, value: string): Promise<void> {
  if (!value) return;
  await store.addBan({ subject_type: type, subject_value: value });
}

function requestUnban(type: BanSubjectType, value: string): void {
  pendingConfirm.value = {
    kind: 'unban',
    subjectType: type,
    subjectValue: value,
    message: `Remove the ban on ${subjectNoun(type)} ${value}? This subject will be able to sign in again.`,
  };
}

function requestClearLockout(type: BanSubjectType, value: string): void {
  pendingConfirm.value = {
    kind: 'clear',
    subjectType: type,
    subjectValue: value,
    message: `Clear the active lockout for ${subjectNoun(type)} ${value}? The failed-attempt counter resets and the subject may retry immediately.`,
  };
}

function cancelConfirm(): void {
  pendingConfirm.value = null;
}

async function acceptConfirm(): Promise<void> {
  const pending = pendingConfirm.value;
  if (!pending) return;
  const subject = {
    subject_type: pending.subjectType,
    subject_value: pending.subjectValue,
  };
  pendingConfirm.value = null;
  if (pending.kind === 'unban') {
    await store.removeBan(subject);
  } else {
    await store.clearLockout(subject);
  }
}

async function submitAddBan(): Promise<void> {
  const value = addValue.value.trim();
  if (!value) return;
  const payload: AddBanPayload = {
    subject_type: asSubjectType(addType.value),
    subject_value: value,
  };
  const reason = addReason.value.trim();
  if (reason) payload.reason = reason;
  const ttl = Number.parseInt(addTtl.value, 10);
  if (Number.isFinite(ttl) && ttl > 0) payload.ttl_minutes = ttl;

  await store.addBan(payload);
  // Keep the subject type for repeat bans; clear the one-shot inputs.
  addValue.value = '';
  addReason.value = '';
  addTtl.value = '';
}

onMounted(() => {
  void store.fetchLog(1);
  void store.fetchBans(1);
});
</script>

<template>
  <div class="fx-lockout">
    <!-- ============================ Event log ============================ -->
    <section class="fx-lockout__panel" aria-labelledby="fx-lockout-log-title">
      <header class="fx-lockout__panel-header">
        <div class="fx-lockout__panel-heading">
          <h4 id="fx-lockout-log-title" class="fx-lockout__panel-title">
            Recent login events
          </h4>
          <p class="fx-lockout__panel-subtitle">
            {{
              store.log.total === 1
                ? '1 event recorded.'
                : `${String(store.log.total)} events recorded.`
            }}
          </p>
        </div>
      </header>

      <!-- Shared confirm for destructive moderation ops. -->
      <div
        v-if="pendingConfirm"
        class="fx-lockout__confirm"
        role="alertdialog"
        aria-labelledby="fx-lockout-confirm-title"
      >
        <p id="fx-lockout-confirm-title" class="fx-lockout__confirm-text">
          {{ pendingConfirm.message }}
        </p>
        <div class="fx-lockout__confirm-actions">
          <button
            type="button"
            class="fx-lockout__button"
            @click="cancelConfirm"
          >
            Cancel
          </button>
          <button
            type="button"
            class="fx-lockout__button fx-lockout__button--danger fx-lockout__confirm-accept"
            @click="acceptConfirm"
          >
            {{
              pendingConfirm.kind === 'unban' ? 'Remove ban' : 'Clear lockout'
            }}
          </button>
        </div>
      </div>

      <!-- Filters -->
      <div class="fx-lockout__filters">
        <Select
          id="fx-lockout-filter-event"
          v-model="pendingEvent"
          label="Event"
          :options="eventOptions"
        />
        <TextField
          id="fx-lockout-filter-ip"
          v-model="pendingIp"
          label="IP address"
          placeholder="e.g. 203.0.113.9"
          autocomplete="off"
        />
        <TextField
          id="fx-lockout-filter-username"
          v-model="pendingUsername"
          label="Username"
          autocomplete="off"
        />
        <TextField
          id="fx-lockout-filter-since"
          v-model="pendingSince"
          label="From"
          placeholder="YYYY-MM-DD"
          autocomplete="off"
        />
        <TextField
          id="fx-lockout-filter-until"
          v-model="pendingUntil"
          label="To"
          placeholder="YYYY-MM-DD"
          autocomplete="off"
        />
        <div class="fx-lockout__filter-actions">
          <button
            type="button"
            class="fx-lockout__button"
            @click="clearFilters"
          >
            Clear
          </button>
          <button
            type="button"
            class="fx-lockout__button fx-lockout__button--primary fx-lockout__filter-apply"
            @click="applyFilters"
          >
            Apply
          </button>
        </div>
      </div>

      <div
        v-if="store.loading.log && !hasRows"
        class="fx-lockout__empty"
        role="status"
      >
        Loading login events…
      </div>
      <div v-else-if="!hasRows" class="fx-lockout__empty">
        No login events recorded yet.
      </div>

      <div
        v-else
        class="fx-lockout__table-wrapper"
        role="region"
        aria-label="Login events"
        tabindex="0"
      >
        <table class="fx-lockout__table">
          <thead>
            <tr>
              <th scope="col">Event</th>
              <th scope="col">IP</th>
              <th scope="col">Username</th>
              <th scope="col">When</th>
              <th scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in store.log.rows" :key="row.id">
              <td>{{ humanizeEvent(row.event_type) }}</td>
              <td>
                <code v-if="row.ip">{{ row.ip }}</code>
                <span v-else class="fx-lockout__muted">—</span>
              </td>
              <td>
                <span v-if="row.username">{{ row.username }}</span>
                <span v-else class="fx-lockout__muted">—</span>
              </td>
              <td class="fx-lockout__nowrap">
                {{ formatDate(row.created_at) }}
              </td>
              <td>
                <div class="fx-lockout__row-actions">
                  <template v-if="row.ip">
                    <button
                      v-if="!isBanned('ip', row.ip)"
                      type="button"
                      class="fx-lockout__button fx-lockout__button--sm"
                      :aria-label="`Ban IP ${row.ip}`"
                      :disabled="store.loading.bans"
                      @click="banSubject('ip', row.ip)"
                    >
                      Ban IP
                    </button>
                    <button
                      v-else
                      type="button"
                      class="fx-lockout__button fx-lockout__button--sm"
                      :aria-label="`Unban IP ${row.ip}`"
                      :disabled="store.loading.bans"
                      @click="requestUnban('ip', row.ip)"
                    >
                      Unban IP
                    </button>
                    <button
                      type="button"
                      class="fx-lockout__button fx-lockout__button--sm"
                      :aria-label="`Clear lockout for IP ${row.ip}`"
                      :disabled="store.loading.lockout"
                      @click="requestClearLockout('ip', row.ip)"
                    >
                      Clear lockout
                    </button>
                  </template>
                  <template v-if="row.username">
                    <button
                      v-if="!isBanned('username', row.username)"
                      type="button"
                      class="fx-lockout__button fx-lockout__button--sm"
                      :aria-label="`Ban username ${row.username}`"
                      :disabled="store.loading.bans"
                      @click="banSubject('username', row.username)"
                    >
                      Ban user
                    </button>
                    <button
                      v-else
                      type="button"
                      class="fx-lockout__button fx-lockout__button--sm"
                      :aria-label="`Unban username ${row.username}`"
                      :disabled="store.loading.bans"
                      @click="requestUnban('username', row.username)"
                    >
                      Unban user
                    </button>
                  </template>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <nav
        v-if="hasRows"
        class="fx-lockout__pagination"
        aria-label="Login events pagination"
      >
        <button
          type="button"
          class="fx-lockout__button fx-lockout__page-prev"
          :disabled="currentPage <= 1 || store.loading.log"
          @click="prevPage"
        >
          <ChevronLeft :size="14" aria-hidden="true" focusable="false" />
          Previous
        </button>
        <span class="fx-lockout__pagination-status" aria-live="polite">
          Page {{ currentPage }} of {{ totalPages }}
        </span>
        <button
          type="button"
          class="fx-lockout__button fx-lockout__page-next"
          :disabled="currentPage >= totalPages || store.loading.log"
          @click="nextPage"
        >
          Next
          <ChevronRight :size="14" aria-hidden="true" focusable="false" />
        </button>
      </nav>
    </section>

    <!-- ============================ Ban manager ========================= -->
    <section class="fx-lockout__panel" aria-labelledby="fx-lockout-bans-title">
      <header class="fx-lockout__panel-header">
        <div class="fx-lockout__panel-heading">
          <h4 id="fx-lockout-bans-title" class="fx-lockout__panel-title">
            Banned IPs and usernames
          </h4>
          <p class="fx-lockout__panel-subtitle">
            Bans block sign-in outright until removed. Add one manually below or
            from any event above.
          </p>
        </div>
      </header>

      <!-- Manual add-ban -->
      <form class="fx-lockout__add" @submit.prevent="submitAddBan">
        <Select
          id="fx-lockout-add-type"
          v-model="addType"
          label="Subject type"
          :options="subjectTypeOptions"
        />
        <TextField
          id="fx-lockout-add-value"
          v-model="addValue"
          :label="addType === 'username' ? 'Username' : 'IP address'"
          autocomplete="off"
        />
        <TextField
          id="fx-lockout-add-reason"
          v-model="addReason"
          label="Reason (optional)"
          autocomplete="off"
        />
        <TextField
          id="fx-lockout-add-ttl"
          v-model="addTtl"
          type="number"
          label="Duration (minutes, optional)"
          placeholder="Leave blank for permanent"
          autocomplete="off"
        />
        <div class="fx-lockout__add-actions">
          <button
            type="submit"
            class="fx-lockout__button fx-lockout__button--primary fx-lockout__add-submit"
            :disabled="!addValue.trim() || store.loading.bans"
          >
            Add ban
          </button>
        </div>
      </form>

      <div v-if="store.bans.rows.length === 0" class="fx-lockout__empty">
        No active bans.
      </div>
      <div
        v-else
        class="fx-lockout__table-wrapper"
        role="region"
        aria-label="Active bans"
        tabindex="0"
      >
        <table class="fx-lockout__table">
          <thead>
            <tr>
              <th scope="col">Type</th>
              <th scope="col">Subject</th>
              <th scope="col">Reason</th>
              <th scope="col">Expires</th>
              <th scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="ban in store.bans.rows" :key="ban.id">
              <td>{{ subjectNoun(asSubjectType(ban.subject_type)) }}</td>
              <td>
                <code>{{ ban.subject_value }}</code>
              </td>
              <td>
                <span v-if="ban.reason">{{ ban.reason }}</span>
                <span v-else class="fx-lockout__muted">—</span>
              </td>
              <td class="fx-lockout__nowrap">
                <span v-if="ban.expires_at">{{
                  formatDate(ban.expires_at)
                }}</span>
                <span v-else class="fx-lockout__muted">Permanent</span>
              </td>
              <td>
                <button
                  type="button"
                  class="fx-lockout__button fx-lockout__button--sm"
                  :aria-label="`Unban ${subjectNoun(asSubjectType(ban.subject_type))} ${ban.subject_value}`"
                  :disabled="store.loading.bans"
                  @click="
                    requestUnban(
                      asSubjectType(ban.subject_type),
                      ban.subject_value,
                    )
                  "
                >
                  Unban
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.fx-lockout {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-4);
}

.fx-lockout__panel {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-4);
}

.fx-lockout__panel + .fx-lockout__panel {
  padding-top: var(--fx-space-4);
  border-top: 1px solid var(--fx-color-border);
}

.fx-lockout__panel-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--fx-space-4);
  flex-wrap: wrap;
}

.fx-lockout__panel-heading {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-1);
}

.fx-lockout__panel-title {
  margin: 0;
  font-family: var(--fx-font-heading);
  font-size: var(--fx-font-size-lg);
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-text);
}

.fx-lockout__panel-subtitle {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
  max-width: 62ch;
}

.fx-lockout__filters {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  gap: var(--fx-space-3);
  align-items: end;
}

.fx-lockout__filter-actions,
.fx-lockout__confirm-actions,
.fx-lockout__add-actions {
  display: flex;
  gap: var(--fx-space-2);
  align-items: end;
}

.fx-lockout__button {
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

.fx-lockout__button:hover:not(:disabled) {
  background: var(--fx-color-elevated);
  border-color: var(--fx-color-border-strong);
}

.fx-lockout__button:focus-visible {
  outline: none;
  border-color: var(--fx-color-primary-strong);
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-lockout__button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.fx-lockout__button--sm {
  padding: var(--fx-space-1) var(--fx-space-2);
  font-size: var(--fx-font-size-xs);
}

.fx-lockout__button--primary {
  background: var(--fx-color-button-primary);
  border-color: var(--fx-color-button-primary);
  color: var(--fx-color-button-primary-text);
  font-family: var(--fx-font-heading);
}

.fx-lockout__button--primary:hover:not(:disabled) {
  background: var(--fx-color-button-primary-hover);
  border-color: var(--fx-color-button-primary-hover);
}

.fx-lockout__button--danger {
  border-color: var(--fx-color-critical);
  color: var(--fx-color-critical);
}

.fx-lockout__button--danger:hover:not(:disabled) {
  background: var(--fx-color-critical);
  color: var(--fx-color-text-inverse);
}

.fx-lockout__confirm {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
  padding: var(--fx-space-3);
  background: var(--fx-color-critical-bg);
  border: 1px solid var(--fx-color-critical);
  border-radius: var(--fx-radius-md);
}

.fx-lockout__confirm-text {
  margin: 0;
  color: var(--fx-color-critical);
  font-size: var(--fx-font-size-sm);
  font-weight: var(--fx-font-weight-medium);
}

.fx-lockout__add {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  gap: var(--fx-space-3);
  align-items: end;
}

.fx-lockout__empty {
  padding: var(--fx-space-5);
  text-align: center;
  color: var(--fx-color-text-muted);
  background: var(--fx-color-canvas);
  border: 1px dashed var(--fx-color-border);
  border-radius: var(--fx-radius-md);
}

.fx-lockout__table-wrapper {
  overflow-x: auto;
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
}

.fx-lockout__table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--fx-font-size-sm);
}

.fx-lockout__table thead {
  background: var(--fx-color-elevated);
}

.fx-lockout__table th,
.fx-lockout__table td {
  padding: var(--fx-space-2) var(--fx-space-3);
  text-align: left;
  border-bottom: 1px solid var(--fx-color-border);
  vertical-align: top;
}

.fx-lockout__table th {
  font-weight: var(--fx-font-weight-semibold);
  color: var(--fx-color-text);
  white-space: nowrap;
}

.fx-lockout__table tbody tr:last-child td {
  border-bottom: none;
}

.fx-lockout__table code {
  font-family: var(--fx-font-mono);
  font-size: 0.9em;
  background: var(--fx-color-elevated);
  padding: 0 var(--fx-space-1);
  border-radius: var(--fx-radius-sm);
}

.fx-lockout__nowrap {
  white-space: nowrap;
}

.fx-lockout__muted {
  color: var(--fx-color-text-muted);
}

.fx-lockout__row-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--fx-space-1);
}

.fx-lockout__pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--fx-space-3);
}

.fx-lockout__pagination-status {
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
}

@media (max-width: 720px) {
  .fx-lockout__filters,
  .fx-lockout__add {
    grid-template-columns: minmax(0, 1fr);
  }

  .fx-lockout__filter-actions,
  .fx-lockout__add-actions {
    justify-content: flex-end;
  }
}
</style>
