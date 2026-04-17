<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

export type ToastVariant = 'info' | 'success' | 'warning' | 'error';

interface Props {
  /** Visible + SR message. */
  message: string;
  /** Visual + semantic variant. */
  variant?: ToastVariant;
  /** Dismiss timeout in milliseconds. Use `Infinity` to disable auto-dismiss. */
  duration?: number;
  /**
   * Screen-reader politeness. 'assertive' interrupts, 'polite' waits for a
   * pause. Use 'assertive' sparingly (e.g., errors that block the user).
   */
  politeness?: 'polite' | 'assertive';
  /** When true, show a close button. */
  dismissible?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  variant: 'info',
  duration: 5000,
  politeness: 'polite',
  dismissible: true,
});

const emit = defineEmits<{
  dismiss: [];
}>();

const visible = ref(true);
let timer: ReturnType<typeof setTimeout> | null = null;

function clearTimer(): void {
  if (timer !== null) {
    clearTimeout(timer);
    timer = null;
  }
}

function scheduleDismiss(): void {
  clearTimer();
  if (!Number.isFinite(props.duration)) return;
  if (props.duration <= 0) return;
  timer = setTimeout(() => {
    dismiss();
  }, props.duration);
}

function dismiss(): void {
  if (!visible.value) return;
  visible.value = false;
  clearTimer();
  emit('dismiss');
}

onMounted(() => {
  scheduleDismiss();
});

onBeforeUnmount(() => {
  clearTimer();
});

// If the message changes while still visible, reset the dismiss timer so the
// user gets the full reading window for the new content.
watch(
  () => props.message,
  () => {
    if (visible.value) scheduleDismiss();
  },
);

const role = props.politeness === 'assertive' ? 'alert' : 'status';
</script>

<template>
  <Transition name="fx-toast">
    <div
      v-if="visible"
      class="fx-toast"
      :class="`fx-toast--${variant}`"
      :role="role"
      :aria-live="politeness"
      aria-atomic="true"
      @mouseenter="clearTimer"
      @mouseleave="scheduleDismiss"
      @focusin="clearTimer"
      @focusout="scheduleDismiss"
    >
      <span class="fx-toast__message">{{ message }}</span>
      <button
        v-if="dismissible"
        type="button"
        class="fx-toast__close"
        aria-label="Dismiss notification"
        @click="dismiss"
      >
        <svg
          viewBox="0 0 20 20"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          aria-hidden="true"
          focusable="false"
        >
          <path d="M6 6l8 8M14 6l-8 8" />
        </svg>
      </button>
    </div>
  </Transition>
</template>

<style scoped>
.fx-toast {
  display: inline-flex;
  align-items: center;
  gap: var(--fx-space-3);
  padding: var(--fx-space-3) var(--fx-space-4);
  border-radius: var(--fx-radius-md);
  box-shadow: var(--fx-shadow-lg);
  border: 1px solid transparent;
  font-size: var(--fx-font-size-md);
  max-width: 28rem;
}

.fx-toast--info {
  background: var(--fx-color-info-bg);
  color: var(--fx-color-info);
  border-color: var(--fx-color-info);
}

.fx-toast--success {
  background: var(--fx-color-ok-bg);
  color: var(--fx-color-ok);
  border-color: var(--fx-color-ok);
}

.fx-toast--warning {
  background: var(--fx-color-warn-bg);
  color: var(--fx-color-warn);
  border-color: var(--fx-color-warn);
}

.fx-toast--error {
  background: var(--fx-color-critical-bg);
  color: var(--fx-color-critical);
  border-color: var(--fx-color-critical);
}

.fx-toast__message {
  flex: 1;
}

.fx-toast__close {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.5rem;
  height: 1.5rem;
  padding: 0;
  border: none;
  border-radius: var(--fx-radius-sm);
  background: transparent;
  color: inherit;
  cursor: pointer;
}

.fx-toast__close:hover {
  background: rgba(0, 0, 0, 0.08);
}

.fx-toast__close svg {
  width: 0.875rem;
  height: 0.875rem;
}

.fx-toast-enter-active,
.fx-toast-leave-active {
  transition:
    opacity var(--fx-transition-base),
    transform var(--fx-transition-base);
}

.fx-toast-enter-from,
.fx-toast-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
