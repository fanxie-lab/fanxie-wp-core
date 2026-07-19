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
  <!--
    Hover show/hide live on the wrapping container (not the button) so the
    pointer can travel trigger → bubble without the tooltip vanishing
    (WCAG 2.1 SC 1.4.13 "Hoverable"). The bubble's transparent bridge
    (see .fx-tip__bubble::after) keeps the --fx-tip-gap part of the container's
    hit area, so mouseleave only fires when the pointer truly exits both.
    Focus/blur/Escape stay on the button for keyboard + screen-reader users.
  -->
  <span class="fx-tip" @mouseenter="show" @mouseleave="hide">
    <button
      type="button"
      class="fx-tip__trigger"
      :aria-label="props.label"
      :aria-describedby="describedBy"
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

  /*
   * Single source of truth for the gap between the trigger and the bubble.
   * Referenced by BOTH the bubble's offset and its transparent hover-bridge
   * (.fx-tip__bubble::after) so the two can never drift apart. If they did, a
   * dead zone would reopen between them and the tooltip would stop being
   * hoverable (WCAG 2.1 SC 1.4.13). Keep this as the only place the gap is set.
   */
  --fx-tip-gap: 6px;
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
  bottom: calc(100% + var(--fx-tip-gap));
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

/*
 * Transparent bridge spanning the --fx-tip-gap between the bubble and the
 * trigger. It makes the gap part of the bubble's hit area so a pointer
 * travelling from the trigger up to the bubble never leaves the container —
 * keeping the tooltip hoverable (WCAG 2.1 SC 1.4.13). Its height MUST equal the
 * bubble's offset, which is why both read the same --fx-tip-gap custom
 * property. Non-interactive and invisible.
 */
.fx-tip__bubble::after {
  content: '';
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  height: var(--fx-tip-gap);
}
</style>
