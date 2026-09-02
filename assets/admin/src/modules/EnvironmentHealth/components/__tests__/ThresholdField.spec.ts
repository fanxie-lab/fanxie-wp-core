import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ThresholdField from '../ThresholdField.vue';

function mountField(modelValue = 365) {
  return mount(ThresholdField, {
    props: {
      fieldKey: 'abandoned_warning_days' as const,
      modelValue,
      label: 'Warn after this many days',
      help: 'Older than this counts as possibly abandoned.',
      unit: 'days',
    },
  });
}

/** Last value committed to the model, or undefined if nothing was committed. */
function lastCommitted(
  wrapper: ReturnType<typeof mountField>,
): number | undefined {
  const events = wrapper.emitted('update:modelValue');
  if (!events) return undefined;
  return events.at(-1)?.[0] as number;
}

describe('<ThresholdField>', () => {
  it('renders a labelled number input with the schema bounds on the input itself', () => {
    const wrapper = mountField();
    const input = wrapper.get('input');

    // Bounds must reach the <input>, not the wrapper div.
    expect(input.attributes('type')).toBe('number');
    expect(input.attributes('min')).toBe('30');
    expect(input.attributes('max')).toBe('3650');
    expect(input.attributes('step')).toBe('1');
    expect(input.attributes('inputmode')).toBe('numeric');
    expect((input.element as HTMLInputElement).value).toBe('365');
  });

  it('associates a native label with the input', () => {
    const wrapper = mountField();
    const id = wrapper.get('input').attributes('id');
    const label = wrapper.get('label');
    expect(label.attributes('for')).toBe(id);
    expect(label.text()).toContain('Warn after this many days');
  });

  it('spells the allowed range out in the help text', () => {
    const wrapper = mountField();
    const help = wrapper.get('.fx-text-field__help');
    expect(help.text()).toContain(
      'Older than this counts as possibly abandoned.',
    );
    expect(help.text()).toContain('Allowed range: 30 to 3650 days.');
    // And the help is wired to the input for screen readers.
    expect(wrapper.get('input').attributes('aria-describedby')).toBe(
      help.attributes('id'),
    );
  });

  it('commits an in-range integer as a number, not a string', async () => {
    const wrapper = mountField();
    await wrapper.get('input').setValue('400');

    expect(lastCommitted(wrapper)).toBe(400);
    expect(typeof lastCommitted(wrapper)).toBe('number');
  });

  it('never commits an empty string when the field is cleared', async () => {
    const wrapper = mountField();
    await wrapper.get('input').setValue('');

    // Nothing was committed — the last good value still stands.
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
  });

  it('never commits NaN for unparseable input', async () => {
    const wrapper = mountField();
    await wrapper.get('input').setValue('abc');

    const committed = wrapper.emitted('update:modelValue');
    if (committed) {
      for (const [value] of committed) {
        expect(Number.isNaN(value)).toBe(false);
      }
    }
  });

  it('clamps a value typed above the maximum', async () => {
    const wrapper = mountField();
    await wrapper.get('input').setValue('99999');
    expect(lastCommitted(wrapper)).toBe(3650);
  });

  it('clamps a value typed below the minimum', async () => {
    const wrapper = mountField();
    await wrapper.get('input').setValue('0');
    expect(lastCommitted(wrapper)).toBe(30);
  });

  it('snaps the visible field back to the committed value on blur', async () => {
    const wrapper = mountField();
    const input = wrapper.get('input');

    await input.setValue('');
    // Mid-edit the box is empty but the model still holds 365.
    expect((input.element as HTMLInputElement).value).toBe('');

    await input.trigger('blur');

    expect((input.element as HTMLInputElement).value).toBe('365');
    expect(lastCommitted(wrapper)).toBe(365);
  });

  it('reconciles the field after an out-of-range entry on blur', async () => {
    const wrapper = mountField();
    const input = wrapper.get('input');

    await input.setValue('99999');
    await input.trigger('blur');

    expect((input.element as HTMLInputElement).value).toBe('3650');
    expect(lastCommitted(wrapper)).toBe(3650);
  });

  it('follows an external model change, e.g. a SaveBar reset', async () => {
    const wrapper = mountField();
    await wrapper.get('input').setValue('500');
    // Stand in for v-model feeding the committed value back down.
    await wrapper.setProps({ modelValue: 500 });
    expect((wrapper.get('input').element as HTMLInputElement).value).toBe(
      '500',
    );

    // Now the reset lands.
    await wrapper.setProps({ modelValue: 365 });

    expect((wrapper.get('input').element as HTMLInputElement).value).toBe(
      '365',
    );
  });

  it('does not fight the user when v-model echoes back what they just typed', async () => {
    const wrapper = mountField();
    const input = wrapper.get('input');

    await input.setValue('40');
    await wrapper.setProps({ modelValue: 40 });

    // The echo must not rewrite the draft out from under a half-typed "400".
    expect((input.element as HTMLInputElement).value).toBe('40');
  });

  it('uses the bounds for its own fieldKey', () => {
    const wrapper = mount(ThresholdField, {
      props: {
        fieldKey: 'cron_overdue_minutes' as const,
        modelValue: 60,
        label: 'Overdue after',
        help: 'Late by this much.',
        unit: 'minutes',
      },
    });

    const input = wrapper.get('input');
    expect(input.attributes('min')).toBe('1');
    expect(input.attributes('max')).toBe('1440');
    expect(wrapper.get('.fx-text-field__help').text()).toContain(
      'Allowed range: 1 to 1440 minutes.',
    );
  });
});
