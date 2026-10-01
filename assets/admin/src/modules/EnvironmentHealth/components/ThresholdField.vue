<script setup lang="ts">
import { computed } from 'vue';
import { NumberField } from '@/components';
import { THRESHOLD_BOUNDS } from '../format';
import type { ThresholdKey } from '../types';

/**
 * ThresholdField — Environment Health's numeric threshold, a thin wrapper that
 * looks up the bounds for its key (THRESHOLD_BOUNDS, mirroring the PHP schema)
 * and defers all draft/clamp behaviour to the shared NumberField.
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
</script>

<template>
  <NumberField
    :id="`fx-threshold-${fieldKey}`"
    :model-value="modelValue"
    :label="label"
    :help="help"
    :unit="unit"
    :min="bounds.min"
    :max="bounds.max"
    @update:model-value="emit('update:modelValue', $event)"
  />
</template>
