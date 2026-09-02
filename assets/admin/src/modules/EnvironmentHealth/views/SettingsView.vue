<script setup lang="ts">
import { computed, ref } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';
import { ConfirmDialog, HelpText, SaveBar, Toggle } from '@/components';
import type { SaveStatus } from '@/components';
import { useEnvironmentHealthStore } from '../stores/environmentHealth';
import ThresholdField from '../components/ThresholdField.vue';
import { abandonedBandsInverted } from '../format';

/**
 * SettingsView — the "Settings" sub-tab: every value the PHP module persists,
 * plus the SaveBar.
 *
 * Unsaved-changes guard
 * ---------------------
 * When everything lived on one screen the SaveBar was always visible, so dirty
 * state was impossible to miss. Behind a tab it is not: a user can edit a
 * threshold, switch to Checks (or to another module in the sidebar) and lose
 * the edit silently.
 *
 * `onBeforeRouteLeave` covers exactly that, because both the sibling tab and
 * every other module are vue-router navigations away from this component. The
 * guard is asynchronous: it stashes the pending navigation, opens the shared
 * ConfirmDialog, and resolves only once the user answers — discard leaves and
 * drops the edits, cancel stays put with the form untouched.
 *
 * It is a no-op on a pristine form, and it deliberately does NOT cover a full
 * browser unload (closing the tab, reloading). A `beforeunload` handler there
 * would only produce the browser's own generic, unstyled prompt, and WordPress
 * admin screens do not normally fight the user's back button; the in-app case
 * is where the edit actually goes missing without warning.
 */

const store = useEnvironmentHealthStore();

const saveStatus = computed<SaveStatus>(() => {
  if (store.loading.saving) return 'saving';
  if (store.error) return 'error';
  return 'idle';
});

// Static ids wiring each HelpText to the switch it describes. Passed through
// Toggle's `describedby` prop, never as a raw aria-describedby — that would
// land on the wrapper <div> instead of the <button role="switch">
// (CLAUDE.md §3.4). Single instance per screen, so plain strings are safe.
const HELP_IDS = {
  checksVersions: 'fx-eh-checks-versions-help',
  checksCron: 'fx-eh-checks-cron-help',
  checksDebug: 'fx-eh-checks-debug-help',
  checksPluginsThemes: 'fx-eh-checks-plugins-themes-help',
  wporgScan: 'fx-eh-wporg-scan-help',
  sslCheck: 'fx-eh-ssl-check-help',
  dashboardWidget: 'fx-eh-dashboard-widget-help',
} as const;

/**
 * The abandoned-plugin bands are the one rule the server cannot catch: each
 * number passes `absint` on its own, but a critical cut-off below the warning
 * cut-off means nothing is ever flagged critical. Block the save rather than
 * let a silently dead setting be written.
 */
const bandsInverted = computed<boolean>(() => {
  const t = store.config?.thresholds;
  if (!t) return false;
  return abandonedBandsInverted(
    t.abandoned_warning_days,
    t.abandoned_critical_days,
  );
});

function onSave(): void {
  void store.save();
}

function onReset(): void {
  store.reset();
}

// --- Unsaved-changes guard --------------------------------------------------

const leaveDialogOpen = ref(false);
/** Resolver for the in-flight navigation, held while the dialog is open. */
let resolveLeave: ((allow: boolean) => void) | null = null;

onBeforeRouteLeave(async () => {
  if (!store.isDirty) return true;

  leaveDialogOpen.value = true;
  const allowed = await new Promise<boolean>((resolve) => {
    resolveLeave = resolve;
  });
  resolveLeave = null;

  // Discarding must actually discard: without the reset the edits would still
  // be sitting in the store, and coming back to this tab would show a dirty
  // form the user believes they threw away.
  if (allowed) store.reset();
  return allowed;
});

function onDiscard(): void {
  leaveDialogOpen.value = false;
  resolveLeave?.(true);
}

function onKeepEditing(): void {
  leaveDialogOpen.value = false;
  resolveLeave?.(false);
}
</script>

<template>
  <div v-if="store.config" class="fx-eh-settings">
    <section class="fx-eh__section" aria-labelledby="fx-eh-checks-heading">
      <header class="fx-eh__section-header">
        <h3 id="fx-eh-checks-heading" class="fx-eh__section-title">
          Which checks run
        </h3>
        <p class="fx-eh__section-hint">
          Turning a group off removes its card from the report entirely — it
          does not mark the checks as passing.
        </p>
      </header>

      <div class="fx-eh__field">
        <Toggle
          v-model="store.config.checks.versions"
          label="Check software versions"
          :describedby="HELP_IDS.checksVersions"
        />
        <HelpText :id="HELP_IDS.checksVersions">
          WordPress, PHP, the database server, the TLS certificate, and whether
          the site is served over HTTPS.
        </HelpText>
      </div>

      <div class="fx-eh__field">
        <Toggle
          v-model="store.config.checks.cron"
          label="Check scheduled tasks"
          :describedby="HELP_IDS.checksCron"
        />
        <HelpText :id="HELP_IDS.checksCron">
          Detects a wedged cron lock and events that are running late.
        </HelpText>
      </div>

      <div class="fx-eh__field">
        <Toggle
          v-model="store.config.checks.debug"
          label="Check debug settings"
          :describedby="HELP_IDS.checksDebug"
        />
        <HelpText :id="HELP_IDS.checksDebug">
          Finds debug switches left on in production, including a debug log
          written inside the web root.
        </HelpText>
      </div>

      <div class="fx-eh__field">
        <Toggle
          v-model="store.config.checks.plugins_themes"
          label="Check plugins and themes"
          :describedby="HELP_IDS.checksPluginsThemes"
        />
        <HelpText :id="HELP_IDS.checksPluginsThemes">
          Inactive plugins, unused themes, and — when the wordpress.org check
          below is on — plugins that look abandoned.
        </HelpText>
      </div>
    </section>

    <section class="fx-eh__section" aria-labelledby="fx-eh-settings">
      <header class="fx-eh__section-header">
        <h3 id="fx-eh-settings" class="fx-eh__section-title">
          Outbound requests and display
        </h3>
        <p class="fx-eh__section-hint">
          The two checks below are the only ones that talk to anything outside
          this server.
        </p>
      </header>

      <div class="fx-eh__field">
        <Toggle
          v-model="store.config.wporg_scan_enabled"
          label="Check plugin freshness on wordpress.org"
          :describedby="HELP_IDS.wporgScan"
        />
        <HelpText :id="HELP_IDS.wporgScan">
          Sends the slug of each active plugin to
          <code>api.wordpress.org</code> to read its last-updated date, a few at
          a time on a daily schedule, cached for 24 hours. Nothing about you or
          your visitors is sent. Turn this off to stop the requests and discard
          everything already cached — abandoned-plugin rows will then report
          that they could not be checked. Plugins not listed on wordpress.org
          (premium or custom) are never scanned.
        </HelpText>
      </div>

      <div class="fx-eh__field">
        <Toggle
          v-model="store.config.ssl_check_enabled"
          label="Check the TLS certificate"
          :describedby="HELP_IDS.sslCheck"
        />
        <HelpText :id="HELP_IDS.sslCheck">
          Opens one short-lived connection to this site to read its certificate
          expiry date, at most twice a day. Hosts that block outbound
          connections will report &ldquo;unknown&rdquo; rather than a false
          alarm, so leaving this on is safe even where it cannot succeed.
        </HelpText>
      </div>

      <div class="fx-eh__field">
        <Toggle
          v-model="store.config.dashboard_widget"
          label="Show the dashboard widget"
          :describedby="HELP_IDS.dashboardWidget"
        />
        <HelpText :id="HELP_IDS.dashboardWidget">
          Mirrors this report onto the main wp-admin dashboard as a summary
          widget. Turning it off changes nothing about which checks run.
        </HelpText>
      </div>
    </section>

    <section class="fx-eh__section" aria-labelledby="fx-eh-thresholds">
      <header class="fx-eh__section-header">
        <h3 id="fx-eh-thresholds" class="fx-eh__section-title">Thresholds</h3>
        <p class="fx-eh__section-hint">
          These decide where a check tips from OK into a warning or a critical
          finding. Raising a number makes the report quieter; lowering it makes
          the report louder. Neither changes anything about your site.
        </p>
      </header>

      <div class="fx-eh__fields-grid">
        <ThresholdField
          v-model="store.config.thresholds.ssl_expiry_warning_days"
          field-key="ssl_expiry_warning_days"
          label="Warn this many days before the certificate expires"
          help="Below this many days of validity left, the TLS row turns into a warning. Most certificates auto-renew about 30 days out, so a smaller number can hide a renewal that has quietly stopped working."
          unit="days"
        />
        <ThresholdField
          v-model="store.config.thresholds.cron_overdue_minutes"
          field-key="cron_overdue_minutes"
          label="Treat an event as overdue after this many minutes"
          help="A scheduled event still unrun this long past its due time is reported as overdue. Sites with little traffic run cron less often, so a low number here reports lateness that is normal for them."
          unit="minutes"
        />
        <ThresholdField
          v-model="store.config.thresholds.abandoned_warning_days"
          field-key="abandoned_warning_days"
          label="Warn when a plugin has not been updated in this many days"
          help="A plugin whose last wordpress.org release is older than this is flagged as possibly abandoned. Only applies while the wordpress.org check above is on."
          unit="days"
        />
        <ThresholdField
          v-model="store.config.thresholds.abandoned_critical_days"
          field-key="abandoned_critical_days"
          label="Flag as critical after this many days without an update"
          help="The harder cut-off for the same check. It must be larger than the warning figure above, or nothing ever reaches critical."
          unit="days"
        />
      </div>

      <HelpText v-if="bandsInverted" id="fx-eh-bands-error" tone="warn">
        The critical cut-off is lower than the warning cut-off, so no plugin
        would ever be flagged critical. Raise it above the warning figure to
        save.
      </HelpText>
      <SaveBar
        :dirty="store.isDirty"
        :status="saveStatus"
        :disabled="store.loading.saving || bandsInverted"
        @save="onSave"
        @reset="onReset"
      />
    </section>

    <ConfirmDialog
      :open="leaveDialogOpen"
      title="Discard unsaved settings?"
      message="You changed Environment Health settings but have not saved them. Leaving this tab will discard those changes."
      confirm-label="Discard changes"
      cancel-label="Keep editing"
      tone="danger"
      @confirm="onDiscard"
      @cancel="onKeepEditing"
    />
  </div>
</template>

<style scoped>
.fx-eh-settings {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-5);
}

.fx-eh__section {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-3);
  padding: var(--fx-space-5);
  background: var(--fx-color-surface);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
  box-shadow: var(--fx-shadow-sm);
}

.fx-eh__section-header {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-1);
}

.fx-eh__section-title {
  margin: 0;
  font-family: var(--fx-font-heading);
  font-size: var(--fx-font-size-xl);
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-text);
}

.fx-eh__section-hint {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
  max-width: 62ch;
}

.fx-eh__field {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
}

/* Two columns on wide viewports so four short numeric fields do not become a
   tall single-file column; collapses to one column below the card's comfort
   width. */
.fx-eh__fields-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr));
  gap: var(--fx-space-4);
}
</style>
