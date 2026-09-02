import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import HealthSummary from '../HealthSummary.vue';
import type { HealthCounts } from '../../types';

function counts(overrides: Partial<HealthCounts> = {}): HealthCounts {
  return { ok: 6, warning: 2, critical: 1, unknown: 1, ...overrides };
}

describe('<HealthSummary>', () => {
  it('exposes the tallies to screen readers as one sentence', () => {
    const wrapper = mount(HealthSummary, {
      props: { counts: counts(), generatedAt: 1_760_000_000 },
    });

    expect(wrapper.get('.fx-visually-hidden').text()).toBe(
      '10 checks: 6 OK, 2 warnings, 1 critical issue, 1 that could not be checked.',
    );
  });

  it('hides the decorative chips from assistive tech so numbers are not read loose', () => {
    const wrapper = mount(HealthSummary, {
      props: { counts: counts(), generatedAt: 1_760_000_000 },
    });

    expect(wrapper.get('.fx-eh-summary__chips').attributes('aria-hidden')).toBe(
      'true',
    );
  });

  it('renders one chip per status, including zeroed buckets', () => {
    const wrapper = mount(HealthSummary, {
      props: {
        counts: counts({ warning: 0, critical: 0, unknown: 0 }),
        generatedAt: 1_760_000_000,
      },
    });

    const chips = wrapper.findAll('.fx-eh-summary__chip');
    expect(chips).toHaveLength(4);
    expect(
      chips.filter((c) => c.classes().includes('fx-eh-summary__chip--zero')),
    ).toHaveLength(3);
  });

  it('gives each chip a text label, never a bare number', () => {
    const wrapper = mount(HealthSummary, {
      props: { counts: counts(), generatedAt: 1_760_000_000 },
    });

    const labels = wrapper
      .findAll('.fx-eh-summary__label')
      .map((n) => n.text());
    expect(labels).toEqual(['OK', 'Warning', 'Critical', 'Not checked']);
  });

  it('states how old the report is so a cached one is never mistaken for live', () => {
    const generatedAt = Math.floor(Date.now() / 1000) - 300;
    const wrapper = mount(HealthSummary, {
      props: { counts: counts(), generatedAt },
    });

    const meta = wrapper.get('.fx-eh-summary__meta');
    expect(meta.text()).toContain('Last checked');
    expect(meta.text()).toContain('5 minutes ago');
    // The meta line is NOT aria-hidden — everyone gets the staleness signal.
    expect(meta.attributes('aria-hidden')).toBeUndefined();
  });

  it('marks up the timestamp as a machine-readable <time>', () => {
    const wrapper = mount(HealthSummary, {
      props: { counts: counts(), generatedAt: 1_760_000_000 },
    });

    expect(wrapper.get('time').attributes('datetime')).toBe(
      '2025-10-09T08:53:20.000Z',
    );
  });

  it('reads "never" and drops the <time> element when no report has run', () => {
    const wrapper = mount(HealthSummary, {
      props: {
        counts: counts({ ok: 0, warning: 0, critical: 0, unknown: 0 }),
        generatedAt: 0,
      },
    });

    expect(wrapper.find('time').exists()).toBe(false);
    expect(wrapper.get('.fx-eh-summary__meta').text()).toContain('never');
    expect(wrapper.get('.fx-visually-hidden').text()).toBe(
      'No environment checks have run yet.',
    );
  });
});
