<script setup lang="ts">
import { computed } from 'vue';
import { useAppStore } from '@/stores/app';
import StatusPill from './StatusPill.vue';

interface Props {
  /** Module id (e.g. 'security-headers'). */
  moduleId: string;
  /** Human-readable module name. */
  moduleName: string;
  /** Phase label, e.g. "Phase 1". */
  phaseLabel: string;
  /** Short description of what the module will do. */
  description?: string;
}

const props = withDefaults(defineProps<Props>(), {
  description: undefined,
});

const store = useAppStore();

const pongLabel = computed<string>(() => {
  if (store.ping.lastPongAt === null) return 'Not yet pinged';
  const when = new Date(store.ping.lastPongAt * 1000);
  return `Last pong at ${when.toLocaleTimeString()}`;
});

async function onPing(): Promise<void> {
  await store.doPing();
}
</script>

<template>
  <section class="fx-placeholder">
    <header class="fx-placeholder__header">
      <h3 class="fx-placeholder__title">{{ moduleName }}</h3>
      <StatusPill variant="info" :label="`Coming in ${phaseLabel}`" />
    </header>

    <p v-if="description" class="fx-placeholder__description">
      {{ description }}
    </p>

    <div class="fx-placeholder__ping">
      <button
        type="button"
        class="fx-placeholder__button"
        :disabled="store.ping.inFlight"
        @click="onPing"
      >
        {{ store.ping.inFlight ? 'Pinging…' : 'Ping backend' }}
      </button>
      <p
        class="fx-placeholder__ping-status"
        aria-live="polite"
        aria-atomic="true"
      >
        <template v-if="store.ping.lastPingError">
          <span class="fx-placeholder__ping-error">
            {{ store.ping.lastPingError }}
          </span>
        </template>
        <template v-else>
          {{ pongLabel }}
        </template>
      </p>
    </div>

    <p class="fx-placeholder__meta">
      <span class="fx-visually-hidden">Module identifier: </span>
      <code>{{ props.moduleId }}</code>
    </p>
  </section>
</template>

<style scoped>
.fx-placeholder {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-4);
  padding: var(--fx-space-5);
  background: var(--fx-color-surface);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
  box-shadow: var(--fx-shadow-sm);
}

.fx-placeholder__header {
  display: flex;
  align-items: center;
  gap: var(--fx-space-3);
  flex-wrap: wrap;
}

.fx-placeholder__title {
  margin: 0;
  font-family: var(--fx-font-heading);
  font-size: var(--fx-font-size-xl);
  font-weight: var(--fx-font-weight-medium);
}

.fx-placeholder__description {
  margin: 0;
  color: var(--fx-color-text-muted);
  max-width: 62ch;
}

.fx-placeholder__ping {
  display: flex;
  align-items: center;
  gap: var(--fx-space-3);
  flex-wrap: wrap;
  padding: var(--fx-space-3) var(--fx-space-4);
  background: var(--fx-color-canvas);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
}

/* Primary: literal brand mint + black text, Lexend 500.
 * #000 on #00e298 measures ~13:1 (AAA). */
.fx-placeholder__button {
  padding: var(--fx-space-2) var(--fx-space-4);
  border: 1px solid var(--fx-color-button-primary);
  border-radius: var(--fx-radius-md);
  background: var(--fx-color-button-primary);
  color: var(--fx-color-button-primary-text);
  font-family: var(--fx-font-heading);
  font-weight: var(--fx-font-weight-medium);
  cursor: pointer;
  transition:
    background var(--fx-transition-fast),
    border-color var(--fx-transition-fast),
    box-shadow var(--fx-transition-fast);
}

.fx-placeholder__button:hover:not(:disabled) {
  background: var(--fx-color-button-primary-hover);
  border-color: var(--fx-color-button-primary-hover);
  box-shadow: var(--fx-shadow-glow);
}

.fx-placeholder__button:active:not(:disabled) {
  background: var(--fx-color-button-primary-active);
  border-color: var(--fx-color-button-primary-active);
}

.fx-placeholder__button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.fx-placeholder__ping-status {
  margin: 0;
  font-size: var(--fx-font-size-sm);
  color: var(--fx-color-text-muted);
}

.fx-placeholder__ping-error {
  color: var(--fx-color-critical);
}

.fx-placeholder__meta {
  margin: 0;
  font-size: var(--fx-font-size-sm);
  color: var(--fx-color-text-dim);
}

.fx-placeholder__meta code {
  font-family: var(--fx-font-mono);
  background: var(--fx-color-elevated);
  padding: 2px var(--fx-space-1);
  border-radius: var(--fx-radius-sm);
  font-size: var(--fx-font-size-xs);
}
</style>
