import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import Hardening from '../Hardening.vue';
import { useHardeningStore } from '../stores/hardening';
import type { ChecksResult, HardeningConfig, HardeningStatus } from '../types';

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
  return { active: true, summary: '8 of 12 active', warnings: [] };
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
    readme_blocked: true,
    license_blocked: true,
    probed_at: 1_713_300_000,
    ...overrides,
  };
}

async function mountWithStore(
  seed: (store: ReturnType<typeof useHardeningStore>) => void,
) {
  setActivePinia(createPinia());
  const store = useHardeningStore();
  vi.spyOn(store, 'load').mockResolvedValue();
  seed(store);

  const wrapper = mount(Hardening, {
    attachTo: document.body,
  });
  await flushPromises();
  return { store, wrapper };
}

describe('<Hardening>', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('calls store.load() on mount', async () => {
    const store = useHardeningStore();
    const loadSpy = vi.spyOn(store, 'load').mockResolvedValue();

    mount(Hardening);
    await flushPromises();

    expect(loadSpy).toHaveBeenCalledTimes(1);
  });

  it('renders all seven section headings once config is loaded', async () => {
    const { wrapper } = await mountWithStore((store) => {
      store.config = makeConfig();
      store.status = makeStatus();
      store.checks = makeChecks();
    });

    const text = wrapper.text();
    expect(text).toContain('User Enumeration');
    expect(text).toContain('XML-RPC');
    expect(text).toContain('Version Disclosure');
    expect(text).toContain('Uploads Directory');
    expect(text).toContain('Login and Sessions');
    expect(text).toContain('File Editing');
    expect(text).toContain('Application Passwords');
  });

  it('hides the Application Passwords toggle when at least one AP exists', async () => {
    const { wrapper } = await mountWithStore((store) => {
      store.config = makeConfig();
      store.status = makeStatus();
      store.checks = makeChecks({ application_passwords_count: 2 });
    });

    // The row's toggle should not be present; the explanatory note should be.
    expect(wrapper.text()).toContain(
      'Application Passwords are currently in use',
    );
    expect(wrapper.text()).not.toContain('Disable Application Passwords');
  });

  it('shows the Application Passwords toggle when no AP exists', async () => {
    const { wrapper } = await mountWithStore((store) => {
      store.config = makeConfig();
      store.status = makeStatus();
      store.checks = makeChecks({ application_passwords_count: 0 });
    });

    expect(wrapper.text()).toContain('Disable Application Passwords');
    expect(wrapper.text()).not.toContain(
      'Application Passwords are currently in use',
    );
  });

  it('renders the wp-config snippet when DISALLOW_FILE_EDIT is not defined', async () => {
    const { wrapper } = await mountWithStore((store) => {
      store.config = makeConfig();
      store.status = makeStatus();
      store.checks = makeChecks({
        disallow_file_edit_defined: false,
        disallow_file_edit_value: false,
      });
    });

    expect(wrapper.text()).toContain("define( 'DISALLOW_FILE_EDIT', true );");
  });

  it('hides the wp-config snippet when the constant is already defined', async () => {
    const { wrapper } = await mountWithStore((store) => {
      store.config = makeConfig();
      store.status = makeStatus();
      store.checks = makeChecks({
        disallow_file_edit_defined: true,
        disallow_file_edit_value: true,
      });
    });

    expect(wrapper.text()).not.toContain(
      "define( 'DISALLOW_FILE_EDIT', true );",
    );
  });

  it('renders a loading placeholder before config arrives', async () => {
    setActivePinia(createPinia());
    const store = useHardeningStore();
    vi.spyOn(store, 'load').mockImplementation(() => {
      store.loading.initial = true;
      return new Promise(() => {
        /* never resolves */
      });
    });

    const wrapper = mount(Hardening);
    await flushPromises();

    expect(wrapper.text()).toContain('Loading hardening configuration…');
  });

  it('exposes a "Run checks" button that calls store.runChecks', async () => {
    const { store, wrapper } = await mountWithStore((s) => {
      s.config = makeConfig();
      s.status = makeStatus();
      s.checks = makeChecks();
    });
    const runChecksSpy = vi.spyOn(store, 'runChecks').mockResolvedValue();

    const button = wrapper.get('.fx-hardening__run-checks');
    await button.trigger('click');

    expect(runChecksSpy).toHaveBeenCalledTimes(1);
  });

  describe('Uploads section', () => {
    it('renders the guard rows (not toggles) with filesystem-derived status', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig();
        store.status = makeStatus();
        store.checks = makeChecks({
          uploads_index_exists: true,
          uploads_htaccess_exists: true,
          uploads_php_executable: false,
        });
      });

      const text = wrapper.text();
      expect(text).toContain('index.php (directory listing prevention)');
      expect(text).toContain('PHP execution blocker (.htaccess)');
      // Both rows should report "Protected" and expose a Restore button.
      expect(text).toContain('Protected');
      expect(text).toContain('Restore');
      expect(text).toContain('Canary probe: blocked');
      // Legacy toggle labels must be gone.
      expect(text).not.toContain('Drop index.php into uploads directory');
      expect(text).not.toContain('Block PHP execution in uploads');
    });

    it('shows "Drop now" on a missing index.php row', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig();
        store.status = makeStatus();
        store.checks = makeChecks({ uploads_index_exists: false });
      });

      expect(wrapper.text()).toContain('Not protected');
      expect(wrapper.text()).toContain('Drop now');
    });

    it('dispatches dropUploadGuard when the "Drop now" button is clicked', async () => {
      const { store, wrapper } = await mountWithStore((s) => {
        s.config = makeConfig();
        s.status = makeStatus();
        s.checks = makeChecks({ uploads_index_exists: false });
      });
      const dropSpy = vi.spyOn(store, 'dropUploadGuard').mockResolvedValue();

      // First guard row is uploads_index.
      const rows = wrapper.findAll('.fx-uploads-guard');
      expect(rows.length).toBe(2);
      const button = rows[0]!.get('.fx-uploads-guard__primary');
      await button.trigger('click');

      expect(dropSpy).toHaveBeenCalledWith('uploads_index');
    });

    it('dispatches dropUploadGuard when "Restore" is clicked on a protected row', async () => {
      const { store, wrapper } = await mountWithStore((s) => {
        s.config = makeConfig();
        s.status = makeStatus();
        s.checks = makeChecks({
          uploads_index_exists: true,
          uploads_htaccess_exists: true,
          uploads_php_executable: false,
        });
      });
      const dropSpy = vi.spyOn(store, 'dropUploadGuard').mockResolvedValue();

      // Click Restore on the index.php row.
      const rows = wrapper.findAll('.fx-uploads-guard');
      const button = rows[0]!.get('.fx-uploads-guard__primary');
      expect(button.text()).toBe('Restore');
      await button.trigger('click');

      expect(dropSpy).toHaveBeenCalledWith('uploads_index');
    });

    it('surfaces an inconclusive canary caption with a "Re-run probe" button', async () => {
      const { store, wrapper } = await mountWithStore((s) => {
        s.config = makeConfig();
        s.status = makeStatus();
        s.checks = makeChecks({
          uploads_htaccess_exists: true,
          uploads_php_executable: null,
        });
      });
      const runChecksSpy = vi.spyOn(store, 'runChecks').mockResolvedValue();

      expect(wrapper.text()).toContain('Canary probe: inconclusive');

      // The secondary button lives on the .htaccess row.
      const rows = wrapper.findAll('.fx-uploads-guard');
      const reRun = rows[1]!.get('.fx-uploads-guard__secondary');
      expect(reRun.text()).toContain('Re-run probe');
      await reRun.trigger('click');

      expect(runChecksSpy).toHaveBeenCalledTimes(1);
    });

    it('spins only the clicked row (per-target guarding flag)', async () => {
      const { store, wrapper } = await mountWithStore((s) => {
        s.config = makeConfig();
        s.status = makeStatus();
        s.checks = makeChecks();
      });
      store.loading.guarding.uploads_index = true;
      await flushPromises();

      const rows = wrapper.findAll('.fx-uploads-guard');
      const firstButton = rows[0]!.get('.fx-uploads-guard__primary');
      const secondButton = rows[1]!.get('.fx-uploads-guard__primary');
      expect(firstButton.attributes('disabled')).toBeDefined();
      expect(secondButton.attributes('disabled')).toBeUndefined();
    });
  });

  describe('Version Disclosure row derivations', () => {
    it('warns with "Still accessible" pill when readme_blocked is false', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig();
        store.status = makeStatus();
        store.checks = makeChecks({
          readme_blocked: false,
          license_blocked: true,
        });
      });

      expect(wrapper.text()).toContain('Still accessible');
      expect(wrapper.text()).not.toContain('write failed');
      expect(wrapper.text()).toContain('AllowOverride');
    });

    it('warns when license_blocked is false even if readme is blocked', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig();
        store.status = makeStatus();
        store.checks = makeChecks({
          readme_blocked: true,
          license_blocked: false,
        });
      });

      expect(wrapper.text()).toContain('Still accessible');
    });

    it('does not show the warning pill when both files are blocked', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig();
        store.status = makeStatus();
        store.checks = makeChecks({
          readme_blocked: true,
          license_blocked: true,
        });
      });

      expect(wrapper.text()).not.toContain('Still accessible');
    });

    it('shows server-aware guidance (not "write failed") when readme is still served on nginx', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig();
        store.status = makeStatus();
        store.checks = makeChecks({
          server_type: 'nginx',
          readme_blocked: false,
          license_blocked: true,
        });
      });

      const text = wrapper.text();
      expect(text).not.toContain('write failed');
      expect(text.toLowerCase()).toContain('nginx');
    });

    it('treats an inconclusive (null) probe as active — no false-alarm warning', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig();
        store.status = makeStatus();
        store.checks = makeChecks({
          readme_blocked: null,
          license_blocked: null,
        });
      });

      expect(wrapper.text()).not.toContain('Still accessible');
    });

    it('renders the cache-busting caveat under the "?ver=" row', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig();
        store.status = makeStatus();
        store.checks = makeChecks();
      });

      expect(wrapper.text()).toContain(
        'stripping version query strings weakens asset cache-busting',
      );
    });
  });

  describe('X-Powered-By derivation', () => {
    it('does not warn when the toggle is on and the header is absent', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig();
        store.config.version_hiding.remove_powered_by = true;
        store.status = makeStatus();
        store.checks = makeChecks({ x_powered_by_present: false });
      });

      expect(wrapper.text()).not.toContain('Still present');
    });

    it('warns when the toggle is on but the header is still present', async () => {
      const { wrapper } = await mountWithStore((store) => {
        store.config = makeConfig();
        store.config.version_hiding.remove_powered_by = true;
        store.status = makeStatus();
        store.checks = makeChecks({ x_powered_by_present: true });
      });

      expect(wrapper.text()).toContain('Still present');
    });
  });
});
