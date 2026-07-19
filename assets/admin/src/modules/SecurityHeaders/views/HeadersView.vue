<script setup lang="ts">
import { computed } from 'vue';
import {
  Toggle,
  TextField,
  Select,
  SaveBar,
  Tooltip,
  HelpText,
} from '@/components';
import type { SelectOption } from '@/components';
import { useSecurityHeadersStore } from '../stores/securityHeaders';
import { useSecurityHeadersConfig } from '../composables/useSecurityHeadersConfig';

/**
 * HeadersView — toggles + inputs for the six non-CSP response headers.
 *
 * Dirty-state is owned by the store (compares serialised config against the
 * pristine snapshot), so we just wire Toggle/TextField inputs to the live
 * store.config object and let the SaveBar observe `isDirty` + `isSaving`.
 */

const store = useSecurityHeadersStore();
const { config, isDirty, isSaving, save, reset } = useSecurityHeadersConfig();

const xfoOptions: SelectOption[] = [
  { value: 'SAMEORIGIN', label: 'SAMEORIGIN (allow framing by same origin)' },
  { value: 'DENY', label: 'DENY (disallow all framing)' },
];

const saveStatus = computed<'idle' | 'saving' | 'saved' | 'error'>(() => {
  if (store.loading.saving) return 'saving';
  if (store.error) return 'error';
  return 'idle';
});

/**
 * String<->number bridge for the HSTS max-age TextField. Writing to a
 * `type="number"` input still produces a string in Vue's v-model, so we
 * coerce here and skip values that aren't positive integers.
 */
const hstsMaxAge = computed<string>({
  get: () => (config.value ? String(config.value.headers.hsts.max_age) : '0'),
  set: (v: string) => {
    const current = config.value;
    if (!current) return;
    const n = Number.parseInt(v, 10);
    if (!Number.isNaN(n) && n >= 0) {
      current.headers.hsts.max_age = n;
    }
  },
});
</script>

<template>
  <div v-if="config" class="fx-headers-view">
    <section class="fx-headers-view__section" aria-labelledby="fx-headers-xfo">
      <header class="fx-headers-view__section-header">
        <div class="fx-headers-view__title-row">
          <h3 id="fx-headers-xfo" class="fx-headers-view__section-title">
            X-Frame-Options
          </h3>
          <Tooltip
            label="More information about X-Frame-Options"
            text="Stops other sites from embedding yours in a frame (clickjacking). SAMEORIGIN allows your own site to frame itself."
          />
        </div>
        <p class="fx-headers-view__section-hint">
          Legacy clickjacking protection. Modern browsers also honour the CSP
          <code>frame-ancestors</code> directive.
        </p>
      </header>
      <Toggle
        v-model="config.headers.xfo.enabled"
        label="Enable X-Frame-Options"
      />
      <Select
        v-model="config.headers.xfo.value"
        label="Value"
        :options="xfoOptions"
        :disabled="!config.headers.xfo.enabled"
      />
    </section>

    <section class="fx-headers-view__section" aria-labelledby="fx-headers-xcto">
      <header class="fx-headers-view__section-header">
        <div class="fx-headers-view__title-row">
          <h3 id="fx-headers-xcto" class="fx-headers-view__section-title">
            X-Content-Type-Options
          </h3>
          <Tooltip
            label="More information about X-Content-Type-Options"
            text="Sends nosniff so browsers don't guess a file's type — blocks tricks that run an upload as script."
          />
        </div>
        <p class="fx-headers-view__section-hint">
          Always <code>nosniff</code> — disables MIME-type sniffing.
        </p>
      </header>
      <Toggle v-model="config.headers.xcto.enabled" label="Send nosniff" />
    </section>

    <section
      class="fx-headers-view__section"
      aria-labelledby="fx-headers-referrer"
    >
      <header class="fx-headers-view__section-header">
        <div class="fx-headers-view__title-row">
          <h3 id="fx-headers-referrer" class="fx-headers-view__section-title">
            Referrer-Policy
          </h3>
          <Tooltip
            label="More information about Referrer-Policy"
            text="Limits how much of the current URL is sent when users click outbound links."
          />
        </div>
        <p class="fx-headers-view__section-hint">
          Controls how much of the referring URL is sent with outgoing requests.
        </p>
      </header>
      <Toggle
        v-model="config.headers.referrer.enabled"
        label="Enable Referrer-Policy"
      />
      <TextField
        v-model="config.headers.referrer.value"
        label="Policy value"
        help="e.g. strict-origin-when-cross-origin (recommended)"
        :disabled="!config.headers.referrer.enabled"
      />
    </section>

    <section
      class="fx-headers-view__section"
      aria-labelledby="fx-headers-permissions"
    >
      <header class="fx-headers-view__section-header">
        <div class="fx-headers-view__title-row">
          <h3
            id="fx-headers-permissions"
            class="fx-headers-view__section-title"
          >
            Permissions-Policy
          </h3>
          <Tooltip
            label="More information about Permissions-Policy"
            text="Turns off browser features (camera, mic, geolocation…) your site doesn't use."
          />
        </div>
        <p class="fx-headers-view__section-hint">
          Restricts browser features (camera, microphone, geolocation, …).
        </p>
      </header>
      <Toggle
        v-model="config.headers.permissions.enabled"
        label="Enable Permissions-Policy"
      />
      <TextField
        v-model="config.headers.permissions.value"
        label="Policy value"
        help="e.g. camera=(), microphone=(), geolocation=()"
        :disabled="!config.headers.permissions.enabled"
      />
    </section>

    <section
      class="fx-headers-view__section"
      aria-labelledby="fx-headers-cache"
    >
      <header class="fx-headers-view__section-header">
        <h3 id="fx-headers-cache" class="fx-headers-view__section-title">
          Cache-Control (admin)
        </h3>
      </header>
      <HelpText id="fx-cache-help">
        Sends a strict <code>Cache-Control: no-store</code> on wp-admin and
        logged-in responses so proxies, CDNs, and browsers never cache private
        or per-user admin pages. Frontend/anonymous caching is untouched. Leave
        on unless a plugin manages admin caching itself — wrong values here can
        serve stale dashboards or leak one user's page to another.
      </HelpText>
      <Toggle
        v-model="config.headers.cache_control.enabled"
        label="Enable admin Cache-Control"
        :describedby="'fx-cache-help'"
      />
    </section>

    <details class="fx-headers-view__advanced">
      <summary class="fx-headers-view__advanced-summary">
        Advanced — HSTS (can break sites)
      </summary>
      <section
        class="fx-headers-view__section"
        aria-labelledby="fx-headers-hsts"
      >
        <header class="fx-headers-view__section-header">
          <h3 id="fx-headers-hsts" class="fx-headers-view__section-title">
            HTTP Strict Transport Security (HSTS)
          </h3>
          <p class="fx-headers-view__section-hint">
            Instructs browsers to use HTTPS only for this domain.
          </p>
        </header>
        <div class="fx-headers-view__callout fx-headers-view__callout--warning">
          <strong>Heads up — HSTS can break a site.</strong>
          Once a browser has cached this header, it will refuse plain HTTP for
          the entire <code>max-age</code> window (up to a year), even if HTTPS
          later breaks. Only enable on a domain that is fully and permanently on
          HTTPS with a valid certificate. The header is also only emitted over
          HTTPS — it will not appear on <code>http://localhost</code> or any
          plain-HTTP request.
        </div>
        <Toggle
          v-model="config.headers.hsts.enabled"
          label="Enable HSTS"
          description="Sends Strict-Transport-Security on every HTTPS response."
        />
        <TextField
          v-model="hstsMaxAge"
          type="number"
          label="max-age (seconds)"
          help="Recommended minimum: 31536000 (1 year)."
          :disabled="!config.headers.hsts.enabled"
        />
        <Toggle
          v-model="config.headers.hsts.include_subdomains"
          label="includeSubDomains"
          description="Apply HSTS to all sub-domains. Off by default — turning this on can lock out subdomains that are not yet on HTTPS."
          :disabled="!config.headers.hsts.enabled"
        />
      </section>
    </details>

    <SaveBar
      :dirty="isDirty"
      :status="saveStatus"
      :disabled="isSaving"
      @save="save"
      @reset="reset"
    />
  </div>
  <p v-else class="fx-headers-view__loading" role="status">
    Loading header configuration…
  </p>
</template>

<style scoped>
.fx-headers-view {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-5);
}

.fx-headers-view__section {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-3);
  padding: var(--fx-space-5);
  background: var(--fx-color-surface);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
  box-shadow: var(--fx-shadow-sm);
}

.fx-headers-view__section-header {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-1);
}

.fx-headers-view__title-row {
  display: flex;
  align-items: center;
  gap: var(--fx-space-2);
}

.fx-headers-view__section-title {
  margin: 0;
  font-family: var(--fx-font-heading);
  font-size: var(--fx-font-size-xl);
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-text);
}

.fx-headers-view__section-hint {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
  max-width: 62ch;
}

.fx-headers-view__section-hint code {
  background: var(--fx-color-elevated);
  padding: 0 var(--fx-space-1);
  border-radius: var(--fx-radius-sm);
  font-family: var(--fx-font-mono);
  font-size: 0.9em;
}

.fx-headers-view__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: var(--fx-space-4);
  align-items: end;
}

.fx-headers-view__callout {
  margin: 0;
  padding: var(--fx-space-3) var(--fx-space-4);
  border-radius: var(--fx-radius-md);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-snug);
}

.fx-headers-view__callout strong {
  display: block;
  margin-bottom: var(--fx-space-1);
  font-weight: var(--fx-font-weight-semibold);
}

.fx-headers-view__callout code {
  font-family: var(--fx-font-mono);
  font-size: 0.9em;
  padding: 0 var(--fx-space-1);
  border-radius: var(--fx-radius-sm);
  background: rgba(0, 0, 0, 0.06);
}

.fx-headers-view__callout--warning {
  background: var(--fx-color-warn-bg);
  border: 1px solid var(--fx-color-warn);
  color: var(--fx-color-warn);
}

.fx-headers-view__advanced {
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
  background: var(--fx-color-surface);
  box-shadow: var(--fx-shadow-sm);
  padding: var(--fx-space-4) var(--fx-space-5);
}

.fx-headers-view__advanced-summary {
  cursor: pointer;
  font-family: var(--fx-font-heading);
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-warn);
}

.fx-headers-view__advanced-summary:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
  border-radius: var(--fx-radius-sm);
}

.fx-headers-view__advanced[open] .fx-headers-view__advanced-summary {
  margin-bottom: var(--fx-space-3);
}

.fx-headers-view__loading {
  margin: 0;
  padding: var(--fx-space-5);
  color: var(--fx-color-text-muted);
  background: var(--fx-color-surface);
  border: 1px dashed var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
}

@media (max-width: 720px) {
  .fx-headers-view__row {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
