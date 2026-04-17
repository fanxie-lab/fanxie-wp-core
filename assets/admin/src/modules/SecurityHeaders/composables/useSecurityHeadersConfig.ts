// Thin form-level wrapper around the Security Headers store.
//
// Views should prefer this composable over poking the store directly for
// dirty-tracking + reset semantics — it keeps imports inside the module and
// lets us add form-specific helpers (validation, field-level dirty) later
// without touching every view.

import { storeToRefs } from 'pinia';
import { computed, type ComputedRef } from 'vue';
import { useSecurityHeadersStore } from '../stores/securityHeaders';
import type { SecurityHeadersConfig } from '../types';

export interface UseSecurityHeadersConfigReturn {
  config: ComputedRef<SecurityHeadersConfig | null>;
  isDirty: ComputedRef<boolean>;
  isSaving: ComputedRef<boolean>;
  save: () => Promise<void>;
  reset: () => void;
}

/**
 * Form-friendly accessors for the module's config. `config` is a writable
 * computed ref — views can `v-model` into `config.value.headers.hsts.enabled`
 * and the store state updates in place.
 */
export function useSecurityHeadersConfig(): UseSecurityHeadersConfigReturn {
  const store = useSecurityHeadersStore();
  const { isDirty } = storeToRefs(store);

  const config = computed<SecurityHeadersConfig | null>(() => store.config);
  const isSaving = computed<boolean>(() => store.loading.saving);

  async function save(): Promise<void> {
    await store.save();
  }

  function reset(): void {
    store.reset();
  }

  return { config, isDirty, isSaving, save, reset };
}
