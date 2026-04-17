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

    // The first Toggle in the HSTS section is the "Enable HSTS" switch.
    const switches = wrapper.findAll('[role="switch"]');
    expect(switches.length).toBeGreaterThan(0);
    await switches[0]!.trigger('click');
    await flushPromises();

    expect(store.config?.headers.hsts.enabled).toBe(false);
    expect(store.isDirty).toBe(true);
  });

  it('calls store.save() when the Save button is clicked', async () => {
    const store = seedStore();
    const saveSpy = vi.spyOn(store, 'save').mockResolvedValue();

    const wrapper = mount(HeadersView);
    // Flip HSTS toggle so the form is dirty and SaveBar enables its button.
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
