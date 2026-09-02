import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import TextField from '../TextField.vue';

describe('<TextField>', () => {
  it('associates the visible label with the input', () => {
    const wrapper = mount(TextField, {
      props: { modelValue: '', label: 'Retention days' },
    });

    const id = wrapper.get('input').attributes('id');
    expect(wrapper.get('label').attributes('for')).toBe(id);
  });

  it('emits update:modelValue on input', async () => {
    const wrapper = mount(TextField, {
      props: { modelValue: '', label: 'L' },
    });

    await wrapper.get('input').setValue('42');

    expect(wrapper.emitted('update:modelValue')).toEqual([['42']]);
  });

  it('emits blur so wrappers can normalise a half-typed value', async () => {
    const wrapper = mount(TextField, {
      props: { modelValue: '', label: 'L' },
    });

    await wrapper.get('input').trigger('blur');

    expect(wrapper.emitted('blur')).toHaveLength(1);
  });

  it('puts numeric bounds on the input, not on the wrapper element', () => {
    const wrapper = mount(TextField, {
      props: {
        modelValue: '30',
        label: 'Days',
        type: 'number',
        min: 1,
        max: 365,
        step: 1,
        inputmode: 'numeric',
      },
    });

    const input = wrapper.get('input');
    expect(input.attributes('min')).toBe('1');
    expect(input.attributes('max')).toBe('365');
    expect(input.attributes('step')).toBe('1');
    expect(input.attributes('inputmode')).toBe('numeric');

    // This component does not set inheritAttrs:false, so bounds passed as bare
    // attributes would have silently landed here and enforced nothing.
    const root = wrapper.get('.fx-text-field');
    expect(root.attributes('min')).toBeUndefined();
    expect(root.attributes('max')).toBeUndefined();
  });

  it('omits the bound attributes entirely when they are not given', () => {
    const wrapper = mount(TextField, {
      props: { modelValue: '', label: 'L', type: 'number' },
    });

    const input = wrapper.get('input');
    expect(input.attributes('min')).toBeUndefined();
    expect(input.attributes('max')).toBeUndefined();
    expect(input.attributes('step')).toBeUndefined();
  });

  it('links help text to the input via aria-describedby', () => {
    const wrapper = mount(TextField, {
      props: { modelValue: '', label: 'L', help: 'Explain the field.' },
    });

    const helpId = wrapper.get('.fx-text-field__help').attributes('id');
    expect(wrapper.get('input').attributes('aria-describedby')).toBe(helpId);
  });

  it('marks the input invalid and announces the error', () => {
    const wrapper = mount(TextField, {
      props: { modelValue: '', label: 'L', error: 'Out of range.' },
    });

    const input = wrapper.get('input');
    expect(input.attributes('aria-invalid')).toBe('true');
    const errorEl = wrapper.get('[role="alert"]');
    expect(errorEl.text()).toBe('Out of range.');
    expect(input.attributes('aria-describedby')).toBe(errorEl.attributes('id'));
  });
});
