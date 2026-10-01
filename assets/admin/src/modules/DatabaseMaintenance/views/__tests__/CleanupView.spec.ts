import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import * as client from '@/api/ajaxClient';
import { makeConfig, makeStatus, mountAt } from '../../__tests__/harness';

const CONFIRM = '.fx-confirm__confirm';

async function mountCleanup(statusOver = makeStatus()) {
  return mountAt('/database-maintenance/cleanup', (store) => {
    store.status = statusOver;
    store.applyConfig(makeConfig());
  });
}

function clickConfirm(): void {
  document.body.querySelector<HTMLButtonElement>(CONFIRM)?.click();
}

afterEach(() => {
  vi.restoreAllMocks();
  document.body.innerHTML = '';
});

describe('CleanupView', () => {
  it('renders one row per item with count and approximate size', async () => {
    const { wrapper } = await mountCleanup();
    const rows = wrapper.findAll('tbody tr[data-task]');
    expect(rows).toHaveLength(9);
    expect(rows[0]?.text()).toContain('Post revisions');
    expect(rows[0]?.text()).toContain('1,247');
    expect(rows[0]?.text()).toContain('≈');
  });

  it('zero-count rows show a dash and cannot be selected', async () => {
    const { wrapper } = await mountCleanup();
    const row = wrapper.get('tr[data-task="orphaned-termmeta"]');
    expect(row.text()).toContain('—');
    expect(
      row.get('input[type="checkbox"]').attributes('disabled'),
    ).toBeDefined();
  });

  it('Preview loads and shows sample rows without purging', async () => {
    const spy = vi.spyOn(client, 'ajax').mockResolvedValue({
      id: 'spam-comments',
      count: 156,
      bytes: 15600,
      sample: [
        {
          label: 'casino-bot',
          detail: 'Buy now',
          date: '2025-01-01T00:00:00+00:00',
        },
      ],
    });
    const { wrapper } = await mountCleanup();
    await wrapper
      .get('tr[data-task="spam-comments"] button[data-action="preview"]')
      .trigger('click');
    await flushPromises();

    expect(spy).toHaveBeenCalledWith('database-maintenance/preview', {
      task: 'spam-comments',
    });
    expect(spy).not.toHaveBeenCalledWith(
      'database-maintenance/purge-step',
      expect.anything(),
    );
    expect(wrapper.text()).toContain('casino-bot');
  });

  it('Purge Selected is disabled until a row is checked', async () => {
    const { wrapper } = await mountCleanup();
    const btn = wrapper.get('button[data-action="purge-selected"]');
    expect(btn.attributes('disabled')).toBeDefined();
    await wrapper
      .get('tr[data-task="spam-comments"] input[type="checkbox"]')
      .setValue(true);
    expect(btn.attributes('disabled')).toBeUndefined();
  });

  it('purging asks for confirmation listing items, then runs steps and refreshes', async () => {
    const spy = vi
      .spyOn(client, 'ajax')
      .mockImplementation((action: string) => {
        if (action.endsWith('purge-step')) {
          return Promise.resolve({
            id: 'spam-comments',
            deleted: 156,
            failed: 0,
            remaining: 0,
            done: true,
            busy: false,
          });
        }
        return Promise.resolve(makeStatus({ 'spam-comments': 0 }));
      });
    const { wrapper } = await mountCleanup();
    await wrapper
      .get('tr[data-task="spam-comments"] input[type="checkbox"]')
      .setValue(true);
    await wrapper.get('button[data-action="purge-selected"]').trigger('click');

    const dialog = document.body.querySelector('[role="alertdialog"]');
    expect(dialog?.textContent).toContain('Spam comments');
    expect(dialog?.textContent).toContain('156');
    expect(spy).not.toHaveBeenCalledWith(
      'database-maintenance/purge-step',
      expect.anything(),
    );

    clickConfirm();
    await flushPromises();

    expect(spy).toHaveBeenCalledWith('database-maintenance/purge-step', {
      task: 'spam-comments',
    });
    expect(spy).toHaveBeenCalledWith('database-maintenance/get-status');
  });

  it('Purge All targets every row with a count', async () => {
    const calls: string[] = [];
    vi.spyOn(client, 'ajax').mockImplementation(
      (action: string, payload?: Record<string, unknown>) => {
        if (action.endsWith('purge-step')) {
          const task = String(payload?.task);
          calls.push(task);
          return Promise.resolve({
            id: task,
            deleted: 1,
            failed: 0,
            remaining: 0,
            done: true,
            busy: false,
          });
        }
        return Promise.resolve(makeStatus());
      },
    );
    const { wrapper } = await mountCleanup();
    await wrapper.get('button[data-action="purge-all"]').trigger('click');
    clickConfirm();
    await flushPromises();

    expect(calls).toEqual(['revisions', 'expired-transients', 'spam-comments']);
  });

  it('disables purge buttons and offers Cancel while a purge is running', async () => {
    let release: (v: unknown) => void = () => undefined;
    vi.spyOn(client, 'ajax').mockImplementation((action: string) => {
      if (action.endsWith('purge-step')) {
        return new Promise((resolve) => {
          release = resolve;
        });
      }
      return Promise.resolve(makeStatus());
    });
    const { wrapper } = await mountCleanup();
    await wrapper.get('button[data-action="purge-all"]').trigger('click');
    clickConfirm();
    await flushPromises();

    expect(
      wrapper.get('button[data-action="purge-all"]').attributes('disabled'),
    ).toBeDefined();
    expect(
      wrapper
        .get('button[data-action="purge-selected"]')
        .attributes('disabled'),
    ).toBeDefined();
    expect(wrapper.find('button[data-action="cancel"]').exists()).toBe(true);

    await wrapper.get('button[data-action="cancel"]').trigger('click');
    release({
      id: 'revisions',
      deleted: 1,
      failed: 0,
      remaining: 5,
      done: false,
      busy: false,
    });
    await flushPromises();
  });

  it('shows the object-cache note on the transients row', async () => {
    const { wrapper } = await mountCleanup(makeStatus({}, true));
    expect(wrapper.get('tr[data-task="expired-transients"]').text()).toMatch(
      /object cache/i,
    );
  });

  it('has a polite live region for progress announcements', async () => {
    const { wrapper } = await mountCleanup();
    expect(wrapper.find('[aria-live="polite"]').exists()).toBe(true);
  });
});
