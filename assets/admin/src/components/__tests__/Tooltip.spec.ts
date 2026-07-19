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

  it('reveals on hover and stays visible when the pointer moves onto the bubble', async () => {
    // WCAG 2.1 SC 1.4.13 "Hoverable": a pointer user must be able to travel
    // from the trigger onto the bubble without the tooltip vanishing.
    //
    // Scope: this asserts only the container-scoped hide mechanism — hover
    // handlers live on the wrapping `.fx-tip`, so a `mouseleave` from the inner
    // button no longer dismisses the bubble. The physical gap-bridge that
    // actually closes the literal pixel gap between trigger and bubble is
    // CSS-only (`.fx-tip__bubble::after`, sized by `--fx-tip-gap`) and cannot be
    // exercised under happy-dom, which does no layout or hit-testing. So this
    // spec is necessary but NOT sufficient on its own for 1.4.13 sign-off; the
    // bridge geometry needs a real browser (manual/e2e) to verify.
    const wrapper = mount(Tooltip, { props: { text: 'Explains it.' } });
    const container = wrapper.get('.fx-tip');
    const button = wrapper.get('button');

    // (a) Hovering the trigger reveals the bubble.
    await container.trigger('mouseenter');
    expect(wrapper.get('[role="tooltip"]').isVisible()).toBe(true);

    // (b) Moving the pointer off the trigger toward the bubble must NOT hide
    // it. Hide is bound to the wrapping container, not the button, so leaving
    // the button (an inner element) does not fire the container's mouseleave
    // — the bubble stays reachable/hoverable.
    await button.trigger('mouseleave');
    expect(wrapper.find('[role="tooltip"]').exists()).toBe(true);
    expect(wrapper.get('[role="tooltip"]').isVisible()).toBe(true);

    // Leaving the whole container (pointer exits trigger + bubble) hides it.
    await container.trigger('mouseleave');
    expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);
  });
});
