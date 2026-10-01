<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import TextField from './TextField.vue';

/**
 * NumberField — a bounded integer settings field that cannot put junk in a store.
 *
 * A plain `v-model.number` on a number input hands back `''` the moment the
 * field is cleared, and server-side `absint` turns an empty field or a pasted
 * word into 0 — harmful for most bounded settings. So the input is bound to a
 * local string draft, and the numeric model is only ever written with a value
 * that is finite, integral and clamped into `[min, max]`. Mid-edit the draft
 * and the model can disagree (typing "5" toward "50" under a min of 30 commits
 * 30); on blur the draft snaps to what was actually committed, so what the user
 * sees is always what will be saved.
 */

const props = withDefaults(
  defineProps<{
    /** Input id. The help text id is derived from it. */
    id: string;
    /** Current committed value. Always a finite integer. */
    modelValue: number;
    /** Visible label. */
    label: string;
    /** Inline explanation of what changing the number does. */
    help: string;
    /** Unit shown after the range hint, e.g. 'days'. May be empty. */
    unit: string;
    /** Inclusive lower bound. */
    min: number;
    /** Inclusive upper bound. */
    max: number;
    disabled?: boolean;
    /** External element id(s) that also describe the input (see TextField). */
    describedby?: string;
  }>(),
  { disabled: false, describedby: undefined },
);

const emit = defineEmits<{
  'update:modelValue': [value: number];
}>();

/** Parse + floor + clamp. `null` means "not a number yet". */
function normalize(raw: string): number | null {
  if (raw.trim() === '') return null;
  const parsed = Number(raw);
  if (!Number.isFinite(parsed)) return null;
  return Math.min(props.max, Math.max(props.min, Math.floor(parsed)));
}

/** What the input actually shows. Diverges from the model only mid-edit. */
const draft = ref(String(props.modelValue));

// Follow external changes (a load, or a SaveBar reset) without fighting the
// user mid-keystroke: only rewrite when the draft would commit differently.
watch(
  () => props.modelValue,
  (next) => {
    if (normalize(draft.value) !== next) {
      draft.value = String(next);
    }
  },
);

const rangeHint = computed(() => {
  const range = `Allowed range: ${String(props.min)} to ${String(props.max)}`;
  const unit = props.unit ? ` ${props.unit}` : '';
  return `${props.help} ${range}${unit}.`.trim();
});

function onDraftUpdate(next: string): void {
  draft.value = next;
  const normalized = normalize(next);
  // Leave the last good value in place while the field is empty/mid-paste.
  if (normalized !== null) {
    emit('update:modelValue', normalized);
  }
}

function onBlur(): void {
  const committed = normalize(draft.value) ?? props.modelValue;
  draft.value = String(committed);
  emit('update:modelValue', committed);
}
</script>

<template>
  <TextField
    :id="id"
    :model-value="draft"
    :label="label"
    :help="rangeHint"
    :disabled="disabled"
    :describedby="describedby"
    type="number"
    inputmode="numeric"
    :min="min"
    :max="max"
    :step="1"
    @update:model-value="onDraftUpdate"
    @blur="onBlur"
  />
</template>
