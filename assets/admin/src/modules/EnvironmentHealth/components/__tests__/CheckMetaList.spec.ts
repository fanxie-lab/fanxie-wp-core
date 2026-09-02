import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import CheckMetaList from '../CheckMetaList.vue';

describe('<CheckMetaList>', () => {
  it('renders one list item per name, in the order given', () => {
    const wrapper = mount(CheckMetaList, {
      props: { label: 'Unused themes', items: ['Twenty Ten', 'Twenty Eleven'] },
    });

    const items = wrapper.findAll('.fx-eh-list__item').map((n) => n.text());
    expect(items).toEqual(['Twenty Ten', 'Twenty Eleven']);
  });

  it('keeps a name containing a comma as one item', () => {
    // A comma-joined sentence could not represent this without corrupting it,
    // which is why the contract carries an array.
    const wrapper = mount(CheckMetaList, {
      props: { label: 'Inactive plugins', items: ['Foo, Inc. Toolkit'] },
    });

    const items = wrapper.findAll('.fx-eh-list__item');
    expect(items).toHaveLength(1);
    expect(items[0]!.text()).toBe('Foo, Inc. Toolkit');
  });

  it('renders duplicate names as separate rows', () => {
    // Two plugins can genuinely share a display name; keying by name would
    // silently drop one.
    const wrapper = mount(CheckMetaList, {
      props: {
        label: 'Inactive plugins',
        items: ['Contact Form', 'Contact Form'],
      },
    });

    expect(wrapper.findAll('.fx-eh-list__item')).toHaveLength(2);
  });

  it('renders nothing at all for an empty list', () => {
    const wrapper = mount(CheckMetaList, {
      props: { label: 'Unused themes', items: [] },
    });

    // No container and no stray heading — an empty array is a real answer.
    expect(wrapper.find('.fx-eh-list').exists()).toBe(false);
    expect(wrapper.find('.fx-eh-list__label').exists()).toBe(false);
    expect(wrapper.find('ul').exists()).toBe(false);
    expect(wrapper.text()).toBe('');
  });

  describe('truncation', () => {
    it('says plainly how many items were dropped', () => {
      const wrapper = mount(CheckMetaList, {
        props: { label: 'Unused themes', items: ['A', 'B'], omitted: 5 },
      });

      expect(wrapper.get('.fx-eh-list__more').text()).toBe('and 5 more');
    });

    it('shows no notice when nothing was dropped', () => {
      const wrapper = mount(CheckMetaList, {
        props: { label: 'Unused themes', items: ['A', 'B'], omitted: 0 },
      });

      expect(wrapper.find('.fx-eh-list__more').exists()).toBe(false);
      expect(wrapper.findAll('.fx-eh-list__item')).toHaveLength(2);
    });

    it('shows no notice when omitted is not passed at all', () => {
      const wrapper = mount(CheckMetaList, {
        props: { label: 'Unused themes', items: ['A'] },
      });

      expect(wrapper.find('.fx-eh-list__more').exists()).toBe(false);
    });

    it('keeps the notice inside the list so list-wise navigation cannot skip it', () => {
      const wrapper = mount(CheckMetaList, {
        props: { label: 'Unused themes', items: ['A'], omitted: 3 },
      });

      const rows = wrapper.get('ul').findAll('li');
      expect(rows).toHaveLength(2);
      expect(rows[1]!.text()).toBe('and 3 more');
    });
  });

  describe('accessibility', () => {
    it('names the list with its own heading', () => {
      const wrapper = mount(CheckMetaList, {
        props: { label: 'Critical', items: ['Ancient Slider'] },
      });

      const labelledBy = wrapper.get('ul').attributes('aria-labelledby');
      expect(labelledBy).toBeTruthy();
      expect(wrapper.get(`#${labelledBy!}`).text()).toBe('Critical');
    });

    it('gives two sibling instances distinct label ids', () => {
      // Both severity groups of `abandoned_plugins` render in the same row, so
      // colliding ids would point both lists at one heading.
      const Pair = defineComponent({
        render: () => [
          h(CheckMetaList, { label: 'Critical', items: ['A'] }),
          h(CheckMetaList, { label: 'Warning', items: ['B'] }),
        ],
      });

      const wrapper = mount(Pair);
      const ids = wrapper
        .findAll('ul')
        .map((n) => n.attributes('aria-labelledby'));

      expect(ids).toHaveLength(2);
      expect(ids[0]).toBeTruthy();
      expect(ids[0]).not.toBe(ids[1]);
    });

    it('carries the severity as text, with the tint as reinforcement only', () => {
      const wrapper = mount(CheckMetaList, {
        props: { label: 'Critical', items: ['A'], tone: 'critical' },
      });

      expect(wrapper.get('.fx-eh-list__label').text()).toBe('Critical');
      expect(wrapper.get('.fx-eh-list').classes()).toContain(
        'fx-eh-list--critical',
      );
    });

    it('applies no tone modifier when no tone is given', () => {
      const wrapper = mount(CheckMetaList, {
        props: { label: 'Unused themes', items: ['A'] },
      });

      const classes = wrapper.get('.fx-eh-list').classes();
      expect(classes).not.toContain('fx-eh-list--warn');
      expect(classes).not.toContain('fx-eh-list--critical');
    });
  });
});
