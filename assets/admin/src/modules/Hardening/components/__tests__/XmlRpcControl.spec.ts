import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import XmlRpcControl from '../XmlRpcControl.vue';

describe('<XmlRpcControl>', () => {
  it('hides the IP textarea when mode is not restrict_ips', () => {
    const wrapper = mount(XmlRpcControl, {
      props: { mode: 'disabled', allowedIps: [] },
    });
    expect(wrapper.find('textarea').exists()).toBe(false);
  });

  it('shows the IP textarea when mode is restrict_ips', () => {
    const wrapper = mount(XmlRpcControl, {
      props: { mode: 'restrict_ips', allowedIps: ['203.0.113.4'] },
    });
    const textarea = wrapper.get('textarea');
    expect((textarea.element as HTMLTextAreaElement).value).toBe('203.0.113.4');
  });

  it('emits update:mode when the select changes', async () => {
    const wrapper = mount(XmlRpcControl, {
      props: { mode: 'disabled', allowedIps: [] },
    });
    const select = wrapper.get('select');
    await select.setValue('restrict_ips');
    const emitted = wrapper.emitted('update:mode');
    expect(emitted).toBeTruthy();
    expect(emitted?.[0]).toEqual(['restrict_ips']);
  });

  it('trims + drops blank lines when emitting update:allowedIps', async () => {
    const wrapper = mount(XmlRpcControl, {
      props: { mode: 'restrict_ips', allowedIps: [] },
    });
    const textarea = wrapper.get('textarea');
    await textarea.setValue('  203.0.113.4  \n\n203.0.113.5\n');
    const emitted = wrapper.emitted('update:allowedIps');
    expect(emitted).toBeTruthy();
    // Last emission should contain the two cleaned IPs.
    const last = emitted?.[emitted.length - 1];
    expect(last).toEqual([['203.0.113.4', '203.0.113.5']]);
  });

  it('flags non-IP lines visually (aria-invalid) without blocking the emit', () => {
    const wrapper = mount(XmlRpcControl, {
      props: { mode: 'restrict_ips', allowedIps: ['not-an-ip'] },
    });
    const textarea = wrapper.get('textarea');
    expect(textarea.attributes('aria-invalid')).toBe('true');
    expect(textarea.classes()).toContain('fx-xmlrpc__textarea--invalid');
    expect(wrapper.get('[role="alert"]').text()).toContain('may not be valid');
  });

  it('does not flag valid IPv4 and IPv6 entries', () => {
    const wrapper = mount(XmlRpcControl, {
      props: {
        mode: 'restrict_ips',
        allowedIps: ['203.0.113.4', '2001:db8::1'],
      },
    });
    const textarea = wrapper.get('textarea');
    expect(textarea.attributes('aria-invalid')).toBeUndefined();
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
  });

  it('disables both controls when `disabled` prop is true', () => {
    const wrapper = mount(XmlRpcControl, {
      props: { mode: 'restrict_ips', allowedIps: [], disabled: true },
    });
    expect((wrapper.get('select').element as HTMLSelectElement).disabled).toBe(
      true,
    );
    expect(
      (wrapper.get('textarea').element as HTMLTextAreaElement).disabled,
    ).toBe(true);
  });

  describe('ChecklistItem embedding (no duplicate label / help)', () => {
    // Bug 2 guard: the inner Select must not render its own visible label or
    // help paragraph when embedded in the ChecklistItem wrapper — the wrapper
    // owns the row label + description and rendering both duplicates text.
    it('does not render the Select’s visible <label> element when ariaLabelledby is provided (embedded case)', () => {
      const wrapper = mount(XmlRpcControl, {
        props: {
          mode: 'disabled',
          allowedIps: [],
          ariaLabelledby: 'fx-row-label-xmlrpc',
        },
      });
      // No <label> tied to the <select> — the external ChecklistItem label
      // supplies the accessible name via ariaLabelledby.
      expect(wrapper.find('.fx-select__label').exists()).toBe(false);
    });

    it('visually hides the Select’s <label> even when ariaLabelledby is absent (standalone embed fallback)', () => {
      // When XmlRpcControl is embedded without a wrapper labelId wired in,
      // `hide-label` still keeps the <label> out of sight visually. It
      // remains in the DOM so the <select> keeps a text accessible name.
      const wrapper = mount(XmlRpcControl, {
        props: { mode: 'disabled', allowedIps: [] },
      });
      const label = wrapper.find('.fx-select__label');
      expect(label.exists()).toBe(true);
      expect(label.classes()).toContain('fx-visually-hidden');
    });

    it('does not render the Select’s help paragraph', () => {
      const wrapper = mount(XmlRpcControl, {
        props: { mode: 'disabled', allowedIps: [] },
      });
      expect(wrapper.find('.fx-select__help').exists()).toBe(false);
    });

    it('forwards ariaLabelledby onto the underlying <select>', () => {
      const wrapper = mount(XmlRpcControl, {
        props: {
          mode: 'disabled',
          allowedIps: [],
          ariaLabelledby: 'fx-row-label-xmlrpc',
        },
      });
      expect(wrapper.get('select').attributes('aria-labelledby')).toBe(
        'fx-row-label-xmlrpc',
      );
    });
  });
});
