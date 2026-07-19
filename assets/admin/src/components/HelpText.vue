<script setup lang="ts">
/**
 * Inline helper text for complex or risky settings — always visible, unlike
 * Tooltip. Give it an `id` and reference it from the control's
 * `aria-describedby` so screen readers announce it with the field.
 */
interface Props {
  id?: string;
  tone?: 'muted' | 'warn';
}

withDefaults(defineProps<Props>(), { id: undefined, tone: 'muted' });
</script>

<template>
  <p
    :id="id"
    role="note"
    class="fx-help"
    :class="{ 'fx-help--warn': tone === 'warn' }"
  >
    <slot />
  </p>
</template>

<style scoped>
.fx-help {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-snug);
  max-width: 62ch;
}

.fx-help--warn {
  color: var(--fx-color-warn);
}

.fx-help :deep(code) {
  background: var(--fx-color-elevated);
  padding: 0 var(--fx-space-1);
  border-radius: var(--fx-radius-sm);
  font-family: var(--fx-font-mono);
  font-size: 0.9em;
}
</style>
