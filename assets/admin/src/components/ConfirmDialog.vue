<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';

/**
 * ConfirmDialog — a shared, focus-managed, dismissable confirmation modal.
 *
 * A true modal overlay (unlike an inline confirm banner, which renders in-flow
 * and is easy to miss). Use it to gate any consequential or destructive action
 * behind an explicit, unavoidable decision. It is:
 *   - role="alertdialog" + aria-modal, labelled by its title and described by
 *     its body;
 *   - focus-trapped (Tab / Shift+Tab cycle within the panel);
 *   - dismissable via Escape or a backdrop click;
 *   - focus-restoring — focus returns to whatever was focused before it opened;
 *   - browse-mode isolating — while open, every sibling branch of the app up to
 *     <body> is marked `inert` + `aria-hidden`, so assistive tech and pointer
 *     input cannot reach the background. All of this is undone on close.
 *
 * Visibility is controlled by the `open` prop (v-model-able via `update:open`).
 * We use `open`/`update:open` rather than the `modelValue` convention of the
 * simpler field primitives (Toggle, Select) because a named `open` reads far
 * more clearly at the call site for a dialog. Consumers may either two-way bind
 * `v-model:open` or drive it one-way and listen to `confirm` / `cancel` — both
 * `confirm` and `cancel` also emit `update:open: false`, so the dialog always
 * closes itself.
 *
 * The overlay is rendered inline (no Teleport): the settings screen has no
 * transformed or clipping ancestor, `position: fixed` already escapes normal
 * flow, and inline rendering keeps the component trivially queryable in tests.
 */

export type ConfirmTone = 'default' | 'danger';

interface Props {
  /** Whether the dialog is open. Two-way bindable via `v-model:open`. */
  open?: boolean;
  /** Accessible dialog title. */
  title: string;
  /**
   * Plain-text body. Ignored when a default slot is provided, which lets a
   * consumer pass richer content (e.g. inline <code>). One of `message` or the
   * slot should be present so the described-by target is not empty.
   */
  message?: string;
  /** Label for the confirming (primary) action. */
  confirmLabel?: string;
  /** Label for the dismissing action. */
  cancelLabel?: string;
  /** `danger` styles the confirm button as destructive. */
  tone?: ConfirmTone;
}

const props = withDefaults(defineProps<Props>(), {
  open: false,
  message: '',
  confirmLabel: 'Confirm',
  cancelLabel: 'Cancel',
  tone: 'default',
});

const emit = defineEmits<{
  confirm: [];
  cancel: [];
  'update:open': [value: boolean];
}>();

const generatedId = useId();
const titleId = `fx-confirm-title-${generatedId}`;
const bodyId = `fx-confirm-body-${generatedId}`;

const rootRef = ref<HTMLElement | null>(null);
const panelRef = ref<HTMLElement | null>(null);
const cancelRef = ref<HTMLButtonElement | null>(null);

/** Element focused before the dialog opened, so we can restore it on close. */
let previouslyFocused: HTMLElement | null = null;

// --- Background isolation ---------------------------------------------------
//
// While open, every element that is NOT on the dialog's own ancestor path is
// marked inert + aria-hidden. We walk from the dialog root up to <body>,
// inerting each sibling branch at every level. We only record (and later
// restore) attributes we actually added, so we never clobber inert/aria-hidden
// that some other feature set for its own reasons.

interface InertRecord {
  el: HTMLElement;
  inert: boolean;
  ariaHidden: boolean;
}

let inertRecords: InertRecord[] = [];
let isolated = false;

function isolateBackground(dialogEl: HTMLElement): void {
  let node: HTMLElement = dialogEl;
  while (node.parentElement) {
    const parent = node.parentElement;
    for (const child of Array.from(parent.children)) {
      if (child === node || !(child instanceof HTMLElement)) continue;
      const record: InertRecord = {
        el: child,
        inert: false,
        ariaHidden: false,
      };
      if (!child.hasAttribute('inert')) {
        child.setAttribute('inert', '');
        record.inert = true;
      }
      if (!child.hasAttribute('aria-hidden')) {
        child.setAttribute('aria-hidden', 'true');
        record.ariaHidden = true;
      }
      if (record.inert || record.ariaHidden) inertRecords.push(record);
    }
    // Stop once we've processed the top of the document.
    if (parent === document.body || parent === document.documentElement) break;
    node = parent;
  }
  isolated = true;
}

function releaseBackground(): void {
  for (const { el, inert, ariaHidden } of inertRecords) {
    if (inert) el.removeAttribute('inert');
    if (ariaHidden) el.removeAttribute('aria-hidden');
  }
  inertRecords = [];
  isolated = false;
}

// --- Focus trap -------------------------------------------------------------

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
    onCancel();
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

// --- Open / close lifecycle -------------------------------------------------

/**
 * Set up focus + background isolation for an open dialog. Runs from `onMounted`
 * (mounted already open) and from the `open` watcher (opened later). The watcher
 * uses `flush: 'post'`, and `onMounted` fires after insertion, so the panel DOM
 * and refs are guaranteed present here — no nextTick needed.
 */
function activate(): void {
  previouslyFocused = document.activeElement as HTMLElement | null;
  if (rootRef.value) isolateBackground(rootRef.value);
  // Land focus on the dismissing action: the safe default for a destructive or
  // lockout-risking decision (the operator must deliberately move to confirm).
  (cancelRef.value ?? panelRef.value)?.focus();
}

/** Tear down isolation and restore focus. Idempotent. */
function deactivate(): void {
  if (!isolated && !previouslyFocused) return;
  // Remove inert BEFORE restoring focus — you cannot focus into an inert tree.
  releaseBackground();
  previouslyFocused?.focus();
  previouslyFocused = null;
}

function onConfirm(): void {
  emit('confirm');
  emit('update:open', false);
}

function onCancel(): void {
  emit('cancel');
  emit('update:open', false);
}

onMounted(() => {
  if (props.open) activate();
});

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) activate();
    else deactivate();
  },
  { flush: 'post' },
);

onBeforeUnmount(() => {
  deactivate();
});
</script>

<template>
  <div v-if="open" ref="rootRef" class="fx-confirm" @keydown="onKeydown">
    <div
      class="fx-confirm__backdrop"
      aria-hidden="true"
      @click="onCancel"
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
        <slot>{{ message }}</slot>
      </div>
      <div class="fx-confirm__actions">
        <button
          ref="cancelRef"
          type="button"
          class="fx-confirm__button fx-confirm__cancel"
          @click="onCancel"
        >
          {{ cancelLabel }}
        </button>
        <button
          type="button"
          class="fx-confirm__button fx-confirm__confirm"
          :class="
            tone === 'danger'
              ? 'fx-confirm__button--danger'
              : 'fx-confirm__button--primary'
          "
          @click="onConfirm"
        >
          {{ confirmLabel }}
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

.fx-confirm__button--danger {
  background: var(--fx-color-critical);
  border-color: var(--fx-color-critical);
  color: var(--fx-color-text-inverse);
  font-family: var(--fx-font-heading);
}

.fx-confirm__button--danger:hover:not(:disabled) {
  /* No darker-critical token exists; nudge the fill darker on hover. */
  filter: brightness(0.92);
}

.fx-confirm__button--danger:focus-visible {
  outline: none;
  border-color: var(--fx-color-critical);
  box-shadow: 0 0 0 3px var(--fx-color-critical-bg);
}
</style>
