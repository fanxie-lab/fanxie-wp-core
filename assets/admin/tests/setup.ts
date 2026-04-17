// Global Vitest setup — runs once per test file before any test.
//
// Responsibilities:
//   1. Boot an MSW server that intercepts `fetch` to window.fanxieWPCore.ajaxUrl.
//   2. Provide a reusable `mockAjaxResponse` helper that registers a handler
//      keyed by the WP admin-ajax sub-action (the `_action` form field).
//   3. Reset window.fanxieWPCore to a known bootstrap before every test so
//      store tests read consistent defaults.

import { afterAll, afterEach, beforeAll, beforeEach } from 'vitest';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import type { FanxieBootstrap } from '@/types/global';

export const TEST_AJAX_URL = 'http://localhost/wp-admin/admin-ajax.php';

/** Canonical bootstrap fixture — mirrors the shape the PHP side hydrates. */
export function defaultBootstrap(): FanxieBootstrap {
  return {
    version: '0.1.0-dev',
    ajaxUrl: TEST_AJAX_URL,
    adminUrl: 'http://localhost/wp-admin/',
    restUrl: 'http://localhost/wp-json/',
    nonce: 'test-nonce-abcdef',
    assetsUrl: 'http://localhost/wp-content/plugins/fanxie-wp-core/assets/',
    user: {
      id: 1,
      caps: {
        manage_fanxie_wp_core: true,
        manage_options: true,
      },
    },
    modules: {
      'security-headers': { enabled: false, config: {} },
      hardening: { enabled: false, config: {} },
      'login-protection': { enabled: false, config: {} },
    },
    i18n: { locale: 'en_US' },
  };
}

/** The MSW server instance — shared across the whole test suite. */
export const server = setupServer();

interface MockAjaxOpts {
  /** HTTP status code returned by the mocked response. Default: 200. */
  status?: number;
  /**
   * If true (default), wrap `response` in the WP `{ success: true, data }`
   * envelope. If false, wrap in `{ success: false, data: response }`.
   * Set to the string 'raw' to skip wrapping entirely (for non-JSON /
   * malformed-payload tests).
   */
  success?: boolean | 'raw';
}

/**
 * Register a one-shot-per-subAction MSW handler against TEST_AJAX_URL.
 *
 * The handler matches on the `_action` form field — the sub-action the
 * AjaxRouter dispatches on. Handlers registered here live until the next
 * `server.resetHandlers()` in `afterEach`.
 *
 * @param subAction  The sub-action name (PHP side uses `sanitize_key`).
 * @param response   The `data` payload to return (pre-envelope).
 * @param opts       Status / success-flag overrides.
 */
export function mockAjaxResponse(
  subAction: string,
  response: unknown,
  opts: MockAjaxOpts = {},
): void {
  const { status = 200, success = true } = opts;

  server.use(
    http.post(TEST_AJAX_URL, async ({ request }) => {
      // Clone to keep the original body consumable by other handlers if they
      // were to run — `formData()` is a one-shot read on the original.
      const cloned = request.clone();
      let requestedSubAction: string | null = null;
      try {
        const form = await cloned.formData();
        const raw = form.get('_action');
        requestedSubAction = typeof raw === 'string' ? raw : null;
      } catch {
        requestedSubAction = null;
      }

      // Skip: let a later handler (or the unhandled-request warning) take it.
      if (requestedSubAction !== subAction) {
        return undefined;
      }

      // MSW's HttpResponse.json expects a JsonBodyType; we accept `unknown`
      // as fixture input and cast here. Test fixtures are developer-supplied
      // and JSON-serialisable in practice; runtime behaviour is validated by
      // the ajax tests themselves.
      if (success === 'raw') {
        return HttpResponse.json(
          response as Parameters<typeof HttpResponse.json>[0],
          { status },
        );
      }

      return HttpResponse.json(
        { success, data: response } as Parameters<typeof HttpResponse.json>[0],
        { status },
      );
    }),
  );
}

beforeAll(() => {
  // `warn` instead of `error` so an unrelated fetch surfaces but does not
  // crash the whole suite — each test opts in explicitly via mockAjaxResponse.
  server.listen({ onUnhandledRequest: 'warn' });
});

beforeEach(() => {
  // Every test sees the same bootstrap. Stores are created in their own
  // `beforeEach`, so this runs first — ordering across setup files is by
  // registration, and our setup file is the only one.
  window.fanxieWPCore = defaultBootstrap();
});

afterEach(() => {
  server.resetHandlers();
});

afterAll(() => {
  server.close();
});
