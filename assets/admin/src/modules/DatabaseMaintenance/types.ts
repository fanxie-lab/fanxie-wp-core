// Mirrors src/Modules/DatabaseMaintenance/AjaxController.php — frozen contract.

export type TaskId =
  | 'revisions'
  | 'expired-transients'
  | 'orphaned-postmeta'
  | 'orphaned-usermeta'
  | 'orphaned-termmeta'
  | 'orphaned-commentmeta'
  | 'auto-drafts'
  | 'trashed-posts'
  | 'spam-comments';

export interface StatusItem {
  id: TaskId;
  label: string;
  count: number;
  bytes: number;
}

export interface StatusResponse {
  items: StatusItem[];
  total_bytes: number;
  object_cache: boolean;
}

export interface SampleRow {
  label: string;
  detail: string;
  date: string | null;
}

export interface PreviewResponse {
  id: TaskId;
  count: number;
  bytes: number;
  sample: SampleRow[];
}

export interface PurgeStepResponse {
  id: TaskId;
  deleted: number;
  failed: number;
  remaining: number;
  done: boolean;
  busy: boolean;
}

export type ScheduleFrequency = 'daily' | 'weekly';

export interface DbSettings {
  revision_limit_enabled: boolean;
  revisions_keep: number;
  auto_draft_days: number;
  trash_days: number;
  spam_days: number;
  schedule_enabled: boolean;
  schedule_frequency: ScheduleFrequency;
  schedule_hour: number;
  schedule_tasks: Record<TaskId, boolean>;
}

export interface ConfigResponse {
  settings: DbSettings;
  /** Non-null when wp-config sets WP_POST_REVISIONS to something other than true. */
  revisions_constant: number | boolean | null;
  next_run: string | null;
}

/** Ranges mirror Settings::RANGES in PHP. */
export const RANGES = {
  revisions_keep: { min: 0, max: 50 },
  auto_draft_days: { min: 1, max: 365 },
  trash_days: { min: 1, max: 365 },
  spam_days: { min: 1, max: 365 },
  schedule_hour: { min: 0, max: 23 },
} as const;
