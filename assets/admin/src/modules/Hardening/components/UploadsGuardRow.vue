<script setup lang="ts">
import { computed, useId } from 'vue';
import { StatusPill } from '@/components';
import type { StatusPillVariant } from '@/components';
import type { UploadsGuardTarget } from '../types';

/**
 * UploadsGuardRow — status-row presentation for a single uploads-directory
 * guard (the index.php file or the .htaccess file).
 *
 * Replaces the toggle-driven ChecklistItem rows for this section. A toggle is
 * misleading here because the underlying file persists when the toggle flips
 * off — admins reported this as confusing. Instead we render the observed
 * filesystem state and offer explicit Drop-now / Restore buttons.
 *
 * The parent (Hardening.vue) owns the "does the row show inconclusive /
 * re-run probe guidance" decision — this component just surfaces the
 * primary status + action. A `canary` slot is reserved for the .htaccess
 * row's canary-probe caption, which has no counterpart on the index.php
 * row.
 */

type GuardStatus = 'protected' | 'missing';

interface Props {
  /** Target identifier passed through to the parent's action handlers. */
  target: UploadsGuardTarget;
  /** Row title shown to the admin. */
  label: string;
  /** One-line description of what this guard does. */
  description: string;
  /** Derived primary status from the probe result. */
  status: GuardStatus;
  /**
   * Optional secondary caption — the .htaccess row uses this to show the
   * canary probe result. Rendered in a muted sub-line next to the status pill.
   */
  caption?: string;
  /** True while this specific row's guard action is in flight. */
  busy: boolean;
  /** True when any global mutation is running (used to disable the button). */
  globalBusy: boolean;
  /**
   * Show a "Re-run probe" secondary button. Used when the canary probe is
   * inconclusive so the admin can retry the detection without losing the
   * primary Restore affordance.
   */
  showReRunProbe?: boolean;
  /** True while the check-rerun is in flight. */
  reRunBusy?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  caption: undefined,
  showReRunProbe: false,
  reRunBusy: false,
});

const emit = defineEmits<{
  drop: [target: UploadsGuardTarget];
  restore: [target: UploadsGuardTarget];
  'rerun-probe': [];
}>();

const generatedId = useId();
const labelId = computed(() => `fx-uploads-guard-${generatedId}`);

const isProtected = computed<boolean>(() => props.status === 'protected');

const pillVariant = computed<StatusPillVariant>(() =>
  isProtected.value ? 'ok' : 'warn',
);
const pillLabel = computed<string>(() =>
  isProtected.value ? 'Protected' : 'Not protected',
);

const primaryButtonLabel = computed<string>(() => {
  if (isProtected.value) {
    return props.busy ? 'Restoring…' : 'Restore';
  }
  return props.busy ? 'Dropping…' : 'Drop now';
});

const primaryButtonAria = computed<string>(() =>
  isProtected.value
    ? `Restore ${props.label}`
    : `Drop ${props.label} protection`,
);

function onPrimaryClick(): void {
  if (isProtected.value) {
    emit('restore', props.target);
  } else {
    emit('drop', props.target);
  }
}

function onReRunProbe(): void {
  emit('rerun-probe');
}
</script>

<template>
  <div
    class="fx-uploads-guard"
    :class="`fx-uploads-guard--${status}`"
    role="group"
    :aria-labelledby="labelId"
  >
    <div class="fx-uploads-guard__text">
      <span :id="labelId" class="fx-uploads-guard__label">{{ label }}</span>
      <span class="fx-uploads-guard__description">{{ description }}</span>
      <div class="fx-uploads-guard__status-row">
        <StatusPill :variant="pillVariant" :label="pillLabel" />
        <span v-if="caption" class="fx-uploads-guard__caption">{{
          caption
        }}</span>
      </div>
    </div>

    <div class="fx-uploads-guard__actions">
      <button
        v-if="showReRunProbe"
        type="button"
        class="fx-uploads-guard__secondary"
        :disabled="reRunBusy || globalBusy"
        @click="onReRunProbe"
      >
        {{ reRunBusy ? 'Re-running…' : 'Re-run probe' }}
      </button>
      <button
        type="button"
        class="fx-uploads-guard__primary"
        :class="
          isProtected
            ? 'fx-uploads-guard__primary--neutral'
            : 'fx-uploads-guard__primary--warn'
        "
        :aria-label="primaryButtonAria"
        :disabled="busy || globalBusy"
        @click="onPrimaryClick"
      >
        {{ primaryButtonLabel }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.fx-uploads-guard {
  display: flex;
  align-items: center;
  gap: var(--fx-space-4);
  padding: var(--fx-space-4);
  background: var(--fx-color-surface);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  transition: border-color var(--fx-transition-fast);
}

.fx-uploads-guard--missing {
  border-color: var(--fx-color-warn);
}

.fx-uploads-guard__text {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-1);
}

.fx-uploads-guard__label {
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-text);
  font-size: var(--fx-font-size-md);
  line-height: var(--fx-line-height-snug);
}

.fx-uploads-guard__description {
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-snug);
  max-width: 62ch;
}

.fx-uploads-guard__status-row {
  display: flex;
  align-items: center;
  gap: var(--fx-space-2);
  flex-wrap: wrap;
  margin-top: var(--fx-space-1);
}

.fx-uploads-guard__caption {
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-snug);
}

.fx-uploads-guard__actions {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  gap: var(--fx-space-2);
  flex-wrap: wrap;
}

.fx-uploads-guard__primary,
.fx-uploads-guard__secondary {
  padding: var(--fx-space-1) var(--fx-space-3);
  border-radius: var(--fx-radius-md);
  font-family: var(--fx-font-body);
  font-size: var(--fx-font-size-sm);
  font-weight: var(--fx-font-weight-medium);
  cursor: pointer;
  transition:
    background var(--fx-transition-fast),
    border-color var(--fx-transition-fast),
    color var(--fx-transition-fast);
}

.fx-uploads-guard__primary--warn {
  border: 1px solid var(--fx-color-warn);
  background: var(--fx-color-warn-bg);
  color: var(--fx-color-warn);
}

.fx-uploads-guard__primary--warn:hover:not(:disabled) {
  background: var(--fx-color-warn);
  color: var(--fx-color-surface);
}

.fx-uploads-guard__primary--neutral {
  border: 1px solid var(--fx-color-border-strong);
  background: var(--fx-color-surface);
  color: var(--fx-color-text);
}

.fx-uploads-guard__primary--neutral:hover:not(:disabled) {
  background: var(--fx-color-elevated);
}

.fx-uploads-guard__secondary {
  border: 1px solid var(--fx-color-border);
  background: var(--fx-color-surface);
  color: var(--fx-color-text);
}

.fx-uploads-guard__secondary:hover:not(:disabled) {
  border-color: var(--fx-color-border-strong);
  background: var(--fx-color-elevated);
}

.fx-uploads-guard__primary:focus,
.fx-uploads-guard__primary:focus-visible,
.fx-uploads-guard__secondary:focus,
.fx-uploads-guard__secondary:focus-visible {
  outline: none;
  border-color: var(--fx-color-primary-strong);
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-uploads-guard__primary:disabled,
.fx-uploads-guard__secondary:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

@media (max-width: 720px) {
  .fx-uploads-guard {
    flex-direction: column;
    align-items: stretch;
  }

  .fx-uploads-guard__actions {
    justify-content: flex-end;
  }
}
</style>
