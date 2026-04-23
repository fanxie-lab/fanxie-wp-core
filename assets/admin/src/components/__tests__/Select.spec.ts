import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Select from '@/components/Select.vue';

/**
 * Vitest/Vue compiles .vue SFCs at import time and does not inject the
 * component <style> block into the test DOM, so asserting on computed
 * styles or a live <style> tag is unreliable. For CSS contract tests we
 * read the SFC source directly — the <style scoped> block is the source
 * of truth for what ships to the browser.
 *
 * `process.cwd()` resolves to the `assets/admin/` workspace when vitest
 * is invoked via `npm run test` from that directory (the standard path
 * used by CI), so the path below anchors there.
 */
const SELECT_SFC_SOURCE = readFileSync(
  resolve(process.cwd(), 'src/components/Select.vue'),
  'utf8',
);

describe('<Select>', () => {
  const options = [
    { value: 'a', label: 'Alpha' },
    { value: 'b', label: 'Bravo' },
  ];

  it('renders a label tied to the underlying <select> via for/id', () => {
    const wrapper = mount(Select, {
      props: { modelValue: 'a', label: 'Pick one', options },
    });
    const label = wrapper.get('label');
    const select = wrapper.get('select');
    expect(label.attributes('for')).toBe(select.attributes('id'));
  });

  it('emits update:modelValue when the underlying <select> changes', async () => {
    const wrapper = mount(Select, {
      props: { modelValue: 'a', label: 'Pick one', options },
    });
    await wrapper.get('select').setValue('b');
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['b']);
  });

  it('renders a decorative caret sibling (aria-hidden) so the native arrow is replaced', () => {
    // WP admin styles suppress the native arrow on many selects, so we draw
    // our own. Guards against regressions where the caret markup is removed.
    const wrapper = mount(Select, {
      props: { modelValue: 'a', label: 'Pick one', options },
    });
    const caret = wrapper.get('.fx-select__caret');
    expect(caret.attributes('aria-hidden')).toBe('true');
    expect(caret.find('svg').exists()).toBe(true);
  });

  it('renders help text linked via aria-describedby when help is provided', () => {
    const wrapper = mount(Select, {
      props: {
        modelValue: 'a',
        label: 'Pick one',
        options,
        help: 'Choose carefully',
      },
    });
    const select = wrapper.get('select');
    const helpId = select.attributes('aria-describedby');
    expect(helpId).toBeTruthy();
    expect(wrapper.get(`#${helpId!}`).text()).toBe('Choose carefully');
  });

  it('marks aria-invalid and shows an alert when error is set', () => {
    const wrapper = mount(Select, {
      props: {
        modelValue: 'a',
        label: 'Pick one',
        options,
        error: 'Required',
      },
    });
    expect(wrapper.get('select').attributes('aria-invalid')).toBe('true');
    expect(wrapper.get('[role="alert"]').text()).toBe('Required');
  });

  describe('external labelling (hideLabel + ariaLabelledby)', () => {
    it('drops the <label> element entirely when ariaLabelledby is set', () => {
      const wrapper = mount(Select, {
        props: {
          modelValue: 'a',
          label: 'Pick one',
          options,
          ariaLabelledby: 'external-label',
        },
      });
      expect(wrapper.find('label').exists()).toBe(false);
    });

    it('forwards ariaLabelledby onto the <select> as aria-labelledby', () => {
      const wrapper = mount(Select, {
        props: {
          modelValue: 'a',
          label: 'Pick one',
          options,
          ariaLabelledby: 'external-label',
        },
      });
      expect(wrapper.get('select').attributes('aria-labelledby')).toBe(
        'external-label',
      );
    });

    it('keeps the <label> but marks it visually hidden when only hideLabel is set', () => {
      const wrapper = mount(Select, {
        props: {
          modelValue: 'a',
          label: 'Pick one',
          options,
          hideLabel: true,
        },
      });
      const label = wrapper.get('label');
      // Label remains in the DOM (so the <select> keeps its accessible name
      // via `for=`/`id=`), but is visually hidden.
      expect(label.classes()).toContain('fx-visually-hidden');
    });

    it('suppresses the help paragraph when hideHelp is true', () => {
      const wrapper = mount(Select, {
        props: {
          modelValue: 'a',
          label: 'Pick one',
          options,
          help: 'Choose carefully',
          hideHelp: true,
        },
      });
      expect(wrapper.find('.fx-select__help').exists()).toBe(false);
      // aria-describedby must not reference an id that no longer exists in
      // the DOM.
      expect(
        wrapper.get('select').attributes('aria-describedby'),
      ).toBeUndefined();
    });

    it('still renders error text even when hideHelp is true', () => {
      const wrapper = mount(Select, {
        props: {
          modelValue: 'a',
          label: 'Pick one',
          options,
          help: 'Choose carefully',
          hideHelp: true,
          error: 'Required',
        },
      });
      expect(wrapper.find('.fx-select__help').exists()).toBe(false);
      expect(wrapper.get('[role="alert"]').text()).toBe('Required');
    });
  });

  describe('caret alignment regressions', () => {
    // The caret positioning bug recurred because WP admin's global
    // `select { max-width: 25em }` capped the <select>'s rendered width
    // while the wrap still stretched to 100% of the column — caret ended
    // up in empty space to the right of the select border. The fix is:
    //   (1) override `max-width` on the <select> with enough specificity,
    //   (2) put the <select> and caret in the same single-cell grid so
    //       the caret's right edge tracks the select's right edge.
    // These tests guard both halves of the contract.
    it('keeps the <select> and caret as sole children of the control-wrap', () => {
      const wrapper = mount(Select, {
        props: { modelValue: 'a', label: 'Pick one', options },
      });
      const wrap = wrapper.get('.fx-select__control-wrap');
      const children = wrap.element.children;
      expect(children.length).toBe(2);
      expect(children[0]?.tagName).toBe('SELECT');
      expect(children[1]?.classList.contains('fx-select__caret')).toBe(true);
    });

    /**
     * Strip CSS comments from the SFC source so simple regex assertions
     * below aren't fooled by `{`/`}` characters that appear inside `/* ... *\/`
     * comment text (e.g. the note about WP admin's `select { max-width: 25em }`).
     */
    const stylesWithoutComments = SELECT_SFC_SOURCE.replace(
      /\/\*[\s\S]*?\*\//g,
      '',
    );

    it('scopes the control rule with enough specificity to beat WP admin `select` rules', () => {
      // Guards two things in the SFC's <style scoped> block:
      //   - the <select> rule is scoped with a compound selector that
      //     out-specifies WP admin's bare `select { max-width: 25em }`,
      //   - `max-width: none` is declared so the select fills the wrap
      //     even when WP admin's rule is present.
      expect(stylesWithoutComments).toMatch(
        /\.fx-select\s+\.fx-select__control\s*\{[^}]*max-width:\s*none/,
      );
      // Width must still be 100% so the <select> spans the whole wrap.
      expect(stylesWithoutComments).toMatch(
        /\.fx-select\s+\.fx-select__control\s*\{[^}]*width:\s*100%/,
      );
    });

    it('places <select> and caret in the same grid cell so the caret tracks the right edge', () => {
      // Grid-cell alignment is what makes the caret follow the <select>'s
      // right edge regardless of column width. Guard the declarations.
      expect(stylesWithoutComments).toMatch(
        /\.fx-select__control-wrap\s*\{[^}]*display:\s*grid/,
      );
      expect(stylesWithoutComments).toMatch(
        /\.fx-select__caret\s*\{[^}]*justify-self:\s*end/,
      );
    });
  });
});
