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

  it('visually hides the label text when hideLabel is true (sr-only)', () => {
    const wrapper = mount(Toggle, {
      props: {
        modelValue: false,
        label: 'Enable feature',
        hideLabel: true,
      },
    });

    const labelText = wrapper.get('.fx-toggle__label-text');
    expect(labelText.classes()).toContain('fx-visually-hidden');
    // The label text is still present in the accessibility tree.
    expect(labelText.text()).toBe('Enable feature');
  });

  it('wires aria-labelledby onto the switch and drops the internal <label> when ariaLabelledby is provided', () => {
    const wrapper = mount(Toggle, {
      props: {
        modelValue: false,
        label: 'Enable feature',
        ariaLabelledby: 'external-label-id',
      },
    });

    const sw = wrapper.get('[role="switch"]');
    expect(sw.attributes('aria-labelledby')).toBe('external-label-id');
    // With an external label we must not also render an internal <label>
    // (otherwise the accessible name is announced twice).
    expect(wrapper.find('label').exists()).toBe(false);
    // aria-label is suppressed so it does not fight aria-labelledby.
    expect(sw.attributes('aria-label')).toBeUndefined();
  });

  it('falls back to aria-label with the label text when no ariaLabelledby is set', () => {
    const wrapper = mount(Toggle, {
      props: {
        modelValue: false,
        label: 'Enable feature',
      },
    });

    const sw = wrapper.get('[role="switch"]');
    expect(sw.attributes('aria-label')).toBe('Enable feature');
  });
});
