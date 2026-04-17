import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import SecurityHeaders from '../SecurityHeaders.vue';
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

describe('<SecurityHeaders>', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('calls store.load() on mount', () => {
    const store = useSecurityHeadersStore();
    const loadSpy = vi.spyOn(store, 'load').mockResolvedValue();

    mount(SecurityHeaders);

    expect(loadSpy).toHaveBeenCalledTimes(1);
  });

  it('renders three sub-tabs inside a tablist', () => {
    const store = useSecurityHeadersStore();
    vi.spyOn(store, 'load').mockResolvedValue();
    store.config = makeConfig();
    store.status = {
      is_https: true,
      hsts_detected: false,
      csp_detected: false,
      report_endpoint: 'https://example.test/csp-report',
    };
    store.enabled = true;

    const wrapper = mount(SecurityHeaders);

    const tablist = wrapper.get('[role="tablist"]');
    const tabs = tablist.findAll('[role="tab"]');
    expect(tabs).toHaveLength(3);
    expect(tabs.map((t) => t.text())).toEqual([
      'Headers',
      'Content Security Policy',
      'Violations',
    ]);
  });

  it('moves aria-selected when a tab is clicked', async () => {
    const store = useSecurityHeadersStore();
    vi.spyOn(store, 'load').mockResolvedValue();
    store.config = makeConfig();
    store.status = {
      is_https: true,
      hsts_detected: false,
      csp_detected: false,
      report_endpoint: 'https://example.test/csp-report',
    };
    store.enabled = true;

    const wrapper = mount(SecurityHeaders);

    const tabs = wrapper.findAll('[role="tab"]');
    expect(tabs[0]?.attributes('aria-selected')).toBe('true');
    expect(tabs[1]?.attributes('aria-selected')).toBe('false');

    await tabs[1]!.trigger('click');

    const tabsAfter = wrapper.findAll('[role="tab"]');
    expect(tabsAfter[0]?.attributes('aria-selected')).toBe('false');
    expect(tabsAfter[1]?.attributes('aria-selected')).toBe('true');
  });

  it('renders a status pill reflecting enabled state', () => {
    const store = useSecurityHeadersStore();
    vi.spyOn(store, 'load').mockResolvedValue();
    store.config = makeConfig();
    store.status = {
      is_https: true,
      hsts_detected: false,
      csp_detected: false,
      report_endpoint: 'https://example.test/csp-report',
    };
    store.enabled = true;

    const wrapper = mount(SecurityHeaders);

    const pill = wrapper.get('.fx-pill');
    expect(pill.text()).toContain('Enabled');
    expect(pill.classes()).toContain('fx-pill--ok');
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

    const wrapper = mount(SecurityHeaders);
    await flushPromises();

    expect(wrapper.text()).toContain('Loading Security Headers configuration…');
  });
});
