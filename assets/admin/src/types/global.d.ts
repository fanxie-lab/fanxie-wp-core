// Ambient declaration of the window.fanxieWPCore contract hydrated by the PHP
// side via wp_add_inline_script(..., 'before'). Shape is frozen — coordinate
// any change with the WordPress development agent (see CLAUDE.md §3.4).

export interface FanxieBootstrap {
  version: string;
  ajaxUrl: string;
  adminUrl: string;
  restUrl: string;
  nonce: string;
  assetsUrl: string;
  user: {
    id: number;
    caps: Record<string, boolean>;
  };
  modules: Record<
    string,
    { enabled: boolean; config: Record<string, unknown> }
  >;
  i18n: {
    locale: string;
  };
}

declare global {
  interface Window {
    // Optional: the PHP bootstrap may fail to inject this script (plugins
    // stripping inline scripts, cache edge cases, etc.). Callers MUST guard
    // access at boundaries; the store's `snapshotBootstrap` asserts presence
    // after `main.ts` has done the runtime check.
    fanxieWPCore?: FanxieBootstrap;
  }
}

export {};
