<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, useId } from 'vue';

/**
 * ConfirmDialog — a focus-managed, dismissable confirmation modal.
 *
 * Unlike the inline `role="alertdialog"` confirms elsewhere in this module
 * (LockoutLog, ViolationsView), this is a true modal overlay used for the one
 * decision that can lock an admin out of their own site: enabling Hide Login.
 * It is therefore focus-trapped, Escape/backdrop dismissable, and restores
 * focus to the trigger on close.
 *
 * Rendered only while shown (parent gates it with `v-if`), so mount == open:
 * focus setup lives in `onMounted`, teardown in `onBeforeUnmount`. It stays in
 * the component tree (no Teleport) — the settings screen has no transformed or
 * clipping ancestor, and inline rendering keeps it trivially testable.
 */

interface Props {
  /** Accessible dialog title. */
  title: string;
  /** Label for the confirming (primary) action. */
  confirmLabel: string;
  /** Label for the dismissing action. */
  cancelLabel?: string;
}

const props = withDefaults(defineProps<Props>(), {
  cancelLabel: 'Cancel',
});

const emit = defineEmits<{
  confirm: [];
  cancel: [];
}>();

const generatedId = useId();
const titleId = `fx-confirm-title-${generatedId}`;
const bodyId = `fx-confirm-body-${generatedId}`;

const panelRef = ref<HTMLElement | null>(null);
const cancelRef = ref<HTMLButtonElement | null>(null);

/** Element focused before the dialog opened, so we can restore it on close. */
let previouslyFocused: HTMLElement | null = null;

/** Focusable, non-disabled descendants of the panel, in DOM order. */
function focusableElements(): HTMLElement[] {
  const panel = panelRef.value;
  if (!panel) return [];
  const selector =
    'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
  return Array.from(panel.querySelectorAll<HTMLElement>(selector)).filter(
    (el) => !el.hasAttribute('disabled'),
  );
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.preventDefault();
    emit('cancel');
    return;
  }
  if (event.key !== 'Tab') return;

  const focusable = focusableElements();
  if (focusable.length === 0) {
    // Nothing to move to — keep focus on the panel.
    event.preventDefault();
    return;
  }
  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  if (!first || !last) return;
  const active = document.activeElement;

  if (event.shiftKey) {
    if (active === first || active === panelRef.value) {
      event.preventDefault();
      last.focus();
    }
  } else if (active === last) {
    event.preventDefault();
    first.focus();
  }
}

onMounted(() => {
  previouslyFocused = document.activeElement as HTMLElement | null;
  // Land focus on the dismissing action: the safe default for a destructive
  // or lockout-risking decision (the operator must deliberately Tab to confirm).
  (cancelRef.value ?? panelRef.value)?.focus();
});

onBeforeUnmount(() => {
  previouslyFocused?.focus();
});
</script>

<template>
  <div class="fx-confirm" @keydown="onKeydown">
    <div
      class="fx-confirm__backdrop"
      aria-hidden="true"
      @click="emit('cancel')"
    ></div>
    <div
      ref="panelRef"
      class="fx-confirm__panel"
      role="alertdialog"
      aria-modal="true"
      :aria-labelledby="titleId"
      :aria-describedby="bodyId"
      tabindex="-1"
    >
      <h4 :id="titleId" class="fx-confirm__title">{{ title }}</h4>
      <div :id="bodyId" class="fx-confirm__body">
        <slot />
      </div>
      <div class="fx-confirm__actions">
        <button
          ref="cancelRef"
          type="button"
          class="fx-confirm__button fx-confirm__cancel"
          @click="emit('cancel')"
        >
          {{ props.cancelLabel }}
        </button>
        <button
          type="button"
          class="fx-confirm__button fx-confirm__button--primary fx-confirm__confirm"
          @click="emit('confirm')"
        >
          {{ props.confirmLabel }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.fx-confirm {
  position: fixed;
  inset: 0;
  z-index: 60;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--fx-space-4);
}

.fx-confirm__backdrop {
  position: absolute;
  inset: 0;
  background: var(--fx-color-overlay, rgba(15, 23, 42, 0.45));
}

.fx-confirm__panel {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-3);
  width: 100%;
  max-width: 30rem;
  padding: var(--fx-space-5);
  background: var(--fx-color-surface);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
  box-shadow: var(--fx-shadow-lg, 0 10px 40px rgba(15, 23, 42, 0.25));
}

.fx-confirm__panel:focus,
.fx-confirm__panel:focus-visible {
  outline: none;
}

.fx-confirm__title {
  margin: 0;
  font-family: var(--fx-font-heading);
  font-size: var(--fx-font-size-lg);
  font-weight: var(--fx-font-weight-semibold);
  color: var(--fx-color-text);
}

.fx-confirm__body {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-snug);
}

.fx-confirm__body :deep(code) {
  background: var(--fx-color-elevated);
  padding: 0 var(--fx-space-1);
  border-radius: var(--fx-radius-sm);
  font-family: var(--fx-font-mono);
  font-size: 0.9em;
}

.fx-confirm__actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--fx-space-2);
}

.fx-confirm__button {
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
    background var(--fx-transition-fast),
    border-color var(--fx-transition-fast),
    color var(--fx-transition-fast);
}

.fx-confirm__button:hover:not(:disabled) {
  background: var(--fx-color-elevated);
  border-color: var(--fx-color-border-strong);
}

.fx-confirm__button:focus-visible {
  outline: none;
  border-color: var(--fx-color-primary-strong);
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-confirm__button--primary {
  background: var(--fx-color-button-primary);
  border-color: var(--fx-color-button-primary);
  color: var(--fx-color-button-primary-text);
  font-family: var(--fx-font-heading);
}

.fx-confirm__button--primary:hover:not(:disabled) {
  background: var(--fx-color-button-primary-hover);
  border-color: var(--fx-color-button-primary-hover);
}
</style>
