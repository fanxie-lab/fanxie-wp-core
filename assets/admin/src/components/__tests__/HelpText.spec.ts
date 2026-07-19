import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import HelpText from '../HelpText.vue';

describe('HelpText', () => {
  it('renders a note with the provided id and slot content', () => {
    const wrapper = mount(HelpText, {
      props: { id: 'cache-help' },
      slots: { default: 'Controls admin caching.' },
    });
    const note = wrapper.get('[role="note"]');
    expect(note.attributes('id')).toBe('cache-help');
    expect(note.text()).toContain('Controls admin caching.');
  });

  it('applies the warn tone modifier', () => {
    const wrapper = mount(HelpText, {
      props: { tone: 'warn' },
      slots: { default: 'Risky.' },
    });
    expect(wrapper.get('[role="note"]').classes()).toContain('fx-help--warn');
  });
});
