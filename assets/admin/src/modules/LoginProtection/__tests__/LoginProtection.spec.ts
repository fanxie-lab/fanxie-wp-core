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
      lock_by_username: false,
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

  it('wires the lock-by-username toggle to a HelpText via aria-describedby', async () => {
    const { wrapper } = await mountWithStore((store) => {
      seedLoaded(store);
    });

    const toggle = wrapper.get(
      '[role="switch"][aria-label="Also lock the targeted username"]',
    );
    const describedby = toggle.attributes('aria-describedby');
    expect(describedby).toBeTruthy();
    expect(wrapper.find(`#${describedby!}`).exists()).toBe(true);
  });

  it('toggles lock-by-username and marks the store dirty', async () => {
    const { store, wrapper } = await mountWithStore((s) => {
      seedLoaded(s);
    });
    expect(store.isDirty).toBe(false);
    expect(store.config?.attempts.lock_by_username).toBe(false);

    await wrapper
      .get('[role="switch"][aria-label="Also lock the targeted username"]')
      .trigger('click');

    expect(store.config?.attempts.lock_by_username).toBe(true);
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

describe('<LoginProtection> — Hide Login', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('renders the slug field editable when the slug is stored', async () => {
    const { wrapper } = await mountWithStore((store) => {
      seedLoaded(store);
      store.slugSource = 'stored';
      store.effectiveSlug = 'secret-door';
    });

    const slug = wrapper.get('#fx-lp-slug');
    expect(slug.attributes('disabled')).toBeUndefined();
    expect(slug.attributes('readonly')).toBeUndefined();
  });

  it('disables the slug field and shows the constant value when constant-sourced', async () => {
    const { wrapper } = await mountWithStore((store) => {
      seedLoaded(store);
      store.slugSource = 'constant';
      store.effectiveSlug = 'locked-slug';
    });

    const slug = wrapper.get('#fx-lp-slug');
    expect(slug.attributes('disabled')).toBeDefined();
    expect((slug.element as HTMLInputElement).value).toBe('locked-slug');
    // The constant is named in an accessible explanation on screen.
    expect(wrapper.text()).toContain('FX_WARDEN_LOGIN_SLUG');
  });

  it('surfaces the recovery note (wp-config constant + CLI reveal)', async () => {
    const { wrapper } = await mountWithStore((store) => {
      seedLoaded(store);
      store.slugSource = 'stored';
    });

    const text = wrapper.text();
    expect(text).toContain('FX_WARDEN_LOGIN_SLUG');
    expect(text).toContain('wp fx-warden login reveal');
  });

  it('wires the enable toggle to a HelpText via aria-describedby', async () => {
    const { wrapper } = await mountWithStore((store) => {
      seedLoaded(store);
      store.slugSource = 'stored';
    });

    const toggle = wrapper.get(
      '[role="switch"][aria-label="Hide the login screen"]',
    );
    const describedby = toggle.attributes('aria-describedby');
    expect(describedby).toBeTruthy();
    expect(wrapper.find(`#${describedby!}`).exists()).toBe(true);
  });

  it('reports the current login address only when hide-login is active', async () => {
    const { wrapper } = await mountWithStore((store) => {
      seedLoaded(store);
      store.slugSource = 'stored';
      store.effectiveSlug = 'secret-door';
      store.hideLoginActive = true;
    });

    const readout = wrapper.get('.fx-login-protection__slug-readout').text();
    expect(readout).toContain('Current login address');
    expect(readout).toContain('secret-door');
  });

  it('does NOT report the stored slug as the login address when hide-login is inactive', async () => {
    // A slug lingers from a previous enable, but the feature is switched off —
    // the read-out must fall back to the default and never claim the login lives
    // at the custom slug (a false sense of security).
    const { wrapper } = await mountWithStore((store) => {
      seedLoaded(store);
      store.slugSource = 'stored';
      store.effectiveSlug = 'secret-door';
      store.hideLoginActive = false;
    });

    const readout = wrapper.get('.fx-login-protection__slug-readout').text();
    expect(readout).not.toContain('secret-door');
    expect(readout).not.toContain('Current login address');
    expect(readout).toContain('wp-login.php');
  });

  it('does NOT enable hide-login until the confirm modal is accepted', async () => {
    const { store, wrapper } = await mountWithStore((s) => {
      seedLoaded(s);
      s.slugSource = 'stored';
    });
    expect(store.config?.hide_login.enabled).toBe(false);
    expect(wrapper.find('[role="alertdialog"]').exists()).toBe(false);

    await wrapper
      .get('[role="switch"][aria-label="Hide the login screen"]')
      .trigger('click');

    // Modal is shown; config is untouched until confirmation.
    expect(wrapper.find('[role="alertdialog"]').exists()).toBe(true);
    expect(store.config?.hide_login.enabled).toBe(false);

    await wrapper.get('.fx-confirm__confirm').trigger('click');

    expect(wrapper.find('[role="alertdialog"]').exists()).toBe(false);
    expect(store.config?.hide_login.enabled).toBe(true);
  });

  it('reverts the toggle when the confirm modal is cancelled', async () => {
    const { store, wrapper } = await mountWithStore((s) => {
      seedLoaded(s);
      s.slugSource = 'stored';
    });

    await wrapper
      .get('[role="switch"][aria-label="Hide the login screen"]')
      .trigger('click');
    expect(wrapper.find('[role="alertdialog"]').exists()).toBe(true);

    await wrapper.get('.fx-confirm__cancel').trigger('click');

    expect(wrapper.find('[role="alertdialog"]').exists()).toBe(false);
    expect(store.config?.hide_login.enabled).toBe(false);
    expect(store.isDirty).toBe(false);
  });

  it('disables hide-login immediately without a confirm', async () => {
    const config = makeConfig();
    config.hide_login.enabled = true;
    const { store, wrapper } = await mountWithStore((s) => {
      s.config = config;
      s.pristine = makeConfig();
      s.pristine.hide_login.enabled = true;
      s.slugSource = 'stored';
    });

    await wrapper
      .get('[role="switch"][aria-label="Hide the login screen"]')
      .trigger('click');

    expect(wrapper.find('[role="alertdialog"]').exists()).toBe(false);
    expect(store.config?.hide_login.enabled).toBe(false);
  });
});

describe('<LoginProtection> — Passwords', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('renders the policy controls', async () => {
    const { wrapper } = await mountWithStore((store) => {
      seedLoaded(store);
    });

    const text = wrapper.text();
    expect(text).toContain('Enforce a strong password policy');
    expect(text).toContain('Require mixed-case letters');
    expect(text).toContain('Require a number');
    expect(text).toContain('Require a symbol');
    expect(wrapper.find('#fx-lp-min-length').exists()).toBe(true);
  });

  it('previews every requirement when all rules are on', async () => {
    const { wrapper } = await mountWithStore((store) => {
      seedLoaded(store);
    });

    const preview = wrapper
      .get('.fx-login-protection__password-preview')
      .text();
    expect(preview).toContain('12 characters');
    expect(preview).toContain('uppercase and lowercase letters');
    expect(preview).toContain('a number');
    expect(preview).toContain('a symbol');
  });

  it('drops a requirement from the preview when its toggle is turned off', async () => {
    const { wrapper } = await mountWithStore((store) => {
      seedLoaded(store);
    });

    await wrapper
      .get('[role="switch"][aria-label="Require a symbol"]')
      .trigger('click');

    const preview = wrapper
      .get('.fx-login-protection__password-preview')
      .text();
    expect(preview).not.toContain('a symbol');
    expect(preview).toContain('a number');
  });

  it('reflects the minimum length in the preview reactively', async () => {
    const { store, wrapper } = await mountWithStore((s) => {
      seedLoaded(s);
    });

    store.config!.passwords.min_length = 8;
    await flushPromises();

    expect(
      wrapper.get('.fx-login-protection__password-preview').text(),
    ).toContain('8 characters');
  });

  it('previews only the length rule when every extra rule is off', async () => {
    const config = makeConfig();
    config.passwords = {
      enforce: true,
      min_length: 10,
      require_mixed_case: false,
      require_number: false,
      require_symbol: false,
    };
    const { wrapper } = await mountWithStore((s) => {
      seedLoaded(s, config);
    });

    const preview = wrapper
      .get('.fx-login-protection__password-preview')
      .text();
    expect(preview).toContain('10 characters');
    expect(preview).not.toContain('uppercase and lowercase letters');
    expect(preview).not.toContain('a number');
    expect(preview).not.toContain('a symbol');
  });

  it('shows a finite length (never NaN) when min_length is non-numeric', async () => {
    const { store, wrapper } = await mountWithStore((s) => {
      seedLoaded(s);
    });

    // Clearing the number input makes v-model.number hand back a non-numeric
    // value; the preview must not render "at least NaN characters".
    store.config!.passwords.min_length = Number.NaN;
    await flushPromises();

    const preview = wrapper
      .get('.fx-login-protection__password-preview')
      .text();
    expect(preview).not.toContain('NaN');
    expect(preview).toMatch(/at least \d+ character/);
  });

  it('marks the store dirty when a password rule changes', async () => {
    const { store, wrapper } = await mountWithStore((s) => {
      seedLoaded(s);
    });
    expect(store.isDirty).toBe(false);

    await wrapper
      .get('[role="switch"][aria-label="Require a number"]')
      .trigger('click');

    expect(store.config?.passwords.require_number).toBe(false);
    expect(store.isDirty).toBe(true);
  });
});

describe('<LoginProtection> — Sessions', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('renders one timeout input per seeded role', async () => {
    const { wrapper } = await mountWithStore((store) => {
      seedLoaded(store);
    });

    const admin = wrapper.get('#fx-lp-session-administrator');
    const fallback = wrapper.get('#fx-lp-session-default');
    expect((admin.element as HTMLInputElement).value).toBe('30');
    expect((fallback.element as HTMLInputElement).value).toBe('120');
  });

  it('updates the timeout map and marks the store dirty on edit', async () => {
    const { store, wrapper } = await mountWithStore((s) => {
      seedLoaded(s);
    });
    expect(store.isDirty).toBe(false);

    await wrapper.get('#fx-lp-session-administrator').setValue('15');

    expect(store.config?.sessions.timeouts.administrator).toBe(15);
    expect(store.isDirty).toBe(true);
  });

  it('wires the enable toggle to a HelpText via aria-describedby', async () => {
    const { wrapper } = await mountWithStore((store) => {
      seedLoaded(store);
    });

    const toggle = wrapper.get(
      '[role="switch"][aria-label="Expire idle sessions"]',
    );
    const describedby = toggle.attributes('aria-describedby');
    expect(describedby).toBeTruthy();
    expect(wrapper.find(`#${describedby!}`).exists()).toBe(true);
  });
});
