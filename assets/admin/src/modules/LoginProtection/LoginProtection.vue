<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
import { KeyRound } from 'lucide-vue-next';
import { SaveBar, StatusPill, Toast } from '@/components';
import type { StatusPillVariant, ToastVariant, SaveStatus } from '@/components';
import { useLoginProtectionStore } from './stores/loginProtection';

/**
 * Login Protection module root.
 *
 * Single scrollable view (no sub-tabs). This task ships the SCAFFOLD only:
 * the header, a derived status pill, and placeholder shells for the five
 * sections whose real controls land in later tasks —
 *   1. Attempt Limiting   (lockout tiers, IP allowlist, proxy trust, retention)
 *   2. Lockout Log         (recent login events + active bans)
 *   3. Hide Login          (custom login slug; effective slug shown read-only)
 *   4. Passwords           (strong-password policy)
 *   5. Sessions            (role-based idle timeouts)
 * — plus a SaveBar wired to the shared store's dirty/save/reset flow.
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
        <p class="fx-login-protection__placeholder" role="note">
          Lockout tiers, IP allowlist, proxy-trust, and log-retention controls
          arrive in a later step.
        </p>
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
        <p class="fx-login-protection__placeholder" role="note">
          The filterable event log and ban manager arrive in a later step.
        </p>
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

.fx-login-protection__toast-region {
  position: fixed;
  right: var(--fx-space-5);
  bottom: var(--fx-space-5);
  z-index: 40;
}
</style>
