/*
 * Admin SPA router.
 *
 * We use `createWebHashHistory` (hash mode) rather than `createWebHistory`
 * because this SPA is served from a WordPress admin page at
 * `admin.php?page=fanxie-wp-core`. HTML5 history mode would generate URLs
 * like `admin.php/security-headers` on in-app navigation, which WP's PHP
 * router would 404 on reload. Hash routing keeps all client-side state in
 * the URL fragment, so every route is bookmarkable and deep-link-safe
 * without any server configuration.
 *
 * Route IDs mirror the module ids in `@/config/modules.ts` so callers can
 * write `{ name: 'security-headers.csp' }` instead of hard-coding paths.
 *
 * SecurityHeaders ships with two nested sub-tabs (headers/csp); the CSP
 * violation log lives inside the CSP tab. Every other module currently
 * resolves to `PlaceholderTab` at the top level until its real
 * implementation lands.
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
