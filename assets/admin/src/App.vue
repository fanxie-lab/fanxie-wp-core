<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import { useAppStore } from '@/stores/app';
import {
  flatModuleIds,
  getGroupForModule,
  getModule,
  groups,
  modules,
} from '@/config/modules';

const store = useAppStore();
const route = useRoute();
const router = useRouter();

/**
 * The active module id is derived from the top-level route name. Every
 * module has a named top-level route (either a real component or a
 * PlaceholderTab fallback), so `route.matched[0].name` is the source of
 * truth for the sidebar's selected state.
 */
const activeModuleId = computed<string>(() => {
  const topName = route.matched[0]?.name;
  if (typeof topName === 'string' && getModule(topName)) return topName;
  return modules[0]?.id ?? 'security-headers';
});

const activeModule = computed(
  () => getModule(activeModuleId.value) ?? modules[0],
);

const activeGroup = computed(
  () => getGroupForModule(activeModuleId.value) ?? groups[0],
);

const version = computed<string>(() => store.version);

/** Collapsible sidebar on narrow viewports. Defaults to expanded. */
const sidebarOpen = ref<boolean>(true);

function toggleSidebar(): void {
  sidebarOpen.value = !sidebarOpen.value;
}

/** Refs to module links so arrow-key navigation can move focus. */
const moduleRefs = ref<Record<string, HTMLAnchorElement | null>>({});

/**
 * RouterLink's template ref returns either the component instance (whose
 * `$el` is the rendered DOM node) or — when vnode refs fire — the Element
 * directly. Normalise both shapes to the underlying anchor element.
 */
function setModuleRef(id: string, el: unknown): void {
  const node =
    el && typeof el === 'object' && '$el' in el
      ? (el as { $el: unknown }).$el
      : el;
  moduleRefs.value[id] = node instanceof HTMLAnchorElement ? node : null;
}

/** Walk the flat module list on Arrow Up/Down inside the nav. */
async function focusModule(id: string): Promise<void> {
  await nextTick();
  moduleRefs.value[id]?.focus();
}

function onModuleKeydown(event: KeyboardEvent, id: string): void {
  const idx = flatModuleIds.indexOf(id);
  if (idx === -1) return;

  let nextIdx: number | null = null;
  switch (event.key) {
    case 'ArrowDown':
      nextIdx = (idx + 1) % flatModuleIds.length;
      break;
    case 'ArrowUp':
      nextIdx = (idx - 1 + flatModuleIds.length) % flatModuleIds.length;
      break;
    case 'Home':
      nextIdx = 0;
      break;
    case 'End':
      nextIdx = flatModuleIds.length - 1;
      break;
    default:
      return;
  }
  event.preventDefault();
  const nextId = flatModuleIds[nextIdx];
  if (nextId !== undefined) {
    void router.push({ name: nextId }).then(() => focusModule(nextId));
  }
}
</script>

<template>
  <div class="fx-shell" :class="{ 'fx-shell--sidebar-closed': !sidebarOpen }">
    <!-- Mobile sidebar toggle (hidden on desktop via CSS). -->
    <button
      type="button"
      class="fx-shell__nav-toggle"
      :aria-expanded="sidebarOpen"
      aria-controls="fx-sidebar"
      @click="toggleSidebar"
    >
      <span class="fx-visually-hidden">
        {{ sidebarOpen ? 'Close navigation' : 'Open navigation' }}
      </span>
      <svg
        viewBox="0 0 20 20"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        aria-hidden="true"
        focusable="false"
        class="fx-shell__nav-toggle-icon"
      >
        <path d="M3 6h14M3 10h14M3 14h14" />
      </svg>
    </button>

    <aside
      id="fx-sidebar"
      class="fx-sidebar"
      :class="{ 'fx-sidebar--open': sidebarOpen }"
    >
      <div class="fx-sidebar__header">
        <span class="fx-sidebar__brand-plugin">Fanxie WP Core</span>
        <span class="fx-sidebar__version" aria-label="Plugin version">
          v{{ version }}
        </span>
      </div>

      <nav class="fx-sidebar__nav" role="navigation" aria-label="Modules">
        <section
          v-for="group in groups"
          :key="group.id"
          class="fx-sidebar__group"
        >
          <h2 class="fx-sidebar__group-label">{{ group.label }}</h2>
          <ul class="fx-sidebar__list">
            <li
              v-for="id in group.moduleIds"
              :key="id"
              class="fx-sidebar__item"
            >
              <RouterLink
                :ref="(el) => setModuleRef(id, el)"
                :to="{ name: id }"
                class="fx-sidebar__link"
                active-class="fx-sidebar__link--active"
                @keydown="onModuleKeydown($event, id)"
              >
                <span class="fx-sidebar__icon" aria-hidden="true">
                  <!-- Per-module Lucide icon, configured in @/config/modules. -->
                  <component
                    :is="getModule(id)?.icon"
                    v-if="getModule(id)?.icon"
                    :size="18"
                    :stroke-width="1.75"
                    aria-hidden="true"
                    focusable="false"
                  />
                </span>
                <span class="fx-sidebar__label">
                  {{ getModule(id)?.label ?? id }}
                </span>
                <span
                  v-if="store.isModuleEnabled(id)"
                  class="fx-sidebar__dot"
                  aria-hidden="true"
                  title="Module enabled"
                ></span>
              </RouterLink>
            </li>
          </ul>
        </section>
      </nav>

      <div class="fx-sidebar__footer">
        <span class="fx-sidebar__footer-text">Built by</span>
        <a
          class="fx-sidebar__footer-link"
          href="https://fanxielab.com"
          target="_blank"
          rel="noopener noreferrer"
        >
          Fanxie Lab
          <svg
            viewBox="0 0 12 12"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
            focusable="false"
            class="fx-sidebar__footer-icon"
          >
            <path d="M4 2h6v6" />
            <path d="M10 2L4.5 7.5" />
            <path d="M9 10H3V4" />
          </svg>
        </a>
      </div>
    </aside>

    <main
      class="fx-main"
      :aria-labelledby="`fx-module-title-${activeModule?.id}`"
    >
      <header class="fx-main__header">
        <p class="fx-main__breadcrumb" aria-hidden="true">
          <span class="fx-main__breadcrumb-group">{{
            activeGroup?.label
          }}</span>
          <span class="fx-main__breadcrumb-sep">/</span>
          <span class="fx-main__breadcrumb-module">
            {{ activeModule?.label }}
          </span>
        </p>
        <!--
          The accessible label for the <main> region. Each module renders
          its own <h2> + description in its root component (with the icon
          chip), so we hide the duplicate visually here while still
          giving assistive tech a labelled landmark.
        -->
        <h1
          :id="`fx-module-title-${activeModule?.id}`"
          class="fx-visually-hidden"
        >
          {{ activeModule?.label }}
        </h1>
      </header>

      <section class="fx-main__content">
        <RouterView />
      </section>
    </main>
  </div>
</template>

<style scoped>
.fx-shell {
  display: grid;
  grid-template-columns: var(--fx-sidebar-width) 1fr;
  min-height: 100vh;
  background: var(--fx-color-canvas);
}

/* Mobile nav trigger — hidden on desktop. */
.fx-shell__nav-toggle {
  display: none;
  position: fixed;
  top: var(--fx-space-3);
  left: var(--fx-space-3);
  z-index: 30;
  width: 2.5rem;
  height: 2.5rem;
  padding: 0;
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  background: var(--fx-color-surface);
  color: var(--fx-color-text);
  cursor: pointer;
  align-items: center;
  justify-content: center;
  box-shadow: var(--fx-shadow-sm);
}

.fx-shell__nav-toggle-icon {
  width: 1.25rem;
  height: 1.25rem;
}

/* ---------- Sidebar ---------- */

.fx-sidebar {
  display: flex;
  flex-direction: column;
  background: var(--fx-color-surface);
  border-right: 1px solid var(--fx-color-border);
  padding: var(--fx-space-5) var(--fx-space-4);
  gap: var(--fx-space-5);
  min-height: 100vh;
  position: sticky;
  top: 0;
  align-self: start;
}

.fx-sidebar__header {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: var(--fx-space-2);
  padding-bottom: var(--fx-space-4);
  border-bottom: 1px solid var(--fx-color-border);
}

.fx-sidebar__brand-plugin {
  font-family: var(--fx-font-heading);
  font-size: var(--fx-font-size-lg);
  font-weight: var(--fx-font-weight-semibold);
  color: var(--fx-color-text);
  line-height: var(--fx-line-height-tight);
  letter-spacing: -0.01em;
}

.fx-sidebar__version {
  display: inline-flex;
  align-items: center;
  padding: 2px var(--fx-space-2);
  background: var(--fx-color-primary-soft);
  color: var(--fx-color-primary-strong);
  border-radius: var(--fx-radius-pill);
  font-size: 0.6875rem; /* 11px — pill is intentionally small */
  font-weight: var(--fx-font-weight-medium);
  font-family: var(--fx-font-mono);
  letter-spacing: 0.02em;
}

.fx-sidebar__nav {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-5);
  flex: 1;
  overflow-y: auto;
}

.fx-sidebar__group {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
}

/* Proper section header treatment (not eyebrow): 800 weight, Title Case,
 * no tracking. Per user directive — the !important is scoped to this
 * component and cannot leak beyond the sidebar nav. */
.fx-sidebar__group-label {
  margin: 0 0 var(--fx-space-1);
  padding: 0 var(--fx-space-2);
  font-family: var(--fx-font-heading);
  font-size: var(--fx-font-size-sm);
  color: var(--fx-color-text);
  text-transform: none;
  font-weight: 800 !important;
  letter-spacing: 0;
  line-height: var(--fx-line-height-tight);
}

.fx-sidebar__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.fx-sidebar__item {
  margin: 0;
}

.fx-sidebar__link {
  display: grid;
  grid-template-columns: 1.25rem 1fr auto;
  align-items: center;
  gap: var(--fx-space-3);
  width: 100%;
  padding: var(--fx-space-2) var(--fx-space-3);
  border: 1px solid transparent;
  border-radius: var(--fx-radius-md);
  background: transparent;
  color: var(--fx-color-text-muted);
  font-family: var(--fx-font-body);
  font-size: var(--fx-font-size-md);
  font-weight: var(--fx-font-weight-medium);
  text-align: left;
  cursor: pointer;
  transition:
    background var(--fx-transition-fast),
    color var(--fx-transition-fast),
    border-color var(--fx-transition-fast);
}

.fx-sidebar__link:hover {
  background: var(--fx-color-elevated);
  color: var(--fx-color-text);
}

.fx-sidebar__link--active {
  background: var(--fx-color-primary-soft);
  color: var(--fx-color-primary-strong);
  border-color: transparent;
}

.fx-sidebar__link--active:hover {
  background: var(--fx-color-primary-soft);
}

.fx-sidebar__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.25rem;
  height: 1.25rem;
  color: currentColor;
}

.fx-sidebar__icon svg {
  width: 100%;
  height: 100%;
}

.fx-sidebar__label {
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.fx-sidebar__dot {
  display: inline-block;
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 50%;
  background: var(--fx-color-primary);
  box-shadow: var(--fx-shadow-glow);
  flex-shrink: 0;
}

.fx-sidebar__footer {
  display: flex;
  align-items: center;
  gap: var(--fx-space-2);
  padding-top: var(--fx-space-4);
  border-top: 1px solid var(--fx-color-border);
  font-size: var(--fx-font-size-sm);
  color: var(--fx-color-text-muted);
}

.fx-sidebar__footer-text {
  color: var(--fx-color-text-dim);
}

.fx-sidebar__footer-link {
  display: inline-flex;
  align-items: center;
  gap: var(--fx-space-1);
  color: var(--fx-color-text);
  font-weight: var(--fx-font-weight-medium);
  text-decoration: none;
  border-radius: var(--fx-radius-sm);
}

.fx-sidebar__footer-link:hover,
.fx-sidebar__footer-link:focus-visible {
  color: var(--fx-color-primary-strong);
}

.fx-sidebar__footer-icon {
  width: 0.75rem;
  height: 0.75rem;
}

/* ---------- Main content area ---------- */

.fx-main {
  padding: var(--fx-space-6) var(--fx-space-6);
  max-width: var(--fx-max-content-width);
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-5);
}

.fx-main__header {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
}

.fx-main__breadcrumb {
  margin: 0;
  display: inline-flex;
  align-items: center;
  gap: var(--fx-space-2);
  font-size: var(--fx-font-size-sm);
  color: var(--fx-color-text-muted);
  font-weight: var(--fx-font-weight-medium);
}

.fx-main__breadcrumb-group {
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-size: var(--fx-font-size-xs);
  color: var(--fx-color-text-dim);
}

.fx-main__breadcrumb-sep {
  color: var(--fx-color-text-dim);
}

.fx-main__breadcrumb-module {
  color: var(--fx-color-text-muted);
}

.fx-main__content {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-4);
}

.fx-main__content :deep(.fx-main__module-loading) {
  margin: 0;
  padding: var(--fx-space-5);
  color: var(--fx-color-text-muted);
  background: var(--fx-color-surface);
  border: 1px dashed var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
}

/* ---------- Responsive ---------- */

@media (max-width: 900px) {
  .fx-shell {
    grid-template-columns: 1fr;
  }

  .fx-shell__nav-toggle {
    display: inline-flex;
  }

  .fx-sidebar {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    width: var(--fx-sidebar-width);
    max-width: 85vw;
    z-index: 20;
    transform: translateX(-100%);
    transition: transform var(--fx-transition-base);
    box-shadow: var(--fx-shadow-lg);
  }

  .fx-sidebar--open {
    transform: translateX(0);
  }

  .fx-main {
    padding: calc(var(--fx-space-6) + 2rem) var(--fx-space-5) var(--fx-space-6);
  }
}
</style>
