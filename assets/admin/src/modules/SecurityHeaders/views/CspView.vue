<script setup lang="ts">
import { computed, ref } from 'vue';
import { Plus, Trash2 } from 'lucide-vue-next';
import { Toggle, Select, SaveBar } from '@/components';
import type { SelectOption } from '@/components';
import { useSecurityHeadersStore } from '../stores/securityHeaders';
import { useSecurityHeadersConfig } from '../composables/useSecurityHeadersConfig';
import { PRESETS, type CspMode } from '../types';

/**
 * CspView — CSP mode selector, directive builder, preset picker, and
 * read-only report URI display.
 */

const store = useSecurityHeadersStore();
const { config, isDirty, isSaving, save, reset } = useSecurityHeadersConfig();

const modeOptions: SelectOption[] = [
  { value: 'off', label: 'Off (no CSP header)' },
  {
    value: 'report-only',
    label: 'Report-Only (violations logged, nothing blocked)',
  },
  { value: 'enforce', label: 'Enforce (violations logged and blocked)' },
];

/** Directive rows as an array so v-for can stay stable across renders. */
interface DirectiveRow {
  name: string;
  sources: string[];
}
const directiveRows = computed<DirectiveRow[]>(() => {
  if (!config.value) return [];
  return Object.entries(config.value.csp.directives).map(([name, sources]) => ({
    name,
    sources,
  }));
});

const learningDisabled = computed<boolean>(() =>
  config.value ? config.value.csp.mode === 'off' : true,
);

const reportEndpoint = computed<string>(
  () => store.status?.report_endpoint ?? '',
);

const saveStatus = computed<'idle' | 'saving' | 'saved' | 'error'>(() => {
  if (store.loading.saving) return 'saving';
  if (store.error) return 'error';
  return 'idle';
});

/** New-directive form state — only visible when "Add directive" is clicked. */
const newDirectiveName = ref<string>('');
const showAddDirective = ref<boolean>(false);

function beginAddDirective(): void {
  showAddDirective.value = true;
  newDirectiveName.value = '';
}

function commitAddDirective(): void {
  if (!config.value) return;
  const key = newDirectiveName.value.trim().toLowerCase();
  if (!key) return;
  // CSP directive names are lowercase ASCII + hyphen (per spec). Light
  // sanitisation — server performs the authoritative check.
  if (!/^[a-z0-9-]+$/.test(key)) return;
  if (config.value.csp.directives[key]) return;
  config.value.csp.directives[key] = [];
  showAddDirective.value = false;
  newDirectiveName.value = '';
}

function cancelAddDirective(): void {
  showAddDirective.value = false;
  newDirectiveName.value = '';
}

function removeDirective(name: string): void {
  if (!config.value) return;
  const { [name]: _removed, ...rest } = config.value.csp.directives;
  void _removed;
  config.value.csp.directives = rest;
}

function addSource(directive: string): void {
  if (!config.value) return;
  const list = config.value.csp.directives[directive];
  if (list) list.push('');
}

function updateSource(directive: string, index: number, value: string): void {
  if (!config.value) return;
  const list = config.value.csp.directives[directive];
  if (list && index >= 0 && index < list.length) {
    list[index] = value;
  }
}

function removeSource(directive: string, index: number): void {
  if (!config.value) return;
  const list = config.value.csp.directives[directive];
  if (list && index >= 0 && index < list.length) {
    list.splice(index, 1);
  }
}

async function applyPreset(id: string): Promise<void> {
  await store.applyPreset(id);
}

function onModeChange(value: string): void {
  if (!config.value) return;
  if (value === 'off' || value === 'report-only' || value === 'enforce') {
    config.value.csp.mode = value as CspMode;
  }
}
</script>

<template>
  <div v-if="config" class="fx-csp-view">
    <section class="fx-csp-view__section">
      <header class="fx-csp-view__section-header">
        <h3 class="fx-csp-view__section-title">Mode</h3>
        <p class="fx-csp-view__section-hint">
          Report-Only is the safest starting point — violations are recorded
          without blocking anything on the site.
        </p>
      </header>
      <Select
        :model-value="config.csp.mode"
        label="CSP mode"
        :options="modeOptions"
        @update:model-value="onModeChange"
      />
      <Toggle
        v-model="config.csp.learning_mode"
        label="Learning mode"
        description="In Enforce mode, also emit the Report-Only header so you can safely audit changes before removing it."
        :disabled="learningDisabled"
      />
    </section>

    <section class="fx-csp-view__section">
      <header class="fx-csp-view__section-header">
        <h3 class="fx-csp-view__section-title">Directives</h3>
        <p class="fx-csp-view__section-hint">
          Directives tell the browser where resources may be loaded from.
        </p>
      </header>

      <ul v-if="directiveRows.length > 0" class="fx-csp-view__directive-list">
        <li
          v-for="row in directiveRows"
          :key="row.name"
          class="fx-csp-view__directive"
        >
          <div class="fx-csp-view__directive-header">
            <code class="fx-csp-view__directive-name">{{ row.name }}</code>
            <button
              type="button"
              class="fx-csp-view__icon-button"
              :aria-label="`Remove directive ${row.name}`"
              @click="removeDirective(row.name)"
            >
              <Trash2 :size="16" aria-hidden="true" focusable="false" />
            </button>
          </div>

          <ul class="fx-csp-view__source-list">
            <li
              v-for="(source, sourceIdx) in row.sources"
              :key="`${row.name}-${String(sourceIdx)}`"
              class="fx-csp-view__source"
            >
              <input
                type="text"
                class="fx-csp-view__source-input"
                :value="source"
                :aria-label="`Source ${String(sourceIdx + 1)} for ${row.name}`"
                placeholder="e.g. 'self', https://example.com"
                @input="
                  updateSource(
                    row.name,
                    sourceIdx,
                    ($event.target as HTMLInputElement).value,
                  )
                "
              />
              <button
                type="button"
                class="fx-csp-view__icon-button"
                :aria-label="`Remove source ${String(sourceIdx + 1)}`"
                @click="removeSource(row.name, sourceIdx)"
              >
                <Trash2 :size="14" aria-hidden="true" focusable="false" />
              </button>
            </li>
          </ul>

          <button
            type="button"
            class="fx-csp-view__button fx-csp-view__button--ghost"
            @click="addSource(row.name)"
          >
            <Plus :size="14" aria-hidden="true" focusable="false" />
            Add source
          </button>
        </li>
      </ul>
      <p v-else class="fx-csp-view__empty">
        No directives yet. Apply a preset below or add one manually.
      </p>

      <div v-if="showAddDirective" class="fx-csp-view__add-directive">
        <label class="fx-csp-view__add-label" for="fx-csp-new-directive">
          New directive name
        </label>
        <input
          id="fx-csp-new-directive"
          v-model="newDirectiveName"
          type="text"
          class="fx-csp-view__source-input"
          placeholder="e.g. script-src"
          @keydown.enter.prevent="commitAddDirective"
          @keydown.escape.prevent="cancelAddDirective"
        />
        <div class="fx-csp-view__add-actions">
          <button
            type="button"
            class="fx-csp-view__button fx-csp-view__button--ghost"
            @click="cancelAddDirective"
          >
            Cancel
          </button>
          <button
            type="button"
            class="fx-csp-view__button fx-csp-view__button--primary"
            :disabled="newDirectiveName.trim().length === 0"
            @click="commitAddDirective"
          >
            Add directive
          </button>
        </div>
      </div>
      <button
        v-else
        type="button"
        class="fx-csp-view__button fx-csp-view__button--ghost"
        @click="beginAddDirective"
      >
        <Plus :size="14" aria-hidden="true" focusable="false" />
        Add directive
      </button>
    </section>

    <section class="fx-csp-view__section">
      <header class="fx-csp-view__section-header">
        <h3 class="fx-csp-view__section-title">Presets</h3>
        <p class="fx-csp-view__section-hint">
          Apply a preset to merge common sources into your policy. Your existing
          directives are preserved.
        </p>
      </header>
      <ul class="fx-csp-view__preset-list">
        <li
          v-for="preset in PRESETS"
          :key="preset.id"
          class="fx-csp-view__preset"
        >
          <div class="fx-csp-view__preset-body">
            <p class="fx-csp-view__preset-label">{{ preset.label }}</p>
            <p class="fx-csp-view__preset-description">
              {{ preset.description }}
            </p>
          </div>
          <button
            type="button"
            class="fx-csp-view__button fx-csp-view__button--ghost"
            :disabled="isSaving"
            @click="applyPreset(preset.id)"
          >
            Apply
          </button>
        </li>
      </ul>
    </section>

    <section class="fx-csp-view__section">
      <header class="fx-csp-view__section-header">
        <h3 class="fx-csp-view__section-title">Report URI</h3>
        <p class="fx-csp-view__section-hint">
          Browsers POST CSP violation reports here. The endpoint is
          automatically configured — copy it for use with external tooling if
          needed.
        </p>
      </header>
      <code class="fx-csp-view__report-uri">{{ reportEndpoint }}</code>
    </section>

    <SaveBar
      :dirty="isDirty"
      :status="saveStatus"
      :disabled="isSaving"
      @save="save"
      @reset="reset"
    />
  </div>
  <p v-else class="fx-csp-view__loading" role="status">
    Loading CSP configuration…
  </p>
</template>

<style scoped>
.fx-csp-view {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-5);
}

.fx-csp-view__section {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-3);
  padding: var(--fx-space-5);
  background: var(--fx-color-surface);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
  box-shadow: var(--fx-shadow-sm);
}

.fx-csp-view__section-header {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-1);
}

.fx-csp-view__section-title {
  margin: 0;
  font-family: var(--fx-font-heading);
  font-size: var(--fx-font-size-xl);
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-text);
}

.fx-csp-view__section-hint {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
  max-width: 62ch;
}

.fx-csp-view__directive-list,
.fx-csp-view__source-list,
.fx-csp-view__preset-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-3);
}

.fx-csp-view__directive {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
  padding: var(--fx-space-3);
  background: var(--fx-color-canvas);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
}

.fx-csp-view__directive-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--fx-space-2);
}

.fx-csp-view__directive-name {
  font-family: var(--fx-font-mono);
  font-size: var(--fx-font-size-md);
  background: var(--fx-color-elevated);
  padding: 2px var(--fx-space-2);
  border-radius: var(--fx-radius-sm);
  color: var(--fx-color-text);
}

.fx-csp-view__source-list {
  gap: var(--fx-space-2);
}

.fx-csp-view__source {
  display: flex;
  align-items: center;
  gap: var(--fx-space-2);
}

.fx-csp-view__source-input {
  flex: 1;
  padding: var(--fx-space-2) var(--fx-space-3);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  background: var(--fx-color-surface);
  color: var(--fx-color-text);
  font-family: var(--fx-font-mono);
  font-size: var(--fx-font-size-sm);
}

.fx-csp-view__source-input:focus,
.fx-csp-view__source-input:focus-visible {
  outline: none;
  border-color: var(--fx-color-primary-strong);
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-csp-view__icon-button {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.75rem;
  height: 1.75rem;
  padding: 0;
  border: 1px solid transparent;
  border-radius: var(--fx-radius-sm);
  background: transparent;
  color: var(--fx-color-text-muted);
  cursor: pointer;
  transition:
    background var(--fx-transition-fast),
    color var(--fx-transition-fast);
}

.fx-csp-view__icon-button:hover {
  background: var(--fx-color-critical-bg);
  color: var(--fx-color-critical);
}

.fx-csp-view__button {
  display: inline-flex;
  align-items: center;
  gap: var(--fx-space-1);
  padding: var(--fx-space-2) var(--fx-space-3);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  background: transparent;
  color: var(--fx-color-text);
  font-family: var(--fx-font-body);
  font-size: var(--fx-font-size-sm);
  font-weight: var(--fx-font-weight-medium);
  cursor: pointer;
  align-self: flex-start;
  transition:
    background var(--fx-transition-fast),
    border-color var(--fx-transition-fast);
}

.fx-csp-view__button:hover:not(:disabled) {
  background: var(--fx-color-elevated);
  border-color: var(--fx-color-border-strong);
}

.fx-csp-view__button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.fx-csp-view__button--primary {
  background: var(--fx-color-button-primary);
  border-color: var(--fx-color-button-primary);
  color: var(--fx-color-button-primary-text);
  font-family: var(--fx-font-heading);
}

.fx-csp-view__button--primary:hover:not(:disabled) {
  background: var(--fx-color-button-primary-hover);
  border-color: var(--fx-color-button-primary-hover);
}

.fx-csp-view__empty {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
}

.fx-csp-view__add-directive {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
  padding: var(--fx-space-3);
  background: var(--fx-color-canvas);
  border: 1px dashed var(--fx-color-border);
  border-radius: var(--fx-radius-md);
}

.fx-csp-view__add-label {
  font-weight: var(--fx-font-weight-medium);
  font-size: var(--fx-font-size-sm);
  color: var(--fx-color-text);
}

.fx-csp-view__add-actions {
  display: flex;
  gap: var(--fx-space-2);
}

.fx-csp-view__preset {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--fx-space-4);
  padding: var(--fx-space-3);
  background: var(--fx-color-canvas);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
}

.fx-csp-view__preset-body {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-1);
  min-width: 0;
}

.fx-csp-view__preset-label {
  margin: 0;
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-text);
}

.fx-csp-view__preset-description {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
}

.fx-csp-view__report-uri {
  display: block;
  padding: var(--fx-space-3);
  background: var(--fx-color-elevated);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  font-family: var(--fx-font-mono);
  font-size: var(--fx-font-size-sm);
  color: var(--fx-color-text);
  word-break: break-all;
}

.fx-csp-view__loading {
  margin: 0;
  padding: var(--fx-space-5);
  color: var(--fx-color-text-muted);
  background: var(--fx-color-surface);
  border: 1px dashed var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
}

@media (max-width: 720px) {
  .fx-csp-view__preset {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
