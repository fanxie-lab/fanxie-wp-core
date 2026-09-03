/*
 * Module + group registry for the admin SPA sidebar IA.
 *
 * Single source of truth for:
 *   - group order and labels
 *   - module order within each group
 *   - human-readable module metadata (label, phase, description)
 *   - per-module icon (Lucide) rendered in the sidebar
 *
 * Adding a new module later is a one-line addition to the `modules` array
 * plus a reference from the owning group's `moduleIds` list.
 */

import type { Component } from 'vue';
// Tree-shake friendly: import only the icons we use, not the whole library.
import {
  Activity,
  Database,
  FileCode2,
  KeyRound,
  Lock,
  ScrollText,
  ShieldCheck,
} from 'lucide-vue-next';

export interface ModuleDef {
  /** Stable id — must match the PHP module id and the Pinia module config key. */
  id: string;
  /** Human-readable label shown in the sidebar and the content header. */
  label: string;
  /** e.g., "Phase 1" — displayed in the placeholder while the module is WIP. */
  phaseLabel: string;
  /** Short description rendered under the content header. */
  description: string;
  /** Lucide icon component rendered next to the sidebar label. */
  icon: Component;
}

export interface GroupDef {
  /** Stable id for the group. */
  id: string;
  /** Human-readable group label (rendered in Title Case in the sidebar). */
  label: string;
  /** Ordered module ids that belong to this group. */
  moduleIds: readonly string[];
}

// Order follows PRD §13 (admin interface).
export const modules: readonly ModuleDef[] = [
  {
    id: 'security-headers',
    label: 'Security Headers',
    phaseLabel: 'Phase 1',
    description:
      'Adds HSTS, CSP, X-Frame-Options, Referrer-Policy, Permissions-Policy and related response headers.',
    icon: ShieldCheck,
  },
  {
    id: 'hardening',
    label: 'Hardening',
    phaseLabel: 'Phase 1',
    description:
      'Reduces information leakage: user enumeration, XML-RPC, version headers, uploads execution, file editing.',
    icon: Lock,
  },
  {
    id: 'login-protection',
    label: 'Login Protection',
    phaseLabel: 'Phase 1',
    description:
      'Brute-force lockouts, custom login slug, strong password policy, role-based session timeouts.',
    icon: KeyRound,
  },
  {
    id: 'asset-manager',
    label: 'Asset Manager',
    phaseLabel: 'Phase 4',
    description:
      'Defer/async/delay scripts, conditional unloads, heartbeat controls, emoji and embed toggles.',
    icon: FileCode2,
  },
  {
    id: 'environment-health',
    label: 'Environment Health',
    phaseLabel: 'Phase 2',
    description:
      'Version checks (WP/PHP/DB/SSL), cron health, debug-mode scan, inactive + abandoned plugin detection.',
    icon: Activity,
  },
  {
    id: 'database-maintenance',
    label: 'Database Maintenance',
    phaseLabel: 'Phase 3',
    description:
      'Revision limits, transient cleanup, orphaned metadata, auto-drafts, trash and spam pruning.',
    icon: Database,
  },
  {
    id: 'activity-log',
    label: 'Activity Log',
    phaseLabel: 'Phase 3',
    description:
      'Logs plugin, theme, user, auth and settings events with 90-day retention and CSV export.',
    icon: ScrollText,
  },
] as const;

export const groups: readonly GroupDef[] = [
  {
    id: 'security',
    label: 'Security',
    moduleIds: ['security-headers', 'hardening', 'login-protection'],
  },
  {
    id: 'performance',
    label: 'Performance',
    moduleIds: ['asset-manager'],
  },
  {
    id: 'maintenance',
    label: 'Maintenance',
    moduleIds: ['environment-health', 'database-maintenance', 'activity-log'],
  },
] as const;

/** O(1) lookup index built once. */
const moduleIndex: Readonly<Record<string, ModuleDef>> = Object.freeze(
  Object.fromEntries(modules.map((m) => [m.id, m])),
);

/** Return the module definition for an id, or undefined if unknown. */
export function getModule(id: string): ModuleDef | undefined {
  return moduleIndex[id];
}

/** Return the group a module belongs to, or undefined if orphaned. */
export function getGroupForModule(moduleId: string): GroupDef | undefined {
  return groups.find((g) => g.moduleIds.includes(moduleId));
}

/** Flat, ordered list of module ids in DOM order across all groups. */
export const flatModuleIds: readonly string[] = groups.flatMap(
  (g) => g.moduleIds,
);

/** Default active module — first module in the first group. */
export const defaultActiveModuleId: string =
  flatModuleIds[0] ?? 'security-headers';
