import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ConfirmDialog from '../ConfirmDialog.vue';

function mountDialog() {
  return mount(ConfirmDialog, {
    attachTo: document.body,
    props: {
      title: 'Hide the login screen?',
      confirmLabel: 'Hide login',
    },
    slots: {
      default: 'This can lock you out.',
    },
  });
}

describe('<ConfirmDialog>', () => {
  it('exposes an accessible alertdialog with a labelled + described panel', () => {
    const wrapper = mountDialog();
    const panel = wrapper.get('[role="alertdialog"]');

    expect(panel.attributes('aria-modal')).toBe('true');
    const labelledby = panel.attributes('aria-labelledby');
    const describedby = panel.attributes('aria-describedby');
    expect(labelledby).toBeTruthy();
    expect(describedby).toBeTruthy();
    expect(wrapper.get(`#${labelledby!}`).text()).toContain(
      'Hide the login screen?',
    );
    expect(wrapper.get(`#${describedby!}`).text()).toContain(
      'This can lock you out.',
    );
    wrapper.unmount();
  });

  it('emits confirm when the confirm button is clicked', async () => {
    const wrapper = mountDialog();
    await wrapper.get('.fx-confirm__confirm').trigger('click');
    expect(wrapper.emitted('confirm')).toHaveLength(1);
    expect(wrapper.emitted('cancel')).toBeUndefined();
    wrapper.unmount();
  });

  it('emits cancel when the cancel button is clicked', async () => {
    const wrapper = mountDialog();
    await wrapper.get('.fx-confirm__cancel').trigger('click');
    expect(wrapper.emitted('cancel')).toHaveLength(1);
    wrapper.unmount();
  });

  it('emits cancel when the backdrop is clicked', async () => {
    const wrapper = mountDialog();
    await wrapper.get('.fx-confirm__backdrop').trigger('click');
    expect(wrapper.emitted('cancel')).toHaveLength(1);
    wrapper.unmount();
  });

  it('emits cancel on Escape', async () => {
    const wrapper = mountDialog();
    await wrapper.get('[role="alertdialog"]').trigger('keydown', {
      key: 'Escape',
    });
    expect(wrapper.emitted('cancel')).toHaveLength(1);
    wrapper.unmount();
  });

  it('moves focus onto the dismiss action on open', () => {
    const wrapper = mountDialog();
    expect(document.activeElement).toBe(
      wrapper.get('.fx-confirm__cancel').element,
    );
    wrapper.unmount();
  });
});
