<script setup lang="ts">
import { computed } from 'vue';

export type SaveStatus = 'idle' | 'saving' | 'saved' | 'error';

interface Props {
  /** True when the form has unsaved changes. */
  dirty: boolean;
  /** Current save state. */
  status?: SaveStatus;
  /** Optional user-facing message paired with status. */
  message?: string;
  /** Disable the save button explicitly (e.g., validation failure). */
  disabled?: boolean;
  /** Label for the save button. */
  saveLabel?: string;
  /** Label for the reset button. */
  resetLabel?: string;
}

const props = withDefaults(defineProps<Props>(), {
  status: 'idle',
  message: undefined,
  disabled: false,
  saveLabel: 'Save changes',
  resetLabel: 'Reset',
});

const emit = defineEmits<{
  save: [];
  reset: [];
}>();

const saveDisabled = computed(
  () => props.disabled || !props.dirty || props.status === 'saving',
);
const resetDisabled = computed(() => !props.dirty || props.status === 'saving');

const statusText = computed<string>(() => {
  if (props.message) return props.message;
  switch (props.status) {
    case 'saving':
      return 'Saving…';
    case 'saved':
      return 'All changes saved.';
    case 'error':
      return 'Save failed.';
    default:
      return props.dirty ? 'You have unsaved changes.' : '';
  }
});

function onSave(): void {
  if (saveDisabled.value) return;
  emit('save');
}

function onReset(): void {
  if (resetDisabled.value) return;
  emit('reset');
}
</script>

<template>
  <div
    class="fx-save-bar"
    :class="{ 'fx-save-bar--dirty': dirty }"
    role="region"
    aria-label="Save changes"
  >
    <div class="fx-save-bar__status">
      <span
        v-if="dirty"
        class="fx-save-bar__dirty-dot"
        aria-hidden="true"
      ></span>
      <span
        class="fx-save-bar__status-text"
        :class="`fx-save-bar__status-text--${status}`"
        aria-live="polite"
        aria-atomic="true"
      >
        {{ statusText }}
      </span>
    </div>
    <div class="fx-save-bar__actions">
      <button
        type="button"
        class="fx-save-bar__button fx-save-bar__button--ghost"
        :disabled="resetDisabled"
        @click="onReset"
      >
        {{ resetLabel }}
      </button>
      <button
        type="button"
        class="fx-save-bar__button fx-save-bar__button--primary"
        :disabled="saveDisabled"
        @click="onSave"
      >
        {{ saveLabel }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.fx-save-bar {
  position: sticky;
  bottom: 0;
  z-index: 10;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--fx-space-4);
  padding: var(--fx-space-3) var(--fx-space-5);
  background: var(--fx-color-surface);
  border-top: 1px solid var(--fx-color-border);
  box-shadow: var(--fx-shadow-md);
}

.fx-save-bar__status {
  display: flex;
  align-items: center;
  gap: var(--fx-space-2);
  min-height: 1.5rem;
}

.fx-save-bar__dirty-dot {
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 50%;
  background: var(--fx-color-warn);
  flex-shrink: 0;
}

.fx-save-bar__status-text {
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
}

.fx-save-bar__status-text--saved {
  color: var(--fx-color-ok);
}

.fx-save-bar__status-text--error {
  color: var(--fx-color-critical);
}

.fx-save-bar__actions {
  display: flex;
  gap: var(--fx-space-2);
}

.fx-save-bar__button {
  padding: var(--fx-space-2) var(--fx-space-4);
  border-radius: var(--fx-radius-md);
  border: 1px solid transparent;
  cursor: pointer;
  font-family: var(--fx-font-body);
  font-size: var(--fx-font-size-md);
  font-weight: var(--fx-font-weight-semibold);
  transition:
    background var(--fx-transition-fast),
    border-color var(--fx-transition-fast),
    color var(--fx-transition-fast),
    box-shadow var(--fx-transition-fast);
}

.fx-save-bar__button:disabled {
  cursor: not-allowed;
  opacity: 0.5;
}

.fx-save-bar__button--ghost {
  background: transparent;
  border-color: var(--fx-color-border);
  color: var(--fx-color-text);
}

.fx-save-bar__button--ghost:hover:not(:disabled) {
  background: var(--fx-color-elevated);
  border-color: var(--fx-color-border-strong);
}

/* Primary: literal brand mint + black text, Lexend 500.
 * #000 on #00e298 measures ~13:1 (AAA). */
.fx-save-bar__button--primary {
  background: var(--fx-color-button-primary);
  border-color: var(--fx-color-button-primary);
  color: var(--fx-color-button-primary-text);
  font-family: var(--fx-font-heading);
  font-weight: var(--fx-font-weight-medium);
}

.fx-save-bar__button--primary:hover:not(:disabled) {
  background: var(--fx-color-button-primary-hover);
  border-color: var(--fx-color-button-primary-hover);
  box-shadow: var(--fx-shadow-glow);
}

.fx-save-bar__button--primary:active:not(:disabled) {
  background: var(--fx-color-button-primary-active);
  border-color: var(--fx-color-button-primary-active);
}
</style>
