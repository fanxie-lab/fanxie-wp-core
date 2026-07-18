# Admin Refinements + Hardening Fixes — Design

- **Date:** 2026-07-18
- **Status:** Approved (design), pending implementation plan
- **Branch:** `phase/1-admin-refinements`
- **Scope phase:** Pre–Phase 1.3 polish (touches the Admin shell, Security Headers module, Hardening module)
- **Owners:** `wordpress-development-expert` (PHP), `frontend-expert` (Vue/TS/CSS) — dispatched per lane below

## Context

Before starting Phase 1.3 (Login Protection), we're making a batch of admin/UX
refinements plus fixing three Hardening defects surfaced during hands-on use in
the wp-env environment. Two of the "questions" in the original request turned
out to be genuine bugs (file-editor guard, misleading uploads messaging) and one
was a confusing-but-correct behaviour (site-wide Application Password count).

## Decisions locked

1. **Security Headers IA:** collapse three tabs to **two** — *Response Headers* +
   *Content Security Policy* — folding the violation log into the CSP tab.
2. **Setting explanations:** **hybrid** — inline helper text for complex/risky
   settings, accessible ⓘ tooltip icons for simple toggles.
3. **Tooltips/help are a project-wide standard.** The `Tooltip` + `HelpText`
   primitives and the hybrid convention apply to **every current and future
   module**, not just this batch. New modules adopt the pattern by default.
4. **Top-level menu icon:** monochrome shield SVG `data:` URI (WP recolors it to
   the admin scheme; brand mint lives inside the SPA, not the menu glyph).

---

## A. Top-level "FX Core" menu — *PHP*

**Change:** `SettingsPage::register_menu()` switches `add_options_page()` →
`add_menu_page()`.

- Menu title: **"FX Core"**. Page title: **"Fanxie WP Core"**.
- Capability unchanged: `Plugin::CAPABILITY` (`manage_fanxie_wp_core`).
- Icon: inline monochrome shield SVG as a `data:image/svg+xml;base64,…` URI.
- Position: a floating value (e.g. `58.9`) to avoid collisions with adjacent
  core/plugin menu items; adjustable.
- First submenu row relabeled **"Settings"** via a matching `add_submenu_page()`
  with the same slug (otherwise WP duplicates the top-level title).
- Menu slug stays `fanxie-wp-core`; only the parent file changes
  (`options-general.php` → `admin.php`).
- The `enqueue_assets()` hook-suffix scoping is preserved — it keys off the hook
  returned by `add_menu_page()`, so no enqueue leakage.

**Audit:** no hardcoded `options-general.php?page=fanxie-wp-core` links or
`plugin_action_links` "Settings" entries exist today (verified), so the move is
self-contained. The frontend uses hash routing + generic `adminUrl`, so no JS
URL changes are required. Re-grep the frontend for any `options-general`
string during implementation as a safety net.

**Acceptance:**
- Menu appears as a top-level "FX Core" item with a visible icon.
- Settings screen loads at `admin.php?page=fanxie-wp-core`.
- SPA assets still enqueue **only** on that screen (screen-ID check intact).

---

## B. Security Headers → two tabs — *Frontend*

**Change:** `SecurityHeaders.vue` `TABS` becomes:
- `Response Headers` (`security-headers.headers`)
- `Content Security Policy` (`security-headers.csp`)

The `violations` child route is removed. `ViolationsView`'s content is rendered
as a collapsible **"Violation log"** section at the bottom of `CspView` (filters
+ refresh preserved). Violations are conceptually part of CSP, so they live with
the policy builder.

- **Router (`router/index.ts`):** drop the `violations` child; add a redirect
  from the old `security-headers.violations` route → `security-headers.csp` so
  existing deep links / bookmarks don't 404.
- **Files:** update `SecurityHeaders.vue`, `router/index.ts`, `CspView.vue`
  (host the violations section), retire/inline `ViolationsView.vue`.
- **Tests:** update `SecurityHeaders.spec.ts` (two tabs, ARIA tablist), any
  violations-view spec migrates to a section test inside the CSP view.

**Acceptance:**
- Two tabs render with correct ARIA tablist semantics + keyboard arrow nav.
- Violation log is reachable and functional within the CSP tab.
- Old `#/security-headers/violations` hash redirects to the CSP tab.

---

## C. HSTS → Advanced section — *Frontend*

**Change:** within the Response Headers tab, HSTS controls (`max-age`,
`includeSubDomains`, preload if present) move to the bottom inside a collapsible
**"Advanced"** `<details>` block, **collapsed by default**, carrying the existing
lock-in warning ("can break sites not fully on HTTPS").

- No PHP change: defaults already ship HSTS off (Phase 1.1 polish).
- The `<details>`/`<summary>` is natively keyboard-accessible; ensure the
  summary is styled as a clear affordance and the warning is `role="note"` or
  equivalent, not hidden until expansion of a separate control.

**Acceptance:**
- HSTS is visually separated at the bottom under a collapsed "Advanced" toggle.
- Warning copy remains visible/associated with the controls.

---

## D. Setting explanations — hybrid, project-wide standard — *Frontend*

**New shared primitives (in `components/`):**
- **`Tooltip`** — WCAG 2.1 AA. Keyboard-focusable ⓘ trigger button,
  `aria-describedby` wiring to the tooltip content, shows on hover **and**
  focus, dismissable with `Escape`, does not trap focus, content is
  screen-reader reachable. No hover-only reveals.
- **`HelpText`** — a lightweight inline helper-text convention (muted one/two
  liner rendered under a control, referenced by the control's `aria-describedby`).

**Application in this batch:**
- Apply across **both** existing modules (Security Headers + Hardening).
- **Cache-Control (admin)** gets a full inline `HelpText` explanation of what it
  is, what it controls (proxy/browser caching of admin responses), and the risk
  of wrong values (stale or private pages served).
- Simple toggles (e.g. `X-Frame-Options`, individual hardening toggles) get ⓘ
  tooltips; complex/risky ones (HSTS, Cache-Control, CSP directives) get inline
  `HelpText`.

**Project-wide standard (cross-cutting):**
- Every current and future module uses these primitives for setting
  explanations. Documented as a convention so Phase 2–6 modules inherit it.
- Follow-up: add a short note to `CLAUDE.md` §3.4 and the checklist's
  cross-phase ongoing items so the standard is discoverable.

**Acceptance:**
- `Tooltip` passes axe-core + manual keyboard/screen-reader checks.
- Cache-Control has a clear inline explanation.
- Every setting in the two live modules has either a tooltip or inline help.

---

## E. File-editor guard fix — *PHP (bug)*

**Bug:** WordPress routes **both** the Theme File Editor and Plugin File Editor
through a single call — `wp_is_file_mod_allowed( 'capability_edit_themes' )`
(`wp-includes/capabilities.php:611`, verified against wp-env core). Our
`FileEditGuard::BLOCKED_CONTEXTS` lists `edit_themes` / `edit_plugins` /
`edit_theme` / `edit_plugin`, none of which is the string WP passes. So even with
`file_editing.runtime_enforce` **on**, the guard is a silent no-op and both
editors stay visible.

**Fix:** `BLOCKED_CONTEXTS = ['capability_edit_themes', 'capability_edit_plugins']`
(WP emits only the first; the second is defensive/forward-compat). Scope is
correct — this leaves `capability_update_core` (plugin/theme install/update/
delete) untouched, matching `DISALLOW_FILE_EDIT` semantics, not the broader
`DISALLOW_FILE_MODS`.

**UI copy:** clarify that enabling this hides both in-dashboard code editors.

**Tests (integration):** with `runtime_enforce` on,
`current_user_can('edit_themes')` and `current_user_can('edit_plugins')` are
`false`; `current_user_can('update_core')` / install caps remain `true`. With
the toggle off, editors are unaffected.

---

## F. Uploads protection detection + messaging — *PHP + Frontend (bug)*

**Bug:** `detect_server_type()` reads only `$_SERVER['SERVER_SOFTWARE']`, and
`is_apache_compatible()` treats `unknown` as Apache — so on a server that doesn't
self-identify (wp-env included) we attempt an `.htaccess` write and then report
"write failed", conflating three distinct states.

**PHP change (`UploadsProtector` / `StatusInspector`):**
- Only **Apache/LiteSpeed** get an `.htaccess` write attempt. `unknown` is
  surfaced honestly rather than written to and reported as failed.
- The authoritative protection signal is the server-agnostic **PHP-execution
  probe** (`probe_php_execution()`), not file presence. Status leads with the
  probe result.

**Frontend change (`UploadsGuardRow`):** replace the single "write failed" with
three explicit states —
1. **Apache/LiteSpeed:** `.htaccess` managed (show managed/dropped state).
2. **nginx/IIS/unknown:** "not applicable on this server" + copy-paste snippet
   (nginx snippet already exists; provide guidance for others).
3. **Genuine write-permission failure:** actionable permissions message.

Protection status pill is driven by the probe (`uploads_php_executable`), so a
correctly-blocked-but-unwritten server reads as protected, not failed.

**Acceptance:**
- On a non-Apache/unknown server, no "write failed" message; the snippet path is
  shown.
- The protection status reflects the live probe.
- Apache/LiteSpeed still writes + reports `.htaccess` state correctly.

---

## G. Application Passwords messaging — *PHP + Frontend (clarity)*

**Behaviour:** `count_application_passwords()` sums `_application_passwords`
usermeta across **every user** on the site (correct for a site-wide disable
gate), but the profile screen shows only the current user's APs — so a count of
"2" with none in your own profile is confusing, and "revoke them before
disabling" is a dead end when the holder isn't you.

**PHP change (`StatusInspector`):** add
`application_passwords_users: { user_login: string, count: number }[]` to the
snapshot (contract addition; coordinate the TS type in `Hardening/types.ts`).
Derive from the same usermeta scan, joining `user_login`.

**Frontend change:** site-wide-aware copy, e.g.
*"In use by 2 credentials across 1 user (admin). These are site-wide, not just
your account."* When the holder set is non-empty, name the users; drop the
"revoke them" imperative when it isn't actionable by the current user.

**Acceptance:**
- Copy states the count is site-wide and names holder(s) when known.
- Contract change reflected in TS types + relevant specs.

---

## Contract changes (PHP ↔ JS)

| Contract | Change |
|---|---|
| `ChecksResult` (`Hardening/types.ts`) + `StatusInspector::snapshot()` | Add `application_passwords_users: {user_login,count}[]` |
| Router route names | Remove `security-headers.violations`; add redirect to `security-headers.csp` |
| Admin menu | `add_options_page` → `add_menu_page` (+ submenu "Settings"); slug unchanged |

## Sequencing & branch strategy

- Single branch: `phase/1-admin-refinements`. One implementation plan.
- Land order (each independently testable):
  1. **E** (file-editor fix) — smallest, highest-value bug.
  2. **F** (uploads detection/messaging).
  3. **G** (AP messaging + contract).
  4. **A** (menu move).
  5. **D** (tooltip/help primitives) — prerequisite for the copy in B/C.
  6. **B** (SecHeaders IA) + **C** (HSTS Advanced).
- Cross-cutting agents dispatched per lane; brief each with the contract at the
  boundary (see table above).

## Out of scope

- Phase 1.3 Login Protection (starts after this batch).
- The untracked `docs/design-system.html` (separate decision; not touched here).
- Any change to Plugin Check warnings-ignored posture (still Phase 7).
- Retrofitting tooltips into modules that don't exist yet — the *standard* is
  set now; each future module applies it as it's built.

## Follow-ups (docs)

- `CLAUDE.md` §3.4: note the `Tooltip`/`HelpText` standard.
- `_PRD/checklist-fanxie-wp-core.md` cross-phase ongoing items: add "every
  setting has a tooltip or inline help (accessible)".
- `CHANGELOG.md`: entry under Unreleased for this batch.
