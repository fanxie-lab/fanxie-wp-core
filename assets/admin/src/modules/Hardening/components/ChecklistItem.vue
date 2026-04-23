<script setup lang="ts">
import { computed, useId } from 'vue';
import { StatusPill } from '@/components';
import type { StatusPillVariant } from '@/components';
import type { ChecklistStatus } from '../types';

/**
 * ChecklistItem — layout wrapper for a single hardening row.
 *
 * Two-row flex layout:
 *
 *   [ control ]  Setting label
 *                Description text (if provided)
 *                [ status pill — warning/non-default states only ]
 *                [ footer slot — Fix now / copy snippet ]
 *
 * The control is expected to be a visually compact primitive (Toggle, Select
 * wrapper, etc.) whose own visible label is suppressed — this component owns
 * the label so we never render it twice. To wire the external label to the
 * underlying input for accessibility, the default slot receives a `labelId`
 * that the caller hands to the control (e.g. Toggle's `ariaLabelledby`).
 *
 * Active / Inactive status is communicated by the control itself (a toggle's
 * on/off position already tells that story); only non-default states
 * (currently `warning`) surface a status pill.
 */

interface Props {
  /** Short title, sentence-leading capital. */
  label: string;
  /** One-line helper text rendered below the label. */
  description?: string;
  /** Derived status pill variant. */
  status: ChecklistStatus;
  /**
   * Optional override for the visible pill label. Only used when the status
   * actually surfaces a pill (i.e. non-active, non-inactive).
   */
  statusLabel?: string;
  /**
   * Optional override for the screen-reader-only pill prefix. Only used when
   * a pill is rendered.
   */
  srStatusPrefix?: string;
}

const props = withDefaults(defineProps<Props>(), {
  description: undefined,
  statusLabel: undefined,
  srStatusPrefix: undefined,
});

const generatedId = useId();
const labelId = computed(() => `fx-hardening-item-${generatedId}`);

/**
 * Whether this row should render a status pill. The toggle (or other control)
 * already carries the `active` / `inactive` signal visually, so we only draw
 * a pill for notable deviations — today that is `warning`, tomorrow it could
 * be `error`, `deprecated`, etc.
 */
const showPill = computed<boolean>(
  () => props.status !== 'active' && props.status !== 'inactive',
);

const pillVariant = computed<StatusPillVariant>(() => {
  switch (props.status) {
    case 'warning':
      return 'warn';
    default:
      return 'neutral';
  }
});

const pillLabel = computed<string>(() => {
  if (props.statusLabel) return props.statusLabel;
  // Unreachable for active / inactive (pill is hidden). Kept for future
  // statuses so adding a new variant doesn't silently render an empty pill.
  return 'Warning';
});

const srPrefix = computed<string>(() => {
  if (props.srStatusPrefix) return props.srStatusPrefix;
  return 'Status: warning.';
});
</script>

<template>
  <div
    class="fx-hardening-item"
    :class="`fx-hardening-item--${status}`"
    role="group"
    :aria-labelledby="labelId"
  >
    <div class="fx-hardening-item__row">
      <div class="fx-hardening-item__control">
        <slot :label-id="labelId" />
      </div>
      <div class="fx-hardening-item__text">
        <span :id="labelId" class="fx-hardening-item__label">
          {{ label }}
        </span>
        <span
          v-if="description"
          class="fx-hardening-item__description"
          data-testid="checklist-item-description"
        >
          {{ description }}
        </span>
        <div v-if="showPill" class="fx-hardening-item__status">
          <StatusPill
            :variant="pillVariant"
            :label="pillLabel"
            :sr-prefix="srPrefix"
          />
        </div>
        <div v-if="$slots.footer" class="fx-hardening-item__footer">
          <slot name="footer" />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.fx-hardening-item {
  padding: var(--fx-space-4);
  background: var(--fx-color-surface);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  transition: border-color var(--fx-transition-fast);
}

.fx-hardening-item--warning {
  border-color: var(--fx-color-warn);
}

.fx-hardening-item__row {
  display: flex;
  align-items: flex-start;
  gap: var(--fx-space-3);
}

.fx-hardening-item__control {
  flex: 0 0 auto;
  /* Nudge the control down so it visually aligns with the cap height of the
     label rather than the label baseline — looks off otherwise. */
  padding-top: 0.125rem;
}

.fx-hardening-item__text {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-1);
}

.fx-hardening-item__label {
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-text);
  font-size: var(--fx-font-size-md);
  line-height: var(--fx-line-height-snug);
}

.fx-hardening-item__description {
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-snug);
  max-width: 62ch;
}

.fx-hardening-item__status {
  margin-top: var(--fx-space-1);
}

.fx-hardening-item__footer {
  margin-top: var(--fx-space-2);
  padding-top: var(--fx-space-3);
  border-top: 1px dashed var(--fx-color-border);
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
}
</style>
