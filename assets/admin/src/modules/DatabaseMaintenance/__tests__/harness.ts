import type {
  ConfigResponse,
  DbSettings,
  StatusItem,
  StatusResponse,
  TaskId,
} from '../types';

const LABELS: Record<TaskId, string> = {
  revisions: 'Post revisions',
  'expired-transients': 'Expired transients',
  'orphaned-postmeta': 'Orphaned post meta',
  'orphaned-usermeta': 'Orphaned user meta',
  'orphaned-termmeta': 'Orphaned term meta',
  'orphaned-commentmeta': 'Orphaned comment meta',
  'auto-drafts': 'Auto-drafts',
  'trashed-posts': 'Trashed posts',
  'spam-comments': 'Spam comments',
};

export function makeStatus(
  counts: Partial<Record<TaskId, number>> = {},
  objectCache = false,
): StatusResponse {
  const defaults: Partial<Record<TaskId, number>> = {
    revisions: 1247,
    'expired-transients': 89,
    'spam-comments': 156,
  };
  const items: StatusItem[] = (Object.keys(LABELS) as TaskId[]).map((id) => {
    const count = counts[id] ?? defaults[id] ?? 0;
    return { id, label: LABELS[id], count, bytes: count * 100 };
  });
  return {
    items,
    total_bytes: items.reduce((n, i) => n + i.bytes, 0),
    object_cache: objectCache,
  };
}

export function makeSettings(over: Partial<DbSettings> = {}): DbSettings {
  return {
    revision_limit_enabled: false,
    revisions_keep: 20,
    auto_draft_days: 7,
    trash_days: 30,
    spam_days: 15,
    schedule_enabled: false,
    schedule_frequency: 'weekly',
    schedule_hour: 3,
    schedule_tasks: {
      revisions: false,
      'expired-transients': true,
      'orphaned-postmeta': true,
      'orphaned-usermeta': true,
      'orphaned-termmeta': true,
      'orphaned-commentmeta': true,
      'auto-drafts': true,
      'trashed-posts': false,
      'spam-comments': true,
    },
    ...over,
  };
}

export function makeConfig(over: Partial<ConfigResponse> = {}): ConfigResponse {
  return {
    settings: makeSettings(),
    revisions_constant: null,
    next_run: null,
    ...over,
  };
}
