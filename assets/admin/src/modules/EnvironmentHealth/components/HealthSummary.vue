<script setup lang="ts">
import { computed } from 'vue';
import {
  buildCountsSentence,
  formatAbsoluteTime,
  formatIsoTime,
  formatLastChecked,
} from '../format';
import type { HealthCounts } from '../types';

/**
 * HealthSummary — the counts strip plus a "last checked" stamp.
 *
 * Accessibility: four bare numbers on four chips are meaningless read aloud in
 * sequence, so the chips are `aria-hidden` decoration and the accessibility
 * tree gets one sentence instead ("9 checks: 6 OK, 2 warnings, 1 critical
 * issue."). The "last checked" line stays exposed to everyone — a cached
 * report must never be mistaken for a live one.
 */

const props = defineProps<{
  counts: HealthCounts;
  /** Unix timestamp in seconds. 0 when no report has arrived yet. */
  generatedAt: number;
}>();

interface Chip {
  key: string;
  label: string;
  count: number;
}

const sentence = computed<string>(() => buildCountsSentence(props.counts));

const chips = computed<Chip[]>(() => [
  { key: 'ok', label: 'OK', count: props.counts.ok },
  { key: 'warning', label: 'Warning', count: props.counts.warning },
  { key: 'critical', label: 'Critical', count: props.counts.critical },
  { key: 'unknown', label: 'Not checked', count: props.counts.unknown },
]);

const relative = computed<string>(() => formatLastChecked(props.generatedAt));
const absolute = computed<string>(() => formatAbsoluteTime(props.generatedAt));
const iso = computed<string>(() => formatIsoTime(props.generatedAt));
</script>

<template>
  <div class="fx-eh-summary">
    <p class="fx-visually-hidden">{{ sentence }}</p>
    <ul class="fx-eh-summary__chips" aria-hidden="true">
      <li
        v-for="chip in chips"
        :key="chip.key"
        class="fx-eh-summary__chip"
        :class="[
          `fx-eh-summary__chip--${chip.key}`,
          { 'fx-eh-summary__chip--zero': chip.count === 0 },
        ]"
      >
        <span class="fx-eh-summary__count">{{ chip.count }}</span>
        <span class="fx-eh-summary__label">{{ chip.label }}</span>
      </li>
    </ul>
    <p class="fx-eh-summary__meta">
      Last checked
      <time v-if="iso" :datetime="iso">{{ relative }}</time>
      <template v-else>never</template>
      <span v-if="absolute" class="fx-eh-summary__absolute">
        ({{ absolute }})
      </span>
    </p>
  </div>
</template>

<style scoped>
.fx-eh-summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--fx-space-4);
  flex-wrap: wrap;
  padding: var(--fx-space-3) var(--fx-space-4);
  background: var(--fx-color-surface);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
}

.fx-eh-summary__chips {
  display: flex;
  align-items: stretch;
  gap: var(--fx-space-2);
  margin: 0;
  padding: 0;
  list-style: none;
  flex-wrap: wrap;
}

.fx-eh-summary__chip {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 5.5rem;
  padding: var(--fx-space-2) var(--fx-space-3);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  background: var(--fx-color-bg-subtle);
}

/* Same house pattern as CheckRow: full-border tint, and only for the states
   that warrant attention. The chip's own label already names the bucket. */
.fx-eh-summary__chip--warning {
  border-color: var(--fx-color-warn);
}

.fx-eh-summary__chip--critical {
  border-color: var(--fx-color-critical);
}

.fx-eh-summary__chip--unknown {
  border-color: var(--fx-color-border-strong);
  border-style: dashed;
}

/* An empty bucket recedes rather than disappearing — the four-slot layout
   stays stable across refreshes so numbers do not jump between positions. */
.fx-eh-summary__chip--zero {
  opacity: 0.55;
}

.fx-eh-summary__count {
  font-family: var(--fx-font-heading);
  font-size: var(--fx-font-size-xl);
  font-weight: var(--fx-font-weight-semibold);
  line-height: var(--fx-line-height-tight);
  color: var(--fx-color-text);
}

.fx-eh-summary__label {
  font-size: var(--fx-font-size-xs);
  color: var(--fx-color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.fx-eh-summary__meta {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-snug);
}

.fx-eh-summary__absolute {
  color: var(--fx-color-text-dim);
}
</style>
