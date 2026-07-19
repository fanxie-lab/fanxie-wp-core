import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import LockoutLog from '../LockoutLog.vue';
import { useLoginProtectionStore } from '../../stores/loginProtection';
import type { BanRow, LoginLogRow } from '../../types';

function makeLogRow(overrides: Partial<LoginLogRow> = {}): LoginLogRow {
  return {
    id: 1,
    event_type: 'login_failed',
    ip: '203.0.113.9',
    username: 'bob',
    user_id: null,
    context: null,
    created_at: '2026-07-18 12:00:00',
    ...overrides,
  };
}

function makeBanRow(overrides: Partial<BanRow> = {}): BanRow {
  return {
    id: 1,
    subject_type: 'ip',
    subject_value: '198.51.100.7',
    reason: null,
    expires_at: null,
    created_at: '2026-07-18 12:00:00',
    ...overrides,
  };
}

type Store = ReturnType<typeof useLoginProtectionStore>;

async function mountWithStore(seed: (store: Store) => void) {
  setActivePinia(createPinia());
  const store = useLoginProtectionStore();
  // Component owns its first log fetch on mount — stub every mutating action so
  // the seeded state is the single source of truth. Spies are captured (rather
  // than referenced via `store.x` in assertions) to satisfy the
  // no-unbound-method rule, mirroring Hardening.spec.
  const spies = {
    fetchLog: vi.spyOn(store, 'fetchLog').mockResolvedValue(),
    clearLogFilters: vi.spyOn(store, 'clearLogFilters').mockResolvedValue(),
    addBan: vi.spyOn(store, 'addBan').mockResolvedValue(),
    removeBan: vi.spyOn(store, 'removeBan').mockResolvedValue(),
    clearLockout: vi.spyOn(store, 'clearLockout').mockResolvedValue(),
  };
  seed(store);

  const wrapper = mount(LockoutLog, { attachTo: document.body });
  await flushPromises();
  return { store, wrapper, spies };
}

describe('<LockoutLog>', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('fetches the first log page on mount', async () => {
    const { spies } = await mountWithStore(() => {
      /* no seed */
    });
    expect(spies.fetchLog).toHaveBeenCalledWith(1);
  });

  it('renders log rows from the store with a semantic column-scoped header', async () => {
    const { wrapper } = await mountWithStore((store) => {
      store.log.rows = [
        makeLogRow(),
        makeLogRow({ id: 2, event_type: 'lockout_started', username: 'carol' }),
      ];
      store.log.total = 2;
    });

    // Native table with column-scoped headers (WCAG).
    expect(wrapper.find('table').exists()).toBe(true);
    expect(wrapper.find('th[scope="col"]').exists()).toBe(true);

    const text = wrapper.text();
    expect(text).toContain('203.0.113.9');
    expect(text).toContain('bob');
    expect(text).toContain('carol');
    // event_type is humanised for display.
    expect(text).toContain('Login failed');
    expect(text).toContain('Lockout started');
  });

  it('shows an empty state when there are no rows', async () => {
    const { wrapper } = await mountWithStore((store) => {
      store.log.rows = [];
      store.log.total = 0;
    });
    expect(wrapper.text().toLowerCase()).toContain('no login events');
  });

  it("bans a row's IP directly (additive) via store.addBan", async () => {
    const { spies, wrapper } = await mountWithStore((s) => {
      s.log.rows = [makeLogRow()];
      s.log.total = 1;
    });

    const banIp = wrapper.get('[aria-label="Ban IP 203.0.113.9"]');
    await banIp.trigger('click');

    expect(spies.addBan).toHaveBeenCalledWith({
      subject_type: 'ip',
      subject_value: '203.0.113.9',
    });
  });

  it("bans a row's username via store.addBan", async () => {
    const { spies, wrapper } = await mountWithStore((s) => {
      s.log.rows = [makeLogRow()];
      s.log.total = 1;
    });

    const banUser = wrapper.get('[aria-label="Ban username bob"]');
    await banUser.trigger('click');

    expect(spies.addBan).toHaveBeenCalledWith({
      subject_type: 'username',
      subject_value: 'bob',
    });
  });

  it('applies filters through store.fetchLog(1, filters)', async () => {
    const { spies, wrapper } = await mountWithStore((s) => {
      s.log.rows = [makeLogRow()];
      s.log.total = 1;
    });

    const ipInput = wrapper.get('#fx-lockout-filter-ip');
    await ipInput.setValue('203.0.113.9');
    const eventSelect = wrapper.get('#fx-lockout-filter-event');
    await eventSelect.setValue('login_failed');

    await wrapper.get('.fx-lockout__filter-apply').trigger('click');

    expect(spies.fetchLog).toHaveBeenCalledWith(
      1,
      expect.objectContaining({
        ip: '203.0.113.9',
        event_type: 'login_failed',
      }),
    );
  });

  it('pages forward via store.fetchLog(nextPage)', async () => {
    const { spies, wrapper } = await mountWithStore((s) => {
      s.log.rows = [makeLogRow()];
      s.log.total = 50; // 2 pages at per_page 25
      s.log.page = 1;
      s.log.per_page = 25;
    });

    await wrapper.get('.fx-lockout__page-next').trigger('click');
    expect(spies.fetchLog).toHaveBeenCalledWith(2);
  });

  it('renders the current bans list with an Unban control', async () => {
    const { wrapper } = await mountWithStore((store) => {
      store.bans.rows = [makeBanRow()];
      store.bans.total = 1;
    });

    const text = wrapper.text();
    expect(text).toContain('198.51.100.7');
    expect(wrapper.find('[aria-label="Unban IP 198.51.100.7"]').exists()).toBe(
      true,
    );
  });

  it('confirms before unbanning (destructive) then calls store.removeBan', async () => {
    const { spies, wrapper } = await mountWithStore((s) => {
      s.bans.rows = [makeBanRow()];
      s.bans.total = 1;
    });

    await wrapper.get('[aria-label="Unban IP 198.51.100.7"]').trigger('click');

    // A confirm dialog appears; the mutation has NOT fired yet.
    expect(spies.removeBan).not.toHaveBeenCalled();
    const dialog = wrapper.find('[role="alertdialog"]');
    expect(dialog.exists()).toBe(true);

    await wrapper.get('.fx-lockout__confirm-accept').trigger('click');

    expect(spies.removeBan).toHaveBeenCalledWith({
      subject_type: 'ip',
      subject_value: '198.51.100.7',
    });
  });

  it('confirms before clearing a lockout then calls store.clearLockout', async () => {
    const { spies, wrapper } = await mountWithStore((s) => {
      s.log.rows = [makeLogRow()];
      s.log.total = 1;
    });

    await wrapper
      .get('[aria-label="Clear lockout for IP 203.0.113.9"]')
      .trigger('click');

    expect(spies.clearLockout).not.toHaveBeenCalled();
    await wrapper.get('.fx-lockout__confirm-accept').trigger('click');

    expect(spies.clearLockout).toHaveBeenCalledWith({
      subject_type: 'ip',
      subject_value: '203.0.113.9',
    });
  });

  it('submits the manual add-ban form via store.addBan', async () => {
    const { spies, wrapper } = await mountWithStore((s) => {
      s.log.rows = [];
      s.log.total = 0;
    });

    await wrapper.get('#fx-lockout-add-type').setValue('username');
    await wrapper.get('#fx-lockout-add-value').setValue('mallory');
    await wrapper.get('.fx-lockout__add').trigger('submit');

    expect(spies.addBan).toHaveBeenCalledWith(
      expect.objectContaining({
        subject_type: 'username',
        subject_value: 'mallory',
      }),
    );
  });
});
