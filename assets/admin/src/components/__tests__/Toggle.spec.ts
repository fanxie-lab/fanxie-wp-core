import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Toggle from '@/components/Toggle.vue';

describe('<Toggle>', () => {
  it('renders a button with role="switch" and aria-checked reflecting the model value', () => {
    const wrapper = mount(Toggle, {
      props: { modelValue: false, label: 'Enable feature' },
    });

    const sw = wrapper.get('[role="switch"]');
    expect(sw.attributes('aria-checked')).toBe('false');
  });

  it('reflects aria-checked=true when modelValue is true', () => {
    const wrapper = mount(Toggle, {
      props: { modelValue: true, label: 'Enable feature' },
    });

    expect(wrapper.get('[role="switch"]').attributes('aria-checked')).toBe(
      'true',
    );
  });

  it('emits update:modelValue with the flipped value on click', async () => {
    const wrapper = mount(Toggle, {
      props: { modelValue: false, label: 'Enable feature' },
    });

    await wrapper.get('[role="switch"]').trigger('click');

    const emitted = wrapper.emitted('update:modelValue');
    expect(emitted).toBeTruthy();
    expect(emitted?.[0]).toEqual([true]);
  });

  it('emits update:modelValue on Space keydown', async () => {
    const wrapper = mount(Toggle, {
      props: { modelValue: false, label: 'Enable feature' },
    });

    await wrapper.get('[role="switch"]').trigger('keydown', { key: ' ' });

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([true]);
  });

  it('does not emit when disabled', async () => {
    const wrapper = mount(Toggle, {
      props: { modelValue: false, label: 'Enable feature', disabled: true },
    });

    await wrapper.get('[role="switch"]').trigger('click');
    await wrapper.get('[role="switch"]').trigger('keydown', { key: ' ' });

    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
  });

  it('wires the label to the switch via for/id so clicking the label focuses the switch', () => {
    const wrapper = mount(Toggle, {
      props: { modelValue: false, label: 'Enable feature' },
    });

    const sw = wrapper.get('[role="switch"]');
    const label = wrapper.get('label');
    const switchId = sw.attributes('id');

    expect(switchId).toBeTruthy();
    expect(label.attributes('for')).toBe(switchId);
  });

  it('adds aria-describedby pointing at the description when description is set', () => {
    const wrapper = mount(Toggle, {
      props: {
        modelValue: false,
        label: 'Enable feature',
        description: 'This turns the feature on site-wide.',
      },
    });

    const sw = wrapper.get('[role="switch"]');
    const describedBy = sw.attributes('aria-describedby');
    expect(describedBy).toBeTruthy();

    const desc = wrapper.get(`#${describedBy!}`);
    expect(desc.text()).toContain('This turns the feature on site-wide.');
  });
});
