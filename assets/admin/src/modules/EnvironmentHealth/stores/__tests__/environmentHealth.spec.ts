import { beforeEach, describe, expect, it } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { useEnvironmentHealthStore } from '../environmentHealth';
import type {
  EnvironmentHealthConfig,
  HealthCheck,
  HealthCounts,
  HealthReport,
} from '../../types';
import { http, HttpResponse } from 'msw';
import {
  mockAjaxResponse,
  server,
  TEST_AJAX_URL,
} from '../../../../../tests/setup';

/**
 * Intercept one sub-action and record the payload the store actually sent.
 * Local to this spec — the shared helper only mocks responses.
 */
function captureAjaxRequest(
  subAction: string,
  response: unknown,
): { body: Record<string, unknown> | null } {
  const captured: { body: Record<string, unknown> | null } = { body: null };
  server.use(
    http.post(TEST_AJAX_URL, async ({ request }) => {
      const body = (await request.clone().json()) as Record<string, unknown>;
      if (body._action !== subAction) return undefined;
      captured.body = body;
      return HttpResponse.json({ success: true, data: response } as never);
    }),
  );
  return captured;
}

function makeCheck(overrides: Partial<HealthCheck> = {}): HealthCheck {
  return {
    id: 'php_version',
    group: 'versions',
    label: 'PHP version',
    status: 'ok',
    value: '8.2.14',
    summary: 'Running a supported PHP release.',
    ...overrides,
  };
}

function makeCounts(overrides: Partial<HealthCounts> = {}): HealthCounts {
  return { ok: 1, warning: 0, critical: 0, unknown: 0, ...overrides };
}

function makeReport(overrides: Partial<HealthReport> = {}): HealthReport {
  return {
    generated_at: 1_760_000_000,
    cached_until: 1_760_000_300,
    counts: makeCounts(),
    checks: [makeCheck()],
    ...overrides,
  };
}

function makeConfig(
  overrides: Partial<EnvironmentHealthConfig> = {},
): EnvironmentHealthConfig {
  // Mirrors get_default_config() in the PHP module.
  return {
    checks: {
      versions: true,
      cron: true,
      debug: true,
      plugins_themes: true,
      ...(overrides.checks ?? {}),
    },
    wporg_scan_enabled: overrides.wporg_scan_enabled ?? true,
    ssl_check_enabled: overrides.ssl_check_enabled ?? true,
    dashboard_widget: overrides.dashboard_widget ?? true,
    thresholds: {
      ssl_expiry_warning_days: 30,
      cron_overdue_minutes: 60,
      abandoned_warning_days: 365,
      abandoned_critical_days: 730,
      ...(overrides.thresholds ?? {}),
    },
  };
}

describe('useEnvironmentHealthStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  describe('load()', () => {
    it('populates config, pristine and report on success', async () => {
      mockAjaxResponse('environment-health/get-config', makeConfig());
      mockAjaxResponse('environment-health/get-report', makeReport());

      const store = useEnvironmentHealthStore();
      await store.load();

      expect(store.config).toEqual(makeConfig());
      expect(store.pristine).toEqual(makeConfig());
      expect(store.report).toEqual(makeReport());
      expect(store.loading.initial).toBe(false);
      expect(store.isDirty).toBe(false);
      expect(store.error).toBeNull();
    });

    it('keeps the report when only the settings call fails', async () => {
      mockAjaxResponse(
        'environment-health/get-config',
        { code: 'denied', message: 'Not allowed.' },
        { success: false },
      );
      mockAjaxResponse('environment-health/get-report', makeReport());

      const store = useEnvironmentHealthStore();
      await store.load();

      expect(store.report).not.toBeNull();
      expect(store.config).toBeNull();
      expect(store.error).toBe('Not allowed.');
      expect(store.toast?.variant).toBe('error');
    });

    it('keeps the settings when only the report call fails', async () => {
      mockAjaxResponse('environment-health/get-config', makeConfig());
      mockAjaxResponse(
        'environment-health/get-report',
        { code: 'probe_failed', message: 'Report unavailable.' },
        { success: false },
      );

      const store = useEnvironmentHealthStore();
      await store.load();

      expect(store.config).toEqual(makeConfig());
      expect(store.report).toBeNull();
      expect(store.error).toBe('Report unavailable.');
    });

    it('surfaces an error when both calls fail', async () => {
      mockAjaxResponse(
        'environment-health/get-config',
        { code: 'denied', message: 'Not allowed.' },
        { success: false },
      );
      mockAjaxResponse(
        'environment-health/get-report',
        { code: 'denied', message: 'Not allowed either.' },
        { success: false },
      );

      const store = useEnvironmentHealthStore();
      await store.load();

      expect(store.config).toBeNull();
      expect(store.report).toBeNull();
      expect(store.error).toBe('Not allowed either.');
      expect(store.toast?.variant).toBe('error');
    });
  });

  describe('refresh()', () => {
    it('replaces the report and toasts on success', async () => {
      mockAjaxResponse('environment-health/get-config', makeConfig());
      mockAjaxResponse('environment-health/get-report', makeReport());
      const store = useEnvironmentHealthStore();
      await store.load();

      const fresh = makeReport({
        generated_at: 1_760_000_900,
        counts: makeCounts({ ok: 0, critical: 1 }),
        checks: [makeCheck({ status: 'critical' })],
      });
      mockAjaxResponse('environment-health/refresh', fresh);

      await store.refresh();

      expect(store.report).toEqual(fresh);
      expect(store.overallStatus).toBe('critical');
      expect(store.loading.refreshing).toBe(false);
      expect(store.toast?.variant).toBe('success');
    });

    it('keeps the previous report and toasts an error when refresh fails', async () => {
      mockAjaxResponse('environment-health/get-config', makeConfig());
      mockAjaxResponse('environment-health/get-report', makeReport());
      const store = useEnvironmentHealthStore();
      await store.load();

      mockAjaxResponse(
        'environment-health/refresh',
        { code: 'timeout', message: 'Probe timed out.' },
        { success: false },
      );
      await store.refresh();

      expect(store.report?.generated_at).toBe(1_760_000_000);
      expect(store.error).toBe('Probe timed out.');
      expect(store.toast?.variant).toBe('error');
      expect(store.loading.refreshing).toBe(false);
    });
  });

  describe('save()', () => {
    it('sends the config under `settings` and adopts the returned report', async () => {
      mockAjaxResponse('environment-health/get-config', makeConfig());
      mockAjaxResponse('environment-health/get-report', makeReport());
      const store = useEnvironmentHealthStore();
      await store.load();

      store.config!.wporg_scan_enabled = false;
      expect(store.isDirty).toBe(true);

      const afterSave = makeReport({
        counts: makeCounts({ ok: 0, unknown: 1 }),
        checks: [makeCheck({ status: 'unknown' })],
      });
      mockAjaxResponse('environment-health/save-config', afterSave);
      mockAjaxResponse(
        'environment-health/get-config',
        makeConfig({ wporg_scan_enabled: false }),
      );

      await store.save();

      expect(store.report).toEqual(afterSave);
      expect(store.pristine).toEqual(makeConfig({ wporg_scan_enabled: false }));
      expect(store.isDirty).toBe(false);
      expect(store.toast?.variant).toBe('success');
    });

    it('round-trips the complete config — every group, toggle and threshold', async () => {
      mockAjaxResponse('environment-health/get-config', makeConfig());
      mockAjaxResponse('environment-health/get-report', makeReport());
      const store = useEnvironmentHealthStore();
      await store.load();

      // Touch every branch of the schema.
      const edited = makeConfig({
        checks: {
          versions: false,
          cron: true,
          debug: false,
          plugins_themes: true,
        },
        wporg_scan_enabled: false,
        ssl_check_enabled: false,
        dashboard_widget: false,
        thresholds: {
          ssl_expiry_warning_days: 14,
          cron_overdue_minutes: 120,
          abandoned_warning_days: 200,
          abandoned_critical_days: 400,
        },
      });
      store.config = edited;

      const captured = captureAjaxRequest(
        'environment-health/save-config',
        makeReport(),
      );
      mockAjaxResponse('environment-health/get-config', edited);

      await store.save();

      // Nothing dropped on the way out...
      expect(captured.body?.settings).toEqual(edited);
      // ...and the canonical read-back becomes the new clean baseline.
      expect(store.config).toEqual(edited);
      expect(store.pristine).toEqual(edited);
      expect(store.isDirty).toBe(false);
    });

    it('re-reads get-config after saving rather than trusting its own copy', async () => {
      mockAjaxResponse('environment-health/get-config', makeConfig());
      mockAjaxResponse('environment-health/get-report', makeReport());
      const store = useEnvironmentHealthStore();
      await store.load();

      // The client asks for 999; the PHP sanitiser is the authority and the
      // canonical read-back says 365. The store must show the server's value,
      // not the optimistic one, or the form would look clean while lying.
      store.config!.thresholds.abandoned_warning_days = 999;
      mockAjaxResponse('environment-health/save-config', makeReport());
      mockAjaxResponse(
        'environment-health/get-config',
        makeConfig({
          thresholds: {
            ssl_expiry_warning_days: 30,
            cron_overdue_minutes: 60,
            abandoned_warning_days: 365,
            abandoned_critical_days: 730,
          },
        }),
      );

      await store.save();

      expect(store.config?.thresholds.abandoned_warning_days).toBe(365);
      expect(store.isDirty).toBe(false);
    });

    it('falls back to the sent copy when the canonical re-read fails', async () => {
      mockAjaxResponse('environment-health/get-config', makeConfig());
      mockAjaxResponse('environment-health/get-report', makeReport());
      const store = useEnvironmentHealthStore();
      await store.load();

      store.config!.dashboard_widget = false;
      mockAjaxResponse('environment-health/save-config', makeReport());
      mockAjaxResponse(
        'environment-health/get-config',
        { code: 'denied', message: 'Gone.' },
        { success: false },
      );

      await store.save();

      // The write itself succeeded, so it still reports success and settles clean.
      expect(store.config?.dashboard_widget).toBe(false);
      expect(store.isDirty).toBe(false);
      expect(store.toast?.variant).toBe('success');
    });

    it('leaves the form dirty when the save fails', async () => {
      mockAjaxResponse('environment-health/get-config', makeConfig());
      mockAjaxResponse('environment-health/get-report', makeReport());
      const store = useEnvironmentHealthStore();
      await store.load();

      store.config!.wporg_scan_enabled = false;
      mockAjaxResponse(
        'environment-health/save-config',
        { code: 'denied', message: 'Not allowed.' },
        { success: false },
      );

      await store.save();

      expect(store.isDirty).toBe(true);
      expect(store.error).toBe('Not allowed.');
      expect(store.loading.saving).toBe(false);
    });

    it('is a no-op when no config has loaded', async () => {
      const store = useEnvironmentHealthStore();
      await store.save();
      expect(store.loading.saving).toBe(false);
      expect(store.toast).toBeNull();
    });
  });

  describe('reset()', () => {
    it('restores the pristine snapshot without sharing a reference', async () => {
      mockAjaxResponse('environment-health/get-config', makeConfig());
      mockAjaxResponse('environment-health/get-report', makeReport());
      const store = useEnvironmentHealthStore();
      await store.load();

      store.config!.wporg_scan_enabled = false;
      store.config!.thresholds.cron_overdue_minutes = 5;
      store.reset();

      expect(store.config).toEqual(makeConfig());
      expect(store.isDirty).toBe(false);

      // Mutating the restored config must not corrupt the baseline — including
      // the nested branches, which a shallow clone would share by reference.
      store.config!.wporg_scan_enabled = false;
      store.config!.thresholds.cron_overdue_minutes = 5;
      store.config!.checks.versions = false;
      expect(store.pristine).toEqual(makeConfig());
    });
  });

  describe('getters', () => {
    it('buckets checks into cards in the canonical group order', async () => {
      mockAjaxResponse('environment-health/get-config', makeConfig());
      mockAjaxResponse(
        'environment-health/get-report',
        makeReport({
          checks: [
            makeCheck({ id: 'inactive_plugins', group: 'plugins_themes' }),
            makeCheck({ id: 'wp_debug', group: 'debug' }),
            makeCheck({ id: 'php_version', group: 'versions' }),
            makeCheck({ id: 'cron_overdue', group: 'cron' }),
            makeCheck({ id: 'wp_version', group: 'versions' }),
          ],
        }),
      );
      const store = useEnvironmentHealthStore();
      await store.load();

      expect(store.checksByGroup.map((b) => b.group)).toEqual([
        'versions',
        'cron',
        'debug',
        'plugins_themes',
      ]);
      expect(store.checksByGroup[0]!.checks.map((c) => c.id)).toEqual([
        'php_version',
        'wp_version',
      ]);
    });

    it('drops groups with no checks rather than rendering an empty card', async () => {
      mockAjaxResponse('environment-health/get-config', makeConfig());
      mockAjaxResponse(
        'environment-health/get-report',
        makeReport({ checks: [makeCheck({ group: 'cron' })] }),
      );
      const store = useEnvironmentHealthStore();
      await store.load();

      expect(store.checksByGroup).toHaveLength(1);
      expect(store.checksByGroup[0]!.group).toBe('cron');
    });

    it('reports an empty report distinctly from "not loaded yet"', async () => {
      const store = useEnvironmentHealthStore();
      expect(store.isEmptyReport).toBe(false);

      mockAjaxResponse('environment-health/get-config', makeConfig());
      mockAjaxResponse(
        'environment-health/get-report',
        makeReport({
          checks: [],
          counts: makeCounts({ ok: 0 }),
        }),
      );
      await store.load();

      expect(store.isEmptyReport).toBe(true);
      expect(store.totalChecks).toBe(0);
      expect(store.countsSentence).toBe('No environment checks have run yet.');
      expect(store.checksByGroup).toEqual([]);
    });

    it('falls back to zeroed counts before any report arrives', () => {
      const store = useEnvironmentHealthStore();
      expect(store.counts).toEqual({
        ok: 0,
        warning: 0,
        critical: 0,
        unknown: 0,
      });
      expect(store.overallStatus).toBe('unknown');
    });

    it('builds the counts sentence from the live report', async () => {
      mockAjaxResponse('environment-health/get-config', makeConfig());
      mockAjaxResponse(
        'environment-health/get-report',
        makeReport({
          counts: { ok: 3, warning: 1, critical: 0, unknown: 2 },
        }),
      );
      const store = useEnvironmentHealthStore();
      await store.load();

      expect(store.totalChecks).toBe(6);
      expect(store.countsSentence).toBe(
        '6 checks: 3 OK, 1 warning, 2 that could not be checked.',
      );
      expect(store.overallStatus).toBe('warning');
    });
  });

  describe('toasts', () => {
    it('pushes and dismisses', () => {
      const store = useEnvironmentHealthStore();
      store.pushToast('Hello.', 'info');
      expect(store.toast?.message).toBe('Hello.');
      store.dismissToast();
      expect(store.toast).toBeNull();
    });
  });
});
