<script setup lang="ts">
import { computed } from 'vue';
import { Select } from '@/components';
import type { SelectOption } from '@/components';
import type { XmlRpcMode } from '../types';

/**
 * XmlRpcControl — mode selector plus a conditional IP allowlist textarea
 * that only appears when `mode === 'restrict_ips'`.
 *
 * Validation is best-effort on the client (we draw red underline on lines
 * that don't look like an IP) — the authoritative pass happens server-side
 * via `sanitizer_callback` + `FILTER_VALIDATE_IP`, so a user can type any
 * text here and it will be cleaned on save.
 */

interface Props {
  mode: XmlRpcMode;
  /** Array of allowed IP strings (IPv4 or IPv6). */
  allowedIps: string[];
  disabled?: boolean;
  /**
   * Id of the external element that labels the mode select. Forwarded from
   * ChecklistItem's default-slot scoped prop so the underlying <select>
   * borrows the row label as its accessible name instead of rendering its
   * own (which would duplicate the row label visually).
   */
  ariaLabelledby?: string;
}

const props = withDefaults(defineProps<Props>(), {
  disabled: false,
  ariaLabelledby: undefined,
});

const emit = defineEmits<{
  'update:mode': [value: XmlRpcMode];
  'update:allowedIps': [value: string[]];
}>();

const modeOptions: SelectOption[] = [
  { value: 'disabled', label: 'Fully disable XML-RPC (recommended)' },
  {
    value: 'restrict_methods',
    label: 'Block dangerous methods (pingback.ping, system.multicall)',
  },
  {
    value: 'restrict_ips',
    label: 'Allow only specific IP addresses (legacy Jetpack)',
  },
  { value: 'off', label: 'Do nothing (leave WP defaults)' },
];

const modeValue = computed<string>({
  get: () => props.mode,
  set: (value: string) => {
    if (
      value === 'disabled' ||
      value === 'restrict_methods' ||
      value === 'restrict_ips' ||
      value === 'off'
    ) {
      emit('update:mode', value);
    }
  },
});

/**
 * Textarea binding — exposes the `string[]` as newline-delimited text so a
 * user can paste a list and trust whitespace will be trimmed. Empty lines
 * are dropped on emit so the persisted payload stays tidy.
 */
const allowedIpsText = computed<string>({
  get: () => props.allowedIps.join('\n'),
  set: (value: string) => {
    const lines = value
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter((line) => line.length > 0);
    emit('update:allowedIps', lines);
  },
});

/** Very loose client-side shape check — server sanitizes authoritatively. */
const IP_V4 =
  /^(25[0-5]|2[0-4]\d|[01]?\d\d?)(\.(25[0-5]|2[0-4]\d|[01]?\d\d?)){3}$/;
const IP_V6 = /^[0-9a-f:]+$/i;

function looksLikeIp(value: string): boolean {
  if (IP_V4.test(value)) return true;
  if (value.includes(':') && IP_V6.test(value)) return true;
  return false;
}

const invalidIps = computed<string[]>(() =>
  props.allowedIps.filter((ip) => ip.length > 0 && !looksLikeIp(ip)),
);

const showIpInput = computed<boolean>(() => props.mode === 'restrict_ips');
</script>

<template>
  <div class="fx-xmlrpc">
    <!--
      Label + help are rendered by the surrounding ChecklistItem wrapper.
      `hide-label` drops the <label> element, `hide-help` suppresses the
      help paragraph, and `aria-labelledby` points the <select>'s
      accessible name at the row's title so the control is still named.
    -->
    <Select
      v-model="modeValue"
      label="XML-RPC mode"
      :options="modeOptions"
      :disabled="disabled"
      hide-label
      hide-help
      :aria-labelledby="ariaLabelledby"
    />

    <div v-if="showIpInput" class="fx-xmlrpc__ips">
      <label for="fx-xmlrpc-ips" class="fx-xmlrpc__label">
        Allowed IP addresses
      </label>
      <p id="fx-xmlrpc-ips-help" class="fx-xmlrpc__help">
        One IPv4 or IPv6 address per line. Invalid entries are dropped on save.
      </p>
      <textarea
        id="fx-xmlrpc-ips"
        v-model="allowedIpsText"
        class="fx-xmlrpc__textarea"
        :class="{ 'fx-xmlrpc__textarea--invalid': invalidIps.length > 0 }"
        rows="5"
        spellcheck="false"
        autocomplete="off"
        aria-describedby="fx-xmlrpc-ips-help"
        :aria-invalid="invalidIps.length > 0 || undefined"
        :disabled="disabled"
      ></textarea>
      <p v-if="invalidIps.length > 0" class="fx-xmlrpc__error" role="alert">
        <!-- eslint-disable-next-line vue/no-v-html -->
        {{ invalidIps.length }} line{{ invalidIps.length === 1 ? '' : 's' }}
        may not be valid IP addresses and will be dropped on save.
      </p>
    </div>
  </div>
</template>

<style scoped>
.fx-xmlrpc {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-3);
  width: 100%;
  max-width: 28rem;
}

.fx-xmlrpc__ips {
  display: flex;
  flex-direction: column;
  gap: var(--fx-space-2);
}

.fx-xmlrpc__label {
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-text);
  font-size: var(--fx-font-size-md);
}

.fx-xmlrpc__help {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
}

.fx-xmlrpc__textarea {
  padding: var(--fx-space-2) var(--fx-space-3);
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-md);
  background: var(--fx-color-surface);
  color: var(--fx-color-text);
  font-family: var(--fx-font-mono);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-normal);
  resize: vertical;
  min-height: 6rem;
  transition:
    border-color var(--fx-transition-fast),
    box-shadow var(--fx-transition-fast);
}

.fx-xmlrpc__textarea:focus,
.fx-xmlrpc__textarea:focus-visible {
  outline: none;
  border-color: var(--fx-color-primary-strong);
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-xmlrpc__textarea--invalid {
  border-color: var(--fx-color-warn);
}

.fx-xmlrpc__error {
  margin: 0;
  font-size: var(--fx-font-size-sm);
  color: var(--fx-color-warn);
}
</style>
