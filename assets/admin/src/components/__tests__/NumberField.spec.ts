import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import NumberField from '../NumberField.vue';

function mountField(modelValue = 10, extra: Record<string, unknown> = {}) {
  return mount(NumberField, {
    props: {
      id: 'keep-days',
      modelValue,
      label: 'Keep for',
      help: 'Older rows are purged.',
      unit: 'days',
      min: 1,
      max: 365,
      ...extra,
    },
  });
}

function lastCommitted(
  wrapper: ReturnType<typeof mountField>,
): number | undefined {
  const events = wrapper.emitted('update:modelValue');
  if (!events) return undefined;
  return events.at(-1)?.[0] as number;
}

describe('<NumberField>', () => {
  it('renders a labelled number input with the supplied bounds on the input', () => {
    const input = mountField().get('input');
    expect(input.attributes('id')).toBe('keep-days');
    expect(input.attributes('type')).toBe('number');
    expect(input.attributes('min')).toBe('1');
    expect(input.attributes('max')).toBe('365');
    expect(input.attributes('step')).toBe('1');
    expect(input.attributes('inputmode')).toBe('numeric');
    expect((input.element as HTMLInputElement).value).toBe('10');
  });

  it('associates a native label with the input', () => {
    const wrapper = mountField();
    expect(wrapper.get('label').attributes('for')).toBe('keep-days');
    expect(wrapper.get('label').text()).toContain('Keep for');
  });

  it('spells the allowed range out in the help text and wires it up', () => {
    const wrapper = mountField();
    const help = wrapper.get('.fx-text-field__help');
    expect(help.text()).toContain('Older rows are purged.');
    expect(help.text()).toContain('Allowed range: 1 to 365 days.');
    expect(wrapper.get('input').attributes('aria-describedby')).toBe(
      help.attributes('id'),
    );
  });

  it('omits the unit from the range hint when there is none', () => {
    const wrapper = mountField(10, { unit: '' });
    expect(wrapper.get('.fx-text-field__help').text()).toContain(
      'Allowed range: 1 to 365.',
    );
  });

  it('merges an external describedby id after the help id', () => {
    const wrapper = mountField(10, { describedby: 'ext-help' });
    const help = wrapper.get('.fx-text-field__help');
    expect(wrapper.get('input').attributes('aria-describedby')).toBe(
      `${help.attributes('id') ?? ''} ext-help`,
    );
  });

  it('commits an in-range integer as a number, not a string', async () => {
    const wrapper = mountField();
    await wrapper.get('input').setValue('40');
    expect(lastCommitted(wrapper)).toBe(40);
    expect(typeof lastCommitted(wrapper)).toBe('number');
  });

  it('never commits an empty string when the field is cleared', async () => {
    const wrapper = mountField();
    await wrapper.get('input').setValue('');
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
  });

  it('never commits NaN for unparseable input', async () => {
    const wrapper = mountField();
    await wrapper.get('input').setValue('abc');
    for (const [value] of wrapper.emitted('update:modelValue') ?? []) {
      expect(Number.isNaN(value)).toBe(false);
    }
  });

  it('clamps a value typed above the maximum', async () => {
    const wrapper = mountField();
    await wrapper.get('input').setValue('99999');
    expect(lastCommitted(wrapper)).toBe(365);
  });

  it('clamps a value typed below the minimum', async () => {
    const wrapper = mountField();
    await wrapper.get('input').setValue('0');
    expect(lastCommitted(wrapper)).toBe(1);
  });

  it('snaps the visible field back to the committed value on blur', async () => {
    const wrapper = mountField();
    const input = wrapper.get('input');
    await input.setValue('');
    expect((input.element as HTMLInputElement).value).toBe('');
    await input.trigger('blur');
    expect((input.element as HTMLInputElement).value).toBe('10');
    expect(lastCommitted(wrapper)).toBe(10);
  });

  it('clamps to the supplied range on blur', async () => {
    const w = mount(NumberField, {
      props: {
        id: 'f',
        modelValue: 10,
        label: 'Days',
        help: '',
        unit: 'days',
        min: 1,
        max: 365,
      },
    });
    const input = w.get('input');
    await input.setValue('999');
    await input.trigger('blur');
    expect(w.emitted('update:modelValue')?.at(-1)).toEqual([365]);
    expect((input.element as HTMLInputElement).value).toBe('365');
  });

  it('follows an external model change, e.g. a SaveBar reset', async () => {
    const wrapper = mountField();
    await wrapper.get('input').setValue('50');
    await wrapper.setProps({ modelValue: 50 });
    expect((wrapper.get('input').element as HTMLInputElement).value).toBe('50');
    await wrapper.setProps({ modelValue: 10 });
    expect((wrapper.get('input').element as HTMLInputElement).value).toBe('10');
  });

  it('does not fight the user when v-model echoes back what they just typed', async () => {
    const wrapper = mountField();
    const input = wrapper.get('input');
    await input.setValue('4');
    await wrapper.setProps({ modelValue: 4 });
    expect((input.element as HTMLInputElement).value).toBe('4');
  });

  it('is inert when disabled', () => {
    const w = mount(NumberField, {
      props: {
        id: 'f',
        modelValue: 10,
        label: 'Keep',
        help: '',
        unit: '',
        min: 0,
        max: 50,
        disabled: true,
      },
    });
    expect(w.get('input').attributes('disabled')).toBeDefined();
  });
});
