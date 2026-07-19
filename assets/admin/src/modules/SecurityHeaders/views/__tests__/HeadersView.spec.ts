import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import HeadersView from '../HeadersView.vue';
import { useSecurityHeadersStore } from '../../stores/securityHeaders';
import type { SecurityHeadersConfig } from '../../types';

function seededConfig(): SecurityHeadersConfig {
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
      directives: {},
      report_uri: '',
    },
  };
}

function seedStore(): ReturnType<typeof useSecurityHeadersStore> {
  const store = useSecurityHeadersStore();
  // Two independent plain-object snapshots — pristine must not share
  // references with config or isDirty would always be false after mutation.
  store.config = seededConfig();
  store.pristine = seededConfig();
  store.status = {
    is_https: true,
    hsts_detected: true,
    csp_detected: false,
    report_endpoint: 'https://example.test/csp-report',
    active: true,
    active_header_count: 4,
    csp_active: true,
    summary: '4 headers · CSP Report-Only',
  };
  return store;
}

describe('<HeadersView>', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('marks the form dirty when a toggle changes', async () => {
    const store = seedStore();
    const wrapper = mount(HeadersView);

    expect(store.isDirty).toBe(false);

    // Target the HSTS switch by its accessible label — it now lives in the
    // Advanced section at the bottom, so index-based lookup is not reliable.
    const hstsSwitch = wrapper
      .findAll('[role="switch"]')
      .find((s) => s.attributes('aria-label') === 'Enable HSTS');
    expect(hstsSwitch).toBeDefined();
    await hstsSwitch!.trigger('click');
    await flushPromises();

    expect(store.config?.headers.hsts.enabled).toBe(false);
    expect(store.isDirty).toBe(true);
  });

  it('places HSTS inside a collapsed Advanced section', () => {
    seedStore();
    const wrapper = mount(HeadersView);

    const advanced = wrapper.get('details.fx-headers-view__advanced');
    expect(advanced.attributes('open')).toBeUndefined(); // collapsed by default
    expect(advanced.text()).toContain('HTTP Strict Transport Security');
  });

  it('explains Cache-Control inline', () => {
    seedStore();
    const wrapper = mount(HeadersView);

    expect(wrapper.text().toLowerCase()).toContain('proxies');
  });

  it('wires the Cache-Control switch to its HelpText via aria-describedby', () => {
    seedStore();
    const wrapper = mount(HeadersView);

    const cacheSwitch = wrapper
      .findAll('[role="switch"]')
      .find((s) => s.attributes('aria-label') === 'Enable admin Cache-Control');
    expect(cacheSwitch).toBeDefined();

    const ids = cacheSwitch!.attributes('aria-describedby')?.split(' ') ?? [];
    expect(ids).toContain('fx-cache-help');

    // The referenced element must actually exist so the description resolves.
    expect(wrapper.find('#fx-cache-help').exists()).toBe(true);
  });

  it('calls store.save() when the Save button is clicked', async () => {
    const store = seedStore();
    const saveSpy = vi.spyOn(store, 'save').mockResolvedValue();

    const wrapper = mount(HeadersView);
    // Flip the first toggle so the form is dirty and SaveBar enables its button.
    await wrapper.findAll('[role="switch"]')[0]!.trigger('click');
    await flushPromises();

    // SaveBar renders a primary button labelled "Save changes" by default.
    const saveBtn = wrapper
      .findAll('button')
      .find((b) => b.text() === 'Save changes');
    expect(saveBtn).toBeDefined();
    expect(saveBtn!.attributes('disabled')).toBeUndefined();
    await saveBtn!.trigger('click');

    expect(saveSpy).toHaveBeenCalledTimes(1);
  });
});
