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
  /**
   * When true, the visible `<label>` element is dropped from the DOM. Use this
   * when the Select is embedded inside a wrapper that already renders the
   * visible label — pair with `ariaLabelledby` so the <select> still has an
   * accessible name.
   */
  hideLabel?: boolean;
  /**
   * When true, the `<p class="fx-select__help">` element is suppressed even if
   * `help` is passed. Useful inside wrappers (e.g. ChecklistItem) whose own
   * description already communicates the same thing — prevents a duplicate
   * description line without forcing callers to unset `help`.
   */
  hideHelp?: boolean;
  /**
   * Optional id of an element that labels the select. When set, the select
   * uses this as its accessible name via aria-labelledby, and the internal
   * <label for> is dropped (same rationale as Toggle — no competing names).
   * Errors are always rendered regardless of this flag.
   */
  ariaLabelledby?: string;
}

const props = withDefaults(defineProps<Props>(), {
  help: undefined,
  error: undefined,
  disabled: false,
  required: false,
  id: undefined,
  hideLabel: false,
  hideHelp: false,
  ariaLabelledby: undefined,
});

const emit = defineEmits<{
  'update:modelValue': [value: string];
}>();

const generatedId = useId();
const fieldId = computed(() => props.id ?? `fx-select-${generatedId}`);
const helpId = computed(() => `${fieldId.value}-help`);
const errorId = computed(() => `${fieldId.value}-error`);

/**
 * Whether the inline help paragraph is actually rendered. We keep the id
 * wiring in sync with the DOM — if the help node is suppressed (hideHelp,
 * or an error has taken its slot) we must not reference it via
 * aria-describedby, otherwise assistive tech resolves the id to nothing.
 */
const showHelp = computed(
  () => Boolean(props.help) && !props.hideHelp && !props.error,
);

const describedBy = computed(() => {
  const ids: string[] = [];
  if (showHelp.value) ids.push(helpId.value);
  if (props.error) ids.push(errorId.value);
  return ids.length > 0 ? ids.join(' ') : undefined;
});

function isGrouped(
  opts: SelectOption[] | SelectOptionGroup[],
): opts is SelectOptionGroup[] {
  if (opts.length === 0) return false;
  const first = opts[0];
  return first !== undefined && typeof first === 'object' && 'options' in first;
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
  <div
    class="fx-select"
    :class="{
      'fx-select--invalid': Boolean(error),
      'fx-select--label-hidden': hideLabel || Boolean(ariaLabelledby),
    }"
  >
    <!--
      Visible label. Omitted entirely when an external label is wired via
      `ariaLabelledby` — rendering both would expose the same accessible name
      twice and clicking the external label would not focus the <select>.
    -->
    <label
      v-if="!ariaLabelledby"
      :for="fieldId"
      class="fx-select__label"
      :class="{ 'fx-visually-hidden': hideLabel }"
    >
      {{ label }}
      <span v-if="required" class="fx-select__required" aria-hidden="true"
        >*</span
      >
      <span v-if="required" class="fx-visually-hidden">(required)</span>
    </label>
    <div class="fx-select__control-wrap">
      <select
        :id="fieldId"
        class="fx-select__control"
        :value="modelValue"
        :disabled="disabled"
        :required="required"
        :aria-invalid="Boolean(error) || undefined"
        :aria-describedby="describedBy"
        :aria-labelledby="ariaLabelledby"
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
      <!--
        Decorative caret. The native select arrow is routinely overridden by
        WordPress admin styles (which set appearance: none on most selects in
        the plugins area), so we draw our own chevron and turn off the browser
        widget explicitly. Hidden from assistive tech — the <select> itself
        already announces as a combobox.
      -->
      <span class="fx-select__caret" aria-hidden="true">
        <svg
          width="12"
          height="8"
          viewBox="0 0 12 8"
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
          focusable="false"
        >
          <path
            d="M1 1.5L6 6.5L11 1.5"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      </span>
    </div>
    <p v-if="showHelp" :id="helpId" class="fx-select__help">{{ help }}</p>
    <!--
      Errors always render — a wrapper hiding the helper line must not also
      swallow validation feedback.
    -->
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

/*
 * Wrapper owns the layout box the caret sits inside. Previous attempts
 * used `inline-flex` with `flex: 1 1 auto` on the <select>, assuming
 * the select would claim the full wrap width. That assumption breaks in
 * the WP admin: `wp-admin/css/forms.css` ships `select { max-width: 25em }`
 * at element-selector specificity, which caps the <select>'s rendered
 * width even when our `width: 100%` wins the `width` cascade. The wrap
 * then stretched to 100% of the parent column while the <select> stopped
 * at 25em — caret ended up floating in the empty gap to the right.
 *
 * Fix is twofold:
 *   1. Use a single-cell CSS grid so the <select> and caret share the
 *      exact same box. The caret is placed `justify-self: end` inside
 *      that cell, so it follows wherever the <select>'s right edge is.
 *   2. Explicitly override `max-width` on the <select> with enough
 *      selector specificity (two classes = 0,2,0) to beat the WP admin
 *      rule without needing `!important`.
 */
.fx-select .fx-select__control-wrap {
  position: relative;
  display: grid;
  grid-template-columns: 1fr;
  width: 100%;
}

.fx-select .fx-select__control {
  /* Place in the single grid cell so the caret (same cell) overlays
     exactly our right edge — no floating gap possible. */
  grid-column: 1;
  grid-row: 1;
  width: 100%;
  /* Defeat WP admin `select { max-width: 25em }` which otherwise caps
     the rendered width and pushes the caret away from the select. */
  max-width: none;
  min-width: 0;
  /* Extra right-side padding clears the custom caret; 2.5rem = caret width
     (0.75rem) + right offset (0.875rem) + breathing room. */
  padding: var(--fx-space-2) 2.5rem var(--fx-space-2) var(--fx-space-3);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  background: var(--fx-color-surface);
  color: var(--fx-color-text);
  font-family: var(--fx-font-body);
  font-size: var(--fx-font-size-md);
  line-height: var(--fx-line-height-normal);
  /* Suppress the native arrow — WordPress admin styles already do this on
     many selects, and we need a deterministic baseline to draw our own. */
  appearance: none;
  -webkit-appearance: none;
  -moz-appearance: none;
  background-image: none;
  transition:
    border-color var(--fx-transition-fast),
    box-shadow var(--fx-transition-fast);
}

/* Firefox: hide the dotted focus ring around the selected option text, we
   already provide a focus ring on the element itself. */
.fx-select__control::-ms-expand {
  display: none;
}

/*
 * Caret shares the single grid cell with the <select>, so its right
 * edge tracks the select's right edge exactly no matter how wide or
 * narrow the column is. `justify-self: end` + `align-self: center`
 * replaces the old `position: absolute; right: ...; top: 50%` trick
 * and guarantees there is no empty gap between the select border and
 * the caret glyph. A 0.875rem right margin matches the old offset so
 * the caret still sits inside the select's right padding.
 */
.fx-select .fx-select__caret {
  grid-column: 1;
  grid-row: 1;
  justify-self: end;
  align-self: center;
  margin-right: 0.875rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--fx-color-text-muted);
  pointer-events: none;
  transition: color var(--fx-transition-fast);
}

.fx-select .fx-select__control-wrap:hover .fx-select__caret,
.fx-select .fx-select__control:focus ~ .fx-select__caret,
.fx-select .fx-select__control:focus-visible ~ .fx-select__caret {
  color: var(--fx-color-text);
}

.fx-select .fx-select__control:disabled ~ .fx-select__caret {
  opacity: 0.5;
}

.fx-select__control:hover:not(:disabled) {
  border-color: var(--fx-color-border-strong);
}

.fx-select__control:focus,
.fx-select__control:focus-visible {
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
