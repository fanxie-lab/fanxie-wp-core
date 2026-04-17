# Fanxie WP Core — Admin SPA

Vue 3 + TypeScript + Pinia single-page app that renders the plugin's Settings → Fanxie WP Core screen. This workspace is independent from the repo-root `package.json` (which only wires wp-env).

## Stack

- Vue 3 (`<script setup>` + Composition API)
- TypeScript (strict, `noUncheckedIndexedAccess`)
- Pinia for state
- Vite for dev server + build
- Plain scoped CSS (no Tailwind/UnoCSS — see `CLAUDE.md` §3.4)

## Getting started

```bash
cd assets/admin
npm install
npm run dev      # vite dev server (see "Dev modes" below for standalone vs. WP HMR)
npm run build    # vue-tsc --noEmit && vite build → dist/
npm run type-check
```

Node 20+ required.

## Dev modes

Two ways to run the dev server, both driven by the same `npm run dev` command — they differ only in which URL you open in your browser.

### 1. Standalone sandbox

Good for pure component / styling work where you don't need real WP data, a real session, real AJAX, or real nonces.

```bash
cd assets/admin
npm run dev
# then open http://localhost:5173 directly
```

Vite serves `index.html`, which pre-populates `window.fanxieWPCore` with a mocked bootstrap so components render without WordPress. **`index.html` is never shipped** — it exists only for this mode.

### 2. Integrated / WP HMR (recommended for real features)

HMR inside the actual WordPress admin — real session, real AJAX, real nonces, real PHP side-effects.

```bash
cd assets/admin
npm run dev            # leave this running
# then, in a browser:
# http://localhost:8888/wp-admin/options-general.php?page=fanxie-wp-core
```

How it works: when the Vite dev server starts, it writes a hot-file marker at `assets/admin/.vite-hot` containing the dev server URL (e.g., `http://localhost:5173`). `SettingsPage.php` detects the marker and enqueues modules directly from `http://localhost:5173/src/main.ts` instead of the built `dist/` bundle. HMR flows through the WP admin page, untouched. When you `Ctrl-C` the dev server, the marker is removed and WP falls back to `dist/`.

The Vite server is pinned to `localhost:5173` with `strictPort: true` and `cors: true` so `localhost:8888` can load modules from it.

### Troubleshooting

- **Port 5173 already in use.** Vite exits instead of silently drifting. Kill the conflicting process (`lsof -i :5173`) or restart wp-env so stale processes die with it.
- **`.vite-hot` left behind after a crash.** The marker is cleaned up on `SIGINT` / `SIGTERM` / `exit` / `closeBundle`, but a hard kill (`kill -9`) can skip that. If WP keeps loading from `localhost:5173` when you expect `dist/`, delete `assets/admin/.vite-hot` manually.
- **Browser still serving the old prod bundle.** Hard refresh (Cmd-Shift-R / Ctrl-Shift-R). The WP admin page may have cached the `dist/admin.js` response before the marker was written.

## Build output

Vite writes exactly two public files:

- `assets/admin/dist/admin.js`
- `assets/admin/dist/admin.css`

The PHP side enqueues those exact paths via `wp_enqueue_script` / `wp_enqueue_style` on the plugin's settings screen only. **Do not rename the entry/asset outputs** without coordinating with the WordPress dev agent.

Source maps are emitted to `dist/**/*.map` and excluded from git via the root `.gitignore`.

## PHP ↔ Vue contract

| Piece | Value |
|---|---|
| Mount node | `#fanxie-wp-core-admin` |
| Bootstrap global | `window.fanxieWPCore` (shape in `src/types/global.d.ts`) |
| AJAX endpoint | `window.fanxieWPCore.ajaxUrl` (POST) |
| Fixed `action` field | `fanxie_wp_core` |
| Sub-action field | `_action` |
| Nonce field | `_ajax_nonce` |
| Response envelope | `{ success: boolean; data: unknown }` |
| Proof-of-life sub-action | `ping` → `{ pong: true, time: <unix_ts> }` |

## Structure

```
assets/admin/
├── index.html              # dev shell only
├── package.json            # this workspace
├── tsconfig.json           # strict, path alias "@/*"
├── tsconfig.node.json      # for vite.config.ts
├── vite.config.ts          # build outputs admin.js + admin.css
└── src/
    ├── main.ts             # entry — mounts to #fanxie-wp-core-admin
    ├── App.vue             # root component + ARIA tablist
    ├── api/ajaxClient.ts   # typed admin-ajax.php wrapper + AjaxError
    ├── stores/app.ts       # Pinia store, bootstrap + ping()
    ├── components/         # shared primitives (Toggle, TextField, etc.)
    ├── styles/main.css     # tokens + scoped base styles
    └── types/global.d.ts   # window.fanxieWPCore contract
```

## Not yet set up (tracked on the Phase 0 checklist)

- **Phase 0.3** — Vitest, Vue Test Utils, MSW.
- **Phase 0.4** — ESLint, Prettier, Stylelint.

## Conventions

- `<script setup lang="ts">` everywhere.
- `defineProps<...>()` / `defineEmits<...>()` with typed generics — no runtime declaration form.
- No `any`. Use `unknown` + narrow.
- Every interactive element keyboard-reachable and screen-reader labelled.
- Every form control has an explicit `<label for="...">`.
- Light mode only (brand identity, matches fanxielab.com). Reduced motion via `prefers-reduced-motion`.
