<script setup lang="ts">
import { computed, ref, useId } from 'vue';

/**
 * Accessible information tooltip (WCAG 2.1 AA).
 *
 * A keyboard-focusable ⓘ button describes a short text bubble via
 * `aria-describedby`. Shows on hover and focus; hides on blur / Escape. The
 * bubble content is always in the DOM for screen readers when open, and never
 * hover-only. This is the shared help primitive for simple settings across all
 * modules (see HelpText for inline explanations of complex/risky settings).
 */

interface Props {
  /** Tooltip body text. */
  text: string;
  /** Accessible name for the trigger button. */
  label?: string;
}

const props = withDefaults(defineProps<Props>(), {
  label: 'More information',
});

const open = ref(false);
const bubbleId = `fx-tip-${useId()}`;
const describedBy = computed(() => (open.value ? bubbleId : undefined));

function show(): void {
  open.value = true;
}
function hide(): void {
  open.value = false;
}
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') hide();
}
</script>

<template>
  <span class="fx-tip">
    <button
      type="button"
      class="fx-tip__trigger"
      :aria-label="props.label"
      :aria-describedby="describedBy"
      :aria-expanded="open"
      @mouseenter="show"
      @mouseleave="hide"
      @focus="show"
      @blur="hide"
      @keydown="onKeydown"
    >
      <svg
        class="fx-tip__icon"
        viewBox="0 0 16 16"
        aria-hidden="true"
        focusable="false"
      >
        <circle
          cx="8"
          cy="8"
          r="7"
          fill="none"
          stroke="currentColor"
          stroke-width="1.4"
        />
        <circle cx="8" cy="4.6" r="0.95" fill="currentColor" />
        <path
          d="M8 7v5"
          fill="none"
          stroke="currentColor"
          stroke-width="1.4"
          stroke-linecap="round"
        />
      </svg>
    </button>
    <span v-if="open" :id="bubbleId" role="tooltip" class="fx-tip__bubble">
      {{ props.text }}
    </span>
  </span>
</template>

<style scoped>
.fx-tip {
  position: relative;
  display: inline-flex;
  vertical-align: middle;
}

.fx-tip__trigger {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.05rem;
  height: 1.05rem;
  padding: 0;
  border: none;
  background: transparent;
  color: var(--fx-color-text-muted);
  cursor: help;
  border-radius: 50%;
}

.fx-tip__trigger:hover,
.fx-tip__trigger:focus-visible {
  color: var(--fx-color-primary-strong);
}

.fx-tip__trigger:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-tip__icon {
  width: 100%;
  height: 100%;
}

.fx-tip__bubble {
  position: absolute;
  bottom: calc(100% + 6px);
  left: 50%;
  transform: translateX(-50%);
  z-index: 50;
  width: max-content;
  max-width: 18rem;
  padding: var(--fx-space-2) var(--fx-space-3);
  background: var(--fx-color-text);
  color: var(--fx-color-surface);
  border-radius: var(--fx-radius-md);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-snug);
  box-shadow: var(--fx-shadow-md);
  white-space: normal;
}
</style>
