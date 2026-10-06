/*
 * Admin SPA router.
 *
 * We use `createWebHashHistory` (hash mode) rather than `createWebHistory`
 * because this SPA is served from a WordPress admin page at
 * `admin.php?page=fanxie-warden`. HTML5 history mode would generate URLs
 * like `admin.php/security-headers` on in-app navigation, which WP's PHP
 * router would 404 on reload. Hash routing keeps all client-side state in
 * the URL fragment, so every route is bookmarkable and deep-link-safe
 * without any server configuration.
 *
 * Route IDs mirror the module ids in `@/config/modules.ts` so callers can
 * write `{ name: 'security-headers.csp' }` instead of hard-coding paths.
 *
 * SecurityHeaders ships with two nested sub-tabs (headers/csp); the CSP
 * violation log lives inside the CSP tab. EnvironmentHealth follows the same
 * nested shape (checks/settings). Every other module currently resolves to
 * `PlaceholderTab` at the top level until its real implementation lands.
 */

import {
  createRouter,
  createWebHashHistory,
  type RouteRecordRaw,
} from 'vue-router';
import { PlaceholderTab } from '@/components';
import { getModule, modules } from '@/config/modules';
// `.vue` SFCs re-exported through a barrel resolve to `any` outside a full
// vue-tsc context; lint then flags `component: PlaceholderTab` as
// unsafe-assignment. vue-tsc itself is clean — narrow ESLint false positive.
/* eslint-disable @typescript-eslint/no-unsafe-assignment */

/**
 * Shared props resolver for the PlaceholderTab route — pulls human-readable
 * metadata from the module registry so a deep-link to an un-built module
 * still renders a sensible header.
 */
interface PlaceholderProps {
  moduleId: string;
  moduleName: string;
  phaseLabel: string;
  description: string | undefined;
}

function placeholderPropsFor(id: string): PlaceholderProps {
  const mod = getModule(id);
  return {
    moduleId: id,
    moduleName: mod?.label ?? id,
    phaseLabel: mod?.phaseLabel ?? '',
    description: mod?.description,
  };
}

// Modules that have dedicated implementations (one-line addition per module
// when a real component ships). Any module id NOT in this map falls through
// to a top-level PlaceholderTab route.
const liveModuleRoutes: Record<string, RouteRecordRaw> = {
  'security-headers': {
    path: '/security-headers',
    name: 'security-headers',
    component: () => import('@/modules/SecurityHeaders/SecurityHeaders.vue'),
    redirect: { name: 'security-headers.headers' },
    children: [
      {
        path: 'headers',
        name: 'security-headers.headers',
        component: () =>
          import('@/modules/SecurityHeaders/views/HeadersView.vue'),
      },
      {
        path: 'csp',
        name: 'security-headers.csp',
        component: () => import('@/modules/SecurityHeaders/views/CspView.vue'),
      },
    ],
  },
  // Hardening ships as a single scrollable checklist view — no sub-tabs,
  // no redirect target, no children. Status is derived per-row inside the
  // component itself from the shared store.
  hardening: {
    path: '/hardening',
    name: 'hardening',
    component: () => import('@/modules/Hardening/Hardening.vue'),
  },
  // Login Protection is a single top-level view (no sub-tabs) — the attempt
  // limiter, lockout log, hide-login, password policy, and session sections
  // all live in one scrollable page driven by the shared store.
  'login-protection': {
    path: '/login-protection',
    name: 'login-protection',
    component: () => import('@/modules/LoginProtection/LoginProtection.vue'),
  },
  // Environment Health splits into two sub-tabs: the report (Checks) and the
  // module's settings. The shell keeps the header, the counts summary and the
  // Re-run control above both, so the status counts stay visible while the
  // user is editing a threshold.
  'environment-health': {
    path: '/environment-health',
    name: 'environment-health',
    component: () =>
      import('@/modules/EnvironmentHealth/EnvironmentHealth.vue'),
    redirect: { name: 'environment-health.checks' },
    children: [
      {
        path: 'checks',
        name: 'environment-health.checks',
        component: () =>
          import('@/modules/EnvironmentHealth/views/ChecksView.vue'),
      },
      {
        path: 'settings',
        name: 'environment-health.settings',
        component: () =>
          import('@/modules/EnvironmentHealth/views/SettingsView.vue'),
      },
    ],
  },
  'database-maintenance': {
    path: '/database-maintenance',
    name: 'database-maintenance',
    component: () =>
      import('@/modules/DatabaseMaintenance/DatabaseMaintenance.vue'),
    redirect: { name: 'database-maintenance.cleanup' },
    children: [
      {
        path: 'cleanup',
        name: 'database-maintenance.cleanup',
        component: () =>
          import('@/modules/DatabaseMaintenance/views/CleanupView.vue'),
      },
      {
        path: 'settings',
        name: 'database-maintenance.settings',
        component: () =>
          import('@/modules/DatabaseMaintenance/views/SettingsView.vue'),
      },
    ],
  },
};

/** Build a placeholder route for a module id. */
function makePlaceholderRoute(id: string): RouteRecordRaw {
  return {
    path: `/${id}`,
    name: id,
    component: PlaceholderTab,
    props: () => placeholderPropsFor(id),
  };
}

const moduleRoutes: RouteRecordRaw[] = modules.map((m) => {
  const live = liveModuleRoutes[m.id];
  return live ?? makePlaceholderRoute(m.id);
});

export const routes: RouteRecordRaw[] = [
  { path: '/', redirect: { name: 'security-headers' } },
  ...moduleRoutes,
  {
    // Back-compat: the violations log now lives inside the CSP tab.
    path: '/security-headers/violations',
    redirect: { name: 'security-headers.csp' },
  },
  {
    // Catch-all: unknown paths render a generic placeholder so deep-links
    // from future modules don't break the shell.
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: PlaceholderTab,
    props: () => ({
      moduleId: 'unknown',
      moduleName: 'Not found',
      phaseLabel: '',
      description: 'This module does not exist yet.',
    }),
  },
];

export const router = createRouter({
  history: createWebHashHistory(),
  routes,
  scrollBehavior(_to, from) {
    // Always return to the top on route change — the shell has its own
    // sticky sidebar and we want the main content to start from its header.
    // Use smooth scrolling for in-app navigation (when there's a previous
    // route) so tab switches don't jump harshly. The very first navigation
    // (no `from.name`) jumps instantly to avoid an animated load-in.
    const behavior: ScrollBehavior = from.name ? 'smooth' : 'auto';
    return { top: 0, behavior };
  },
});

export default router;
