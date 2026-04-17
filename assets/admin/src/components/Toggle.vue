<script setup lang="ts">
import { computed, useId } from 'vue';

interface Props {
  /** Current on/off state. Use `v-model` on the parent for two-way binding. */
  modelValue: boolean;
  /** Visible label. Required for accessibility. */
  label: string;
  /** Optional description rendered beneath the label and linked via aria-describedby. */
  description?: string;
  /** Disabled state. */
  disabled?: boolean;
  /** Optional explicit id; one is generated otherwise. */
  id?: string;
}

const props = withDefaults(defineProps<Props>(), {
  description: undefined,
  disabled: false,
  id: undefined,
});

const emit = defineEmits<{
  'update:modelValue': [value: boolean];
  change: [value: boolean];
}>();

const generatedId = useId();
const fieldId = computed(() => props.id ?? `fx-toggle-${generatedId}`);
const descId = computed(() => `${fieldId.value}-desc`);

function toggle(): void {
  if (props.disabled) return;
  const next = !props.modelValue;
  emit('update:modelValue', next);
  emit('change', next);
}

function onKeydown(event: KeyboardEvent): void {
  if (props.disabled) return;
  // role="switch" should respond to Space and Enter.
  if (event.key === ' ' || event.key === 'Enter') {
    event.preventDefault();
    toggle();
  }
}
</script>

<template>
  <div class="fx-toggle" :class="{ 'fx-toggle--disabled': disabled }">
    <button
      :id="fieldId"
      type="button"
      role="switch"
      class="fx-toggle__switch"
      :aria-checked="modelValue"
      :aria-describedby="description ? descId : undefined"
      :disabled="disabled"
      @click="toggle"
      @keydown="onKeydown"
    >
      <span class="fx-toggle__thumb" aria-hidden="true"></span>
    </button>
    <label :for="fieldId" class="fx-toggle__label">
      <span class="fx-toggle__label-text">{{ label }}</span>
      <span v-if="description" :id="descId" class="fx-toggle__description">
        {{ description }}
      </span>
    </label>
  </div>
</template>

<style scoped>
.fx-toggle {
  display: flex;
  align-items: flex-start;
  gap: var(--fx-space-3);
}

.fx-toggle--disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.fx-toggle__switch {
  flex-shrink: 0;
  position: relative;
  width: 2.25rem;
  height: 1.25rem;
  padding: 0;
  border: 1px solid var(--fx-color-border-strong);
  border-radius: var(--fx-radius-pill);
  background: var(--fx-color-bg-subtle);
  cursor: pointer;
  transition:
    background var(--fx-transition-fast),
    border-color var(--fx-transition-fast);
}

.fx-toggle__switch[aria-checked='true'] {
  background: var(--fx-color-primary-strong);
  border-color: var(--fx-color-primary-strong);
}

.fx-toggle__switch:hover:not(:disabled) {
  border-color: var(--fx-color-primary-strong);
}

.fx-toggle__switch:disabled {
  cursor: not-allowed;
}

.fx-toggle__thumb {
  position: absolute;
  top: 50%;
  left: 2px;
  width: 1rem;
  height: 1rem;
  border-radius: 50%;
  background: var(--fx-color-surface);
  box-shadow: var(--fx-shadow-sm);
  transform: translateY(-50%);
  transition: left var(--fx-transition-fast);
  will-change: left;
}

.fx-toggle__switch[aria-checked='true'] .fx-toggle__thumb {
  left: calc(100% - 1rem - 2px);
}

.fx-toggle__label {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-1);
  cursor: pointer;
  user-select: none;
}

.fx-toggle--disabled .fx-toggle__label {
  cursor: not-allowed;
}

.fx-toggle__label-text {
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-text);
}

.fx-toggle__description {
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
}
</style>
