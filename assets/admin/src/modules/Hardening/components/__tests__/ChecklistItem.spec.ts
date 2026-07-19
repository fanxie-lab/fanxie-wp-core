import { describe, expect, it } from 'vitest';
import { h } from 'vue';
import { mount } from '@vue/test-utils';
import ChecklistItem from '../ChecklistItem.vue';

describe('<ChecklistItem>', () => {
  it('renders the label and description', () => {
    const wrapper = mount(ChecklistItem, {
      props: {
        label: 'Block author archive',
        description: 'Returns 404 for /?author=N.',
        status: 'active',
      },
      slots: {
        default: '<span class="control-stub">toggle here</span>',
      },
    });

    expect(wrapper.text()).toContain('Block author archive');
    expect(
      wrapper.get('[data-testid="checklist-item-description"]').text(),
    ).toBe('Returns 404 for /?author=N.');
    expect(wrapper.find('.control-stub').exists()).toBe(true);
  });

  it('does not render a status pill for status=active — the control itself conveys state', () => {
    const wrapper = mount(ChecklistItem, {
      props: { label: 'L', status: 'active' },
    });
    expect(wrapper.find('.fx-pill').exists()).toBe(false);
  });

  it('does not render a status pill for status=inactive — the control itself conveys state', () => {
    const wrapper = mount(ChecklistItem, {
      props: { label: 'L', status: 'inactive' },
    });
    expect(wrapper.find('.fx-pill').exists()).toBe(false);
  });

  it('renders a "warn" pill for status=warning with a default "Warning" label', () => {
    const wrapper = mount(ChecklistItem, {
      props: { label: 'L', status: 'warning' },
    });
    const pill = wrapper.get('.fx-pill');
    expect(pill.classes()).toContain('fx-pill--warn');
    expect(pill.text()).toContain('Warning');
  });

  it('honours a custom statusLabel on warning rows', () => {
    const wrapper = mount(ChecklistItem, {
      props: {
        label: 'L',
        status: 'warning',
        statusLabel: 'Still present',
      },
    });
    expect(wrapper.get('.fx-pill').text()).toContain('Still present');
  });

  it('renders the footer slot when provided', () => {
    const wrapper = mount(ChecklistItem, {
      props: { label: 'L', status: 'warning' },
      slots: {
        footer: '<button class="fix-btn">Fix now</button>',
      },
    });
    expect(wrapper.find('.fix-btn').exists()).toBe(true);
  });

  it('omits the footer section when the slot is empty', () => {
    const wrapper = mount(ChecklistItem, {
      props: { label: 'L', status: 'inactive' },
    });
    expect(wrapper.find('.fx-hardening-item__footer').exists()).toBe(false);
  });

  it('exposes the label via aria-labelledby on the row group', () => {
    const wrapper = mount(ChecklistItem, {
      props: { label: 'Block author archive', status: 'active' },
    });
    const group = wrapper.get('[role="group"]');
    const ariaLabelledBy = group.attributes('aria-labelledby');
    expect(ariaLabelledBy).toBeTruthy();
    // The id should exist in the DOM and carry the label.
    const labelEl = wrapper.find(`#${ariaLabelledBy!}`);
    expect(labelEl.exists()).toBe(true);
    expect(labelEl.text()).toBe('Block author archive');
  });

  it('passes labelId down to the default slot so the control can wire aria-labelledby', () => {
    const wrapper = mount(ChecklistItem, {
      props: { label: 'Block author archive', status: 'active' },
      slots: {
        // Scoped slot renders the received labelId into a data attribute so
        // the test can confirm it matches the group's aria-labelledby target.
        default: (scope: { labelId: string }) =>
          h(
            'span',
            { class: 'control-stub', 'data-label-id': scope.labelId },
            'control',
          ),
      },
    });

    const group = wrapper.get('[role="group"]');
    const groupLabelId = group.attributes('aria-labelledby');
    const slotReceived = wrapper
      .get('.control-stub')
      .attributes('data-label-id');
    expect(slotReceived).toBe(groupLabelId);
  });

  it('renders a tooltip trigger when the tooltip prop is set', () => {
    const wrapper = mount(ChecklistItem, {
      props: {
        label: 'Block author archive',
        status: 'active',
        tooltip: 'Why this matters.',
      },
    });
    expect(wrapper.find('button[aria-label="More information"]').exists()).toBe(
      true,
    );
  });

  it('renders no tooltip trigger when the tooltip prop is omitted', () => {
    const wrapper = mount(ChecklistItem, {
      props: { label: 'Block author archive', status: 'active' },
    });
    expect(wrapper.find('button[aria-label="More information"]').exists()).toBe(
      false,
    );
  });
});
