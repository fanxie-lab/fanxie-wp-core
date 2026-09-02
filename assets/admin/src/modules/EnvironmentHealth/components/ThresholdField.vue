<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { TextField } from '@/components';
import { normalizeThreshold, THRESHOLD_BOUNDS } from '../format';
import type { ThresholdKey } from '../types';

/**
 * ThresholdField — a numeric settings field that cannot put junk in the store.
 *
 * The problem it solves: the PHP schema sanitises these with `absint`, which
 * turns an empty field or a pasted word into **0**, and 0 is actively harmful
 * for every one of these settings (see THRESHOLD_BOUNDS). A plain
 * `v-model.number` on a number input hands back `''` the moment the field is
 * cleared, so the store would carry an empty string straight to the server.
 *
 * So the input is bound to a local string draft, and the numeric model is only
 * ever written with a value that survived `normalizeThreshold` — finite,
 * integral, and clamped into range. While the field is mid-edit the draft and
 * the model can disagree (typing "5" toward "50" under a min of 30 commits 30);
 * on blur the draft snaps to whatever was actually committed, so what the user
 * sees is always what will be saved.
 *
 * Not promoted to `@/components` yet: it is the first numeric-bounds field in
 * the codebase, and there is no second call site to prove the shape. Database
 * Maintenance (Phase 3) will likely be that call site.
 */

const props = defineProps<{
  /** Which threshold this is — selects the bounds and the field id. */
  fieldKey: ThresholdKey;
  /** Current committed value. Always a finite integer. */
  modelValue: number;
  /** Visible label. Mirrors the PHP schema label. */
  label: string;
  /** Inline explanation of what changing the number actually does. */
  help: string;
  /** Unit shown after the range hint, e.g. 'days'. */
  unit: string;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: number];
}>();

const bounds = computed(() => THRESHOLD_BOUNDS[props.fieldKey]);

/** What the input actually shows. Diverges from the model only mid-edit. */
const draft = ref(String(props.modelValue));

// Keep the draft in step with external changes (a load, or a SaveBar reset),
// but never fight the user mid-keystroke: only rewrite when the committed
// value genuinely differs from what the draft would commit to.
watch(
  () => props.modelValue,
  (next) => {
    if (normalizeThreshold(draft.value, bounds.value) !== next) {
      draft.value = String(next);
    }
  },
);

const rangeHint = computed(
  () =>
    `${props.help} Allowed range: ${String(bounds.value.min)} to ${String(
      bounds.value.max,
    )} ${props.unit}.`,
);

function onDraftUpdate(next: string): void {
  draft.value = next;
  const normalized = normalizeThreshold(next, bounds.value);
  // `null` means "not a number yet" — the field is empty or mid-paste. Leave
  // the last good value in the store rather than writing NaN or ''.
  if (normalized !== null) {
    emit('update:modelValue', normalized);
  }
}

function onBlur(): void {
  const normalized = normalizeThreshold(draft.value, bounds.value);
  // Snap back to the last committed value when the field was left unusable,
  // so the user never walks away from a box that disagrees with what saves.
  const committed = normalized ?? props.modelValue;
  draft.value = String(committed);
  emit('update:modelValue', committed);
}
</script>

<template>
  <TextField
    :model-value="draft"
    :label="label"
    :help="rangeHint"
    type="number"
    inputmode="numeric"
    :min="bounds.min"
    :max="bounds.max"
    :step="bounds.step"
    @update:model-value="onDraftUpdate"
    @blur="onBlur"
  />
</template>
