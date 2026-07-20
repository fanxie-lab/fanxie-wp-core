import { beforeEach, describe, expect, it } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { http, HttpResponse } from 'msw';
import { useLoginProtectionStore } from '../loginProtection';
import type {
  BanListResponse,
  BanRow,
  LoginLogResponse,
  LoginLogRow,
  LoginProtectionConfig,
  LoginProtectionConfigResponse,
} from '../../types';
import {
  TEST_AJAX_URL,
  mockAjaxResponse,
  server,
} from '../../../../../tests/setup';

function makeConfig(): LoginProtectionConfig {
  return {
    attempts: {
      enabled: true,
      trust_proxy: false,
      proxy_header: 'HTTP_X_FORWARDED_FOR',
      allowlist: [],
      lock_by_username: false,
      tiers: [
        { threshold: 5, lockout_minutes: 15 },
        { threshold: 10, lockout_minutes: 60 },
        { threshold: 20, lockout_minutes: 1440 },
      ],
      log_retention_days: 30,
    },
    hide_login: {
      enabled: false,
      slug: '',
    },
    passwords: {
      enforce: false,
      min_length: 12,
      require_mixed_case: true,
      require_number: true,
      require_symbol: true,
    },
    sessions: {
      enabled: false,
      timeouts: {
        administrator: 30,
        default: 120,
      },
    },
  };
}

function makeConfigResponse(
  overrides: Partial<LoginProtectionConfigResponse> = {},
): LoginProtectionConfigResponse {
  return {
    config: makeConfig(),
    slug_source: 'stored',
    effective_slug: '',
    hide_login_active: false,
    ...overrides,
  };
}

function makeLogRow(overrides: Partial<LoginLogRow> = {}): LoginLogRow {
  return {
    id: 1,
    event_type: 'login_failed',
    ip: '203.0.113.9',
    username: 'bob',
    user_id: null,
    context: null,
    created_at: '2026-07-18 12:00:00',
    ...overrides,
  };
}

function makeLogResponse(
  overrides: Partial<LoginLogResponse> = {},
): LoginLogResponse {
  return {
    rows: [makeLogRow()],
    total: 1,
    page: 1,
    per_page: 25,
    ...overrides,
  };
}

function makeBanRow(overrides: Partial<BanRow> = {}): BanRow {
  return {
    id: 1,
    subject_type: 'ip',
    subject_value: '203.0.113.9',
    reason: 'Repeated failures',
    expires_at: null,
    created_at: '2026-07-18 12:00:00',
    ...overrides,
  };
}

function makeBanResponse(
  overrides: Partial<BanListResponse> = {},
): BanListResponse {
  return {
    rows: [makeBanRow()],
    total: 1,
    ...overrides,
  };
}

describe('useLoginProtectionStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  describe('load()', () => {
    it('populates config, slugSource, effectiveSlug and pristine on success', async () => {
      const response = makeConfigResponse({
        slug_source: 'constant',
        effective_slug: 'secret-door',
      });
      mockAjaxResponse('login_protection/get-config', response);

      const store = useLoginProtectionStore();
      await store.load();

      expect(store.config).toEqual(response.config);
      expect(store.slugSource).toBe('constant');
      expect(store.effectiveSlug).toBe('secret-door');
      expect(store.loading.initial).toBe(false);
      expect(store.isDirty).toBe(false);
      expect(store.error).toBeNull();
    });

    it('captures hide_login_active from the envelope', async () => {
      mockAjaxResponse(
        'login_protection/get-config',
        makeConfigResponse({
          effective_slug: 'secret-door',
          hide_login_active: true,
        }),
      );

      const store = useLoginProtectionStore();
      await store.load();

      expect(store.hideLoginActive).toBe(true);
    });

    it('leaves hideLoginActive false when the envelope reports it inactive', async () => {
      // effective_slug is present (a slug is stored) but the feature is off, so
      // the login is NOT actually hidden — hideLoginActive must reflect that.
      mockAjaxResponse(
        'login_protection/get-config',
        makeConfigResponse({
          effective_slug: 'secret-door',
          hide_login_active: false,
        }),
      );

      const store = useLoginProtectionStore();
      await store.load();

      expect(store.hideLoginActive).toBe(false);
    });

    it('surfaces an error toast when get-config fails', async () => {
      mockAjaxResponse(
        'login_protection/get-config',
        { code: 'forbidden', message: 'Not allowed.' },
        { success: false },
      );

      const store = useLoginProtectionStore();
      await store.load();

      expect(store.error).toBe('Not allowed.');
      expect(store.config).toBeNull();
      expect(store.toast?.variant).toBe('error');
      expect(store.toast?.message).toBe('Not allowed.');
    });
  });

  describe('save()', () => {
    it('POSTs the config under a { config } key with the mutated value', async () => {
      mockAjaxResponse('login_protection/get-config', makeConfigResponse());
      const store = useLoginProtectionStore();
      await store.load();

      store.config!.hide_login.enabled = true;
      store.config!.hide_login.slug = 'secret-door';

      // Capture the outgoing request body to assert the exact wire shape —
      // this endpoint uses `config` (not Hardening's `settings`) key.
      let capturedBody: Record<string, unknown> | null = null;
      server.use(
        http.post(TEST_AJAX_URL, async ({ request }) => {
          const body = (await request.json()) as Record<string, unknown>;
          if (body._action !== 'login_protection/save-config') return undefined;
          capturedBody = body;
          return HttpResponse.json({
            success: true,
            data: makeConfigResponse({
              config: {
                ...makeConfig(),
                hide_login: { enabled: true, slug: 'secret-door' },
              },
              effective_slug: 'secret-door',
            }),
          });
        }),
      );

      await store.save();

      expect(capturedBody).not.toBeNull();
      const sent = capturedBody as unknown as {
        config?: LoginProtectionConfig;
      };
      expect(sent.config).toBeDefined();
      expect(sent.config?.hide_login.slug).toBe('secret-door');
    });

    it('updates state from the response and resets the dirty flag', async () => {
      mockAjaxResponse('login_protection/get-config', makeConfigResponse());
      const store = useLoginProtectionStore();
      await store.load();

      store.config!.passwords.enforce = true;
      expect(store.isDirty).toBe(true);

      const saved = makeConfigResponse();
      saved.config.passwords.enforce = true;
      saved.effective_slug = 'secret-door';
      saved.hide_login_active = true;
      mockAjaxResponse('login_protection/save-config', saved);

      await store.save();

      expect(store.loading.saving).toBe(false);
      expect(store.config?.passwords.enforce).toBe(true);
      expect(store.effectiveSlug).toBe('secret-door');
      expect(store.hideLoginActive).toBe(true);
      expect(store.isDirty).toBe(false);
      expect(store.toast?.variant).toBe('success');
    });

    it('surfaces the field on a validation-failure response', async () => {
      mockAjaxResponse('login_protection/get-config', makeConfigResponse());
      const store = useLoginProtectionStore();
      await store.load();

      store.config!.hide_login.enabled = true;
      mockAjaxResponse(
        'login_protection/save-config',
        {
          code: 'invalid_slug',
          message: 'Choose a custom login slug that is not a reserved path.',
          data: { field: 'hide_login.slug' },
        },
        { success: false },
      );

      await store.save();

      expect(store.fieldError?.field).toBe('hide_login.slug');
      expect(store.error).toBe(
        'Choose a custom login slug that is not a reserved path.',
      );
      expect(store.toast?.variant).toBe('error');
    });

    it('no-ops when config is null', async () => {
      const store = useLoginProtectionStore();
      await store.save();
      expect(store.loading.saving).toBe(false);
      expect(store.toast).toBeNull();
    });
  });

  describe('reset()', () => {
    it('reverts the live config to the pristine snapshot', async () => {
      mockAjaxResponse('login_protection/get-config', makeConfigResponse());
      const store = useLoginProtectionStore();
      await store.load();

      store.config!.attempts.enabled = false;
      expect(store.isDirty).toBe(true);

      store.reset();

      expect(store.config?.attempts.enabled).toBe(true);
      expect(store.isDirty).toBe(false);
    });
  });

  describe('fetchLog()', () => {
    it('populates rows + pagination and merges filters', async () => {
      const response = makeLogResponse({ total: 3, page: 2, per_page: 25 });
      mockAjaxResponse('login_protection/get-log', response);

      const store = useLoginProtectionStore();
      await store.fetchLog(2, { event_type: 'login_failed' });

      expect(store.log.rows).toEqual(response.rows);
      expect(store.log.total).toBe(3);
      expect(store.log.page).toBe(2);
      expect(store.log.filters.event_type).toBe('login_failed');
      expect(store.loading.log).toBe(false);
    });
  });

  describe('fetchBans()', () => {
    it('calls get-bans and populates the ban rows + total via applyBans', async () => {
      const response = makeBanResponse({
        rows: [
          makeBanRow(),
          makeBanRow({ id: 2, subject_value: '198.51.100.7' }),
        ],
        total: 2,
      });
      mockAjaxResponse('login_protection/get-bans', response);

      const store = useLoginProtectionStore();
      await store.fetchBans();

      expect(store.bans.rows).toEqual(response.rows);
      expect(store.bans.total).toBe(2);
      expect(store.loading.bans).toBe(false);
      expect(store.error).toBeNull();
    });

    it('surfaces an error toast when get-bans fails', async () => {
      mockAjaxResponse(
        'login_protection/get-bans',
        { code: 'forbidden', message: 'Not allowed.' },
        { success: false },
      );

      const store = useLoginProtectionStore();
      await store.fetchBans();

      expect(store.error).toBe('Not allowed.');
      expect(store.bans.rows).toEqual([]);
      expect(store.toast?.variant).toBe('error');
      expect(store.loading.bans).toBe(false);
    });
  });

  describe('addBan()', () => {
    it('applies the returned ban list and toasts success', async () => {
      mockAjaxResponse('login_protection/add-ban', makeBanResponse());

      const store = useLoginProtectionStore();
      await store.addBan({ subject_type: 'ip', subject_value: '203.0.113.9' });

      expect(store.bans.rows).toHaveLength(1);
      expect(store.bans.total).toBe(1);
      expect(store.toast?.variant).toBe('success');
      expect(store.loading.bans).toBe(false);
    });

    it('surfaces the subject_value field on an invalid IP', async () => {
      mockAjaxResponse(
        'login_protection/add-ban',
        {
          code: 'invalid_ip',
          message: 'Enter a valid IP address to ban.',
          data: { field: 'subject_value' },
        },
        { success: false },
      );

      const store = useLoginProtectionStore();
      await store.addBan({ subject_type: 'ip', subject_value: 'nope' });

      expect(store.fieldError?.field).toBe('subject_value');
      expect(store.toast?.variant).toBe('error');
    });
  });

  describe('removeBan()', () => {
    it('applies the returned ban list', async () => {
      mockAjaxResponse(
        'login_protection/remove-ban',
        makeBanResponse({ rows: [], total: 0 }),
      );

      const store = useLoginProtectionStore();
      await store.removeBan({
        subject_type: 'ip',
        subject_value: '203.0.113.9',
      });

      expect(store.bans.rows).toEqual([]);
      expect(store.bans.total).toBe(0);
      expect(store.toast?.variant).toBe('success');
    });
  });

  describe('clearLockout()', () => {
    it('toasts success on a { cleared: true } response', async () => {
      mockAjaxResponse('login_protection/clear-lockout', { cleared: true });

      const store = useLoginProtectionStore();
      await store.clearLockout({
        subject_type: 'ip',
        subject_value: '203.0.113.9',
      });

      expect(store.toast?.variant).toBe('success');
      expect(store.loading.lockout).toBe(false);
    });
  });

  describe('pushToast / dismissToast', () => {
    it('pushes and dismisses a toast', () => {
      const store = useLoginProtectionStore();
      store.pushToast('hello', 'info');
      expect(store.toast?.message).toBe('hello');
      expect(store.toast?.variant).toBe('info');

      store.dismissToast();
      expect(store.toast).toBeNull();
    });
  });
});
