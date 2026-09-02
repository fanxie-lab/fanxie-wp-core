<script setup lang="ts">
import { useEnvironmentHealthStore } from '../stores/environmentHealth';
import CheckGroupCard from '../components/CheckGroupCard.vue';

/**
 * ChecksView — the "Checks" sub-tab: one card per populated check group.
 *
 * Deliberately thin. The shell owns loading, the header, the refresh control
 * and HealthSummary; this view only renders the report body. That split is
 * what keeps the OK/warning/critical counts on screen while the user is over
 * on the Settings tab adjusting a threshold.
 *
 * The store is loaded once by the shell (`EnvironmentHealth.vue`), so switching
 * tabs re-renders this component without refetching anything.
 */

const store = useEnvironmentHealthStore();

function onCopyFailed(message: string): void {
  store.pushToast(message, 'error');
}
</script>

<template>
  <div class="fx-eh-checks">
    <p v-if="store.isEmptyReport" class="fx-eh-checks__empty">
      No checks ran. This usually means every group was switched off — turn one
      back on under Settings, then re-run the checks.
    </p>

    <div
      v-else
      class="fx-eh-checks__groups"
      :aria-busy="store.loading.refreshing"
    >
      <CheckGroupCard
        v-for="bucket in store.checksByGroup"
        :key="bucket.group"
        :group="bucket.group"
        :checks="bucket.checks"
        @copy-failed="onCopyFailed"
      />
    </div>
  </div>
</template>

<style scoped>
.fx-eh-checks__groups {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-5);
}

.fx-eh-checks__empty {
  margin: 0;
  padding: var(--fx-space-5);
  color: var(--fx-color-text-muted);
  background: var(--fx-color-surface);
  border: 1px dashed var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
}
</style>
