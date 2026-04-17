<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { ShieldCheck } from 'lucide-vue-next';
import { StatusPill, Toast } from '@/components';
import type { StatusPillVariant, ToastVariant } from '@/components';
import { useSecurityHeadersStore } from './stores/securityHeaders';

/**
 * SecurityHeaders module root.
 *
 * - Mounts the per-module store and calls `load()` on mount.
 * - Renders three sub-tabs (Headers / CSP / Violations) as named router
 *   links so each tab is deep-linkable. RouterLink is used in `custom`
 *   slot mode so we can render it as a `<button role="tab">` and preserve
 *   full ARIA tablist semantics (keyboard arrow nav, aria-selected, roving
 *   tabindex).
 * - Shows a StatusPill rendering the server-formatted status summary.
 */

type TabId = 'headers' | 'csp' | 'violations';

interface Tab {
  id: TabId;
  label: string;
  routeName: string;
}

const TABS: readonly Tab[] = [
  { id: 'headers', label: 'Headers', routeName: 'security-headers.headers' },
  {
    id: 'csp',
    label: 'Content Security Policy',
    routeName: 'security-headers.csp',
  },
  {
    id: 'violations',
    label: 'Violations',
    routeName: 'security-headers.violations',
  },
] as const;

const store = useSecurityHeadersStore();
const route = useRoute();
const router = useRouter();

/** The active tab is derived from the current route name. */
const activeTab = computed<TabId>(() => {
  const match = TABS.find((t) => route.name === t.routeName);
  return match?.id ?? 'headers';
});

const tabButtons = ref<Record<TabId, HTMLButtonElement | null>>({
  headers: null,
  csp: null,
  violations: null,
});

function setTabRef(id: TabId, el: Element | null): void {
  tabButtons.value[id] = el instanceof HTMLButtonElement ? el : null;
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

const statusPill = computed<{ variant: StatusPillVariant; label: string }>(
  () => {
    if (!store.status) {
      return { variant: 'neutral', label: 'Loading…' };
    }
    if (!store.status.active) {
      return { variant: 'neutral', label: store.status.summary };
    }
    return { variant: 'ok', label: store.status.summary };
  },
);

const toastVariant = computed<ToastVariant>(() => {
  const variant = store.toast?.variant ?? 'info';
  if (variant === 'success') return 'success';
  if (variant === 'error') return 'error';
  return 'info';
});

function panelId(tab: TabId): string {
  return `fx-sh-panel-${tab}`;
}

function tabId(tab: TabId): string {
  return `fx-sh-tab-${tab}`;
}

onMounted(() => {
  void store.load();
});

// Reset toast-stamp keyed Transition so repeated toasts re-render.
const toastKey = computed<number>(() => store.toast?.stamp ?? 0);

watch(
  () => store.toast,
  (next) => {
    if (!next) return;
    // Auto-clear the store copy after the toast duration expires — the
    // Toast component handles its own visibility; we just keep the store
    // tidy so a later dismiss action doesn't re-render a stale one.
    window.setTimeout(() => {
      if (store.toast?.stamp === next.stamp) {
        store.dismissToast();
      }
    }, 5500);
  },
);
</script>

<template>
  <div class="fx-sh">
    <header class="fx-sh__module-header">
      <span class="fx-sh__icon-wrap" aria-hidden="true">
        <ShieldCheck
          :size="28"
          :stroke-width="1.75"
          aria-hidden="true"
          focusable="false"
        />
      </span>
      <div class="fx-sh__module-text">
        <div class="fx-sh__title-row">
          <h2 class="fx-sh__title">Security Headers</h2>
          <StatusPill :variant="statusPill.variant" :label="statusPill.label" />
        </div>
        <p class="fx-sh__description">
          Adds HSTS, Content Security Policy, X-Frame-Options and related
          response headers to harden the site against clickjacking, MIME
          confusion, and script injection.
        </p>
      </div>
    </header>

    <div class="fx-sh__tabs">
      <div
        role="tablist"
        aria-label="Security Headers sections"
        class="fx-sh__tablist"
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
              class="fx-sh__tab"
              :class="{ 'fx-sh__tab--active': activeTab === tab.id }"
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

      <div
        v-if="store.loading.config && !store.config"
        class="fx-sh__loading"
        role="status"
      >
        Loading Security Headers configuration…
      </div>

      <section
        v-else
        :id="panelId(activeTab)"
        role="tabpanel"
        :aria-labelledby="tabId(activeTab)"
        class="fx-sh__panel"
        :tabindex="0"
      >
        <RouterView />
      </section>
    </div>

    <Teleport to="body">
      <div v-if="store.toast" class="fx-sh__toast-region" aria-live="polite">
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
.fx-sh {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-5);
}

.fx-sh__module-header {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: var(--fx-space-4);
  align-items: start;
}

.fx-sh__icon-wrap {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 3rem;
  height: 3rem;
  border-radius: var(--fx-radius-lg);
  background: var(--fx-color-primary-soft);
  color: var(--fx-color-primary-strong);
}

.fx-sh__module-text {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
  min-width: 0;
}

.fx-sh__title-row {
  display: flex;
  align-items: center;
  gap: var(--fx-space-3);
  flex-wrap: wrap;
}

.fx-sh__title {
  margin: 0;
  font-family: var(--fx-font-heading);
  font-size: var(--fx-font-size-3xl);
  font-weight: var(--fx-font-weight-semibold);
  color: var(--fx-color-text);
  line-height: var(--fx-line-height-tight);
  letter-spacing: -0.015em;
}

.fx-sh__description {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-lg);
  line-height: var(--fx-line-height-snug);
  max-width: 62ch;
}

.fx-sh__tabs {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-4);
}

.fx-sh__tablist {
  display: flex;
  gap: var(--fx-space-1);
  border-bottom: 1px solid var(--fx-color-border);
  overflow-x: auto;
}

.fx-sh__tab {
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
  border-bottom: 2px solid transparent;
  /* Align the 2px active underline with the tablist's own border so the
   * active tab appears to merge with the content panel below. */
  margin-bottom: -1px;
  transition:
    color var(--fx-transition-fast),
    border-color var(--fx-transition-fast);
}

.fx-sh__tab:hover {
  color: var(--fx-color-text);
}

.fx-sh__tab--active {
  color: var(--fx-color-text);
  border-bottom-color: var(--fx-color-primary-strong);
}

.fx-sh__panel {
  outline: none;
}

.fx-sh__loading {
  padding: var(--fx-space-5);
  color: var(--fx-color-text-muted);
  background: var(--fx-color-surface);
  border: 1px dashed var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
}

.fx-sh__toast-region {
  position: fixed;
  right: var(--fx-space-5);
  bottom: var(--fx-space-5);
  z-index: 40;
}
</style>
