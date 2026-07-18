<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Lock, RefreshCw, Copy, Check } from 'lucide-vue-next';
import { SaveBar, StatusPill, Toast, Toggle } from '@/components';
import type { StatusPillVariant, ToastVariant, SaveStatus } from '@/components';
import { useHardeningStore } from './stores/hardening';
import ChecklistItem from './components/ChecklistItem.vue';
import UploadsGuardRow from './components/UploadsGuardRow.vue';
import XmlRpcControl from './components/XmlRpcControl.vue';
import type { ChecklistStatus, UploadsGuardTarget } from './types';

/**
 * Hardening module root.
 *
 * Single scrollable checklist view (per user direction — no sub-tabs).
 * Groups:
 *   1. User Enumeration      (2 toggles)
 *   2. XML-RPC               (XmlRpcControl)
 *   3. Version Disclosure    (5 toggles — readme/license row surfaces a
 *                             "Still accessible" pill + help text when the
 *                             server-side rewrite silently failed)
 *   4. Uploads Directory     (UploadsGuardRow ×2 — status + drop/restore
 *                             buttons derived from filesystem probe)
 *   5. Login & Sessions      (1 toggle)
 *   6. File Editing          (1 toggle + wp-config snippet fallback)
 *   7. Application Passwords (1 toggle, hidden when any AP exists)
 *
 * Status-pill variants per row are derived from `store.checks` where
 * server-level signals are available (e.g. X-Powered-By still arriving
 * even though the toggle is on). Inconclusive probes (`null`) surface a
 * warning pill with a "re-run checks" hint.
 */

const store = useHardeningStore();

const saveStatus = computed<SaveStatus>(() => {
  if (store.loading.saving) return 'saving';
  if (store.error) return 'error';
  return 'idle';
});

const headerStatusPill = computed<{
  variant: StatusPillVariant;
  label: string;
}>(() => {
  if (!store.status) {
    return { variant: 'neutral', label: 'Loading…' };
  }
  if (!store.status.active) {
    return { variant: 'neutral', label: store.status.summary };
  }
  return { variant: 'ok', label: store.status.summary };
});

const toastVariant = computed<ToastVariant>(() => {
  const variant = store.toast?.variant ?? 'info';
  if (variant === 'success') return 'success';
  if (variant === 'error') return 'error';
  return 'info';
});

// Toast auto-clear so a later dismiss action doesn't re-render a stale one.
watch(
  () => store.toast,
  (next) => {
    if (!next) return;
    window.setTimeout(() => {
      if (store.toast?.stamp === next.stamp) {
        store.dismissToast();
      }
    }, 5500);
  },
);

const toastKey = computed<number>(() => store.toast?.stamp ?? 0);

// Whether each row's derived status is currently "active + no obstacle".
// Helpers below make the template readable.
function booleanToggleStatus(enabled: boolean): ChecklistStatus {
  return enabled ? 'active' : 'inactive';
}

/** A boolean check that signals a warning when truthy (e.g., "still listable"). */
function warnIfTrue(
  enabled: boolean,
  obstaclePresent: boolean,
): ChecklistStatus {
  if (!enabled) return 'inactive';
  return obstaclePresent ? 'warning' : 'active';
}

// --- Derived row statuses ---------------------------------------------------

const statusBlockAuthor = computed<ChecklistStatus>(() =>
  booleanToggleStatus(
    store.config?.user_enumeration.block_author_archive ?? false,
  ),
);
const statusBlockRestUsers = computed<ChecklistStatus>(() =>
  booleanToggleStatus(
    store.config?.user_enumeration.block_rest_users_endpoint ?? false,
  ),
);

const xmlrpcActive = computed<boolean>(() => {
  const mode = store.config?.xmlrpc.mode;
  return mode !== undefined && mode !== 'off';
});
const statusXmlRpc = computed<ChecklistStatus>(() =>
  xmlrpcActive.value ? 'active' : 'inactive',
);

const statusRemovePoweredBy = computed<ChecklistStatus>(() =>
  warnIfTrue(
    store.config?.version_hiding.remove_powered_by ?? false,
    store.checks?.x_powered_by_present ?? false,
  ),
);
const statusRemoveWpGenerator = computed<ChecklistStatus>(() =>
  booleanToggleStatus(
    store.config?.version_hiding.remove_wp_generator ?? false,
  ),
);
const statusRemoveRssGenerator = computed<ChecklistStatus>(() =>
  booleanToggleStatus(
    store.config?.version_hiding.remove_rss_generator ?? false,
  ),
);
const statusStripVersionQuery = computed<ChecklistStatus>(() =>
  booleanToggleStatus(
    store.config?.version_hiding.strip_version_query ?? false,
  ),
);
const statusBlockReadmeLicense = computed<ChecklistStatus>(() => {
  const enabled = store.config?.version_hiding.block_readme_license ?? false;
  if (!enabled) return 'inactive';
  // Warn only when a probe positively reports the file is STILL served
  // (=== false). `null` (inconclusive — e.g. dev host can't reach itself) is
  // treated as active so we don't cry wolf.
  const readmeServed = store.checks?.readme_blocked === false;
  const licenseServed = store.checks?.license_blocked === false;
  if (readmeServed || licenseServed) return 'warning';
  return 'active';
});

/**
 * Server-aware remediation copy for the readme/license warning footer.
 * Branches by detected `server_type` — Apache/LiteSpeed honor .htaccess so
 * the fix is confirming AllowOverride; nginx/IIS never read .htaccess at
 * all, so the fix is a server-block rule instead.
 */
const readmeServerGuidance = computed<string>(() => {
  const type = store.checks?.server_type ?? 'unknown';
  if (type === 'apache' || type === 'litespeed') {
    return 'The rewrite rule is in place but one or both files still respond. Confirm your host allows .htaccess overrides (AllowOverride) for the site root.';
  }
  if (type === 'nginx' || type === 'iis') {
    return `Your server (${type}) does not use .htaccess. Add a rule to block these files, then run checks again.`;
  }
  return 'One or both files still respond. If your server is nginx/IIS, add the equivalent server-block rule; on Apache, confirm .htaccess overrides are allowed.';
});

// Uploads section now renders status rows (not toggles) driven directly by
// `checks.*`. Status is derived from observed filesystem state — the backend
// `drop_index` / `block_php_execution` config flags still drive auto-maintain
// at activation/save time, but we do not surface them as UI toggles anymore.

const uploadsIndexStatus = computed<'protected' | 'missing'>(() =>
  (store.checks?.uploads_index_exists ?? false) ? 'protected' : 'missing',
);
const uploadsHtaccessStatus = computed<'protected' | 'missing'>(() =>
  (store.checks?.uploads_htaccess_exists ?? false) ? 'protected' : 'missing',
);

/**
 * Caption for the .htaccess row's canary probe. Server-side probe writes a
 * dummy `.php` into the uploads dir and fetches it; `uploads_php_executable`
 * encodes the result (`false` = blocked, `true` = executable, `null` = could
 * not reach the host / probe inconclusive).
 */
const uploadsHtaccessCaption = computed<string>(() => {
  const probe = store.checks?.uploads_php_executable;
  if (probe === null || probe === undefined) {
    return 'Canary probe: inconclusive';
  }
  return probe ? 'Canary probe: executable' : 'Canary probe: blocked';
});

const uploadsHtaccessShowReRun = computed<boolean>(() => {
  const probe = store.checks?.uploads_php_executable;
  return probe === null || probe === undefined;
});

const statusLoginErrors = computed<ChecklistStatus>(() =>
  booleanToggleStatus(store.config?.login.obfuscate_errors ?? false),
);

const statusFileEditing = computed<ChecklistStatus>(() => {
  // "Active" requires either the constant to be defined OR runtime fallback on.
  const constantApplied =
    (store.checks?.disallow_file_edit_defined ?? false) &&
    (store.checks?.disallow_file_edit_value ?? false);
  const runtime = store.config?.file_editing.runtime_enforce ?? false;
  if (constantApplied) return 'active';
  if (runtime) return 'warning'; // runtime fallback is less secure — surface a nudge
  return 'inactive';
});

const showWpConfigSnippet = computed<boolean>(
  () => store.checks !== null && !store.checks.disallow_file_edit_defined,
);

const statusApplicationPasswords = computed<ChecklistStatus>(() =>
  booleanToggleStatus(store.config?.application_passwords.disable ?? false),
);

// Application Passwords row is hidden entirely when any AP currently exists.
const showApplicationPasswordsRow = computed<boolean>(() => {
  const count = store.checks?.application_passwords_count ?? 0;
  return count === 0;
});
const applicationPasswordsCount = computed<number>(
  () => store.checks?.application_passwords_count ?? 0,
);

// --- wp-config snippet copy-to-clipboard -----------------------------------
const WP_CONFIG_SNIPPET = "define( 'DISALLOW_FILE_EDIT', true );";
const snippetCopied = ref<boolean>(false);
let snippetCopyTimer: ReturnType<typeof setTimeout> | null = null;

async function copySnippet(): Promise<void> {
  try {
    await navigator.clipboard.writeText(WP_CONFIG_SNIPPET);
    snippetCopied.value = true;
    if (snippetCopyTimer !== null) {
      clearTimeout(snippetCopyTimer);
    }
    snippetCopyTimer = setTimeout(() => {
      snippetCopied.value = false;
    }, 2000);
  } catch {
    store.pushToast(
      'Copy to clipboard failed. Select the snippet and copy manually.',
      'error',
    );
  }
}

// --- Event handlers --------------------------------------------------------

function onRunChecks(): void {
  void store.runChecks();
}

function onSave(): void {
  void store.save();
}

function onReset(): void {
  store.reset();
}

/**
 * Drop (or re-write) the uploads guard. This handler powers both the
 * "Drop now" button (when the file is missing) and the "Restore" button
 * (when the file is present but the admin wants to repair its contents or
 * re-run after an inconclusive canary). Both paths hit the same
 * `hardening/drop-upload-guard` endpoint — idempotent by contract.
 */
function onDropGuard(target: UploadsGuardTarget): void {
  if (target === 'uploads_htaccess') {
    const serverType = store.checks?.server_type;
    if (serverType === 'nginx' || serverType === 'iis') {
      if (
        !window.confirm(
          'This server appears to run nginx/IIS. A .htaccess file will have no effect here — you will need to add a matching server config block manually. Continue anyway?',
        )
      ) {
        return;
      }
    }
  }
  void store.dropUploadGuard(target);
}

function onReRunProbe(): void {
  void store.runChecks();
}

// Some toggles are "unusual" enough that a confirmation prompt reduces the
// odds of a surprised admin locking themselves out. Mirrors the pattern used
// by the SecurityHeaders HSTS row.
function confirmTogglingOff(
  label: string,
  next: boolean,
  current: boolean,
): boolean {
  if (next === current) return true;
  if (next) return true;
  return window.confirm(
    `You are about to disable "${label}". This removes a hardening protection. Continue?`,
  );
}

function onToggleBlockReadmeLicense(next: boolean): void {
  if (!store.config) return;
  if (
    !confirmTogglingOff(
      'Block readme.html / license.txt',
      next,
      store.config.version_hiding.block_readme_license,
    )
  ) {
    return;
  }
  store.config.version_hiding.block_readme_license = next;
}

onMounted(() => {
  void store.load();
});
</script>

<template>
  <div class="fx-hardening">
    <header class="fx-hardening__header">
      <span class="fx-hardening__icon-wrap" aria-hidden="true">
        <Lock
          :size="28"
          :stroke-width="1.75"
          aria-hidden="true"
          focusable="false"
        />
      </span>
      <div class="fx-hardening__header-text">
        <div class="fx-hardening__title-row">
          <h2 class="fx-hardening__title">Hardening</h2>
          <StatusPill
            :variant="headerStatusPill.variant"
            :label="headerStatusPill.label"
          />
        </div>
        <p class="fx-hardening__description">
          Close common information-disclosure vectors: user enumeration,
          XML-RPC, version fingerprints, uploads-directory PHP execution, file
          editing, and unused Application Passwords.
        </p>
      </div>
      <div class="fx-hardening__header-actions">
        <button
          type="button"
          class="fx-hardening__run-checks"
          :disabled="store.loading.checks"
          @click="onRunChecks"
        >
          <RefreshCw
            :size="16"
            :stroke-width="1.75"
            class="fx-hardening__run-checks-icon"
            :class="{
              'fx-hardening__run-checks-icon--spinning': store.loading.checks,
            }"
            aria-hidden="true"
            focusable="false"
          />
          <span>{{ store.loading.checks ? 'Running…' : 'Run checks' }}</span>
        </button>
      </div>
    </header>

    <p
      v-if="store.loading.initial && !store.config"
      class="fx-hardening__loading"
      role="status"
    >
      Loading hardening configuration…
    </p>

    <div v-else-if="store.config" class="fx-hardening__sections">
      <!-- 1. User Enumeration -->
      <section
        class="fx-hardening__section"
        aria-labelledby="fx-hardening-user-enum"
      >
        <header class="fx-hardening__section-header">
          <h3 id="fx-hardening-user-enum" class="fx-hardening__section-title">
            User Enumeration
          </h3>
          <p class="fx-hardening__section-hint">
            Prevent anonymous discovery of usernames via author archives and the
            REST users endpoint.
          </p>
        </header>
        <div class="fx-hardening__items">
          <ChecklistItem
            label="Block author archive (?author=N)"
            description="Returns 404 for unauthenticated requests to author URLs."
            :status="statusBlockAuthor"
          >
            <template #default="{ labelId }">
              <Toggle
                v-model="store.config.user_enumeration.block_author_archive"
                label="Block author archive"
                hide-label
                :aria-labelledby="labelId"
              />
            </template>
          </ChecklistItem>
          <ChecklistItem
            label="Block REST /wp/v2/users for anonymous requests"
            description="Returns 401 to unauthenticated GETs of the users endpoint."
            :status="statusBlockRestUsers"
          >
            <template #default="{ labelId }">
              <Toggle
                v-model="
                  store.config.user_enumeration.block_rest_users_endpoint
                "
                label="Block REST users endpoint"
                hide-label
                :aria-labelledby="labelId"
              />
            </template>
          </ChecklistItem>
        </div>
      </section>

      <!-- 2. XML-RPC -->
      <section
        class="fx-hardening__section"
        aria-labelledby="fx-hardening-xmlrpc"
      >
        <header class="fx-hardening__section-header">
          <h3 id="fx-hardening-xmlrpc" class="fx-hardening__section-title">
            XML-RPC
          </h3>
          <p class="fx-hardening__section-hint">
            Most modern sites can disable XML-RPC entirely. Jetpack-legacy sites
            may need the IP allowlist.
          </p>
        </header>
        <ChecklistItem
          label="XML-RPC mode"
          description="Choose how the /xmlrpc.php endpoint is handled."
          :status="statusXmlRpc"
        >
          <template #default="{ labelId }">
            <XmlRpcControl
              :mode="store.config.xmlrpc.mode"
              :allowed-ips="store.config.xmlrpc.allowed_ips"
              :aria-labelledby="labelId"
              @update:mode="store.config.xmlrpc.mode = $event"
              @update:allowed-ips="store.config.xmlrpc.allowed_ips = $event"
            />
          </template>
        </ChecklistItem>
      </section>

      <!-- 3. Version Disclosure -->
      <section
        class="fx-hardening__section"
        aria-labelledby="fx-hardening-version"
      >
        <header class="fx-hardening__section-header">
          <h3 id="fx-hardening-version" class="fx-hardening__section-title">
            Version Disclosure
          </h3>
          <p class="fx-hardening__section-hint">
            Scrub version strings from response headers and front-end markup so
            scanners get less to fingerprint.
          </p>
        </header>
        <div class="fx-hardening__items">
          <ChecklistItem
            label="Remove X-Powered-By header"
            description="Calls header_remove('X-Powered-By'). Some servers re-inject it at a lower layer; we flag when that happens."
            :status="statusRemovePoweredBy"
            :status-label="
              statusRemovePoweredBy === 'warning' ? 'Still present' : undefined
            "
          >
            <template #default="{ labelId }">
              <Toggle
                v-model="store.config.version_hiding.remove_powered_by"
                label="Remove X-Powered-By"
                hide-label
                :aria-labelledby="labelId"
              />
            </template>
          </ChecklistItem>
          <ChecklistItem
            label="Remove WordPress generator tag"
            description="Removes the <meta name=generator> tag from the head."
            :status="statusRemoveWpGenerator"
          >
            <template #default="{ labelId }">
              <Toggle
                v-model="store.config.version_hiding.remove_wp_generator"
                label="Remove WP generator tag"
                hide-label
                :aria-labelledby="labelId"
              />
            </template>
          </ChecklistItem>
          <ChecklistItem
            label="Remove generator from RSS feeds"
            description="Strips the <generator> element from RSS / Atom feeds."
            :status="statusRemoveRssGenerator"
          >
            <template #default="{ labelId }">
              <Toggle
                v-model="store.config.version_hiding.remove_rss_generator"
                label="Remove RSS generator"
                hide-label
                :aria-labelledby="labelId"
              />
            </template>
          </ChecklistItem>
          <ChecklistItem
            label="Strip ?ver= query from scripts and styles"
            description="Removes the version query string from enqueued asset URLs."
            :status="statusStripVersionQuery"
          >
            <template #default="{ labelId }">
              <Toggle
                v-model="store.config.version_hiding.strip_version_query"
                label="Strip version query"
                hide-label
                :aria-labelledby="labelId"
              />
            </template>
            <template #footer>
              <p class="fx-hardening__help" role="note">
                Note: stripping version query strings weakens asset
                cache-busting. Browsers may hold stale JS/CSS longer across
                WordPress upgrades. Most sites are fine with this; if you see
                users reporting broken admin UI after upgrades, disable.
              </p>
            </template>
          </ChecklistItem>
          <ChecklistItem
            label="Block access to readme.html and license.txt"
            description="Returns 404 for both files — they leak the exact WP version."
            :status="statusBlockReadmeLicense"
            :status-label="
              statusBlockReadmeLicense === 'warning'
                ? 'Still accessible'
                : undefined
            "
          >
            <template #default="{ labelId }">
              <Toggle
                :model-value="store.config.version_hiding.block_readme_license"
                label="Block readme and license"
                hide-label
                :aria-labelledby="labelId"
                @update:model-value="onToggleBlockReadmeLicense"
              />
            </template>
            <template v-if="statusBlockReadmeLicense === 'warning'" #footer>
              <p
                class="fx-hardening__help fx-hardening__help--warn"
                role="note"
              >
                {{ readmeServerGuidance }}
              </p>
              <pre
                v-if="store.checks?.server_type === 'nginx'"
                class="fx-hardening__snippet"
              ><code>location ~* /(readme\.html|license\.txt)$ {
    deny all;
}</code></pre>
            </template>
          </ChecklistItem>
        </div>
      </section>

      <!-- 4. Uploads Directory -->
      <section
        class="fx-hardening__section"
        aria-labelledby="fx-hardening-uploads"
      >
        <header class="fx-hardening__section-header">
          <h3 id="fx-hardening-uploads" class="fx-hardening__section-title">
            Uploads Directory
          </h3>
          <p class="fx-hardening__section-hint">
            Prevent directory listing and block PHP execution in
            <code>/wp-content/uploads/</code>. Status reflects what is currently
            on disk — click Drop now (or Restore) to reconcile.
          </p>
        </header>
        <div class="fx-hardening__items">
          <UploadsGuardRow
            target="uploads_index"
            label="index.php (directory listing prevention)"
            description="A blank index.php in /wp-content/uploads/ prevents empty-directory browsing on misconfigured servers."
            :status="uploadsIndexStatus"
            :busy="store.loading.guarding.uploads_index"
            :global-busy="store.loading.fixing || store.loading.saving"
            @drop="onDropGuard"
            @restore="onDropGuard"
          />
          <UploadsGuardRow
            target="uploads_htaccess"
            label="PHP execution blocker (.htaccess)"
            description="Blocks direct execution of uploaded .php files on Apache / LiteSpeed. nginx and IIS need a matching server-block rule."
            :status="uploadsHtaccessStatus"
            :caption="uploadsHtaccessCaption"
            :busy="store.loading.guarding.uploads_htaccess"
            :global-busy="store.loading.fixing || store.loading.saving"
            :show-re-run-probe="uploadsHtaccessShowReRun"
            :re-run-busy="store.loading.checks"
            @drop="onDropGuard"
            @restore="onDropGuard"
            @rerun-probe="onReRunProbe"
          />
          <div
            v-if="
              store.checks?.server_type === 'nginx' ||
              store.checks?.server_type === 'iis'
            "
            class="fx-hardening__server-note"
            role="note"
          >
            <template v-if="store.checks?.server_type === 'nginx'">
              <p class="fx-hardening__server-note-message">
                nginx detected — a <code>.htaccess</code> file will not be
                evaluated. Add this to your server block instead:
              </p>
              <pre
                class="fx-hardening__snippet"
              ><code>location ~* /wp-content/uploads/.*\.php$ {
    deny all;
}</code></pre>
            </template>
            <p v-else class="fx-hardening__server-note-message">
              IIS detected — the <code>.htaccess</code> file has no effect. Add
              a matching rule to <code>web.config</code>.
            </p>
          </div>
        </div>
      </section>

      <!-- 5. Login & Sessions -->
      <section
        class="fx-hardening__section"
        aria-labelledby="fx-hardening-login"
      >
        <header class="fx-hardening__section-header">
          <h3 id="fx-hardening-login" class="fx-hardening__section-title">
            Login and Sessions
          </h3>
          <p class="fx-hardening__section-hint">
            Replace specific login error messages with a generic "Invalid
            username or password."
          </p>
        </header>
        <div class="fx-hardening__items">
          <ChecklistItem
            label="Obfuscate login errors"
            description="Hides whether the username or the password was wrong."
            :status="statusLoginErrors"
          >
            <template #default="{ labelId }">
              <Toggle
                v-model="store.config.login.obfuscate_errors"
                label="Obfuscate login errors"
                hide-label
                :aria-labelledby="labelId"
              />
            </template>
          </ChecklistItem>
        </div>
      </section>

      <!-- 6. File Editing -->
      <section
        class="fx-hardening__section"
        aria-labelledby="fx-hardening-file-editing"
      >
        <header class="fx-hardening__section-header">
          <h3
            id="fx-hardening-file-editing"
            class="fx-hardening__section-title"
          >
            File Editing
          </h3>
          <p class="fx-hardening__section-hint">
            Disable the dashboard
            <strong>Appearance → Theme File Editor</strong> and
            <strong>Plugins → Plugin File Editor</strong> so a compromised admin
            cannot edit PHP directly. Enabling this hides both editors.
          </p>
        </header>
        <div class="fx-hardening__items">
          <ChecklistItem
            label="Runtime-enforce file editing lockdown"
            description="Fallback for sites that cannot edit wp-config.php. Less robust than the DISALLOW_FILE_EDIT constant."
            :status="statusFileEditing"
            :status-label="
              statusFileEditing === 'warning'
                ? 'Runtime only'
                : statusFileEditing === 'active'
                  ? 'Constant applied'
                  : undefined
            "
          >
            <template #default="{ labelId }">
              <Toggle
                v-model="store.config.file_editing.runtime_enforce"
                label="Runtime enforce"
                hide-label
                :aria-labelledby="labelId"
              />
            </template>
            <template v-if="showWpConfigSnippet" #footer>
              <div class="fx-hardening__snippet-block">
                <p class="fx-hardening__snippet-message">
                  Preferred fix — add this line to
                  <code>wp-config.php</code> near the other
                  <code>define()</code> calls:
                </p>
                <div class="fx-hardening__snippet-row">
                  <pre
                    class="fx-hardening__snippet"
                  ><code>{{ WP_CONFIG_SNIPPET }}</code></pre>
                  <button
                    type="button"
                    class="fx-hardening__copy-button"
                    :aria-label="
                      snippetCopied
                        ? 'Snippet copied'
                        : 'Copy wp-config snippet'
                    "
                    @click="() => void copySnippet()"
                  >
                    <Check
                      v-if="snippetCopied"
                      :size="14"
                      :stroke-width="1.75"
                      aria-hidden="true"
                      focusable="false"
                    />
                    <Copy
                      v-else
                      :size="14"
                      :stroke-width="1.75"
                      aria-hidden="true"
                      focusable="false"
                    />
                    <span>{{ snippetCopied ? 'Copied' : 'Copy' }}</span>
                  </button>
                </div>
              </div>
            </template>
          </ChecklistItem>
        </div>
      </section>

      <!-- 7. Application Passwords -->
      <section
        class="fx-hardening__section"
        aria-labelledby="fx-hardening-app-passwords"
      >
        <header class="fx-hardening__section-header">
          <h3
            id="fx-hardening-app-passwords"
            class="fx-hardening__section-title"
          >
            Application Passwords
          </h3>
          <p class="fx-hardening__section-hint">
            Disable the WP 5.6+ Application Passwords feature if you do not use
            the REST API from external tools.
          </p>
        </header>
        <div v-if="showApplicationPasswordsRow" class="fx-hardening__items">
          <ChecklistItem
            label="Disable Application Passwords"
            description="Makes wp_is_application_passwords_available return false."
            :status="statusApplicationPasswords"
          >
            <template #default="{ labelId }">
              <Toggle
                v-model="store.config.application_passwords.disable"
                label="Disable Application Passwords"
                hide-label
                :aria-labelledby="labelId"
              />
            </template>
          </ChecklistItem>
        </div>
        <p v-else class="fx-hardening__ap-note" role="note">
          Application Passwords are currently in use by
          {{ applicationPasswordsCount }} credential{{
            applicationPasswordsCount === 1 ? '' : 's'
          }}. Revoke them before disabling this feature.
        </p>
      </section>

      <SaveBar
        :dirty="store.isDirty"
        :status="saveStatus"
        :disabled="store.loading.saving || store.loading.fixing"
        @save="onSave"
        @reset="onReset"
      />
    </div>

    <Teleport to="body">
      <div
        v-if="store.toast"
        class="fx-hardening__toast-region"
        aria-live="polite"
      >
        <Toast
          :key="toastKey"
          :message="store.toast.message"
          :variant="toastVariant"
          @dismiss="store.dismissToast()"
        />
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.fx-hardening {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-5);
}

.fx-hardening__header {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: var(--fx-space-4);
  align-items: start;
}

.fx-hardening__icon-wrap {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 3rem;
  height: 3rem;
  border-radius: var(--fx-radius-lg);
  background: var(--fx-color-primary-soft);
  color: var(--fx-color-primary-strong);
}

.fx-hardening__header-text {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
  min-width: 0;
}

.fx-hardening__title-row {
  display: flex;
  align-items: center;
  gap: var(--fx-space-3);
  flex-wrap: wrap;
}

.fx-hardening__title {
  margin: 0;
  font-family: var(--fx-font-heading);
  font-size: var(--fx-font-size-3xl);
  font-weight: var(--fx-font-weight-semibold);
  color: var(--fx-color-text);
  line-height: var(--fx-line-height-tight);
  letter-spacing: -0.015em;
}

.fx-hardening__description {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-lg);
  line-height: var(--fx-line-height-snug);
  max-width: 62ch;
}

.fx-hardening__header-actions {
  display: flex;
  align-items: center;
}

.fx-hardening__run-checks {
  display: inline-flex;
  align-items: center;
  gap: var(--fx-space-2);
  padding: var(--fx-space-2) var(--fx-space-3);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  background: var(--fx-color-surface);
  color: var(--fx-color-text);
  font-family: var(--fx-font-body);
  font-size: var(--fx-font-size-md);
  font-weight: var(--fx-font-weight-medium);
  cursor: pointer;
  transition:
    border-color var(--fx-transition-fast),
    background var(--fx-transition-fast);
}

.fx-hardening__run-checks:hover:not(:disabled) {
  border-color: var(--fx-color-border-strong);
  background: var(--fx-color-elevated);
}

.fx-hardening__run-checks:focus,
.fx-hardening__run-checks:focus-visible {
  outline: none;
  border-color: var(--fx-color-primary-strong);
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-hardening__run-checks:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.fx-hardening__run-checks-icon--spinning {
  animation: fx-hardening-spin 1s linear infinite;
}

@media (prefers-reduced-motion: reduce) {
  .fx-hardening__run-checks-icon--spinning {
    animation: none;
  }
}

@keyframes fx-hardening-spin {
  to {
    transform: rotate(360deg);
  }
}

.fx-hardening__loading {
  margin: 0;
  padding: var(--fx-space-5);
  color: var(--fx-color-text-muted);
  background: var(--fx-color-surface);
  border: 1px dashed var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
}

.fx-hardening__sections {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-5);
}

.fx-hardening__section {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-3);
  padding: var(--fx-space-5);
  background: var(--fx-color-surface);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
  box-shadow: var(--fx-shadow-sm);
}

.fx-hardening__section-header {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-1);
}

.fx-hardening__section-title {
  margin: 0;
  font-family: var(--fx-font-heading);
  font-size: var(--fx-font-size-xl);
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-text);
}

.fx-hardening__section-hint {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
  max-width: 62ch;
}

.fx-hardening__section-hint code {
  background: var(--fx-color-elevated);
  padding: 0 var(--fx-space-1);
  border-radius: var(--fx-radius-sm);
  font-family: var(--fx-font-mono);
  font-size: 0.9em;
}

.fx-hardening__items {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-3);
}

.fx-hardening__fix {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
}

.fx-hardening__fix-message {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
}

.fx-hardening__fix-message code {
  background: var(--fx-color-elevated);
  padding: 0 var(--fx-space-1);
  border-radius: var(--fx-radius-sm);
  font-family: var(--fx-font-mono);
  font-size: 0.9em;
}

.fx-hardening__fix-button {
  align-self: flex-start;
  padding: var(--fx-space-1) var(--fx-space-3);
  border: 1px solid var(--fx-color-warn);
  border-radius: var(--fx-radius-md);
  background: var(--fx-color-warn-bg);
  color: var(--fx-color-warn);
  font-family: var(--fx-font-body);
  font-size: var(--fx-font-size-sm);
  font-weight: var(--fx-font-weight-medium);
  cursor: pointer;
  transition:
    background var(--fx-transition-fast),
    border-color var(--fx-transition-fast);
}

.fx-hardening__fix-button:hover:not(:disabled) {
  background: var(--fx-color-warn);
  color: var(--fx-color-surface);
}

.fx-hardening__fix-button:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.fx-hardening__snippet-block {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
}

.fx-hardening__snippet-message {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
}

.fx-hardening__snippet-message code {
  background: var(--fx-color-elevated);
  padding: 0 var(--fx-space-1);
  border-radius: var(--fx-radius-sm);
  font-family: var(--fx-font-mono);
  font-size: 0.9em;
}

.fx-hardening__snippet-row {
  display: flex;
  align-items: center;
  gap: var(--fx-space-2);
  flex-wrap: wrap;
}

.fx-hardening__snippet {
  margin: 0;
  padding: var(--fx-space-2) var(--fx-space-3);
  background: var(--fx-color-elevated);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  font-family: var(--fx-font-mono);
  font-size: var(--fx-font-size-sm);
  overflow-x: auto;
  flex: 1 1 auto;
}

.fx-hardening__snippet code {
  font-family: inherit;
}

.fx-hardening__copy-button {
  display: inline-flex;
  align-items: center;
  gap: var(--fx-space-1);
  padding: var(--fx-space-1) var(--fx-space-2);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  background: var(--fx-color-surface);
  color: var(--fx-color-text);
  font-family: var(--fx-font-body);
  font-size: var(--fx-font-size-sm);
  cursor: pointer;
  transition:
    border-color var(--fx-transition-fast),
    background var(--fx-transition-fast);
}

.fx-hardening__copy-button:hover {
  border-color: var(--fx-color-border-strong);
  background: var(--fx-color-elevated);
}

.fx-hardening__copy-button:focus,
.fx-hardening__copy-button:focus-visible {
  outline: none;
  border-color: var(--fx-color-primary-strong);
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-hardening__ap-note {
  margin: 0;
  padding: var(--fx-space-3) var(--fx-space-4);
  background: var(--fx-color-info-bg);
  border: 1px solid var(--fx-color-info);
  border-radius: var(--fx-radius-md);
  color: var(--fx-color-info);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-snug);
}

.fx-hardening__help {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-snug);
  max-width: 62ch;
}

.fx-hardening__help code {
  background: var(--fx-color-elevated);
  padding: 0 var(--fx-space-1);
  border-radius: var(--fx-radius-sm);
  font-family: var(--fx-font-mono);
  font-size: 0.9em;
}

.fx-hardening__help--warn {
  color: var(--fx-color-warn);
}

.fx-hardening__server-note {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
  padding: var(--fx-space-3) var(--fx-space-4);
  background: var(--fx-color-info-bg);
  border: 1px solid var(--fx-color-info);
  border-radius: var(--fx-radius-md);
}

.fx-hardening__server-note-message {
  margin: 0;
  color: var(--fx-color-info);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-snug);
}

.fx-hardening__server-note-message code {
  background: var(--fx-color-elevated);
  padding: 0 var(--fx-space-1);
  border-radius: var(--fx-radius-sm);
  font-family: var(--fx-font-mono);
  font-size: 0.9em;
}

.fx-hardening__toast-region {
  position: fixed;
  right: var(--fx-space-5);
  bottom: var(--fx-space-5);
  z-index: 40;
}

@media (max-width: 720px) {
  .fx-hardening__header {
    grid-template-columns: auto 1fr;
  }

  .fx-hardening__header-actions {
    grid-column: 1 / -1;
  }
}
</style>
