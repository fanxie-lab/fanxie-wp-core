import { beforeEach, describe, expect, it } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { useSecurityHeadersStore } from '../securityHeaders';
import type {
  ConfigResponse,
  SecurityHeadersConfig,
  ViolationsResponse,
} from '../../types';
import { mockAjaxResponse } from '../../../../../tests/setup';

function makeConfig(): SecurityHeadersConfig {
  return {
    headers: {
      hsts: { enabled: true, max_age: 31536000, include_subdomains: true },
      xfo: { enabled: true, value: 'SAMEORIGIN' },
      xcto: { enabled: true },
      referrer: { enabled: true, value: 'strict-origin-when-cross-origin' },
      permissions: { enabled: true, value: 'camera=(), microphone=()' },
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

function makeConfigResponse(
  overrides: Partial<ConfigResponse> = {},
): ConfigResponse {
  return {
    enabled: true,
    settings: makeConfig(),
    status: {
      is_https: true,
      hsts_detected: false,
      csp_detected: false,
      report_endpoint:
        'https://example.test/wp-json/fanxie-wp-core/v1/csp-report',
    },
    ...overrides,
  };
}

function makeViolationsResponse(
  overrides: Partial<ViolationsResponse> = {},
): ViolationsResponse {
  return {
    rows: [],
    total: 0,
    page: 1,
    per_page: 20,
    ...overrides,
  };
}

describe('useSecurityHeadersStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  describe('load()', () => {
    it('populates config, status, enabled and pristine snapshot on success', async () => {
      const configResponse = makeConfigResponse();
      mockAjaxResponse('security-headers/get-config', configResponse);
      mockAjaxResponse(
        'security-headers/list-violations',
        makeViolationsResponse(),
      );

      const store = useSecurityHeadersStore();
      await store.load();

      expect(store.config).toEqual(configResponse.settings);
      expect(store.status).toEqual(configResponse.status);
      expect(store.enabled).toBe(true);
      expect(store.loading.config).toBe(false);
      expect(store.isDirty).toBe(false);
      expect(store.error).toBeNull();
    });

    it('surfaces an error message when get-config fails', async () => {
      mockAjaxResponse(
        'security-headers/get-config',
        { code: 'denied', message: 'Not allowed.' },
        { success: false },
      );
      mockAjaxResponse(
        'security-headers/list-violations',
        makeViolationsResponse(),
      );

      const store = useSecurityHeadersStore();
      await store.load();

      expect(store.error).toBe('Not allowed.');
      expect(store.config).toBeNull();
      expect(store.toast?.variant).toBe('error');
    });
  });

  describe('save()', () => {
    it('persists the current config and resets the dirty flag', async () => {
      const initial = makeConfigResponse();
      mockAjaxResponse('security-headers/get-config', initial);
      mockAjaxResponse(
        'security-headers/list-violations',
        makeViolationsResponse(),
      );
      const store = useSecurityHeadersStore();
      await store.load();

      // Mutate the live config so we're dirty.
      store.config!.headers.hsts.enabled = false;
      expect(store.isDirty).toBe(true);

      // Server echoes back the saved config.
      const saved = makeConfigResponse();
      saved.settings.headers.hsts.enabled = false;
      mockAjaxResponse('security-headers/save-config', saved);

      await store.save();

      expect(store.loading.saving).toBe(false);
      expect(store.config?.headers.hsts.enabled).toBe(false);
      expect(store.isDirty).toBe(false);
      expect(store.toast?.variant).toBe('success');
    });
  });

  describe('applyPreset()', () => {
    it('merges directives returned by the server into state', async () => {
      const initial = makeConfigResponse();
      mockAjaxResponse('security-headers/get-config', initial);
      mockAjaxResponse(
        'security-headers/list-violations',
        makeViolationsResponse(),
      );
      const store = useSecurityHeadersStore();
      await store.load();

      const presetApplied = makeConfigResponse();
      presetApplied.settings.csp.directives = {
        ...presetApplied.settings.csp.directives,
        'script-src': ["'self'", 'https://js.stripe.com'],
        'connect-src': ["'self'", 'https://api.stripe.com'],
      };
      mockAjaxResponse('security-headers/apply-preset', presetApplied);

      await store.applyPreset('woocommerce');

      expect(store.config?.csp.directives['script-src']).toEqual([
        "'self'",
        'https://js.stripe.com',
      ]);
      expect(store.config?.csp.directives['connect-src']).toEqual([
        "'self'",
        'https://api.stripe.com',
      ]);
    });
  });

  describe('loadViolations()', () => {
    it('paginates through the violation log', async () => {
      const initial = makeConfigResponse();
      mockAjaxResponse('security-headers/get-config', initial);
      mockAjaxResponse(
        'security-headers/list-violations',
        makeViolationsResponse({
          rows: [
            {
              id: 1,
              created_at: '2026-04-01T12:00:00Z',
              last_seen_at: '2026-04-01T12:00:00Z',
              directive: 'script-src',
              blocked_uri: 'https://evil.example/pwn.js',
              document_uri: 'https://example.test/',
              source_file: null,
              line_number: null,
              user_agent: null,
              count: 3,
            },
          ],
          total: 25,
          page: 1,
          per_page: 20,
        }),
      );
      const store = useSecurityHeadersStore();
      await store.load();

      expect(store.violations.page).toBe(1);
      expect(store.violations.total).toBe(25);
      expect(store.violations.rows).toHaveLength(1);

      mockAjaxResponse(
        'security-headers/list-violations',
        makeViolationsResponse({
          rows: [],
          total: 25,
          page: 2,
          per_page: 20,
        }),
      );

      await store.loadViolations(2);
      expect(store.violations.page).toBe(2);
      expect(store.violations.rows).toHaveLength(0);
    });
  });

  describe('purgeViolations()', () => {
    it('calls purge then reloads page 1', async () => {
      mockAjaxResponse('security-headers/get-config', makeConfigResponse());
      mockAjaxResponse(
        'security-headers/list-violations',
        makeViolationsResponse({ total: 10 }),
      );
      const store = useSecurityHeadersStore();
      await store.load();

      mockAjaxResponse('security-headers/purge-violations', { deleted: 10 });
      mockAjaxResponse(
        'security-headers/list-violations',
        makeViolationsResponse({ total: 0, page: 1 }),
      );

      await store.purgeViolations({ all: true });

      expect(store.violations.total).toBe(0);
      expect(store.violations.page).toBe(1);
      expect(store.toast?.variant).toBe('success');
      expect(store.toast?.message).toContain('10');
    });
  });
});
