import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import LoginProtection from '../LoginProtection.vue';
import { useLoginProtectionStore } from '../stores/loginProtection';
import type { LoginProtectionConfig } from '../types';

function makeConfig(): LoginProtectionConfig {
  return {
    attempts: {
      enabled: true,
      trust_proxy: false,
      proxy_header: 'HTTP_X_FORWARDED_FOR',
      allowlist: ['10.0.0.1', '10.0.0.2'],
      tiers: [
        { threshold: 5, lockout_minutes: 15 },
        { threshold: 10, lockout_minutes: 60 },
        { threshold: 20, lockout_minutes: 1440 },
      ],
      log_retention_days: 30,
    },
    hide_login: { enabled: false, slug: '' },
    passwords: {
      enforce: false,
      min_length: 12,
      require_mixed_case: true,
      require_number: true,
      require_symbol: true,
    },
    sessions: { enabled: false, timeouts: { administrator: 30, default: 120 } },
  };
}

type Store = ReturnType<typeof useLoginProtectionStore>;

async function mountWithStore(seed: (store: Store) => void) {
  setActivePinia(createPinia());
  const store = useLoginProtectionStore();
  vi.spyOn(store, 'load').mockResolvedValue();
  // LockoutLog (a child) fetches the log + ban list on mount — stub both so no
  // ajax escapes. Capture the spy for assertions (avoids the no-unbound-method
  // rule).
  const fetchLog = vi.spyOn(store, 'fetchLog').mockResolvedValue();
  vi.spyOn(store, 'fetchBans').mockResolvedValue();
  seed(store);

  const wrapper = mount(LoginProtection, { attachTo: document.body });
  await flushPromises();
  return { store, wrapper, spies: { fetchLog } };
}

/** Seed helper: config + a deep-equal pristine so isDirty starts false. */
function seedLoaded(
  store: Store,
  config: LoginProtectionConfig = makeConfig(),
) {
  store.config = config;
  store.pristine = makeConfig();
}

describe('<LoginProtection> — Attempt Limiting', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('renders the attempt-limiting controls once config is loaded', async () => {
    const { wrapper } = await mountWithStore((store) => {
      seedLoaded(store);
    });

    const text = wrapper.text();
    expect(text).toContain('Enable attempt limiting');
    expect(text).toContain('Lockout tiers');
    expect(text).toContain('Trusted IP allowlist');
    expect(text).toContain('Log retention (days)');
    expect(text).toContain('Trust reverse-proxy header for client IP');
  });

  it('wires the enable toggle to a HelpText via aria-describedby', async () => {
    const { wrapper } = await mountWithStore((store) => {
      seedLoaded(store);
    });

    const toggle = wrapper.get(
      '[role="switch"][aria-label="Enable attempt limiting"]',
    );
    const describedby = toggle.attributes('aria-describedby');
    expect(describedby).toBeTruthy();
    expect(wrapper.find(`#${describedby!}`).exists()).toBe(true);
  });

  it('marks the store dirty when a setting changes', async () => {
    const { store, wrapper } = await mountWithStore((s) => {
      seedLoaded(s);
    });
    expect(store.isDirty).toBe(false);

    await wrapper
      .get('[role="switch"][aria-label="Enable attempt limiting"]')
      .trigger('click');

    expect(store.config?.attempts.enabled).toBe(false);
    expect(store.isDirty).toBe(true);
  });

  it('adds a lockout tier', async () => {
    const { store, wrapper } = await mountWithStore((s) => {
      seedLoaded(s);
    });
    expect(store.config?.attempts.tiers).toHaveLength(3);

    await wrapper.get('.fx-login-protection__add-button').trigger('click');

    expect(store.config?.attempts.tiers).toHaveLength(4);
  });

  it('removes a lockout tier', async () => {
    const { store, wrapper } = await mountWithStore((s) => {
      seedLoaded(s);
    });

    await wrapper.get('[aria-label="Remove tier 1"]').trigger('click');

    expect(store.config?.attempts.tiers).toHaveLength(2);
    // The first tier (threshold 5) is the one that was removed.
    expect(store.config?.attempts.tiers[0]?.threshold).toBe(10);
  });

  it('disables the remove control when only one tier remains', async () => {
    const config = makeConfig();
    config.attempts.tiers = [{ threshold: 5, lockout_minutes: 15 }];
    const { wrapper } = await mountWithStore((s) => {
      seedLoaded(s, config);
    });

    const remove = wrapper.get('[aria-label="Remove tier 1"]');
    expect(remove.attributes('disabled')).toBeDefined();
  });

  it('presents the allowlist as newline-joined text and writes back an array', async () => {
    const { store, wrapper } = await mountWithStore((s) => {
      seedLoaded(s);
    });

    const textarea = wrapper.get('#fx-lp-allowlist');
    expect((textarea.element as HTMLTextAreaElement).value).toBe(
      '10.0.0.1\n10.0.0.2',
    );

    await textarea.setValue('192.168.0.1\n192.168.0.2');
    expect(store.config?.attempts.allowlist).toEqual([
      '192.168.0.1',
      '192.168.0.2',
    ]);
  });

  it('disables the proxy-header field until proxy trust is enabled', async () => {
    const { store, wrapper } = await mountWithStore((s) => {
      seedLoaded(s);
    });

    const proxyInput = wrapper.get('.fx-login-protection__proxy-header input');
    expect(proxyInput.attributes('disabled')).toBeDefined();

    store.config!.attempts.trust_proxy = true;
    await flushPromises();
    expect(proxyInput.attributes('disabled')).toBeUndefined();
  });

  it('renders the Lockout Log child (fetches the log on mount)', async () => {
    const { spies, wrapper } = await mountWithStore((s) => {
      seedLoaded(s);
    });

    expect(wrapper.text()).toContain('Recent login events');
    expect(spies.fetchLog).toHaveBeenCalledWith(1);
  });
});
