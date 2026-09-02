<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { Check, Copy } from 'lucide-vue-next';

/**
 * CodeSnippet — a copy-paste-ready code block with an accessible copy button.
 *
 * Promoted to `@/components` in Phase 2.2 (same route ConfirmDialog took in
 * Phase 1.3): Hardening already shipped an ad-hoc copy button, and Environment
 * Health needs the same affordance on every `remediation.kind === 'snippet'`.
 *
 * Accessibility notes — the reason this is a primitive rather than a per-module
 * one-off:
 *   - Confirmation is never colour- or icon-only. The button's visible text
 *     flips Copy → Copied AND a polite live region announces the result, so
 *     screen-reader and low-vision users get the same feedback sighted users do.
 *   - Failure is announced too (clipboard access is denied in some browsers and
 *     absent entirely outside secure contexts), and re-emitted as `copy-failed`
 *     so the host can raise a toast.
 *   - `code` is rendered through text interpolation, never `v-html`. Snippets
 *     arrive from PHP and must not be able to inject markup.
 */

interface Props {
  /** Snippet body. Rendered as text — never as markup. */
  code: string;
  /**
   * Optional language tag. Rendered as a badge and folded into the copy
   * button's accessible name (e.g. "Copy php snippet").
   */
  language?: string;
  /** Extra context for the copy button's accessible name, e.g. "wp-config.php". */
  context?: string;
  /** Set false to render a read-only block with no copy affordance. */
  copyable?: boolean;
  /** How long the "Copied" confirmation stays visible, in milliseconds. */
  confirmDuration?: number;
}

const props = withDefaults(defineProps<Props>(), {
  language: undefined,
  context: undefined,
  copyable: true,
  confirmDuration: 2500,
});

const emit = defineEmits<{
  copied: [];
  'copy-failed': [message: string];
}>();

const COPY_FAILED_MESSAGE =
  'Copy failed. Select the snippet and copy it manually.';
const COPY_OK_MESSAGE = 'Snippet copied to clipboard.';

const copied = ref(false);
/** Mirrored into the polite live region. Empty string announces nothing. */
const announcement = ref('');
let resetTimer: ReturnType<typeof setTimeout> | null = null;

/** The bar only earns its height when it has something to hold. */
const showBar = computed<boolean>(
  () => Boolean(props.language) || props.copyable,
);

const copyButtonLabel = computed<string>(() => {
  const parts = ['Copy'];
  if (props.language) parts.push(props.language);
  parts.push('snippet');
  if (props.context) parts.push(`for ${props.context}`);
  return parts.join(' ');
});

function clearResetTimer(): void {
  if (resetTimer !== null) {
    clearTimeout(resetTimer);
    resetTimer = null;
  }
}

function scheduleReset(): void {
  clearResetTimer();
  resetTimer = setTimeout(() => {
    copied.value = false;
    announcement.value = '';
    resetTimer = null;
  }, props.confirmDuration);
}

async function copy(): Promise<void> {
  try {
    // Throws a TypeError outside a secure context, where `navigator.clipboard`
    // is undefined — same catch path as a denied permission.
    await navigator.clipboard.writeText(props.code);
    copied.value = true;
    announcement.value = COPY_OK_MESSAGE;
    emit('copied');
  } catch {
    copied.value = false;
    announcement.value = COPY_FAILED_MESSAGE;
    emit('copy-failed', COPY_FAILED_MESSAGE);
  }
  scheduleReset();
}

function onCopyClick(): void {
  void copy();
}

onBeforeUnmount(clearResetTimer);
</script>

<template>
  <div class="fx-snippet">
    <div v-if="showBar" class="fx-snippet__bar">
      <span v-if="language" class="fx-snippet__lang">
        <span class="fx-visually-hidden">Language: </span>{{ language }}
      </span>
      <button
        v-if="copyable"
        type="button"
        class="fx-snippet__copy"
        :aria-label="copyButtonLabel"
        @click="onCopyClick"
      >
        <Check
          v-if="copied"
          :size="14"
          :stroke-width="1.75"
          aria-hidden="true"
          focusable="false"
        />
        <Copy
          v-else
          :size="14"
          :stroke-width="1.75"
          aria-hidden="true"
          focusable="false"
        />
        <span>{{ copied ? 'Copied' : 'Copy' }}</span>
      </button>
    </div>
    <pre class="fx-snippet__pre"><code>{{ code }}</code></pre>
    <!--
      Polite live region. Starts empty and is populated on copy so assistive
      tech announces the outcome; the visible Copy → Copied swap alone would
      be silent. Cleared again once the confirmation times out so a later copy
      of the same snippet re-announces.
    -->
    <span class="fx-visually-hidden" role="status" aria-live="polite">
      {{ announcement }}
    </span>
  </div>
</template>

<style scoped>
.fx-snippet {
  display: flex;
  flex-direction: column;
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  background: var(--fx-color-elevated);
  overflow: hidden;
}

.fx-snippet__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--fx-space-2);
  padding: var(--fx-space-1) var(--fx-space-2);
  border-bottom: 1px solid var(--fx-color-border);
  background: var(--fx-color-bg-subtle);
  min-height: 2rem;
}

.fx-snippet__lang {
  font-family: var(--fx-font-mono);
  font-size: var(--fx-font-size-xs);
  color: var(--fx-color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.fx-snippet__copy {
  display: inline-flex;
  align-items: center;
  gap: var(--fx-space-1);
  /* Pushed right even when no language badge occupies the start of the bar. */
  margin-left: auto;
  padding: var(--fx-space-1) var(--fx-space-2);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-sm);
  background: var(--fx-color-surface);
  color: var(--fx-color-text);
  font-family: var(--fx-font-body);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-tight);
  cursor: pointer;
  transition:
    border-color var(--fx-transition-fast),
    background var(--fx-transition-fast);
}

.fx-snippet__copy:hover {
  border-color: var(--fx-color-border-strong);
  background: var(--fx-color-elevated);
}

.fx-snippet__copy:focus,
.fx-snippet__copy:focus-visible {
  outline: none;
  border-color: var(--fx-color-primary-strong);
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-snippet__pre {
  margin: 0;
  padding: var(--fx-space-3);
  font-family: var(--fx-font-mono);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-snug);
  color: var(--fx-color-text);
  /* Wide snippets scroll inside the block instead of widening the page. */
  overflow-x: auto;
  white-space: pre;
  tab-size: 2;
}

.fx-snippet__pre code {
  font-family: inherit;
}
</style>
