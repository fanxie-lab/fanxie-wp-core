<script setup lang="ts">
import { computed } from 'vue';
import {
  HelpText,
  NumberField,
  SaveBar,
  Select,
  Toggle,
  Tooltip,
} from '@/components';
import type { SaveStatus } from '@/components';
import { useDatabaseMaintenanceStore } from '../stores/databaseMaintenance';
import { formatSiteTime } from '../format';
import { RANGES, type TaskId } from '../types';

const store = useDatabaseMaintenanceStore();
const s = computed(() => store.settings);

const SCHEDULE_ROWS: { id: TaskId; label: string; help: string }[] = [
  {
    id: 'revisions',
    label: 'Post revisions',
    help: 'Keeps the newest revisions per post (the number above) and deletes older ones.',
  },
  {
    id: 'expired-transients',
    label: 'Expired transients',
    help: 'Cached values that have already expired. Always safe to remove.',
  },
  {
    id: 'orphaned-postmeta',
    label: 'Orphaned post meta',
    help: 'Data left behind by posts that no longer exist.',
  },
  {
    id: 'orphaned-usermeta',
    label: 'Orphaned user meta',
    help: 'Data left behind by deleted users.',
  },
  {
    id: 'orphaned-termmeta',
    label: 'Orphaned term meta',
    help: 'Data left behind by deleted categories and tags.',
  },
  {
    id: 'orphaned-commentmeta',
    label: 'Orphaned comment meta',
    help: 'Data left behind by deleted comments.',
  },
  {
    id: 'auto-drafts',
    label: 'Auto-drafts',
    help: 'Empty drafts WordPress creates when "Add New" is opened and abandoned.',
  },
  {
    id: 'trashed-posts',
    label: 'Trashed posts',
    help: 'Permanently deletes posts that have been in the trash longer than the limit above.',
  },
  {
    id: 'spam-comments',
    label: 'Spam comments',
    help: 'Permanently deletes spam older than the limit above.',
  },
];

const hourOptions = Array.from({ length: 24 }, (_, h) => ({
  value: String(h),
  label: `${String(h).padStart(2, '0')}:00`,
}));

const frequencyOptions = [
  { value: 'daily', label: 'Daily' },
  { value: 'weekly', label: 'Weekly' },
];

const saveStatus = computed<SaveStatus>(() =>
  store.loading.saving ? 'saving' : 'idle',
);

const nextRunText = computed(() =>
  store.nextRun ? formatSiteTime(store.nextRun) : null,
);

const lockedText = computed(() => {
  const v = store.revisionsConstant;
  if (v === null) return '';
  let meaning: string;
  if (v === false || v === 0) {
    meaning = 'revisions are disabled';
  } else if (v === true || v < 0) {
    meaning = 'unlimited revisions are kept';
  } else {
    meaning = `${String(v)} revisions are kept per post`;
  }
  return `Your wp-config.php sets WP_POST_REVISIONS, so ${meaning}. Remove that line to manage the limit here.`;
});

const keepHelp = computed(() =>
  store.isLocked
    ? 'How many revisions the purge keeps per post. 0 removes all revisions.'
    : 'How many revisions the purge keeps per post, and the cap when "Limit stored revisions" is on. 0 removes all revisions.',
);

function setHour(value: string): void {
  if (s.value) s.value.schedule_hour = Number(value);
}
</script>

<template>
  <form v-if="s" class="fx-db-settings" @submit.prevent="store.save()">
    <fieldset class="fx-db-settings__group">
      <legend>Revisions</legend>

      <div data-field="revision_limit_enabled">
        <Toggle
          v-model="s.revision_limit_enabled"
          label="Limit stored revisions"
          :disabled="store.isLocked"
          describedby="fx-db-rev-help"
        />
        <HelpText id="fx-db-rev-help" :tone="store.isLocked ? 'warn' : 'muted'">
          <template v-if="store.isLocked">{{ lockedText }}</template>
          <template v-else>
            Off by default. When on, WordPress keeps only this many revisions
            for each post from now on. Existing revisions are untouched until
            you purge them on the Cleanup tab.
          </template>
        </HelpText>
      </div>

      <div data-field="revisions_keep">
        <NumberField
          id="fx-db-revisions-keep"
          v-model="s.revisions_keep"
          label="Revisions to keep per post"
          :help="keepHelp"
          unit="revisions"
          :min="RANGES.revisions_keep.min"
          :max="RANGES.revisions_keep.max"
        />
      </div>
    </fieldset>

    <fieldset class="fx-db-settings__group">
      <legend>Age limits</legend>
      <div data-field="auto_draft_days">
        <NumberField
          id="fx-db-auto-draft-days"
          v-model="s.auto_draft_days"
          label="Delete auto-drafts older than"
          help="Abandoned empty drafts."
          unit="days"
          :min="RANGES.auto_draft_days.min"
          :max="RANGES.auto_draft_days.max"
        />
      </div>
      <div data-field="trash_days">
        <NumberField
          id="fx-db-trash-days"
          v-model="s.trash_days"
          label="Delete trashed posts older than"
          help="Measured from when the post was moved to the trash. Trashed media files are deleted from disk too."
          unit="days"
          :min="RANGES.trash_days.min"
          :max="RANGES.trash_days.max"
        />
      </div>
      <div data-field="spam_days">
        <NumberField
          id="fx-db-spam-days"
          v-model="s.spam_days"
          label="Delete spam comments older than"
          help="Gives you time to rescue a real comment caught as spam."
          unit="days"
          :min="RANGES.spam_days.min"
          :max="RANGES.spam_days.max"
        />
      </div>
    </fieldset>

    <fieldset class="fx-db-settings__group">
      <legend>Schedule</legend>

      <div data-field="schedule_enabled">
        <Toggle
          v-model="s.schedule_enabled"
          label="Run cleanups on a schedule"
          describedby="fx-db-sched-help"
        />
        <HelpText id="fx-db-sched-help">
          Runs the cleanups ticked below at a quiet hour. Uses WordPress's
          built-in scheduler, which runs when the site gets a visit.
        </HelpText>
      </div>

      <template v-if="s.schedule_enabled">
        <div data-field="schedule_frequency">
          <Select
            v-model="s.schedule_frequency"
            label="Frequency"
            help="Weekly suits most sites; daily is for busy sites with lots of comments or edits."
            :options="frequencyOptions"
          />
        </div>
        <div data-field="schedule_hour">
          <Select
            :model-value="String(s.schedule_hour)"
            label="Time of day (site timezone)"
            help="Runs at the start of this hour in the site's timezone (Settings → General). WordPress's scheduler needs a site visit to trigger, so it may start a little later."
            :options="hourOptions"
            @update:model-value="setHour"
          />
        </div>
        <p v-if="nextRunText" class="fx-db-settings__next-run">
          Next run: {{ nextRunText }}
        </p>

        <div
          v-for="row in SCHEDULE_ROWS"
          :key="row.id"
          :data-field="`schedule_tasks.${row.id}`"
          class="fx-db-settings__task"
        >
          <Toggle v-model="s.schedule_tasks[row.id]" :label="row.label" />
          <Tooltip :label="`About ${row.label}`" :text="row.help" />
        </div>
      </template>
    </fieldset>

    <SaveBar
      :dirty="store.isDirty"
      :status="saveStatus"
      @save="store.save()"
      @reset="store.reset()"
    />
  </form>
</template>

<style scoped>
.fx-db-settings {
  display: grid;
  gap: var(--fx-space-5);
}

.fx-db-settings__group {
  display: grid;
  gap: var(--fx-space-3);
  border: 0;
  padding: 0;
  margin: 0;
}

.fx-db-settings__group legend {
  font-weight: var(--fx-font-weight-semibold);
  margin-bottom: var(--fx-space-2);
}

.fx-db-settings__task {
  display: flex;
  align-items: center;
  gap: var(--fx-space-2);
}

.fx-db-settings__next-run {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
}
</style>
