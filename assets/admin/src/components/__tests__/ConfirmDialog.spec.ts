import { afterEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ConfirmDialog from '../ConfirmDialog.vue';

function mountDialog() {
  return mount(ConfirmDialog, {
    attachTo: document.body,
    props: {
      open: true,
      title: 'Hide the login screen?',
      confirmLabel: 'Hide login',
    },
    slots: {
      default: 'This can lock you out.',
    },
  });
}

describe('<ConfirmDialog>', () => {
  afterEach(() => {
    // Guard against a leaked isolation attribute bleeding into the next test.
    document.body.removeAttribute('inert');
    document.body.removeAttribute('aria-hidden');
  });

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

  it('renders the plain-text message when no default slot is given', () => {
    const wrapper = mount(ConfirmDialog, {
      attachTo: document.body,
      props: {
        open: true,
        title: 'Remove ban?',
        message: 'The subject will be able to sign in again.',
      },
    });
    expect(wrapper.get('.fx-confirm__body').text()).toBe(
      'The subject will be able to sign in again.',
    );
    wrapper.unmount();
  });

  it('is not rendered while closed', () => {
    const wrapper = mount(ConfirmDialog, {
      attachTo: document.body,
      props: { open: false, title: 'Nope' },
    });
    expect(wrapper.find('[role="alertdialog"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('emits confirm (and closes) when the confirm button is clicked', async () => {
    const wrapper = mountDialog();
    await wrapper.get('.fx-confirm__confirm').trigger('click');
    expect(wrapper.emitted('confirm')).toHaveLength(1);
    expect(wrapper.emitted('cancel')).toBeUndefined();
    // Closing is signalled via update:open so v-model:open consumers stay in sync.
    expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
    wrapper.unmount();
  });

  it('emits cancel (and closes) when the cancel button is clicked', async () => {
    const wrapper = mountDialog();
    await wrapper.get('.fx-confirm__cancel').trigger('click');
    expect(wrapper.emitted('cancel')).toHaveLength(1);
    expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
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

  it('applies the destructive style to the confirm button in danger tone', () => {
    const wrapper = mount(ConfirmDialog, {
      attachTo: document.body,
      props: {
        open: true,
        title: 'Clear lockout?',
        message: 'The subject may retry immediately.',
        confirmLabel: 'Clear lockout',
        tone: 'danger',
      },
    });
    const confirm = wrapper.get('.fx-confirm__confirm');
    expect(confirm.classes()).toContain('fx-confirm__button--danger');
    expect(confirm.classes()).not.toContain('fx-confirm__button--primary');
    wrapper.unmount();
  });

  it('marks sibling background content inert + aria-hidden while open and restores it on close', () => {
    // A stand-in for the app root: a sibling of the dialog under <body>.
    const background = document.createElement('div');
    background.setAttribute('data-testid', 'app-background');
    document.body.appendChild(background);

    const wrapper = mountDialog();

    // Background isolated while the modal is open...
    expect(background.hasAttribute('inert')).toBe(true);
    expect(background.getAttribute('aria-hidden')).toBe('true');
    // ...but the dialog itself is never inerted.
    expect(wrapper.get('.fx-confirm').attributes('inert')).toBeUndefined();

    wrapper.unmount();

    // ...and fully restored once it closes.
    expect(background.hasAttribute('inert')).toBe(false);
    expect(background.hasAttribute('aria-hidden')).toBe(false);

    background.remove();
  });

  it('restores focus to the previously focused trigger on close', () => {
    const trigger = document.createElement('button');
    trigger.textContent = 'Open';
    document.body.appendChild(trigger);
    trigger.focus();
    expect(document.activeElement).toBe(trigger);

    const wrapper = mountDialog();
    // Focus moved into the dialog while open.
    expect(document.activeElement).not.toBe(trigger);

    wrapper.unmount();
    // Focus handed back to the trigger.
    expect(document.activeElement).toBe(trigger);

    trigger.remove();
  });
});
