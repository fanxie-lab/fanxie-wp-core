<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
import { Activity, RefreshCw } from 'lucide-vue-next';
import { HelpText, SaveBar, StatusPill, Toast, Toggle } from '@/components';
import type { SaveStatus, ToastVariant } from '@/components';
import { useEnvironmentHealthStore } from './stores/environmentHealth';
import CheckGroupCard from './components/CheckGroupCard.vue';
import HealthSummary from './components/HealthSummary.vue';
import ThresholdField from './components/ThresholdField.vue';
import { abandonedBandsInverted, statusPill } from './format';

/**
 * Environment Health module root (PRD §7).
 *
 * Single scrollable view:
 *   1. Header — overall status pill + Refresh
 *   2. Counts summary + "last checked" stamp
 *   3. One card per CheckGroup (versions / cron / debug / plugins_themes)
 *   4. Scan settings (wp.org abandoned-plugin scan) + SaveBar
 *
 * Every `label` / `summary` / `detail` string is rendered exactly as PHP sent
 * it — those are already translated server-side. Only this file's own chrome
 * (headings, buttons, empty states) is authored here.
 */

const store = useEnvironmentHealthStore();

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

const headerPill = computed(() => {
  if (!store.report) {
    return {
      variant: 'neutral' as const,
      label: 'Loading…',
      srPrefix: 'Status:',
    };
  }
  return statusPill(store.overallStatus);
});

const saveStatus = computed<SaveStatus>(() => {
  if (store.loading.saving) return 'saving';
  if (store.error) return 'error';
  return 'idle';
});

const toastVariant = computed<ToastVariant>(() => {
  const variant = store.toast?.variant ?? 'info';
  if (variant === 'success') return 'success';
  if (variant === 'error') return 'error';
  return 'info';
});

const toastKey = computed<number>(() => store.toast?.stamp ?? 0);

/**
 * Politely announced refresh state. Screen readers otherwise get no signal
 * that a long-running server round-trip is under way, or that it finished.
 */
const refreshAnnouncement = computed<string>(() => {
  if (store.loading.refreshing) return 'Re-running environment checks…';
  if (store.report) return `Checks up to date. ${store.countsSentence}`;
  return '';
});

/** Report failed to load AND nothing cached is on screen — a dead end. */
const showLoadFailure = computed<boolean>(
  () => store.error !== null && store.report === null && !store.loading.initial,
);

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

function onRefresh(): void {
  void store.refresh();
}

function onSave(): void {
  void store.save();
}

function onReset(): void {
  store.reset();
}

function onCopyFailed(message: string): void {
  store.pushToast(message, 'error');
}

onMounted(() => {
  void store.load();
});
</script>

<template>
  <div class="fx-eh">
    <header class="fx-eh__header">
      <span class="fx-eh__icon-wrap" aria-hidden="true">
        <Activity
          :size="28"
          :stroke-width="1.75"
          aria-hidden="true"
          focusable="false"
        />
      </span>
      <div class="fx-eh__header-text">
        <div class="fx-eh__title-row">
          <h2 class="fx-eh__title">Environment Health</h2>
          <StatusPill
            :variant="headerPill.variant"
            :label="headerPill.label"
            :sr-prefix="headerPill.srPrefix"
          />
        </div>
        <p class="fx-eh__description">
          Checks the software versions, cron scheduling, debug flags, and
          plugin/theme hygiene this site is running on. Nothing here changes
          your site — every finding comes with a recommended action you apply
          yourself.
        </p>
      </div>
      <div class="fx-eh__header-actions">
        <button
          type="button"
          class="fx-eh__refresh"
          :disabled="store.loading.refreshing || store.loading.initial"
          @click="onRefresh"
        >
          <RefreshCw
            class="fx-eh__refresh-icon"
            :class="{
              'fx-eh__refresh-icon--spinning': store.loading.refreshing,
            }"
            :size="16"
            :stroke-width="1.75"
            aria-hidden="true"
            focusable="false"
          />
          <span>{{
            store.loading.refreshing ? 'Re-running…' : 'Re-run checks'
          }}</span>
        </button>
      </div>
    </header>

    <!-- Busy + completion state, announced without stealing focus. -->
    <span class="fx-visually-hidden" role="status" aria-live="polite">
      {{ refreshAnnouncement }}
    </span>

    <p
      v-if="store.loading.initial && !store.report"
      class="fx-eh__panel"
      role="status"
    >
      Loading environment report…
    </p>

    <div v-else-if="showLoadFailure" class="fx-eh__panel fx-eh__panel--error">
      <p class="fx-eh__panel-title">
        The environment report could not be loaded.
      </p>
      <p class="fx-eh__panel-body">{{ store.error }}</p>
      <button type="button" class="fx-eh__refresh" @click="onRefresh">
        <RefreshCw
          :size="16"
          :stroke-width="1.75"
          aria-hidden="true"
          focusable="false"
        />
        <span>Try again</span>
      </button>
    </div>

    <template v-else-if="store.report">
      <HealthSummary
        :counts="store.counts"
        :generated-at="store.report.generated_at"
      />

      <p v-if="store.isEmptyReport" class="fx-eh__panel">
        No checks ran. This usually means every check was disabled — re-run the
        checks, or enable the wp.org scan below.
      </p>

      <div v-else class="fx-eh__groups" :aria-busy="store.loading.refreshing">
        <CheckGroupCard
          v-for="bucket in store.checksByGroup"
          :key="bucket.group"
          :group="bucket.group"
          :checks="bucket.checks"
          @copy-failed="onCopyFailed"
        />
      </div>
    </template>

    <section
      v-if="store.config"
      class="fx-eh__section"
      aria-labelledby="fx-eh-checks-heading"
    >
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

    <section
      v-if="store.config"
      class="fx-eh__section"
      aria-labelledby="fx-eh-settings"
    >
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

    <section
      v-if="store.config"
      class="fx-eh__section"
      aria-labelledby="fx-eh-thresholds"
    >
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

    <Teleport to="body">
      <div v-if="store.toast" class="fx-eh__toast-region" aria-live="polite">
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
.fx-eh {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-5);
}

.fx-eh__header {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: var(--fx-space-4);
  align-items: start;
}

.fx-eh__icon-wrap {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 3rem;
  height: 3rem;
  border-radius: var(--fx-radius-lg);
  background: var(--fx-color-primary-soft);
  color: var(--fx-color-primary-strong);
}

.fx-eh__header-text {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
  min-width: 0;
}

.fx-eh__title-row {
  display: flex;
  align-items: center;
  gap: var(--fx-space-3);
  flex-wrap: wrap;
}

.fx-eh__title {
  margin: 0;
  font-family: var(--fx-font-heading);
  font-size: var(--fx-font-size-3xl);
  font-weight: var(--fx-font-weight-semibold);
  color: var(--fx-color-text);
  line-height: var(--fx-line-height-tight);
  letter-spacing: -0.015em;
}

.fx-eh__description {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-lg);
  line-height: var(--fx-line-height-snug);
  max-width: 62ch;
}

.fx-eh__header-actions {
  display: flex;
  align-items: center;
}

.fx-eh__refresh {
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

.fx-eh__refresh:hover:not(:disabled) {
  border-color: var(--fx-color-border-strong);
  background: var(--fx-color-elevated);
}

.fx-eh__refresh:focus,
.fx-eh__refresh:focus-visible {
  outline: none;
  border-color: var(--fx-color-primary-strong);
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-eh__refresh:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.fx-eh__refresh-icon--spinning {
  animation: fx-eh-spin 1s linear infinite;
}

@media (prefers-reduced-motion: reduce) {
  .fx-eh__refresh-icon--spinning {
    animation: none;
  }
}

@keyframes fx-eh-spin {
  to {
    transform: rotate(360deg);
  }
}

.fx-eh__panel {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: var(--fx-space-2);
  margin: 0;
  padding: var(--fx-space-5);
  color: var(--fx-color-text-muted);
  background: var(--fx-color-surface);
  border: 1px dashed var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
}

.fx-eh__panel--error {
  border-style: solid;
  border-color: var(--fx-color-critical);
  background: var(--fx-color-critical-bg);
}

.fx-eh__panel-title {
  margin: 0;
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-critical);
}

.fx-eh__panel-body {
  margin: 0;
  font-size: var(--fx-font-size-sm);
}

.fx-eh__groups {
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

.fx-eh__field {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
}

.fx-eh__section-hint {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
  max-width: 62ch;
}

/* Two columns on wide viewports so four short numeric fields do not become a
   tall single-file column; collapses to one column below the card's comfort
   width. */
.fx-eh__fields-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr));
  gap: var(--fx-space-4);
}

.fx-eh__toast-region {
  position: fixed;
  right: var(--fx-space-5);
  bottom: var(--fx-space-5);
  z-index: 40;
}

@media (max-width: 720px) {
  .fx-eh__header {
    grid-template-columns: auto 1fr;
  }

  .fx-eh__header-actions {
    grid-column: 1 / -1;
  }
}
</style>
