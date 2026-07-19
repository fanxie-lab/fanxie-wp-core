<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
import { KeyRound, Plus, Trash2 } from 'lucide-vue-next';
import {
  HelpText,
  SaveBar,
  StatusPill,
  TextField,
  Toast,
  Toggle,
} from '@/components';
import type { StatusPillVariant, ToastVariant, SaveStatus } from '@/components';
import { useLoginProtectionStore } from './stores/loginProtection';
import LockoutLog from './components/LockoutLog.vue';

/**
 * Login Protection module root.
 *
 * Single scrollable view (no sub-tabs). Five sections plus a SaveBar wired to
 * the shared store's dirty/save/reset flow:
 *   1. Attempt Limiting   (lockout tiers, IP allowlist, proxy trust, retention) — live
 *   2. Lockout Log         (recent login events + active bans, via <LockoutLog>) — live
 *   3. Hide Login          (custom login slug; effective slug shown read-only) — later task
 *   4. Passwords           (strong-password policy) — later task
 *   5. Sessions            (role-based idle timeouts) — later task
 */

const store = useLoginProtectionStore();

const saveStatus = computed<SaveStatus>(() => {
  if (store.loading.saving) return 'saving';
  if (store.error) return 'error';
  return 'idle';
});

/**
 * Header pill summarises how many of the four feature groups are switched on.
 * A lightweight, purely client-derived signal — the module config envelope
 * carries no server-side status object (unlike Hardening).
 */
const activeGroupCount = computed<number>(() => {
  const config = store.config;
  if (!config) return 0;
  return [
    config.attempts.enabled,
    config.hide_login.enabled,
    config.passwords.enforce,
    config.sessions.enabled,
  ].filter(Boolean).length;
});

const headerStatusPill = computed<{
  variant: StatusPillVariant;
  label: string;
}>(() => {
  if (!store.config) {
    return { variant: 'neutral', label: 'Loading…' };
  }
  const count = activeGroupCount.value;
  return {
    variant: count > 0 ? 'ok' : 'neutral',
    label: `${String(count)} of 4 protections active`,
  };
});

/**
 * Human-readable read-out of the effective login address. Available now even
 * though the Hide Login controls are not — it comes straight from the store's
 * config envelope, and surfaces the wp-config constant override when present.
 */
const effectiveLoginSummary = computed<string>(() => {
  if (store.slugSource === 'constant') {
    return `Locked to /${store.effectiveSlug} by a wp-config constant.`;
  }
  if (store.effectiveSlug !== '') {
    return `Current login address: /${store.effectiveSlug}`;
  }
  return 'Using the default /wp-login.php address.';
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

function onSave(): void {
  void store.save();
}

function onReset(): void {
  store.reset();
}

// --- Attempt-limiting field helpers ----------------------------------------

// Static ids wiring HelpText blocks to the controls they describe (single
// instance per screen, so plain string ids are safe).
const attemptsEnabledHelpId = 'fx-lp-attempts-enabled-help';
const proxyHelpId = 'fx-lp-attempts-proxy-help';

/**
 * Trusted-IP allowlist presented as newline-joined text ⇄ string[]. The split
 * is intentionally lossless (no trim/filter) so typing a new line isn't undone
 * mid-edit; the store/PHP layer sanitises empty lines and whitespace on save.
 */
const allowlistText = computed<string>({
  get() {
    return store.config?.attempts.allowlist.join('\n') ?? '';
  },
  set(value: string) {
    if (!store.config) return;
    store.config.attempts.allowlist = value.split('\n');
  },
});

function addTier(): void {
  if (!store.config) return;
  store.config.attempts.tiers.push({ threshold: 5, lockout_minutes: 15 });
}

/** Remove a tier, always keeping at least one so the policy is never empty. */
function removeTier(index: number): void {
  if (!store.config) return;
  const tiers = store.config.attempts.tiers;
  if (tiers.length <= 1) return;
  tiers.splice(index, 1);
}

onMounted(() => {
  void store.load();
});
</script>

<template>
  <div class="fx-login-protection">
    <header class="fx-login-protection__header">
      <span class="fx-login-protection__icon-wrap" aria-hidden="true">
        <KeyRound
          :size="28"
          :stroke-width="1.75"
          aria-hidden="true"
          focusable="false"
        />
      </span>
      <div class="fx-login-protection__header-text">
        <div class="fx-login-protection__title-row">
          <h2 class="fx-login-protection__title">Login Protection</h2>
          <StatusPill
            :variant="headerStatusPill.variant"
            :label="headerStatusPill.label"
          />
        </div>
        <p class="fx-login-protection__description">
          Slow down brute-force attacks with staged lockouts, hide the login
          screen behind a secret slug, enforce a strong password policy, and
          expire idle sessions by role.
        </p>
      </div>
    </header>

    <p
      v-if="store.loading.initial && !store.config"
      class="fx-login-protection__loading"
      role="status"
    >
      Loading login protection settings…
    </p>

    <div v-else-if="store.config" class="fx-login-protection__sections">
      <!-- 1. Attempt Limiting -->
      <section
        class="fx-login-protection__section"
        aria-labelledby="fx-login-protection-attempts"
      >
        <header class="fx-login-protection__section-header">
          <h3
            id="fx-login-protection-attempts"
            class="fx-login-protection__section-title"
          >
            Attempt Limiting
          </h3>
          <p class="fx-login-protection__section-hint">
            Lock out an IP or username after repeated failures, with escalating
            durations. Trusted IPs can be allowlisted and reverse-proxy headers
            honoured for the real client address.
          </p>
        </header>
        <div class="fx-login-protection__fields">
          <!-- Master enable -->
          <div class="fx-login-protection__field">
            <Toggle
              v-model="store.config.attempts.enabled"
              label="Enable attempt limiting"
              :describedby="attemptsEnabledHelpId"
            />
            <HelpText :id="attemptsEnabledHelpId">
              When on, repeated failed logins from the same IP or username
              trigger an escalating lockout — slowing brute-force and
              credential-stuffing attacks without affecting legitimate users.
            </HelpText>
          </div>

          <!-- Lockout tiers -->
          <div
            class="fx-login-protection__field"
            role="group"
            aria-labelledby="fx-lp-tiers-label"
          >
            <p id="fx-lp-tiers-label" class="fx-login-protection__field-label">
              Lockout tiers
            </p>
            <p class="fx-login-protection__field-hint">
              After a number of failed attempts, lock the subject out for the
              given duration. Add tiers to escalate repeat offenders.
            </p>
            <div class="fx-login-protection__tiers">
              <div class="fx-login-protection__tier-head" aria-hidden="true">
                <span>After failures</span>
                <span>Lock for (minutes)</span>
                <span></span>
              </div>
              <div
                v-for="(tier, index) in store.config.attempts.tiers"
                :key="index"
                class="fx-login-protection__tier-row"
              >
                <input
                  v-model.number="tier.threshold"
                  type="number"
                  min="1"
                  step="1"
                  inputmode="numeric"
                  class="fx-login-protection__num-input"
                  :aria-label="`Tier ${String(index + 1)} failure threshold`"
                />
                <input
                  v-model.number="tier.lockout_minutes"
                  type="number"
                  min="1"
                  step="1"
                  inputmode="numeric"
                  class="fx-login-protection__num-input"
                  :aria-label="`Tier ${String(index + 1)} lockout minutes`"
                />
                <button
                  type="button"
                  class="fx-login-protection__icon-button"
                  :disabled="store.config.attempts.tiers.length <= 1"
                  :aria-label="`Remove tier ${String(index + 1)}`"
                  @click="removeTier(index)"
                >
                  <Trash2 :size="15" aria-hidden="true" focusable="false" />
                </button>
              </div>
            </div>
            <button
              type="button"
              class="fx-login-protection__add-button"
              @click="addTier"
            >
              <Plus :size="15" aria-hidden="true" focusable="false" />
              Add tier
            </button>
          </div>

          <!-- Trusted-IP allowlist -->
          <div class="fx-login-protection__field">
            <label
              for="fx-lp-allowlist"
              class="fx-login-protection__field-label"
            >
              Trusted IP allowlist
            </label>
            <p
              id="fx-lp-allowlist-hint"
              class="fx-login-protection__field-hint"
            >
              One IP address per line. Listed addresses are never locked out —
              use for office or VPN egress IPs you fully control.
            </p>
            <textarea
              id="fx-lp-allowlist"
              v-model="allowlistText"
              class="fx-login-protection__textarea"
              rows="4"
              spellcheck="false"
              autocomplete="off"
              aria-describedby="fx-lp-allowlist-hint"
            ></textarea>
          </div>

          <!-- Trusted proxy -->
          <div class="fx-login-protection__field">
            <Toggle
              v-model="store.config.attempts.trust_proxy"
              label="Trust reverse-proxy header for client IP"
              :describedby="proxyHelpId"
            />
            <HelpText :id="proxyHelpId" tone="warn">
              Only enable behind a reverse proxy or CDN you control. If turned
              on without one, an attacker can spoof this header to forge their
              IP and slip past every lockout.
            </HelpText>
            <div class="fx-login-protection__proxy-header">
              <TextField
                v-model="store.config.attempts.proxy_header"
                label="Proxy header name"
                :disabled="!store.config.attempts.trust_proxy"
                placeholder="HTTP_X_FORWARDED_FOR"
                autocomplete="off"
              />
            </div>
          </div>

          <!-- Log retention -->
          <div class="fx-login-protection__field">
            <label
              for="fx-lp-retention"
              class="fx-login-protection__field-label"
            >
              Log retention (days)
            </label>
            <p
              id="fx-lp-retention-hint"
              class="fx-login-protection__field-hint"
            >
              Login events older than this are pruned automatically.
            </p>
            <input
              id="fx-lp-retention"
              v-model.number="store.config.attempts.log_retention_days"
              type="number"
              min="1"
              step="1"
              inputmode="numeric"
              class="fx-login-protection__num-input fx-login-protection__num-input--wide"
              aria-describedby="fx-lp-retention-hint"
            />
          </div>
        </div>
      </section>

      <!-- 2. Lockout Log -->
      <section
        class="fx-login-protection__section"
        aria-labelledby="fx-login-protection-log"
      >
        <header class="fx-login-protection__section-header">
          <h3
            id="fx-login-protection-log"
            class="fx-login-protection__section-title"
          >
            Lockout Log
          </h3>
          <p class="fx-login-protection__section-hint">
            Review recent login events, clear an active lockout, and manage the
            list of banned IPs and usernames.
          </p>
        </header>
        <LockoutLog />
      </section>

      <!-- 3. Hide Login -->
      <section
        class="fx-login-protection__section"
        aria-labelledby="fx-login-protection-hide-login"
      >
        <header class="fx-login-protection__section-header">
          <h3
            id="fx-login-protection-hide-login"
            class="fx-login-protection__section-title"
          >
            Hide Login
          </h3>
          <p class="fx-login-protection__section-hint">
            Move <code>wp-login.php</code> to a secret slug so automated bots
            can't find the sign-in form.
          </p>
        </header>
        <p class="fx-login-protection__slug-readout" role="note">
          {{ effectiveLoginSummary }}
        </p>
        <p class="fx-login-protection__placeholder" role="note">
          The custom-slug editor arrives in a later step.
        </p>
      </section>

      <!-- 4. Passwords -->
      <section
        class="fx-login-protection__section"
        aria-labelledby="fx-login-protection-passwords"
      >
        <header class="fx-login-protection__section-header">
          <h3
            id="fx-login-protection-passwords"
            class="fx-login-protection__section-title"
          >
            Passwords
          </h3>
          <p class="fx-login-protection__section-hint">
            Require a minimum length plus a mix of cases, numbers, and symbols
            when users set or reset a password.
          </p>
        </header>
        <p class="fx-login-protection__placeholder" role="note">
          The strong-password policy controls arrive in a later step.
        </p>
      </section>

      <!-- 5. Sessions -->
      <section
        class="fx-login-protection__section"
        aria-labelledby="fx-login-protection-sessions"
      >
        <header class="fx-login-protection__section-header">
          <h3
            id="fx-login-protection-sessions"
            class="fx-login-protection__section-title"
          >
            Sessions
          </h3>
          <p class="fx-login-protection__section-hint">
            Automatically sign out idle users, with per-role timeouts so
            administrators expire sooner than everyone else.
          </p>
        </header>
        <p class="fx-login-protection__placeholder" role="note">
          The per-role session-timeout controls arrive in a later step.
        </p>
      </section>

      <SaveBar
        :dirty="store.isDirty"
        :status="saveStatus"
        :disabled="store.loading.saving"
        @save="onSave"
        @reset="onReset"
      />
    </div>

    <Teleport to="body">
      <div
        v-if="store.toast"
        class="fx-login-protection__toast-region"
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
.fx-login-protection {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-5);
}

.fx-login-protection__header {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: var(--fx-space-4);
  align-items: start;
}

.fx-login-protection__icon-wrap {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 3rem;
  height: 3rem;
  border-radius: var(--fx-radius-lg);
  background: var(--fx-color-primary-soft);
  color: var(--fx-color-primary-strong);
}

.fx-login-protection__header-text {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
  min-width: 0;
}

.fx-login-protection__title-row {
  display: flex;
  align-items: center;
  gap: var(--fx-space-3);
  flex-wrap: wrap;
}

.fx-login-protection__title {
  margin: 0;
  font-family: var(--fx-font-heading);
  font-size: var(--fx-font-size-3xl);
  font-weight: var(--fx-font-weight-semibold);
  color: var(--fx-color-text);
  line-height: var(--fx-line-height-tight);
  letter-spacing: -0.015em;
}

.fx-login-protection__description {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-lg);
  line-height: var(--fx-line-height-snug);
  max-width: 62ch;
}

.fx-login-protection__loading {
  margin: 0;
  padding: var(--fx-space-5);
  color: var(--fx-color-text-muted);
  background: var(--fx-color-surface);
  border: 1px dashed var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
}

.fx-login-protection__sections {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-5);
}

.fx-login-protection__section {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-3);
  padding: var(--fx-space-5);
  background: var(--fx-color-surface);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
  box-shadow: var(--fx-shadow-sm);
}

.fx-login-protection__section-header {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-1);
}

.fx-login-protection__section-title {
  margin: 0;
  font-family: var(--fx-font-heading);
  font-size: var(--fx-font-size-xl);
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-text);
}

.fx-login-protection__section-hint {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
  max-width: 62ch;
}

.fx-login-protection__section-hint code {
  background: var(--fx-color-elevated);
  padding: 0 var(--fx-space-1);
  border-radius: var(--fx-radius-sm);
  font-family: var(--fx-font-mono);
  font-size: 0.9em;
}

.fx-login-protection__slug-readout {
  margin: 0;
  padding: var(--fx-space-3) var(--fx-space-4);
  background: var(--fx-color-info-bg);
  border: 1px solid var(--fx-color-info);
  border-radius: var(--fx-radius-md);
  color: var(--fx-color-info);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-snug);
}

.fx-login-protection__placeholder {
  margin: 0;
  padding: var(--fx-space-3) var(--fx-space-4);
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-snug);
  background: var(--fx-color-elevated);
  border: 1px dashed var(--fx-color-border);
  border-radius: var(--fx-radius-md);
}

.fx-login-protection__fields {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-4);
}

.fx-login-protection__field {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
}

.fx-login-protection__field-label {
  margin: 0;
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-text);
}

.fx-login-protection__field-hint {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-snug);
  max-width: 62ch;
}

.fx-login-protection__tiers {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
  max-width: 30rem;
}

.fx-login-protection__tier-head,
.fx-login-protection__tier-row {
  display: grid;
  grid-template-columns: 1fr 1fr auto;
  gap: var(--fx-space-2);
  align-items: center;
}

.fx-login-protection__tier-head {
  font-size: var(--fx-font-size-xs);
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.fx-login-protection__num-input {
  width: 100%;
  padding: var(--fx-space-2) var(--fx-space-3);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  background: var(--fx-color-surface);
  color: var(--fx-color-text);
  font-family: var(--fx-font-body);
  font-size: var(--fx-font-size-md);
  font-variant-numeric: tabular-nums;
  transition:
    border-color var(--fx-transition-fast),
    box-shadow var(--fx-transition-fast);
}

.fx-login-protection__num-input--wide {
  max-width: 10rem;
}

.fx-login-protection__num-input:hover {
  border-color: var(--fx-color-border-strong);
}

.fx-login-protection__num-input:focus,
.fx-login-protection__num-input:focus-visible {
  outline: none;
  border-color: var(--fx-color-primary-strong);
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-login-protection__icon-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.25rem;
  height: 2.25rem;
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  background: transparent;
  color: var(--fx-color-text-muted);
  cursor: pointer;
  transition:
    border-color var(--fx-transition-fast),
    color var(--fx-transition-fast),
    background var(--fx-transition-fast);
}

.fx-login-protection__icon-button:hover:not(:disabled) {
  border-color: var(--fx-color-critical);
  color: var(--fx-color-critical);
  background: var(--fx-color-critical-bg);
}

.fx-login-protection__icon-button:focus-visible {
  outline: none;
  border-color: var(--fx-color-primary-strong);
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-login-protection__icon-button:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.fx-login-protection__add-button {
  align-self: flex-start;
  display: inline-flex;
  align-items: center;
  gap: var(--fx-space-1);
  padding: var(--fx-space-2) var(--fx-space-3);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  background: transparent;
  color: var(--fx-color-text);
  font-family: var(--fx-font-body);
  font-size: var(--fx-font-size-sm);
  font-weight: var(--fx-font-weight-medium);
  cursor: pointer;
  transition:
    border-color var(--fx-transition-fast),
    background var(--fx-transition-fast);
}

.fx-login-protection__add-button:hover {
  border-color: var(--fx-color-border-strong);
  background: var(--fx-color-elevated);
}

.fx-login-protection__add-button:focus-visible {
  outline: none;
  border-color: var(--fx-color-primary-strong);
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-login-protection__textarea {
  width: 100%;
  max-width: 30rem;
  padding: var(--fx-space-2) var(--fx-space-3);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  background: var(--fx-color-surface);
  color: var(--fx-color-text);
  font-family: var(--fx-font-mono);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-normal);
  resize: vertical;
  transition:
    border-color var(--fx-transition-fast),
    box-shadow var(--fx-transition-fast);
}

.fx-login-protection__textarea:hover {
  border-color: var(--fx-color-border-strong);
}

.fx-login-protection__textarea:focus,
.fx-login-protection__textarea:focus-visible {
  outline: none;
  border-color: var(--fx-color-primary-strong);
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-login-protection__proxy-header {
  max-width: 24rem;
}

.fx-login-protection__toast-region {
  position: fixed;
  right: var(--fx-space-5);
  bottom: var(--fx-space-5);
  z-index: 40;
}
</style>
