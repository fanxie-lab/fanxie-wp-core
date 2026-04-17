// Barrel export for shared component primitives.
// Prefer named imports from '@/components' over deep paths.

export { default as Toggle } from './Toggle.vue';
export { default as TextField } from './TextField.vue';
export { default as Select } from './Select.vue';
export { default as SaveBar } from './SaveBar.vue';
export { default as StatusPill } from './StatusPill.vue';
export { default as Toast } from './Toast.vue';
export { default as PlaceholderTab } from './PlaceholderTab.vue';

export type { SaveStatus } from './SaveBar.vue';
export type { StatusPillVariant } from './StatusPill.vue';
export type { ToastVariant } from './Toast.vue';
export type { SelectOption, SelectOptionGroup } from './Select.vue';
