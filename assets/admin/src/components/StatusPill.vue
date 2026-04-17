<script setup lang="ts">
import { computed } from 'vue';

export type StatusPillVariant = 'ok' | 'warn' | 'critical' | 'info' | 'neutral';

interface Props {
  /** Semantic variant. Drives colour, icon, and SR label prefix. */
  variant: StatusPillVariant;
  /** Visible label text. */
  label: string;
  /**
   * Optional override for the screen-reader prefix (e.g. "Status: ").
   * Defaults to a variant-appropriate prefix so colour alone never conveys meaning.
   */
  srPrefix?: string;
}

const props = withDefaults(defineProps<Props>(), {
  srPrefix: undefined,
});

// Variant-appropriate inline SVG glyphs. These are decorative — the SR prefix
// carries the semantic information. All paths are inline-safe (no user input).
const iconPaths: Record<StatusPillVariant, string> = {
  ok: 'M5 10.5l3.5 3.5L15 7.5',
  warn: 'M10 5v5M10 14v.5',
  critical: 'M6 6l8 8M14 6l-8 8',
  info: 'M10 8v5M10 5.5v.5',
  neutral: 'M5 10h10',
};

const defaultSrPrefix: Record<StatusPillVariant, string> = {
  ok: 'OK:',
  warn: 'Warning:',
  critical: 'Critical:',
  info: 'Info:',
  neutral: 'Status:',
};

const srPrefixComputed = computed<string>(
  () => props.srPrefix ?? defaultSrPrefix[props.variant],
);

const iconPath = computed<string>(() => iconPaths[props.variant]);
</script>

<template>
  <span class="fx-pill" :class="`fx-pill--${variant}`">
    <span class="fx-visually-hidden">{{ srPrefixComputed }} </span>
    <svg
      class="fx-pill__icon"
      viewBox="0 0 20 20"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      <path :d="iconPath" />
    </svg>
    <span class="fx-pill__label">{{ label }}</span>
  </span>
</template>

<style scoped>
.fx-pill {
  display: inline-flex;
  align-items: center;
  gap: var(--fx-space-1);
  padding: 2px var(--fx-space-2);
  border-radius: var(--fx-radius-pill);
  font-family: var(--fx-font-body);
  font-size: var(--fx-font-size-xs);
  font-weight: var(--fx-font-weight-medium);
  line-height: var(--fx-line-height-tight);
  border: 1px solid transparent;
  white-space: nowrap;
}

.fx-pill__icon {
  width: 0.875rem;
  height: 0.875rem;
  flex-shrink: 0;
}

.fx-pill--ok {
  color: var(--fx-color-ok);
  background: var(--fx-color-ok-bg);
  border-color: var(--fx-color-ok);
}

.fx-pill--warn {
  color: var(--fx-color-warn);
  background: var(--fx-color-warn-bg);
  border-color: var(--fx-color-warn);
}

.fx-pill--critical {
  color: var(--fx-color-critical);
  background: var(--fx-color-critical-bg);
  border-color: var(--fx-color-critical);
}

.fx-pill--info {
  color: var(--fx-color-info);
  background: var(--fx-color-info-bg);
  border-color: var(--fx-color-info);
}

.fx-pill--neutral {
  color: var(--fx-color-neutral);
  background: var(--fx-color-neutral-bg);
  border-color: var(--fx-color-border);
}
</style>
