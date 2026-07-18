import { beforeEach, describe, expect, it } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { useHardeningStore } from '../hardening';
import type {
  ChecksResult,
  HardeningConfig,
  HardeningResponse,
  HardeningStatus,
} from '../../types';
import { mockAjaxResponse } from '../../../../../tests/setup';

function makeConfig(): HardeningConfig {
  return {
    user_enumeration: {
      block_author_archive: true,
      block_rest_users_endpoint: true,
    },
    xmlrpc: { mode: 'disabled', allowed_ips: [] },
    version_hiding: {
      remove_powered_by: true,
      remove_wp_generator: true,
      remove_rss_generator: true,
      strip_version_query: true,
      block_readme_license: true,
    },
    uploads: { drop_index: true, block_php_execution: true },
    login: { obfuscate_errors: true },
    file_editing: { runtime_enforce: false },
    application_passwords: { disable: false },
  };
}

function makeStatus(): HardeningStatus {
  return {
    active: true,
    summary: '8 of 12 active',
    warnings: [],
  };
}

function makeChecks(overrides: Partial<ChecksResult> = {}): ChecksResult {
  return {
    server_type: 'apache',
    x_powered_by_present: false,
    disallow_file_edit_defined: true,
    disallow_file_edit_value: true,
    uploads_dir_listable: false,
    uploads_php_executable: false,
    uploads_htaccess_exists: true,
    uploads_index_exists: true,
    application_passwords_count: 0,
    application_passwords_users: [],
    readme_blocked: true,
    license_blocked: true,
    probed_at: 1_713_300_000,
    ...overrides,
  };
}

function makeResponse(
  overrides: Partial<HardeningResponse> = {},
): HardeningResponse {
  return {
    settings: makeConfig(),
    status: makeStatus(),
    checks: makeChecks(),
    ...overrides,
  };
}

describe('useHardeningStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  describe('load()', () => {
    it('populates config, status, checks and pristine on success', async () => {
      const response = makeResponse();
      mockAjaxResponse('hardening/get-config', response);

      const store = useHardeningStore();
      await store.load();

      expect(store.config).toEqual(response.settings);
      expect(store.status).toEqual(response.status);
      expect(store.checks).toEqual(response.checks);
      expect(store.loading.initial).toBe(false);
      expect(store.isDirty).toBe(false);
      expect(store.error).toBeNull();
    });

    it('surfaces an error toast when get-config fails', async () => {
      mockAjaxResponse(
        'hardening/get-config',
        { code: 'denied', message: 'Not allowed.' },
        { success: false },
      );

      const store = useHardeningStore();
      await store.load();

      expect(store.error).toBe('Not allowed.');
      expect(store.config).toBeNull();
      expect(store.toast?.variant).toBe('error');
      expect(store.toast?.message).toBe('Not allowed.');
    });
  });

  describe('save()', () => {
    it('persists the current config and resets the dirty flag', async () => {
      mockAjaxResponse('hardening/get-config', makeResponse());
      const store = useHardeningStore();
      await store.load();

      // Mutate — we're dirty.
      store.config!.login.obfuscate_errors = false;
      expect(store.isDirty).toBe(true);

      const saved = makeResponse();
      saved.settings.login.obfuscate_errors = false;
      mockAjaxResponse('hardening/save-config', saved);

      await store.save();

      expect(store.loading.saving).toBe(false);
      expect(store.config?.login.obfuscate_errors).toBe(false);
      expect(store.isDirty).toBe(false);
      expect(store.toast?.variant).toBe('success');
    });

    it('no-ops when config is null', async () => {
      const store = useHardeningStore();
      // Should not throw and should not toggle saving flag.
      await store.save();
      expect(store.loading.saving).toBe(false);
    });
  });

  describe('reset()', () => {
    it('reverts the live config to the pristine snapshot', async () => {
      mockAjaxResponse('hardening/get-config', makeResponse());
      const store = useHardeningStore();
      await store.load();

      store.config!.xmlrpc.mode = 'off';
      expect(store.isDirty).toBe(true);

      store.reset();

      expect(store.config?.xmlrpc.mode).toBe('disabled');
      expect(store.isDirty).toBe(false);
    });
  });

  describe('runChecks()', () => {
    it('replaces checks with the fresh probe (cache bypass)', async () => {
      mockAjaxResponse('hardening/get-config', makeResponse());
      const store = useHardeningStore();
      await store.load();

      const fresh = makeResponse({
        checks: makeChecks({
          x_powered_by_present: true,
          probed_at: 1_713_400_000,
        }),
      });
      mockAjaxResponse('hardening/run-checks', fresh);

      await store.runChecks();

      expect(store.checks?.x_powered_by_present).toBe(true);
      expect(store.checks?.probed_at).toBe(1_713_400_000);
      expect(store.loading.checks).toBe(false);
    });
  });

  describe('applyFix()', () => {
    it('dispatches the uploads_index target and refreshes state', async () => {
      mockAjaxResponse('hardening/get-config', makeResponse());
      const store = useHardeningStore();
      await store.load();

      const fixed = makeResponse({
        checks: makeChecks({ uploads_index_exists: true }),
      });
      mockAjaxResponse('hardening/apply-fix', fixed);

      await store.applyFix('uploads_index');

      expect(store.checks?.uploads_index_exists).toBe(true);
      expect(store.loading.fixing).toBe(false);
      expect(store.toast?.variant).toBe('success');
      expect(store.toast?.message).toContain('index.php');
    });

    it('dispatches the uploads_htaccess target and surfaces the .htaccess label', async () => {
      mockAjaxResponse('hardening/get-config', makeResponse());
      const store = useHardeningStore();
      await store.load();

      mockAjaxResponse('hardening/apply-fix', makeResponse());

      await store.applyFix('uploads_htaccess');

      expect(store.toast?.variant).toBe('success');
      expect(store.toast?.message).toContain('.htaccess');
    });

    it('surfaces errors through the toast channel', async () => {
      mockAjaxResponse('hardening/get-config', makeResponse());
      const store = useHardeningStore();
      await store.load();

      mockAjaxResponse(
        'hardening/apply-fix',
        { code: 'fs_error', message: 'Could not write file.' },
        { success: false },
      );

      await store.applyFix('uploads_index');

      expect(store.error).toBe('Could not write file.');
      expect(store.toast?.variant).toBe('error');
    });
  });

  describe('dropUploadGuard()', () => {
    it('dispatches drop-upload-guard and reapplies the server envelope', async () => {
      mockAjaxResponse(
        'hardening/get-config',
        makeResponse({
          checks: makeChecks({ uploads_index_exists: false }),
        }),
      );
      const store = useHardeningStore();
      await store.load();
      expect(store.checks?.uploads_index_exists).toBe(false);

      // Server reports the file is now on disk — UI should flip to "Protected"
      // immediately based on this fresh snapshot (no second click needed).
      mockAjaxResponse(
        'hardening/drop-upload-guard',
        makeResponse({
          checks: makeChecks({ uploads_index_exists: true }),
        }),
      );

      await store.dropUploadGuard('uploads_index');

      expect(store.checks?.uploads_index_exists).toBe(true);
      expect(store.loading.guarding.uploads_index).toBe(false);
      expect(store.loading.fixing).toBe(false);
      expect(store.toast?.variant).toBe('success');
      expect(store.toast?.message).toBe('Protection restored.');
    });

    it('keeps the per-target guarding flag scoped so only the clicked row spins', async () => {
      mockAjaxResponse('hardening/get-config', makeResponse());
      const store = useHardeningStore();
      await store.load();

      // The promise resolves asynchronously — we can assert the sibling flag
      // stays false while the other is true by inspecting before awaiting.
      mockAjaxResponse('hardening/drop-upload-guard', makeResponse());

      const pending = store.dropUploadGuard('uploads_htaccess');
      expect(store.loading.guarding.uploads_htaccess).toBe(true);
      expect(store.loading.guarding.uploads_index).toBe(false);
      await pending;
      expect(store.loading.guarding.uploads_htaccess).toBe(false);
    });

    it('surfaces errors through the toast channel', async () => {
      mockAjaxResponse('hardening/get-config', makeResponse());
      const store = useHardeningStore();
      await store.load();

      mockAjaxResponse(
        'hardening/drop-upload-guard',
        { code: 'fs_error', message: 'Write denied.' },
        { success: false },
      );

      await store.dropUploadGuard('uploads_htaccess');

      expect(store.error).toBe('Write denied.');
      expect(store.toast?.variant).toBe('error');
      expect(store.loading.guarding.uploads_htaccess).toBe(false);
    });
  });

  describe('removeUploadGuard()', () => {
    it('dispatches remove-upload-guard and reapplies the server envelope', async () => {
      mockAjaxResponse('hardening/get-config', makeResponse());
      const store = useHardeningStore();
      await store.load();

      mockAjaxResponse(
        'hardening/remove-upload-guard',
        makeResponse({
          checks: makeChecks({ uploads_index_exists: false }),
        }),
      );

      await store.removeUploadGuard('uploads_index');

      expect(store.checks?.uploads_index_exists).toBe(false);
      expect(store.loading.guarding.uploads_index).toBe(false);
      expect(store.toast?.variant).toBe('success');
      expect(store.toast?.message).toBe('Protection removed.');
    });

    it('surfaces errors through the toast channel', async () => {
      mockAjaxResponse('hardening/get-config', makeResponse());
      const store = useHardeningStore();
      await store.load();

      mockAjaxResponse(
        'hardening/remove-upload-guard',
        { code: 'fs_error', message: 'Delete failed.' },
        { success: false },
      );

      await store.removeUploadGuard('uploads_index');

      expect(store.error).toBe('Delete failed.');
      expect(store.toast?.variant).toBe('error');
    });
  });

  describe('pushToast / dismissToast', () => {
    it('pushes and dismisses a toast', () => {
      const store = useHardeningStore();
      store.pushToast('hello', 'info');
      expect(store.toast?.message).toBe('hello');
      expect(store.toast?.variant).toBe('info');

      store.dismissToast();
      expect(store.toast).toBeNull();
    });
  });
});
