<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import { Database } from 'lucide-vue-next';
import { Toast } from '@/components';
import type { ToastVariant } from '@/components';
import { useDatabaseMaintenanceStore } from './stores/databaseMaintenance';

/**
 * Database Maintenance module root.
 *
 * Shell only: header, tablist, routed panel and Toast region. Tab semantics
 * mirror Environment Health / SecurityHeaders (RouterLink `custom` slot as
 * `role="tab"` in a `role="tablist"`, roving tabindex, arrow-key nav).
 * `load()` runs once on mount; switching tabs never refetches.
 */

type TabId = 'cleanup' | 'settings';

interface Tab {
  id: TabId;
  label: string;
  routeName: string;
}

const TABS: readonly Tab[] = [
  {
    id: 'cleanup',
    label: 'Cleanup',
    routeName: 'database-maintenance.cleanup',
  },
  {
    id: 'settings',
    label: 'Settings',
    routeName: 'database-maintenance.settings',
  },
] as const;

const store = useDatabaseMaintenanceStore();
const route = useRoute();
const router = useRouter();

/** The active tab is derived from the current route name. */
const activeTab = computed<TabId>(() => {
  const match = TABS.find((t) => route.name === t.routeName);
  return match?.id ?? 'cleanup';
});

const tabButtons = ref<Record<TabId, HTMLAnchorElement | null>>({
  cleanup: null,
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
  return `fx-dm-panel-${tab}`;
}

function tabId(tab: TabId): string {
  return `fx-dm-tab-${tab}`;
}

const toastVariant = computed<ToastVariant>(() => {
  const variant = store.toast?.variant ?? 'info';
  if (variant === 'success') return 'success';
  if (variant === 'error') return 'error';
  return 'info';
});

const toastKey = computed<number>(() => store.toast?.stamp ?? 0);

/** Load failed and nothing is cached on screen: a dead end without a retry. */
const showLoadFailure = computed<boolean>(
  () => store.error !== null && store.status === null && !store.loading.initial,
);

// Toast auto-clear so a stale one never lingers.
watch(
  () => store.toast,
  (next) => {
    if (!next) return;
    window.setTimeout(() => {
      if (store.toast?.stamp === next.stamp) {
        store.toast = null;
      }
    }, 5500);
  },
);

function onRetry(): void {
  void store.load();
}

onMounted(() => {
  void store.load();
});
</script>

<template>
  <div class="fx-dm">
    <header class="fx-dm__header">
      <span class="fx-dm__icon-wrap" aria-hidden="true">
        <Database
          :size="28"
          :stroke-width="1.75"
          aria-hidden="true"
          focusable="false"
        />
      </span>
      <div class="fx-dm__header-text">
        <h2 class="fx-dm__title">Database Maintenance</h2>
        <p class="fx-dm__description">
          Find and remove database bloat. Nothing is deleted without a preview
          and your confirmation.
        </p>
      </div>
    </header>

    <p
      v-if="store.loading.initial && !store.status"
      class="fx-dm__panel"
      role="status"
    >
      Loading database status…
    </p>

    <div v-else-if="showLoadFailure" class="fx-dm__panel fx-dm__panel--error">
      <p class="fx-dm__panel-title">Database status could not be loaded.</p>
      <p class="fx-dm__panel-body">{{ store.error }}</p>
      <button type="button" class="fx-dm__retry" @click="onRetry">
        Try again
      </button>
    </div>

    <div v-else class="fx-dm__tabs">
      <div
        role="tablist"
        aria-label="Database Maintenance sections"
        class="fx-dm__tablist"
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
              class="fx-dm__tab"
              :class="{ 'fx-dm__tab--active': activeTab === tab.id }"
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
        class="fx-dm__panel-region"
        :tabindex="0"
      >
        <RouterView />
      </section>
    </div>

    <Teleport to="body">
      <div v-if="store.toast" class="fx-dm__toast-region" aria-live="polite">
        <Toast
          :key="toastKey"
          :message="store.toast.message"
          :variant="toastVariant"
          @dismiss="store.toast = null"
        />
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.fx-dm {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-5);
}

.fx-dm__header {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: var(--fx-space-4);
  align-items: start;
}

.fx-dm__icon-wrap {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 3rem;
  height: 3rem;
  border-radius: var(--fx-radius-lg);
  background: var(--fx-color-primary-soft);
  color: var(--fx-color-primary-strong);
}

.fx-dm__header-text {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
  min-width: 0;
}

.fx-dm__title {
  margin: 0;
  font-family: var(--fx-font-heading);
  font-size: var(--fx-font-size-3xl);
  font-weight: var(--fx-font-weight-semibold);
  color: var(--fx-color-text);
  line-height: var(--fx-line-height-tight);
  letter-spacing: -0.015em;
}

.fx-dm__description {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-lg);
  line-height: var(--fx-line-height-snug);
  max-width: 62ch;
}

.fx-dm__retry {
  padding: var(--fx-space-2) var(--fx-space-3);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  background: var(--fx-color-surface);
  color: var(--fx-color-text);
  font-family: var(--fx-font-body);
  font-size: var(--fx-font-size-md);
  font-weight: var(--fx-font-weight-medium);
  cursor: pointer;
}

.fx-dm__retry:hover {
  border-color: var(--fx-color-border-strong);
  background: var(--fx-color-elevated);
}

.fx-dm__retry:focus-visible {
  outline: none;
  border-color: var(--fx-color-primary-strong);
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-dm__tabs {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-4);
}

.fx-dm__tablist {
  display: flex;
  gap: var(--fx-space-1);
  border-bottom: 1px solid var(--fx-color-border);
  overflow-x: auto;
}

.fx-dm__tab {
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

.fx-dm__tab:hover {
  color: var(--fx-color-text);
}

.fx-dm__tab--active {
  color: var(--fx-color-text);
  border-bottom-color: var(--fx-color-primary-strong);
}

.fx-dm__panel-region {
  outline: none;
}

.fx-dm__panel {
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

.fx-dm__panel--error {
  border-style: solid;
  border-color: var(--fx-color-critical);
  background: var(--fx-color-critical-bg);
}

.fx-dm__panel-title {
  margin: 0;
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-critical);
}

.fx-dm__panel-body {
  margin: 0;
  font-size: var(--fx-font-size-sm);
}

.fx-dm__toast-region {
  position: fixed;
  right: var(--fx-space-5);
  bottom: var(--fx-space-5);
  z-index: 40;
}

@media (max-width: 720px) {
  .fx-dm__header {
    grid-template-columns: auto 1fr;
  }
}
</style>
