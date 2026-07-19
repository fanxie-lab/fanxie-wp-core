import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory, type Router } from 'vue-router';
import SecurityHeaders from '../SecurityHeaders.vue';
import HeadersView from '../views/HeadersView.vue';
import CspView from '../views/CspView.vue';
import { useSecurityHeadersStore } from '../stores/securityHeaders';
import type { SecurityHeadersConfig } from '../types';

function makeConfig(): SecurityHeadersConfig {
  return {
    headers: {
      hsts: { enabled: true, max_age: 31536000, include_subdomains: true },
      xfo: { enabled: true, value: 'SAMEORIGIN' },
      xcto: { enabled: true },
      referrer: { enabled: true, value: 'strict-origin-when-cross-origin' },
      permissions: { enabled: false, value: '' },
      cache_control: { enabled: false, value: 'no-store' },
    },
    csp: {
      mode: 'report-only',
      learning_mode: true,
      directives: {
        'default-src': ["'self'"],
      },
      report_uri: 'https://example.test/wp-json/fanxie-wp-core/v1/csp-report',
    },
  };
}

/**
 * Build a fresh in-memory router with the same nested-route shape the real
 * app uses. Memory history keeps tests deterministic and avoids touching
 * window.location.
 */
function makeTestRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/security-headers',
        name: 'security-headers',
        component: SecurityHeaders,
        redirect: { name: 'security-headers.headers' },
        children: [
          {
            path: 'headers',
            name: 'security-headers.headers',
            component: HeadersView,
          },
          {
            path: 'csp',
            name: 'security-headers.csp',
            component: CspView,
          },
        ],
      },
      {
        // Back-compat: the violations log now lives inside the CSP tab.
        path: '/security-headers/violations',
        redirect: { name: 'security-headers.csp' },
      },
    ],
  });
}

async function mountAt(path: string) {
  const router = makeTestRouter();
  await router.push(path);
  await router.isReady();
  // Mount a tiny wrapper that renders <router-view /> so the nested route
  // layout resolves through the real router rather than a forced parent.
  const wrapper = mount(
    {
      template: '<router-view />',
    },
    {
      global: {
        plugins: [router],
      },
    },
  );
  await flushPromises();
  return { router, wrapper };
}

describe('<SecurityHeaders>', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('calls store.load() on mount', async () => {
    const store = useSecurityHeadersStore();
    const loadSpy = vi.spyOn(store, 'load').mockResolvedValue();
    store.config = makeConfig();

    await mountAt('/security-headers/headers');

    expect(loadSpy).toHaveBeenCalledTimes(1);
  });

  it('renders two sub-tabs inside a tablist', async () => {
    const store = useSecurityHeadersStore();
    vi.spyOn(store, 'load').mockResolvedValue();
    store.config = makeConfig();
    store.status = {
      is_https: true,
      hsts_detected: false,
      csp_detected: false,
      report_endpoint: 'https://example.test/csp-report',
      active: true,
      active_header_count: 4,
      csp_active: true,
      summary: '4 headers · CSP Report-Only',
    };

    const { wrapper } = await mountAt('/security-headers/headers');

    const tablist = wrapper.get('[role="tablist"]');
    const tabs = tablist.findAll('[role="tab"]');
    expect(tabs).toHaveLength(2);
    expect(tabs.map((t) => t.text())).toEqual([
      'Response Headers',
      'Content Security Policy',
    ]);
  });

  it('moves aria-selected when a tab is clicked (route changes)', async () => {
    const store = useSecurityHeadersStore();
    vi.spyOn(store, 'load').mockResolvedValue();
    store.config = makeConfig();
    store.status = {
      is_https: true,
      hsts_detected: false,
      csp_detected: false,
      report_endpoint: 'https://example.test/csp-report',
      active: true,
      active_header_count: 4,
      csp_active: true,
      summary: '4 headers · CSP Report-Only',
    };

    const { router, wrapper } = await mountAt('/security-headers/headers');

    const tabs = wrapper.findAll('[role="tab"]');
    expect(tabs[0]?.attributes('aria-selected')).toBe('true');
    expect(tabs[1]?.attributes('aria-selected')).toBe('false');

    await tabs[1]!.trigger('click');
    await flushPromises();

    expect(router.currentRoute.value.name).toBe('security-headers.csp');

    const tabsAfter = wrapper.findAll('[role="tab"]');
    expect(tabsAfter[0]?.attributes('aria-selected')).toBe('false');
    expect(tabsAfter[1]?.attributes('aria-selected')).toBe('true');
  });

  it('renders the correct tab active on a deep link', async () => {
    const store = useSecurityHeadersStore();
    vi.spyOn(store, 'load').mockResolvedValue();
    store.config = makeConfig();

    const { wrapper } = await mountAt('/security-headers/csp');

    const tabs = wrapper.findAll('[role="tab"]');
    expect(tabs[1]?.attributes('aria-selected')).toBe('true');
    expect(tabs[0]?.attributes('aria-selected')).toBe('false');
  });

  it('redirects the legacy violations deep link to the CSP tab', async () => {
    const store = useSecurityHeadersStore();
    vi.spyOn(store, 'load').mockResolvedValue();
    store.config = makeConfig();

    const { router, wrapper } = await mountAt('/security-headers/violations');

    expect(router.currentRoute.value.name).toBe('security-headers.csp');

    const tabs = wrapper.findAll('[role="tab"]');
    expect(tabs[1]?.attributes('aria-selected')).toBe('true');
    expect(tabs[0]?.attributes('aria-selected')).toBe('false');
  });

  it('renders a status pill reflecting the server summary', async () => {
    const store = useSecurityHeadersStore();
    vi.spyOn(store, 'load').mockResolvedValue();
    store.config = makeConfig();
    store.status = {
      is_https: true,
      hsts_detected: false,
      csp_detected: false,
      report_endpoint: 'https://example.test/csp-report',
      active: true,
      active_header_count: 4,
      csp_active: true,
      summary: '4 headers · CSP Report-Only',
    };

    const { wrapper } = await mountAt('/security-headers/headers');

    const pill = wrapper.get('.fx-pill');
    expect(pill.text()).toContain('4 headers · CSP Report-Only');
    expect(pill.classes()).toContain('fx-pill--ok');
  });

  it('renders a neutral pill when status.active is false', async () => {
    const store = useSecurityHeadersStore();
    vi.spyOn(store, 'load').mockResolvedValue();
    store.config = makeConfig();
    store.status = {
      is_https: true,
      hsts_detected: false,
      csp_detected: false,
      report_endpoint: 'https://example.test/csp-report',
      active: false,
      active_header_count: 0,
      csp_active: false,
      summary: 'Inactive',
    };

    const { wrapper } = await mountAt('/security-headers/headers');

    const pill = wrapper.get('.fx-pill');
    expect(pill.text()).toContain('Inactive');
    expect(pill.classes()).toContain('fx-pill--neutral');
  });

  it('shows a loading placeholder while config is loading for the first time', async () => {
    const store = useSecurityHeadersStore();
    // Don't resolve — we want to observe the loading state.
    vi.spyOn(store, 'load').mockImplementation(() => {
      store.loading.config = true;
      return new Promise(() => {
        /* never resolves */
      });
    });

    const { wrapper } = await mountAt('/security-headers/headers');

    expect(wrapper.text()).toContain('Loading Security Headers configuration…');
  });
});
