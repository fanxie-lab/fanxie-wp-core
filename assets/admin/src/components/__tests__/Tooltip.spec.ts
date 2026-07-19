import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Tooltip from '../Tooltip.vue';

describe('Tooltip', () => {
  it('exposes an accessible trigger describing the tooltip text', async () => {
    const wrapper = mount(Tooltip, {
      props: { text: 'Explains the setting.' },
    });
    const button = wrapper.get('button');
    expect(button.attributes('aria-label')).toBe('More information');
    await button.trigger('focus');
    const describedBy = button.attributes('aria-describedby');
    expect(describedBy).toBeTruthy();
    const bubble = wrapper.get(`#${describedBy!}`);
    expect(bubble.text()).toContain('Explains the setting.');
  });

  it('reveals on focus and hides on Escape', async () => {
    const wrapper = mount(Tooltip, { props: { text: 'Hi' } });
    const button = wrapper.get('button');
    await button.trigger('focus');
    expect(wrapper.get('[role="tooltip"]').isVisible()).toBe(true);
    await button.trigger('keydown', { key: 'Escape' });
    expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);
  });
});
