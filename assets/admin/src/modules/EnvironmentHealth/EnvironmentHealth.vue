<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import { Activity, RefreshCw } from 'lucide-vue-next';
import { StatusPill, Toast } from '@/components';
import type { ToastVariant } from '@/components';
import { useEnvironmentHealthStore } from './stores/environmentHealth';
import HealthSummary from './components/HealthSummary.vue';
import { statusPill } from './format';

/**
 * Environment Health module root (PRD §7).
 *
 * Shell only — it owns everything that stays put across both sub-tabs:
 *   - the page header, overall status pill and Re-run control
 *   - HealthSummary (counts + "last checked")
 *   - the tablist, the routed panel, and the Toast region
 *
 * HealthSummary is pinned here rather than living inside ChecksView on
 * purpose: the OK/warning/critical counts stay on screen while the user is
 * over on Settings adjusting a threshold, so a critical finding can never be
 * scrolled away from or tabbed out of view.
 *
 * `load()` runs once here, on the shell's mount. Switching tabs swaps the
 * routed child but never remounts the shell, so no tab switch refetches.
 *
 * Tab semantics mirror SecurityHeaders exactly (the house pattern): RouterLink
 * in `custom` slot mode rendered as `role="tab"` inside a `role="tablist"`, so
 * each tab stays a real, deep-linkable link while keeping keyboard arrow nav,
 * aria-selected and a roving tabindex.
 */

type TabId = 'checks' | 'settings';

interface Tab {
  id: TabId;
  label: string;
  routeName: string;
}

const TABS: readonly Tab[] = [
  { id: 'checks', label: 'Checks', routeName: 'environment-health.checks' },
  {
    id: 'settings',
    label: 'Settings',
    routeName: 'environment-health.settings',
  },
] as const;

const store = useEnvironmentHealthStore();
const route = useRoute();
const router = useRouter();

/** The active tab is derived from the current route name. */
const activeTab = computed<TabId>(() => {
  const match = TABS.find((t) => route.name === t.routeName);
  return match?.id ?? 'checks';
});

const tabButtons = ref<Record<TabId, HTMLAnchorElement | null>>({
  checks: null,
  settings: null,
});

function setTabRef(id: TabId, el: Element | null): void {
  tabButtons.value[id] = el instanceof HTMLAnchorElement ? el : null;
}

async function focusTab(id: TabId): Promise<void> {
  await nextTick();
  tabButtons.value[id]?.focus();
}

function onTabKeydown(event: KeyboardEvent, id: TabId): void {
  const idx = TABS.findIndex((t) => t.id === id);
  if (idx === -1) return;

  let nextIdx: number | null = null;
  switch (event.key) {
    case 'ArrowRight':
      nextIdx = (idx + 1) % TABS.length;
      break;
    case 'ArrowLeft':
      nextIdx = (idx - 1 + TABS.length) % TABS.length;
      break;
    case 'Home':
      nextIdx = 0;
      break;
    case 'End':
      nextIdx = TABS.length - 1;
      break;
    default:
      return;
  }

  event.preventDefault();
  const nextTab = TABS[nextIdx];
  if (nextTab) {
    void router
      .push({ name: nextTab.routeName })
      .then(() => focusTab(nextTab.id));
  }
}

function panelId(tab: TabId): string {
  return `fx-eh-panel-${tab}`;
}

function tabId(tab: TabId): string {
  return `fx-eh-tab-${tab}`;
}

const headerPill = computed(() => {
  if (!store.report) {
    return {
      variant: 'neutral' as const,
      label: 'Loading…',
      srPrefix: 'Status:',
    };
  }
  return statusPill(store.overallStatus);
});

const toastVariant = computed<ToastVariant>(() => {
  const variant = store.toast?.variant ?? 'info';
  if (variant === 'success') return 'success';
  if (variant === 'error') return 'error';
  return 'info';
});

const toastKey = computed<number>(() => store.toast?.stamp ?? 0);

/**
 * Politely announced refresh state. Screen readers otherwise get no signal
 * that a long-running server round-trip is under way, or that it finished.
 */
const refreshAnnouncement = computed<string>(() => {
  if (store.loading.refreshing) return 'Re-running environment checks…';
  if (store.report) return `Checks up to date. ${store.countsSentence}`;
  return '';
});

/** Report failed to load AND nothing cached is on screen — a dead end. */
const showLoadFailure = computed<boolean>(
  () => store.error !== null && store.report === null && !store.loading.initial,
);

// Toast auto-clear so a later dismiss action doesn't re-render a stale one.
watch(
  () => store.toast,
  (next) => {
    if (!next) return;
    window.setTimeout(() => {
      if (store.toast?.stamp === next.stamp) {
        store.dismissToast();
      }
    }, 5500);
  },
);

function onRefresh(): void {
  void store.refresh();
}

onMounted(() => {
  void store.load();
});
</script>

<template>
  <div class="fx-eh">
    <header class="fx-eh__header">
      <span class="fx-eh__icon-wrap" aria-hidden="true">
        <Activity
          :size="28"
          :stroke-width="1.75"
          aria-hidden="true"
          focusable="false"
        />
      </span>
      <div class="fx-eh__header-text">
        <div class="fx-eh__title-row">
          <h2 class="fx-eh__title">Environment Health</h2>
          <StatusPill
            :variant="headerPill.variant"
            :label="headerPill.label"
            :sr-prefix="headerPill.srPrefix"
          />
        </div>
        <p class="fx-eh__description">
          Checks the software versions, cron scheduling, debug flags, and
          plugin/theme hygiene this site is running on. Nothing here changes
          your site — every finding comes with a recommended action you apply
          yourself.
        </p>
      </div>
      <div class="fx-eh__header-actions">
        <button
          type="button"
          class="fx-eh__refresh"
          :disabled="store.loading.refreshing || store.loading.initial"
          @click="onRefresh"
        >
          <RefreshCw
            class="fx-eh__refresh-icon"
            :class="{
              'fx-eh__refresh-icon--spinning': store.loading.refreshing,
            }"
            :size="16"
            :stroke-width="1.75"
            aria-hidden="true"
            focusable="false"
          />
          <span>{{
            store.loading.refreshing ? 'Re-running…' : 'Re-run checks'
          }}</span>
        </button>
      </div>
    </header>

    <!-- Busy + completion state, announced without stealing focus. -->
    <span class="fx-visually-hidden" role="status" aria-live="polite">
      {{ refreshAnnouncement }}
    </span>

    <!--
      Pinned across both tabs — see the component docblock. Rendered as soon as
      a report exists so the counts survive a tab switch.
    -->
    <HealthSummary
      v-if="store.report"
      :counts="store.counts"
      :generated-at="store.report.generated_at"
    />

    <p
      v-if="store.loading.initial && !store.report"
      class="fx-eh__panel"
      role="status"
    >
      Loading environment report…
    </p>

    <div v-else-if="showLoadFailure" class="fx-eh__panel fx-eh__panel--error">
      <p class="fx-eh__panel-title">
        The environment report could not be loaded.
      </p>
      <p class="fx-eh__panel-body">{{ store.error }}</p>
      <button type="button" class="fx-eh__refresh" @click="onRefresh">
        <RefreshCw
          :size="16"
          :stroke-width="1.75"
          aria-hidden="true"
          focusable="false"
        />
        <span>Try again</span>
      </button>
    </div>

    <div v-else class="fx-eh__tabs">
      <div
        role="tablist"
        aria-label="Environment Health sections"
        class="fx-eh__tablist"
      >
        <RouterLink
          v-for="tab in TABS"
          :key="tab.id"
          :to="{ name: tab.routeName }"
          custom
        >
          <template #default="{ href, navigate }">
            <a
              :id="tabId(tab.id)"
              :ref="(el) => setTabRef(tab.id, el as Element | null)"
              :href="href"
              role="tab"
              class="fx-eh__tab"
              :class="{ 'fx-eh__tab--active': activeTab === tab.id }"
              :aria-selected="activeTab === tab.id"
              :aria-controls="panelId(tab.id)"
              :tabindex="activeTab === tab.id ? 0 : -1"
              @click="navigate"
              @keydown="onTabKeydown($event, tab.id)"
            >
              {{ tab.label }}
            </a>
          </template>
        </RouterLink>
      </div>

      <section
        :id="panelId(activeTab)"
        role="tabpanel"
        :aria-labelledby="tabId(activeTab)"
        class="fx-eh__panel-region"
        :tabindex="0"
      >
        <RouterView />
      </section>
    </div>

    <Teleport to="body">
      <div v-if="store.toast" class="fx-eh__toast-region" aria-live="polite">
        <Toast
          :key="toastKey"
          :message="store.toast.message"
          :variant="toastVariant"
          @dismiss="store.dismissToast()"
        />
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.fx-eh {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-5);
}

.fx-eh__header {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: var(--fx-space-4);
  align-items: start;
}

.fx-eh__icon-wrap {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 3rem;
  height: 3rem;
  border-radius: var(--fx-radius-lg);
  background: var(--fx-color-primary-soft);
  color: var(--fx-color-primary-strong);
}

.fx-eh__header-text {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
  min-width: 0;
}

.fx-eh__title-row {
  display: flex;
  align-items: center;
  gap: var(--fx-space-3);
  flex-wrap: wrap;
}

.fx-eh__title {
  margin: 0;
  font-family: var(--fx-font-heading);
  font-size: var(--fx-font-size-3xl);
  font-weight: var(--fx-font-weight-semibold);
  color: var(--fx-color-text);
  line-height: var(--fx-line-height-tight);
  letter-spacing: -0.015em;
}

.fx-eh__description {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-lg);
  line-height: var(--fx-line-height-snug);
  max-width: 62ch;
}

.fx-eh__header-actions {
  display: flex;
  align-items: center;
}

.fx-eh__refresh {
  display: inline-flex;
  align-items: center;
  gap: var(--fx-space-2);
  padding: var(--fx-space-2) var(--fx-space-3);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  background: var(--fx-color-surface);
  color: var(--fx-color-text);
  font-family: var(--fx-font-body);
  font-size: var(--fx-font-size-md);
  font-weight: var(--fx-font-weight-medium);
  cursor: pointer;
  transition:
    border-color var(--fx-transition-fast),
    background var(--fx-transition-fast);
}

.fx-eh__refresh:hover:not(:disabled) {
  border-color: var(--fx-color-border-strong);
  background: var(--fx-color-elevated);
}

.fx-eh__refresh:focus,
.fx-eh__refresh:focus-visible {
  outline: none;
  border-color: var(--fx-color-primary-strong);
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-eh__refresh:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.fx-eh__refresh-icon--spinning {
  animation: fx-eh-spin 1s linear infinite;
}

@media (prefers-reduced-motion: reduce) {
  .fx-eh__refresh-icon--spinning {
    animation: none;
  }
}

@keyframes fx-eh-spin {
  to {
    transform: rotate(360deg);
  }
}

.fx-eh__tabs {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-4);
}

.fx-eh__tablist {
  display: flex;
  gap: var(--fx-space-1);
  border-bottom: 1px solid var(--fx-color-border);
  overflow-x: auto;
}

.fx-eh__tab {
  padding: var(--fx-space-2) var(--fx-space-4);
  border: none;
  background: transparent;
  color: var(--fx-color-text-muted);
  font-family: var(--fx-font-body);
  font-size: var(--fx-font-size-md);
  font-weight: var(--fx-font-weight-medium);
  cursor: pointer;
  position: relative;
  white-space: nowrap;
  text-decoration: none;
  border-bottom: 2px solid transparent;
  /* Align the 2px active underline with the tablist's own border so the
   * active tab appears to merge with the content panel below. */
  margin-bottom: -1px;
  transition:
    color var(--fx-transition-fast),
    border-color var(--fx-transition-fast);
}

.fx-eh__tab:hover {
  color: var(--fx-color-text);
}

.fx-eh__tab--active {
  color: var(--fx-color-text);
  border-bottom-color: var(--fx-color-primary-strong);
}

.fx-eh__panel-region {
  outline: none;
}

.fx-eh__panel {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: var(--fx-space-2);
  margin: 0;
  padding: var(--fx-space-5);
  color: var(--fx-color-text-muted);
  background: var(--fx-color-surface);
  border: 1px dashed var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
}

.fx-eh__panel--error {
  border-style: solid;
  border-color: var(--fx-color-critical);
  background: var(--fx-color-critical-bg);
}

.fx-eh__panel-title {
  margin: 0;
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-critical);
}

.fx-eh__panel-body {
  margin: 0;
  font-size: var(--fx-font-size-sm);
}

.fx-eh__toast-region {
  position: fixed;
  right: var(--fx-space-5);
  bottom: var(--fx-space-5);
  z-index: 40;
}

@media (max-width: 720px) {
  .fx-eh__header {
    grid-template-columns: auto 1fr;
  }

  .fx-eh__header-actions {
    grid-column: 1 / -1;
  }
}
</style>
