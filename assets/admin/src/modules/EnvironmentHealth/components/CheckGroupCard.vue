<script setup lang="ts">
import { computed, useId } from 'vue';
import { StatusPill } from '@/components';
import CheckRow from './CheckRow.vue';
import { CHECK_GROUP_LABELS, statusPill, worstStatus } from '../format';
import type { CheckGroup, HealthCheck } from '../types';

/**
 * CheckGroupCard — one card per CheckGroup (PRD §7.6: "grouped cards").
 *
 * The card's headline pill reflects the worst status inside it, so the card
 * colour is a summary rather than the only carrier of meaning: the pill text,
 * the per-row pills and the row edges all say the same thing.
 */

const props = defineProps<{
  group: CheckGroup;
  checks: HealthCheck[];
}>();

const emit = defineEmits<{
  'copy-failed': [message: string];
}>();

const headingId = `fx-eh-group-${useId()}`;

const title = computed<string>(() => CHECK_GROUP_LABELS[props.group]);
const worst = computed(() => worstStatus(props.checks));
const pill = computed(() => statusPill(worst.value));

function onCopyFailed(message: string): void {
  emit('copy-failed', message);
}
</script>

<template>
  <section
    class="fx-eh-group"
    :class="`fx-eh-group--${worst}`"
    :aria-labelledby="headingId"
  >
    <header class="fx-eh-group__header">
      <h3 :id="headingId" class="fx-eh-group__title">{{ title }}</h3>
      <StatusPill
        :variant="pill.variant"
        :label="pill.label"
        :sr-prefix="`${title} group status:`"
      />
    </header>
    <ul class="fx-eh-group__list">
      <CheckRow
        v-for="check in checks"
        :key="check.id"
        :check="check"
        @copy-failed="onCopyFailed"
      />
    </ul>
  </section>
</template>

<style scoped>
.fx-eh-group {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-3);
  padding: var(--fx-space-5);
  background: var(--fx-color-surface);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
  box-shadow: var(--fx-shadow-sm);
}

.fx-eh-group--warning {
  border-color: var(--fx-color-warn);
}

.fx-eh-group--critical {
  border-color: var(--fx-color-critical);
}

.fx-eh-group__header {
  display: flex;
  align-items: center;
  gap: var(--fx-space-3);
  flex-wrap: wrap;
}

.fx-eh-group__title {
  margin: 0;
  font-family: var(--fx-font-heading);
  font-size: var(--fx-font-size-xl);
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-text);
}

.fx-eh-group__list {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}
</style>
