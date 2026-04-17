<script setup lang="ts">
import { computed, useId } from 'vue';

export interface SelectOption {
  value: string;
  label: string;
  disabled?: boolean;
}

export interface SelectOptionGroup {
  label: string;
  options: SelectOption[];
}

interface Props {
  /** Current selected value. */
  modelValue: string;
  /** Visible label. */
  label: string;
  /** Either a flat option list or an array of groups. */
  options: SelectOption[] | SelectOptionGroup[];
  /** Optional help text. */
  help?: string;
  /** Optional error text. */
  error?: string;
  disabled?: boolean;
  required?: boolean;
  id?: string;
}

const props = withDefaults(defineProps<Props>(), {
  help: undefined,
  error: undefined,
  disabled: false,
  required: false,
  id: undefined,
});

const emit = defineEmits<{
  'update:modelValue': [value: string];
}>();

const generatedId = useId();
const fieldId = computed(() => props.id ?? `fx-select-${generatedId}`);
const helpId = computed(() => `${fieldId.value}-help`);
const errorId = computed(() => `${fieldId.value}-error`);

const describedBy = computed(() => {
  const ids: string[] = [];
  if (props.help) ids.push(helpId.value);
  if (props.error) ids.push(errorId.value);
  return ids.length > 0 ? ids.join(' ') : undefined;
});

function isGrouped(
  opts: SelectOption[] | SelectOptionGroup[],
): opts is SelectOptionGroup[] {
  if (opts.length === 0) return false;
  const first = opts[0];
  return (
    first !== undefined && typeof first === 'object' && 'options' in first
  );
}

const grouped = computed(() => isGrouped(props.options));
const flatOptions = computed<SelectOption[]>(() =>
  grouped.value ? [] : (props.options as SelectOption[]),
);
const groups = computed<SelectOptionGroup[]>(() =>
  grouped.value ? (props.options as SelectOptionGroup[]) : [],
);

function onChange(event: Event): void {
  const target = event.target as HTMLSelectElement;
  emit('update:modelValue', target.value);
}
</script>

<template>
  <div class="fx-select" :class="{ 'fx-select--invalid': Boolean(error) }">
    <label :for="fieldId" class="fx-select__label">
      {{ label }}
      <span v-if="required" class="fx-select__required" aria-hidden="true">*</span>
      <span v-if="required" class="fx-visually-hidden">(required)</span>
    </label>
    <select
      :id="fieldId"
      class="fx-select__control"
      :value="modelValue"
      :disabled="disabled"
      :required="required"
      :aria-invalid="Boolean(error) || undefined"
      :aria-describedby="describedBy"
      @change="onChange"
    >
      <template v-if="grouped">
        <optgroup
          v-for="group in groups"
          :key="group.label"
          :label="group.label"
        >
          <option
            v-for="opt in group.options"
            :key="opt.value"
            :value="opt.value"
            :disabled="opt.disabled"
          >
            {{ opt.label }}
          </option>
        </optgroup>
      </template>
      <template v-else>
        <option
          v-for="opt in flatOptions"
          :key="opt.value"
          :value="opt.value"
          :disabled="opt.disabled"
        >
          {{ opt.label }}
        </option>
      </template>
    </select>
    <p v-if="help && !error" :id="helpId" class="fx-select__help">{{ help }}</p>
    <p v-if="error" :id="errorId" class="fx-select__error" role="alert">
      {{ error }}
    </p>
  </div>
</template>

<style scoped>
.fx-select {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
}

.fx-select__label {
  font-weight: 500;
  color: var(--fx-color-text);
}

.fx-select__required {
  color: var(--fx-color-critical);
  margin-left: 2px;
}

.fx-select__control {
  padding: var(--fx-space-2) var(--fx-space-3);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  background: var(--fx-color-surface);
  color: var(--fx-color-text);
  font-family: var(--fx-font-body);
  font-size: var(--fx-font-size-md);
  line-height: var(--fx-line-height-normal);
  transition:
    border-color var(--fx-transition-fast),
    box-shadow var(--fx-transition-fast);
}

.fx-select__control:hover:not(:disabled) {
  border-color: var(--fx-color-border-strong);
}

.fx-select__control:focus {
  outline: none;
  border-color: var(--fx-color-primary-strong);
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-select__control:disabled {
  background: var(--fx-color-elevated);
  cursor: not-allowed;
  opacity: 0.7;
}

.fx-select--invalid .fx-select__control {
  border-color: var(--fx-color-critical);
}

.fx-select__help {
  margin: 0;
  font-size: var(--fx-font-size-sm);
  color: var(--fx-color-text-muted);
}

.fx-select__error {
  margin: 0;
  font-size: var(--fx-font-size-sm);
  color: var(--fx-color-critical);
}
</style>
