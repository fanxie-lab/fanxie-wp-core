import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import CodeSnippet from '../CodeSnippet.vue';

/**
 * Replace `navigator.clipboard` with a controllable stub. happy-dom does not
 * ship a usable clipboard, and the real one is unavailable outside secure
 * contexts anyway — which is exactly the failure path we need to exercise.
 */
function stubClipboard(writeText: () => Promise<void>): void {
  Object.defineProperty(navigator, 'clipboard', {
    value: { writeText },
    configurable: true,
    writable: true,
  });
}

function removeClipboard(): void {
  Object.defineProperty(navigator, 'clipboard', {
    value: undefined,
    configurable: true,
    writable: true,
  });
}

describe('<CodeSnippet>', () => {
  beforeEach(() => {
    stubClipboard(() => Promise.resolve());
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('renders the code as text, never as markup', () => {
    const wrapper = mount(CodeSnippet, {
      props: { code: '<script>alert(1)</script>' },
    });

    expect(wrapper.get('code').text()).toBe('<script>alert(1)</script>');
    // The interpolated value must not have produced a real element.
    expect(wrapper.find('script').exists()).toBe(false);
  });

  it('renders the language badge with a screen-reader prefix', () => {
    const wrapper = mount(CodeSnippet, {
      props: { code: 'x', language: 'php' },
    });

    const badge = wrapper.get('.fx-snippet__lang');
    expect(badge.text()).toContain('php');
    expect(badge.get('.fx-visually-hidden').text()).toBe('Language:');
  });

  it('omits the bar entirely when there is no language and copying is disabled', () => {
    const wrapper = mount(CodeSnippet, {
      props: { code: 'x', copyable: false },
    });

    expect(wrapper.find('.fx-snippet__bar').exists()).toBe(false);
    expect(wrapper.find('.fx-snippet__copy').exists()).toBe(false);
  });

  it('builds a copy button name that carries the visible label, language and context', () => {
    const wrapper = mount(CodeSnippet, {
      props: { code: 'x', language: 'php', context: 'wp-config.php' },
    });

    const button = wrapper.get('.fx-snippet__copy');
    // WCAG 2.5.3 Label in Name — the accessible name must contain "Copy".
    expect(button.attributes('aria-label')).toBe(
      'Copy php snippet for wp-config.php',
    );
    expect(button.text()).toContain('Copy');
  });

  it('writes the code to the clipboard and confirms visibly on click', async () => {
    const writeText = vi.fn(() => Promise.resolve());
    stubClipboard(writeText);

    const wrapper = mount(CodeSnippet, { props: { code: 'define( X );' } });
    await wrapper.get('.fx-snippet__copy').trigger('click');
    await flushPromises();

    expect(writeText).toHaveBeenCalledWith('define( X );');
    expect(wrapper.get('.fx-snippet__copy').text()).toContain('Copied');
  });

  it('announces a successful copy through a polite live region', async () => {
    const wrapper = mount(CodeSnippet, { props: { code: 'x' } });

    const live = wrapper.get('[role="status"]');
    expect(live.attributes('aria-live')).toBe('polite');
    expect(live.text()).toBe('');

    await wrapper.get('.fx-snippet__copy').trigger('click');
    await flushPromises();

    expect(wrapper.get('[role="status"]').text()).toBe(
      'Snippet copied to clipboard.',
    );
  });

  it('emits copied on success', async () => {
    const wrapper = mount(CodeSnippet, { props: { code: 'x' } });
    await wrapper.get('.fx-snippet__copy').trigger('click');
    await flushPromises();

    expect(wrapper.emitted('copied')).toHaveLength(1);
    expect(wrapper.emitted('copy-failed')).toBeUndefined();
  });

  it('announces and emits copy-failed when the clipboard rejects', async () => {
    stubClipboard(() => Promise.reject(new Error('denied')));

    const wrapper = mount(CodeSnippet, { props: { code: 'x' } });
    await wrapper.get('.fx-snippet__copy').trigger('click');
    await flushPromises();

    expect(wrapper.get('.fx-snippet__copy').text()).toContain('Copy');
    expect(wrapper.get('[role="status"]').text()).toBe(
      'Copy failed. Select the snippet and copy it manually.',
    );
    expect(wrapper.emitted('copy-failed')).toEqual([
      ['Copy failed. Select the snippet and copy it manually.'],
    ]);
  });

  it('treats a missing clipboard API (insecure context) as a copy failure', async () => {
    removeClipboard();

    const wrapper = mount(CodeSnippet, { props: { code: 'x' } });
    await wrapper.get('.fx-snippet__copy').trigger('click');
    await flushPromises();

    expect(wrapper.emitted('copy-failed')).toHaveLength(1);
    expect(wrapper.get('[role="status"]').text()).toContain('Copy failed.');
  });

  it('clears the confirmation and the announcement after confirmDuration', async () => {
    vi.useFakeTimers();
    const wrapper = mount(CodeSnippet, {
      props: { code: 'x', confirmDuration: 1000 },
    });

    await wrapper.get('.fx-snippet__copy').trigger('click');
    // Let the awaited clipboard promise settle before advancing timers.
    await vi.advanceTimersByTimeAsync(0);
    expect(wrapper.get('.fx-snippet__copy').text()).toContain('Copied');

    await vi.advanceTimersByTimeAsync(1000);
    await flushPromises();

    expect(wrapper.get('.fx-snippet__copy').text()).toContain('Copy');
    expect(wrapper.get('[role="status"]').text()).toBe('');
  });
});
