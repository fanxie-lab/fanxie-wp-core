<script setup lang="ts">
import { computed, useId } from 'vue';

interface Props {
  /** Two-way bound value. */
  modelValue: string;
  /** Visible label. Required — never use placeholder as a label. */
  label: string;
  /** Optional help text, rendered under the input and linked via aria-describedby. */
  help?: string;
  /** Optional error text. When non-empty, marks the input as invalid. */
  error?: string;
  /** Native input type. Defaults to 'text'. */
  type?: 'text' | 'email' | 'url' | 'password' | 'search' | 'tel' | 'number';
  /** Placeholder — must not substitute for the label. */
  placeholder?: string;
  /** Disabled. */
  disabled?: boolean;
  /** Read-only. */
  readonly?: boolean;
  /** Marks the field as required. */
  required?: boolean;
  /** Optional explicit id. */
  id?: string;
  /** autocomplete hint. */
  autocomplete?: string;
  /**
   * Numeric bounds and granularity, forwarded to the native input.
   *
   * These are real props rather than fall-through attributes on purpose: this
   * component does not set `inheritAttrs: false`, so a bare `min`/`max` would
   * land on the wrapper <div> and the browser would enforce nothing — the same
   * trap CLAUDE.md §3.4 documents for Toggle's aria-describedby. Only
   * meaningful with type="number".
   */
  min?: number;
  max?: number;
  step?: number;
  /** Virtual-keyboard hint, e.g. 'numeric' for integer entry on mobile. */
  inputmode?: 'text' | 'numeric' | 'decimal' | 'tel' | 'email' | 'url';
  /**
   * Optional id(s) of an *external* element that also describes the input,
   * merged after the internal help/error ids. A prop rather than a raw
   * aria-describedby attribute, which would fall through to the wrapper <div>.
   */
  describedby?: string;
}

const props = withDefaults(defineProps<Props>(), {
  help: undefined,
  error: undefined,
  type: 'text',
  placeholder: undefined,
  disabled: false,
  readonly: false,
  required: false,
  id: undefined,
  autocomplete: undefined,
  min: undefined,
  max: undefined,
  step: undefined,
  inputmode: undefined,
  describedby: undefined,
});

const emit = defineEmits<{
  'update:modelValue': [value: string];
  /** Raised on blur so wrappers can normalise a half-typed value. */
  blur: [];
}>();

const generatedId = useId();
const fieldId = computed(() => props.id ?? `fx-text-${generatedId}`);
const helpId = computed(() => `${fieldId.value}-help`);
const errorId = computed(() => `${fieldId.value}-error`);

const describedBy = computed(() => {
  const ids: string[] = [];
  if (props.help) ids.push(helpId.value);
  if (props.error) ids.push(errorId.value);
  if (props.describedby) ids.push(props.describedby);
  return ids.length > 0 ? ids.join(' ') : undefined;
});

function onInput(event: Event): void {
  const target = event.target as HTMLInputElement;
  emit('update:modelValue', target.value);
}

function onBlur(): void {
  emit('blur');
}
</script>

<template>
  <div
    class="fx-text-field"
    :class="{ 'fx-text-field--invalid': Boolean(error) }"
  >
    <label :for="fieldId" class="fx-text-field__label">
      {{ label }}
      <span v-if="required" class="fx-text-field__required" aria-hidden="true"
        >*</span
      >
      <span v-if="required" class="fx-visually-hidden">(required)</span>
    </label>
    <input
      :id="fieldId"
      class="fx-text-field__input"
      :type="type"
      :value="modelValue"
      :placeholder="placeholder"
      :disabled="disabled"
      :readonly="readonly"
      :required="required"
      :autocomplete="autocomplete"
      :min="min"
      :max="max"
      :step="step"
      :inputmode="inputmode"
      :aria-invalid="Boolean(error) || undefined"
      :aria-describedby="describedBy"
      @input="onInput"
      @blur="onBlur"
    />
    <p v-if="help && !error" :id="helpId" class="fx-text-field__help">
      {{ help }}
    </p>
    <p v-if="error" :id="errorId" class="fx-text-field__error" role="alert">
      <slot name="error">{{ error }}</slot>
    </p>
  </div>
</template>

<style scoped>
.fx-text-field {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
}

.fx-text-field__label {
  font-weight: 500;
  color: var(--fx-color-text);
}

.fx-text-field__required {
  color: var(--fx-color-critical);
  margin-left: 2px;
}

.fx-text-field__input {
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

.fx-text-field__input:hover:not(:disabled):not(:read-only) {
  border-color: var(--fx-color-border-strong);
}

.fx-text-field__input:focus,
.fx-text-field__input:focus-visible {
  outline: none;
  border-color: var(--fx-color-primary-strong);
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-text-field__input:disabled {
  background: var(--fx-color-elevated);
  cursor: not-allowed;
  opacity: 0.7;
}

.fx-text-field--invalid .fx-text-field__input {
  border-color: var(--fx-color-critical);
}

.fx-text-field__help {
  margin: 0;
  font-size: var(--fx-font-size-sm);
  color: var(--fx-color-text-muted);
}

.fx-text-field__error {
  margin: 0;
  font-size: var(--fx-font-size-sm);
  color: var(--fx-color-critical);
}
</style>
