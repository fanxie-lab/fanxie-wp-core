import { describe, expect, it } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import CheckGroupCard from '../CheckGroupCard.vue';
import type { CheckStatus, HealthCheck } from '../../types';

function makeCheck(
  id: string,
  status: CheckStatus = 'ok',
  overrides: Partial<HealthCheck> = {},
): HealthCheck {
  return {
    id,
    group: 'versions',
    label: id,
    status,
    value: '',
    summary: `${id} summary`,
    ...overrides,
  };
}

describe('<CheckGroupCard>', () => {
  it('renders the group heading and wires it to the section', () => {
    const wrapper = mount(CheckGroupCard, {
      props: { group: 'versions', checks: [makeCheck('php_version')] },
    });

    const section = wrapper.get('section');
    const labelledBy = section.attributes('aria-labelledby');
    expect(wrapper.get(`#${labelledBy!}`).text()).toBe('Versions');
  });

  it('labels each group with its human-readable name', () => {
    const groups = [
      ['versions', 'Versions'],
      ['cron', 'Cron'],
      ['debug', 'Debug mode'],
      ['plugins_themes', 'Plugins and themes'],
    ] as const;

    for (const [group, label] of groups) {
      const wrapper = mount(CheckGroupCard, {
        props: { group, checks: [makeCheck('x')] },
      });
      expect(wrapper.get('h3').text()).toBe(label);
    }
  });

  it('renders one row per check as a list item', () => {
    const wrapper = mount(CheckGroupCard, {
      props: {
        group: 'versions',
        checks: [makeCheck('a'), makeCheck('b'), makeCheck('c')],
      },
    });

    expect(wrapper.findAll('li.fx-eh-row')).toHaveLength(3);
    expect(wrapper.get('ul').element.children).toHaveLength(3);
  });

  it('summarises the card with the worst status inside it', () => {
    const wrapper = mount(CheckGroupCard, {
      props: {
        group: 'versions',
        checks: [
          makeCheck('a', 'ok'),
          makeCheck('b', 'warning'),
          makeCheck('c', 'critical'),
        ],
      },
    });

    // First pill in the DOM is the card header's.
    const headerPill = wrapper.get('.fx-eh-group__header .fx-pill');
    expect(headerPill.classes()).toContain('fx-pill--critical');
    expect(headerPill.text()).toContain('Critical');
    expect(wrapper.get('section').classes()).toContain('fx-eh-group--critical');
  });

  it('does not let an unchecked row outrank a real warning', () => {
    const wrapper = mount(CheckGroupCard, {
      props: {
        group: 'plugins_themes',
        checks: [makeCheck('a', 'unknown'), makeCheck('b', 'warning')],
      },
    });

    expect(wrapper.get('.fx-eh-group__header .fx-pill').text()).toContain(
      'Warning',
    );
  });

  it('scopes the header pill announcement to the group', () => {
    const wrapper = mount(CheckGroupCard, {
      props: { group: 'cron', checks: [makeCheck('a', 'ok')] },
    });

    expect(
      wrapper.get('.fx-eh-group__header .fx-pill .fx-visually-hidden').text(),
    ).toBe('Cron group status:');
  });

  it('re-emits copy-failed from a row', async () => {
    const wrapper = mount(CheckGroupCard, {
      props: {
        group: 'cron',
        checks: [
          makeCheck('a', 'warning', {
            remediation: [{ kind: 'snippet', code: 'x' }],
          }),
        ],
      },
    });

    Object.defineProperty(navigator, 'clipboard', {
      value: {
        writeText: () => Promise.reject(new Error('denied')),
      },
      configurable: true,
      writable: true,
    });

    await wrapper.get('.fx-eh-row__toggle').trigger('click');
    await wrapper.get('.fx-snippet__copy').trigger('click');
    await flushPromises();

    expect(wrapper.emitted('copy-failed')).toHaveLength(1);
  });
});
