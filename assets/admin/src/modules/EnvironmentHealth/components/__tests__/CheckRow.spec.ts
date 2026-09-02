import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import CheckRow from '../CheckRow.vue';
import CheckMetaList from '../CheckMetaList.vue';
import type { CheckStatus, HealthCheck } from '../../types';

function makeCheck(overrides: Partial<HealthCheck> = {}): HealthCheck {
  return {
    id: 'php_version',
    group: 'versions',
    label: 'PHP version',
    status: 'ok',
    value: '8.2.14',
    summary: 'Running a supported PHP release.',
    ...overrides,
  };
}

function stubClipboard(writeText: () => Promise<void>): void {
  Object.defineProperty(navigator, 'clipboard', {
    value: { writeText },
    configurable: true,
    writable: true,
  });
}

describe('<CheckRow>', () => {
  beforeEach(() => {
    stubClipboard(() => Promise.resolve());
  });

  it('renders the PHP-supplied label, value and summary verbatim', () => {
    const wrapper = mount(CheckRow, { props: { check: makeCheck() } });

    expect(wrapper.get('.fx-eh-row__label').text()).toBe('PHP version');
    expect(wrapper.get('.fx-eh-row__value').text()).toContain('8.2.14');
    expect(wrapper.get('.fx-eh-row__summary').text()).toBe(
      'Running a supported PHP release.',
    );
  });

  it('prefixes the observed value for screen readers so it is not a loose number', () => {
    const wrapper = mount(CheckRow, { props: { check: makeCheck() } });
    expect(wrapper.get('.fx-eh-row__value .fx-visually-hidden').text()).toBe(
      'Current value:',
    );
  });

  it('omits the value element entirely when the check has none', () => {
    const wrapper = mount(CheckRow, {
      props: { check: makeCheck({ value: '' }) },
    });
    expect(wrapper.find('.fx-eh-row__value').exists()).toBe(false);
  });

  describe('status rendering', () => {
    const cases: { status: CheckStatus; pillClass: string; text: string }[] = [
      { status: 'ok', pillClass: 'fx-pill--ok', text: 'OK' },
      { status: 'warning', pillClass: 'fx-pill--warn', text: 'Warning' },
      {
        status: 'critical',
        pillClass: 'fx-pill--critical',
        text: 'Critical',
      },
      {
        status: 'unknown',
        pillClass: 'fx-pill--neutral',
        text: "Couldn't check",
      },
    ];

    for (const { status, pillClass, text } of cases) {
      it(`renders a text-bearing pill for status=${status}`, () => {
        const wrapper = mount(CheckRow, {
          props: { check: makeCheck({ status }) },
        });

        const pill = wrapper.get('.fx-pill');
        expect(pill.classes()).toContain(pillClass);
        // Text, not colour — the label must be readable on its own.
        expect(pill.text()).toContain(text);
        expect(wrapper.classes()).toContain(`fx-eh-row--${status}`);
      });
    }

    it('gives unknown a distinct treatment from both ok and critical', () => {
      const unknown = mount(CheckRow, {
        props: { check: makeCheck({ status: 'unknown' }) },
      });
      const ok = mount(CheckRow, {
        props: { check: makeCheck({ status: 'ok' }) },
      });
      const critical = mount(CheckRow, {
        props: { check: makeCheck({ status: 'critical' }) },
      });

      expect(unknown.get('.fx-pill').classes()).not.toEqual(
        ok.get('.fx-pill').classes(),
      );
      expect(unknown.get('.fx-pill').classes()).not.toEqual(
        critical.get('.fx-pill').classes(),
      );
      // And it reads as "not checked", never as a failure.
      expect(unknown.get('.fx-pill').text()).toContain("Couldn't check");
      expect(unknown.get('.fx-pill').text()).not.toContain('Critical');
    });
  });

  describe('disclosure', () => {
    it('renders no toggle when there is nothing to expand', () => {
      const wrapper = mount(CheckRow, { props: { check: makeCheck() } });
      expect(wrapper.find('.fx-eh-row__toggle').exists()).toBe(false);
    });

    it('renders a collapsed toggle when a detail exists', () => {
      const wrapper = mount(CheckRow, {
        props: { check: makeCheck({ detail: 'PHP 8.1 is security-only.' }) },
      });

      const toggle = wrapper.get('.fx-eh-row__toggle');
      expect(toggle.attributes('aria-expanded')).toBe('false');
      expect(toggle.attributes('aria-controls')).toBeTruthy();
    });

    it('points aria-controls at a region that exists even while collapsed', () => {
      const wrapper = mount(CheckRow, {
        props: { check: makeCheck({ detail: 'Some detail.' }) },
      });

      const controls = wrapper
        .get('.fx-eh-row__toggle')
        .attributes('aria-controls');
      const region = wrapper.find(`#${controls!}`);
      expect(region.exists()).toBe(true);
      expect(region.attributes('style')).toContain('display: none');
    });

    it('flips aria-expanded and reveals the detail on click', async () => {
      const wrapper = mount(CheckRow, {
        props: { check: makeCheck({ detail: 'PHP 8.1 is security-only.' }) },
      });

      await wrapper.get('.fx-eh-row__toggle').trigger('click');

      expect(
        wrapper.get('.fx-eh-row__toggle').attributes('aria-expanded'),
      ).toBe('true');
      const region = wrapper.get('.fx-eh-row__detail');
      // v-show clears the inline style entirely when the region is shown.
      expect(region.attributes('style') ?? '').not.toContain('display: none');
      expect(region.text()).toContain('PHP 8.1 is security-only.');
    });

    it('collapses again on a second click', async () => {
      const wrapper = mount(CheckRow, {
        props: { check: makeCheck({ detail: 'Detail.' }) },
      });

      await wrapper.get('.fx-eh-row__toggle').trigger('click');
      await wrapper.get('.fx-eh-row__toggle').trigger('click');

      expect(
        wrapper.get('.fx-eh-row__toggle').attributes('aria-expanded'),
      ).toBe('false');
    });

    it('emits toggle with the new expanded state', async () => {
      const wrapper = mount(CheckRow, {
        props: { check: makeCheck({ detail: 'Detail.' }) },
      });

      await wrapper.get('.fx-eh-row__toggle').trigger('click');
      await wrapper.get('.fx-eh-row__toggle').trigger('click');

      expect(wrapper.emitted('toggle')).toEqual([[true], [false]]);
    });

    it('labels the disclosure region with the row label', () => {
      const wrapper = mount(CheckRow, {
        props: { check: makeCheck({ detail: 'Detail.' }) },
      });

      const region = wrapper.get('.fx-eh-row__detail');
      const labelledBy = region.attributes('aria-labelledby');
      expect(wrapper.get(`#${labelledBy!}`).text()).toBe('PHP version');
    });

    it('names the toggle uniquely while still containing its visible text', () => {
      const wrapper = mount(CheckRow, {
        props: { check: makeCheck({ detail: 'Detail.' }) },
      });

      // WCAG 2.5.3 — visible "Details" must be part of the accessible name.
      const toggle = wrapper.get('.fx-eh-row__toggle');
      expect(toggle.text()).toContain('Details');
      expect(toggle.text()).toContain('PHP version');
      expect(toggle.attributes('aria-label')).toBeUndefined();
    });
  });

  describe('remediation', () => {
    it('renders a copy-paste-ready snippet with a language badge', async () => {
      const wrapper = mount(CheckRow, {
        props: {
          check: makeCheck({
            status: 'warning',
            remediation: [
              {
                kind: 'snippet',
                language: 'php',
                code: "define( 'DISABLE_WP_CRON', true );",
              },
            ],
          }),
        },
      });

      await wrapper.get('.fx-eh-row__toggle').trigger('click');

      expect(wrapper.get('.fx-snippet code').text()).toBe(
        "define( 'DISABLE_WP_CRON', true );",
      );
      expect(wrapper.get('.fx-snippet__lang').text()).toContain('php');
    });

    it('copies the snippet and confirms both visibly and in a live region', async () => {
      const writeText = vi.fn(() => Promise.resolve());
      stubClipboard(writeText);

      const wrapper = mount(CheckRow, {
        props: {
          check: makeCheck({
            remediation: [
              { kind: 'snippet', language: 'bash', code: 'crontab -e' },
            ],
          }),
        },
      });

      await wrapper.get('.fx-eh-row__toggle').trigger('click');
      await wrapper.get('.fx-snippet__copy').trigger('click');
      await flushPromises();

      expect(writeText).toHaveBeenCalledWith('crontab -e');
      expect(wrapper.get('.fx-snippet__copy').text()).toContain('Copied');
      expect(wrapper.get('.fx-snippet [role="status"]').text()).toBe(
        'Snippet copied to clipboard.',
      );
    });

    it('re-emits copy-failed so the module root can toast it', async () => {
      stubClipboard(() => Promise.reject(new Error('denied')));

      const wrapper = mount(CheckRow, {
        props: {
          check: makeCheck({
            remediation: [{ kind: 'snippet', code: 'x' }],
          }),
        },
      });

      await wrapper.get('.fx-eh-row__toggle').trigger('click');
      await wrapper.get('.fx-snippet__copy').trigger('click');
      await flushPromises();

      const emitted = wrapper.emitted('copy-failed');
      expect(emitted).toHaveLength(1);
      expect(emitted![0]![0]).toContain('Copy failed.');
    });

    it('renders a link remediation as a safe new-tab anchor', async () => {
      const wrapper = mount(CheckRow, {
        props: {
          check: makeCheck({
            remediation: [
              {
                kind: 'link',
                url: 'https://wordpress.org/documentation/article/cron/',
                label: 'How WP-Cron works',
              },
            ],
          }),
        },
      });

      await wrapper.get('.fx-eh-row__toggle').trigger('click');

      const link = wrapper.get('.fx-eh-row__link');
      expect(link.attributes('href')).toBe(
        'https://wordpress.org/documentation/article/cron/',
      );
      expect(link.attributes('rel')).toBe('noopener noreferrer');
      expect(link.attributes('target')).toBe('_blank');
      expect(link.text()).toContain('How WP-Cron works');
      expect(link.text()).toContain('opens in a new tab');
    });

    it('falls back to the URL when a link has no label', async () => {
      const wrapper = mount(CheckRow, {
        props: {
          check: makeCheck({
            remediation: [{ kind: 'link', url: 'https://example.test/docs' }],
          }),
        },
      });

      await wrapper.get('.fx-eh-row__toggle').trigger('click');
      expect(wrapper.get('.fx-eh-row__link').text()).toContain(
        'https://example.test/docs',
      );
    });

    it('drops javascript: links rather than rendering them', () => {
      const wrapper = mount(CheckRow, {
        props: {
          check: makeCheck({
            remediation: [{ kind: 'link', url: 'javascript:alert(1)' }],
          }),
        },
      });

      expect(wrapper.find('.fx-eh-row__link').exists()).toBe(false);
      // Nothing renderable left, so no disclosure control either.
      expect(wrapper.find('.fx-eh-row__toggle').exists()).toBe(false);
    });

    it('drops snippets with no code', () => {
      const wrapper = mount(CheckRow, {
        props: {
          check: makeCheck({ remediation: [{ kind: 'snippet' }] }),
        },
      });

      expect(wrapper.find('.fx-eh-row__toggle').exists()).toBe(false);
    });

    it('renders multiple remediations in the order PHP sent them', async () => {
      const wrapper = mount(CheckRow, {
        props: {
          check: makeCheck({
            remediation: [
              { kind: 'snippet', language: 'php', code: 'A' },
              { kind: 'snippet', language: 'bash', code: 'B' },
              { kind: 'link', url: 'https://example.test/', label: 'Docs' },
            ],
          }),
        },
      });

      await wrapper.get('.fx-eh-row__toggle').trigger('click');

      const codes = wrapper.findAll('.fx-snippet code').map((n) => n.text());
      expect(codes).toEqual(['A', 'B']);
      expect(wrapper.findAll('.fx-eh-row__link')).toHaveLength(1);
    });
  });

  describe('meta lists', () => {
    // PHP names items inline only while there are three or fewer of them and
    // falls back to a bare count above that, so for any list worth reading the
    // expanded region is the only place the names appear at all.
    // Return type left to inference on purpose: annotating it as
    // `ReturnType<typeof mount>` erases the component generics and trips
    // no-unsafe-return under the strict type-checked preset.
    function listRow(overrides: Partial<HealthCheck>) {
      return mount(CheckRow, {
        props: {
          check: makeCheck({
            id: 'inactive_themes',
            group: 'plugins_themes',
            label: 'Inactive themes',
            status: 'warning',
            value: '2',
            summary: '2 unused themes are installed.',
            ...overrides,
          }),
        },
      });
    }

    it('renders the names as a real list, not a joined sentence', async () => {
      const wrapper = listRow({
        meta: { names: ['Twenty Ten', 'Twenty Eleven'], names_omitted: 0 },
      });

      await wrapper.get('.fx-eh-row__toggle').trigger('click');

      const items = wrapper.findAll('.fx-eh-list__item').map((n) => n.text());
      expect(items).toEqual(['Twenty Ten', 'Twenty Eleven']);
    });

    it('keeps a name containing a comma as a single item', async () => {
      const wrapper = listRow({
        meta: { names: ['Foo, Inc. Starter', 'Bar'] },
      });

      await wrapper.get('.fx-eh-row__toggle').trigger('click');

      const items = wrapper.findAll('.fx-eh-list__item').map((n) => n.text());
      expect(items).toEqual(['Foo, Inc. Starter', 'Bar']);
    });

    it('opens a disclosure for a check whose only detail is its list', () => {
      const wrapper = listRow({
        detail: undefined,
        remediation: [],
        meta: { names: ['Twenty Ten'] },
      });

      const toggle = wrapper.get('.fx-eh-row__toggle');
      expect(toggle.attributes('aria-expanded')).toBe('false');
      const controls = toggle.attributes('aria-controls');
      expect(wrapper.find(`#${controls!}`).exists()).toBe(true);
    });

    it('keeps the aria-expanded / aria-controls wiring intact around the list', async () => {
      const wrapper = listRow({
        detail: 'Unused themes are attack surface.',
        meta: { names: ['Twenty Ten'] },
      });

      const toggle = wrapper.get('.fx-eh-row__toggle');
      const controls = toggle.attributes('aria-controls');

      await toggle.trigger('click');

      expect(toggle.attributes('aria-expanded')).toBe('true');
      const region = wrapper.get(`#${controls!}`);
      // The list lives inside the region the trigger controls, not beside it.
      expect(region.find('.fx-eh-list__item').exists()).toBe(true);
      expect(region.attributes('aria-labelledby')).toBeTruthy();
    });

    it('says plainly how many names were dropped', async () => {
      const wrapper = listRow({
        meta: { names: ['A', 'B'], names_omitted: 5 },
      });

      await wrapper.get('.fx-eh-row__toggle').trigger('click');
      expect(wrapper.get('.fx-eh-list__more').text()).toBe('and 5 more');
    });

    it('shows no truncation notice when the omitted count is zero', async () => {
      const wrapper = listRow({
        meta: { names: ['A', 'B'], names_omitted: 0 },
      });

      await wrapper.get('.fx-eh-row__toggle').trigger('click');
      expect(wrapper.find('.fx-eh-list__more').exists()).toBe(false);
    });

    it('renders no list at all for an empty array', () => {
      const wrapper = listRow({
        detail: undefined,
        remediation: [],
        meta: { names: [], names_omitted: 0 },
      });

      expect(wrapper.find('.fx-eh-list').exists()).toBe(false);
      // Nothing renderable left, so no disclosure control either.
      expect(wrapper.find('.fx-eh-row__toggle').exists()).toBe(false);
    });

    it('renders no list when the names key is missing entirely', () => {
      const wrapper = listRow({
        detail: undefined,
        remediation: [],
        meta: { total_installed: 6 },
      });

      expect(wrapper.find('.fx-eh-list').exists()).toBe(false);
      expect(wrapper.find('.fx-eh-row__toggle').exists()).toBe(false);
    });

    it('renders the rest of the row when a list arrives as the wrong type', () => {
      // The pre-change contract sent a joined string here. A malformed meta
      // degrades to no list; it never throws and never takes the row with it.
      const wrapper = listRow({
        detail: 'Unused themes are attack surface.',
        meta: { names: 'Twenty Ten, Twenty Eleven' },
      });

      expect(wrapper.find('.fx-eh-list').exists()).toBe(false);
      expect(wrapper.get('.fx-eh-row__summary').text()).toBe(
        '2 unused themes are installed.',
      );
      // The detail is still reachable — only the list dropped out.
      expect(wrapper.find('.fx-eh-row__toggle').exists()).toBe(true);
    });

    it('splits abandoned plugins into two labelled severity groups', async () => {
      const wrapper = listRow({
        id: 'abandoned_plugins',
        label: 'Abandoned plugins',
        status: 'critical',
        meta: {
          critical_names: ['Ancient Slider'],
          critical_omitted: 0,
          warning_names: ['Stale Forms', 'Old SEO'],
          warning_omitted: 2,
        },
      });

      await wrapper.get('.fx-eh-row__toggle').trigger('click');

      const groups = wrapper.findAllComponents(CheckMetaList);
      expect(groups).toHaveLength(2);

      const labels = wrapper.findAll('.fx-eh-list__label').map((n) => n.text());
      expect(labels).toEqual(['Critical', 'Warning']);

      // Two lists, not one merged one — the split is the whole finding.
      const lists = wrapper.findAll('.fx-eh-list');
      expect(
        lists[0]!.findAll('.fx-eh-list__item').map((n) => n.text()),
      ).toEqual(['Ancient Slider']);
      expect(
        lists[1]!.findAll('.fx-eh-list__item').map((n) => n.text()),
      ).toEqual(['Stale Forms', 'Old SEO']);
      // Only the truncated group carries a notice.
      expect(lists[0]!.find('.fx-eh-list__more').exists()).toBe(false);
      expect(lists[1]!.get('.fx-eh-list__more').text()).toBe('and 2 more');
    });

    it('drops one malformed severity group without losing the other', async () => {
      const wrapper = listRow({
        id: 'abandoned_plugins',
        label: 'Abandoned plugins',
        status: 'warning',
        meta: {
          critical_names: 'Ancient Slider',
          warning_names: ['Stale Forms'],
        },
      });

      await wrapper.get('.fx-eh-row__toggle').trigger('click');

      const labels = wrapper.findAll('.fx-eh-list__label').map((n) => n.text());
      expect(labels).toEqual(['Warning']);
    });

    it('renders the list between the explanation and the recommended action', async () => {
      const wrapper = listRow({
        detail: 'Unused themes are attack surface.',
        meta: { names: ['Twenty Ten'] },
        remediation: [{ kind: 'link', url: 'https://example.test/themes' }],
      });

      await wrapper.get('.fx-eh-row__toggle').trigger('click');

      const html = wrapper.get('.fx-eh-row__detail').html();
      expect(html.indexOf('fx-eh-row__detail-text')).toBeLessThan(
        html.indexOf('fx-eh-list'),
      );
      expect(html.indexOf('fx-eh-list')).toBeLessThan(
        html.indexOf('fx-eh-row__remediation'),
      );
    });

    it('ignores meta lists on a check that does not declare any', () => {
      const wrapper = mount(CheckRow, {
        props: { check: makeCheck({ meta: { names: ['Akismet'] } }) },
      });

      expect(wrapper.find('.fx-eh-list').exists()).toBe(false);
      expect(wrapper.find('.fx-eh-row__toggle').exists()).toBe(false);
    });
  });
});
