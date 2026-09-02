<script setup lang="ts">
import { computed, useId } from 'vue';
import { formatOmitted } from '../format';

/**
 * CheckMetaList — a labelled list of item names pulled out of a check's `meta`.
 *
 *   Unused themes
 *     · Twenty Twenty-One
 *     · Foo, Inc. Starter
 *     · and 5 more
 *
 * Deliberately knows nothing about plugins, themes or severity thresholds: it
 * takes a label, an array and an omitted count, which is everything all three
 * of the current lists need and everything a Phase 3 check is likely to need
 * too. The mapping from check id to (label, meta keys) lives in `format.ts`.
 *
 * The items arrive as an array and are rendered one per `<li>`. They are never
 * joined and never split — names legitimately contain commas, which is the
 * whole reason the wire format is a list rather than a sentence.
 *
 * Accessibility:
 *   - The `<ul>` is named by its own heading via `aria-labelledby`, so a
 *     screen reader announces "Critical, list, 3 items" rather than an
 *     anonymous list floating inside the disclosure region.
 *   - The truncation notice is the final `<li>` rather than a sibling
 *     paragraph, so it cannot be skipped by list-wise navigation — the point
 *     of the notice is that the list is short *and says so*.
 *   - `tone` only tints; the label text already carries the severity word.
 */

const props = withDefaults(
  defineProps<{
    /** Heading for this list. */
    label: string;
    /** Item names, in the order PHP sent them. */
    items: readonly string[];
    /** How many items the server dropped past its cap. */
    omitted?: number;
    /** Severity tint. Never the sole carrier of meaning. */
    tone?: 'warn' | 'critical' | null;
  }>(),
  { omitted: 0, tone: null },
);

const uid = useId();
const labelId = `fx-eh-list-${uid}-label`;

/** `''` when nothing was dropped, which suppresses the notice entirely. */
const omittedLabel = computed<string>(() => formatOmitted(props.omitted));
</script>

<template>
  <!--
    An empty `items` renders nothing at all — no container, no stray heading.
    An empty array is a real answer from the server ("none to show"), and it
    must look identical to the key being absent.
  -->
  <div
    v-if="items.length > 0"
    class="fx-eh-list"
    :class="tone ? `fx-eh-list--${tone}` : undefined"
  >
    <p :id="labelId" class="fx-eh-list__label">{{ label }}</p>
    <ul class="fx-eh-list__items" :aria-labelledby="labelId">
      <!--
        Keyed by index, not by name: two plugins can genuinely share a display
        name, and a duplicate key would drop one of them from the DOM.
      -->
      <li
        v-for="(item, index) in items"
        :key="`${labelId}-${String(index)}`"
        class="fx-eh-list__item"
      >
        {{ item }}
      </li>
      <li v-if="omittedLabel" class="fx-eh-list__more">{{ omittedLabel }}</li>
    </ul>
  </div>
</template>

<style scoped>
.fx-eh-list {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-1);
}

.fx-eh-list__label {
  margin: 0;
  font-size: var(--fx-font-size-xs);
  font-weight: var(--fx-font-weight-medium);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--fx-color-text-muted);
}

/* Tint only. The label text says "Warning" / "Critical" on its own, so this
   reinforces the grouping for sighted users without becoming the signal. No
   left-edge rule: the row's status already owns the full-border treatment. */
.fx-eh-list--warn .fx-eh-list__label {
  color: var(--fx-color-warn);
}

.fx-eh-list--critical .fx-eh-list__label {
  color: var(--fx-color-critical);
}

/* Deliberately not a flex container: `display: flex` blockifies the children
   and browsers stop painting `::marker` on them, which would silently turn the
   bulleted list into an unindented stack of lines. Plain block flow keeps the
   markers and costs nothing — the gap is one adjacent-sibling margin. */
.fx-eh-list__items {
  margin: 0;
  /* Markers hang in this padding (list-style-position: outside), so every
     item's text — bulleted or not — starts at the same x. */
  padding: 0 0 0 var(--fx-space-4);
  list-style: disc;
  max-width: 62ch;
}

.fx-eh-list__item,
.fx-eh-list__more {
  color: var(--fx-color-text);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-snug);
  /* Plugin names are arbitrary strings and can be long and unbroken. */
  overflow-wrap: anywhere;
}

.fx-eh-list__item + .fx-eh-list__item,
.fx-eh-list__item + .fx-eh-list__more {
  margin-top: var(--fx-space-1);
}

.fx-eh-list__more {
  color: var(--fx-color-text-muted);
  font-style: italic;
  /* No marker: it is a notice about the list, not another member of it. */
  list-style: none;
}
</style>
