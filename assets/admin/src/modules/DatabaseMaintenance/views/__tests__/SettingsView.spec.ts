import { describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import {
  makeConfig,
  makeSettings,
  makeStatus,
  mountAt,
} from '../../__tests__/harness';

async function mountSettings(over = makeConfig()) {
  return mountAt('/database-maintenance/settings', (s) => {
    s.status = makeStatus();
    s.applyConfig(over);
  });
}

describe('SettingsView', () => {
  it('shows the revision limit off with 20 pre-filled', async () => {
    const { wrapper } = await mountSettings();
    const sw = wrapper.get(
      '[data-field="revision_limit_enabled"] [role="switch"]',
    );
    expect(sw.attributes('aria-checked')).toBe('false');
    expect(
      (
        wrapper.get('[data-field="revisions_keep"] input')
          .element as HTMLInputElement
      ).value,
    ).toBe('20');
  });

  it('locks the revision toggle when wp-config sets WP_POST_REVISIONS', async () => {
    const { wrapper } = await mountSettings(
      makeConfig({ revisions_constant: 5 }),
    );
    expect(
      wrapper
        .get('[data-field="revision_limit_enabled"] [role="switch"]')
        .attributes('disabled'),
    ).toBeDefined();
  });

  it('keeps the keep-N field editable when WP_POST_REVISIONS is set', async () => {
    const { wrapper } = await mountSettings(
      makeConfig({ revisions_constant: 5 }),
    );
    expect(
      wrapper.get('[data-field="revisions_keep"] input').attributes('disabled'),
    ).toBeUndefined();
    expect(wrapper.text()).toContain('WP_POST_REVISIONS');
  });

  it.each([
    [-1, 'unlimited revisions are kept'],
    [true, 'unlimited revisions are kept'],
    [false, 'revisions are disabled'],
    [0, 'revisions are disabled'],
    [5, '5 revisions are kept per post'],
  ])('locked help for constant %s says "%s"', async (constant, text) => {
    const { wrapper } = await mountSettings(
      makeConfig({ revisions_constant: constant }),
    );
    expect(
      wrapper.get('[data-field="revision_limit_enabled"]').text(),
    ).toContain(text);
  });

  it('keep-N help says it controls how many the purge keeps', async () => {
    const { wrapper } = await mountSettings();
    expect(wrapper.get('[data-field="revisions_keep"]').text()).toMatch(
      /purge keeps/i,
    );
  });

  it('does not lock revision controls without the constant', async () => {
    const { wrapper } = await mountSettings();
    expect(
      wrapper
        .get('[data-field="revision_limit_enabled"] [role="switch"]')
        .attributes('disabled'),
    ).toBeUndefined();
  });

  it('hides schedule controls until the schedule is enabled', async () => {
    const { wrapper, store } = await mountSettings();
    expect(wrapper.find('[data-field="schedule_frequency"]').exists()).toBe(
      false,
    );
    store.settings!.schedule_enabled = true;
    await flushPromises();
    expect(wrapper.find('[data-field="schedule_frequency"]').exists()).toBe(
      true,
    );
    expect(wrapper.findAll('[data-field^="schedule_tasks."]')).toHaveLength(9);
  });

  it('writes the hour select back as a number', async () => {
    const { wrapper, store } = await mountSettings(
      makeConfig({ settings: makeSettings({ schedule_enabled: true }) }),
    );
    await wrapper.get('[data-field="schedule_hour"] select').setValue('14');
    expect(store.settings!.schedule_hour).toBe(14);
  });

  it('shows the next run when scheduled', async () => {
    const { wrapper } = await mountSettings(
      makeConfig({
        settings: makeSettings({ schedule_enabled: true }),
        next_run: '2026-10-04T03:00:00-05:00',
      }),
    );
    expect(wrapper.text()).toMatch(/Next run/);
  });

  it('renders the next run in site time, not the browser zone', async () => {
    const { wrapper } = await mountSettings(
      makeConfig({
        settings: makeSettings({ schedule_enabled: true }),
        next_run: '2026-10-04T03:00:00-05:00',
      }),
    );
    expect(wrapper.text()).toContain(
      'Next run: Sun, Oct 4, 03:00 (site time, UTC\u221205:00)',
    );
  });

  it('explains the frequency and time-of-day selects', async () => {
    const { wrapper } = await mountSettings(
      makeConfig({ settings: makeSettings({ schedule_enabled: true }) }),
    );
    for (const field of ['schedule_frequency', 'schedule_hour']) {
      const el = wrapper.get(`[data-field="${field}"]`);
      const id = el.get('select').attributes('aria-describedby');
      expect(id).toBeTruthy();
      expect(el.get(`#${id!.split(' ')[0]!}`).text().length).toBeGreaterThan(
        10,
      );
    }
    expect(wrapper.get('[data-field="schedule_hour"]').text()).toContain(
      'site visit',
    );
  });

  it('every toggle has an accessible explanation', async () => {
    const { wrapper } = await mountSettings(
      makeConfig({ settings: makeSettings({ schedule_enabled: true }) }),
    );
    const switches = wrapper.findAll('[role="switch"]');
    expect(switches.length).toBeGreaterThan(0);
    for (const sw of switches) {
      const described = sw.attributes('aria-describedby');
      const tooltipNearby = sw.element
        .closest('[data-field]')
        ?.querySelector('.fx-tip');
      expect(Boolean(described) || Boolean(tooltipNearby)).toBe(true);
    }
  });

  it('wires HelpText to the toggle via aria-describedby', async () => {
    const { wrapper } = await mountSettings();
    const id = wrapper
      .get('[data-field="schedule_enabled"] [role="switch"]')
      .attributes('aria-describedby');
    expect(id).toBeDefined();
    expect(wrapper.get(`#${id!}`).text()).toContain('scheduler');
  });

  it('SaveBar saves through the store', async () => {
    const { wrapper, store } = await mountSettings();
    const save = vi.spyOn(store, 'save').mockResolvedValue();
    store.settings!.spam_days = 30;
    await flushPromises();
    await wrapper.get('.fx-save-bar__button--primary').trigger('click');
    expect(save).toHaveBeenCalled();
  });
});
