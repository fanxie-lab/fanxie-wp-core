<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { ChevronDown, ExternalLink } from 'lucide-vue-next';
import { CodeSnippet, StatusPill } from '@/components';
import CheckMetaList from './CheckMetaList.vue';
import { buildMetaLists, isSafeUrl, statusPill } from '../format';
import type { HealthCheck, Remediation } from '../types';

/**
 * CheckRow — one HealthCheck rendered as a disclosure row.
 *
 *   [pill]  PHP version                                     8.2.14
 *           Running a supported PHP release.
 *           [ Details ▾ ]
 *           └─ detail paragraph + affected items + copy-paste-ready remediation
 *
 * Why this is not the Hardening `ChecklistItem`: that row is control-first
 * (a Toggle owns the left column) and deliberately suppresses its pill for the
 * active/inactive states, because the toggle position already tells that story.
 * Here there is no control at all, the pill is the primary signal and must
 * render for every status, and the row owns expand/collapse state. Same idiom,
 * different component. The genuinely shared piece — the copy-to-clipboard code
 * block — was promoted to `@/components/CodeSnippet.vue` instead.
 *
 * Accessibility:
 *   - The pill carries text and an icon; colour is never the only signal.
 *   - The trigger exposes `aria-expanded` and `aria-controls`; the region is
 *     hidden with `v-show` (not `v-if`) so `aria-controls` always resolves to a
 *     real element, and is labelled by the row's own label.
 */

const props = defineProps<{ check: HealthCheck }>();

const emit = defineEmits<{
  /** Re-raised from CodeSnippet so the module root can toast the failure. */
  'copy-failed': [message: string];
  /** Fired on every expand/collapse with the new state. */
  toggle: [expanded: boolean];
}>();

const uid = useId();
const labelId = `fx-eh-check-${uid}-label`;
const regionId = `fx-eh-check-${uid}-detail`;

const expanded = ref(false);

const pill = computed(() => statusPill(props.check.status));

/** Only http(s) links survive; anything else is dropped at the point of output. */
const remediations = computed<Remediation[]>(() =>
  (props.check.remediation ?? []).filter((item) => {
    if (item.kind === 'snippet') return Boolean(item.code);
    return typeof item.url === 'string' && isSafeUrl(item.url);
  }),
);

/**
 * Named lists lifted out of `meta` — the inactive plugins, the unused themes,
 * the abandoned plugins split by severity.
 *
 * PHP names items inline only while there are three or fewer of them and falls
 * back to a bare count above that, so for any list worth reading this region
 * is the only place the names appear at all. Malformed or absent `meta`
 * degrades to no lists rather than an error (see `buildMetaLists`).
 */
const metaLists = computed(() => buildMetaLists(props.check));

const hasDetail = computed<boolean>(
  () =>
    Boolean(props.check.detail) ||
    metaLists.value.length > 0 ||
    remediations.value.length > 0,
);

function toggleDetail(): void {
  expanded.value = !expanded.value;
  emit('toggle', expanded.value);
}

function onCopyFailed(message: string): void {
  emit('copy-failed', message);
}
</script>

<template>
  <li class="fx-eh-row" :class="`fx-eh-row--${check.status}`">
    <div class="fx-eh-row__main">
      <StatusPill
        class="fx-eh-row__pill"
        :variant="pill.variant"
        :label="pill.label"
        :sr-prefix="pill.srPrefix"
      />

      <div class="fx-eh-row__body">
        <div class="fx-eh-row__head">
          <span :id="labelId" class="fx-eh-row__label">{{ check.label }}</span>
          <span v-if="check.value" class="fx-eh-row__value">
            <span class="fx-visually-hidden">Current value: </span
            >{{ check.value }}
          </span>
        </div>
        <p class="fx-eh-row__summary">{{ check.summary }}</p>
      </div>

      <button
        v-if="hasDetail"
        type="button"
        class="fx-eh-row__toggle"
        :aria-expanded="expanded"
        :aria-controls="regionId"
        @click="toggleDetail"
      >
        <!--
          Visible "Details" plus a hidden row qualifier: the accessible name
          becomes "Details for PHP version", which still literally contains the
          visible label (WCAG 2.5.3 Label in Name) while staying unambiguous in
          a list of a dozen identical buttons. Expanded state is carried by
          aria-expanded, so the name deliberately does not say Show/Hide.
        -->
        <span>Details</span>
        <span class="fx-visually-hidden"> for {{ check.label }}</span>
        <ChevronDown
          class="fx-eh-row__chevron"
          :class="{ 'fx-eh-row__chevron--open': expanded }"
          :size="16"
          :stroke-width="1.75"
          aria-hidden="true"
          focusable="false"
        />
      </button>
    </div>

    <!--
      Rendered unconditionally and toggled with v-show so `aria-controls` on the
      trigger always points at an element that exists. `display: none` keeps it
      out of the accessibility tree and out of the tab order while collapsed.
    -->
    <div
      v-show="expanded"
      :id="regionId"
      role="group"
      :aria-labelledby="labelId"
      class="fx-eh-row__detail"
    >
      <p v-if="check.detail" class="fx-eh-row__detail-text">
        {{ check.detail }}
      </p>

      <!--
        Between the explanation and the fix: "here is why it matters", "here is
        what it applies to", "here is what to do". Each list is a plain
        (label, items, omitted) triple, so a Phase 3 check that reports names
        gets rendered here with no change to this component.
      -->
      <div v-if="metaLists.length" class="fx-eh-row__lists">
        <CheckMetaList
          v-for="list in metaLists"
          :key="list.key"
          :label="list.label"
          :items="list.items"
          :omitted="list.omitted"
          :tone="list.tone"
        />
      </div>

      <div v-if="remediations.length" class="fx-eh-row__remediation">
        <p class="fx-eh-row__remediation-title">Recommended action</p>
        <template v-for="(item, index) in remediations" :key="`rem-${index}`">
          <CodeSnippet
            v-if="item.kind === 'snippet'"
            :code="item.code ?? ''"
            :language="item.language"
            :context="check.label"
            @copy-failed="onCopyFailed"
          />
          <p v-else class="fx-eh-row__link-wrap">
            <a
              class="fx-eh-row__link"
              :href="item.url"
              target="_blank"
              rel="noopener noreferrer"
            >
              {{ item.label ?? item.url }}
              <ExternalLink
                :size="14"
                :stroke-width="1.75"
                aria-hidden="true"
                focusable="false"
              />
              <span class="fx-visually-hidden"> (opens in a new tab)</span>
            </a>
          </p>
        </template>
      </div>
    </div>
  </li>
</template>

<style scoped>
.fx-eh-row {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
  padding: var(--fx-space-3) var(--fx-space-4);
  background: var(--fx-color-surface);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
}

/* Status tint matches the house pattern set by CheckGroupCard and Hardening's
   ChecklistItem: the whole border carries it, and only the states that need
   attention are tinted. An `ok` row stays neutral — tinting the common case
   just adds noise, and the pill already carries the status as text + icon. */
.fx-eh-row--warning {
  border-color: var(--fx-color-warn);
}

.fx-eh-row--critical {
  border-color: var(--fx-color-critical);
}

/* `unknown` reads as "no answer": a dashed neutral border, distinct at a
   glance from both a solid neutral ok row and a solid red critical one. */
.fx-eh-row--unknown {
  border-color: var(--fx-color-border-strong);
  border-style: dashed;
}

.fx-eh-row__main {
  display: flex;
  align-items: flex-start;
  gap: var(--fx-space-3);
}

.fx-eh-row__pill {
  flex: 0 0 auto;
  /* Optically align the pill with the label's cap height. */
  margin-top: 0.1rem;
}

.fx-eh-row__body {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-1);
}

.fx-eh-row__head {
  display: flex;
  align-items: baseline;
  gap: var(--fx-space-3);
  flex-wrap: wrap;
}

.fx-eh-row__label {
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-text);
  font-size: var(--fx-font-size-md);
  line-height: var(--fx-line-height-snug);
}

.fx-eh-row__value {
  font-family: var(--fx-font-mono);
  font-size: var(--fx-font-size-sm);
  color: var(--fx-color-text-muted);
  overflow-wrap: anywhere;
}

.fx-eh-row__summary {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-snug);
  max-width: 62ch;
}

.fx-eh-row__toggle {
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  gap: var(--fx-space-1);
  padding: var(--fx-space-1) var(--fx-space-2);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  background: var(--fx-color-surface);
  color: var(--fx-color-text-muted);
  font-family: var(--fx-font-body);
  font-size: var(--fx-font-size-sm);
  cursor: pointer;
  transition:
    border-color var(--fx-transition-fast),
    color var(--fx-transition-fast);
}

.fx-eh-row__toggle:hover {
  border-color: var(--fx-color-border-strong);
  color: var(--fx-color-text);
}

.fx-eh-row__toggle:focus,
.fx-eh-row__toggle:focus-visible {
  outline: none;
  border-color: var(--fx-color-primary-strong);
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-eh-row__chevron {
  transition: transform var(--fx-transition-fast);
}

.fx-eh-row__chevron--open {
  transform: rotate(180deg);
}

@media (prefers-reduced-motion: reduce) {
  .fx-eh-row__chevron {
    transition: none;
  }
}

.fx-eh-row__detail {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-3);
  padding-top: var(--fx-space-3);
  border-top: 1px dashed var(--fx-color-border);
}

.fx-eh-row__detail-text {
  margin: 0;
  color: var(--fx-color-text);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-normal);
  max-width: 68ch;
}

/* Two lists (abandoned plugins' critical + warning groups) sit as separate
   labelled blocks rather than one merged list — the split *is* the finding. */
.fx-eh-row__lists {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-3);
}

.fx-eh-row__remediation {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
}

.fx-eh-row__remediation-title {
  margin: 0;
  font-size: var(--fx-font-size-xs);
  font-weight: var(--fx-font-weight-medium);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--fx-color-text-muted);
}

.fx-eh-row__link-wrap {
  margin: 0;
}

.fx-eh-row__link {
  display: inline-flex;
  align-items: center;
  gap: var(--fx-space-1);
  color: var(--fx-color-primary-strong);
  font-size: var(--fx-font-size-sm);
}

.fx-eh-row__link:focus,
.fx-eh-row__link:focus-visible {
  outline: 2px solid var(--fx-color-primary-strong);
  outline-offset: 2px;
  border-radius: var(--fx-radius-sm);
}

@media (max-width: 720px) {
  .fx-eh-row__main {
    flex-wrap: wrap;
  }

  .fx-eh-row__toggle {
    order: 3;
    margin-left: auto;
  }
}
</style>
