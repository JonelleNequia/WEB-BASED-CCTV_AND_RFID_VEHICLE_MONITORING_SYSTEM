# Settings UI batch

This batch refines the shared application shell and Settings while retaining
the local purple branding, routes, permissions and existing data behavior.
Dashboard, Gate Monitor, Registry, Visitors and Activity Logs received smoke
checks only; their page-specific redesign is deferred.

## Run the browser checks

Use a development checkout with Composer's development dependencies and Node
20.19+ or 22.12+. Install development packages with `npm install`.

```sh
npm run test:ui
```

The runner uses installed Google Chrome automatically on macOS. Elsewhere,
set `PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH` to the installed Chrome/Chromium
executable, or provision Playwright's Chromium with
`npx playwright install chromium` on the development machine before testing.
`PHP_BINARY` can select the development PHP executable. Playwright is a
development dependency; application pages use only bundled local assets.

The runner creates a private temporary SQLite database, random test-only
credentials, isolated camera/device directories, session table, app key,
configuration cache path and an ephemeral local PHP server. Its fixture
worker refuses a database outside that directory. It does not accept a live
installation URL. It removes its server, credentials and database on exit.
Browser requests outside the isolated server are blocked. Device discovery
and the offline camera image are explicit browser fixtures, not hardware
measurements or production connection states.

## Changes

- Sidebar content scrolls together at short heights. Account details and
  logout remain reachable; the collapsed desktop rail has an account menu.
  Health labels have visible text alongside status colors.
- Settings keeps Gates and Advanced. Advanced destinations are grouped as
  System & devices, Configuration, and Checks & diagnostics. Existing query
  links and calibration gate parameters remain unchanged.
- Responsive gate cards have consistent headings, state badges, Camera,
  RFID Reader and Detection Zone sections. Name/activation and confirmed
  removal live under Manage gate, with the two-gate minimum retained.
  A page-level Add gate link leads to the creation form. Common LAN advice
  appears once instead of being repeated verbatim at each device.
- Gate-name/activation inputs remain associated with one shared form using
  native HTML `form` attributes. This preserves the existing all-gates
  activation validation. Invalid controls reveal their management section
  before browser validation focuses them.
- Device dialogs retain their header/footer while the body scrolls. They
  show safe identifiers, selection, connection/identity uncertainty, scan
  completion and failures. Selection/focus survive background refresh;
  removed selections cannot submit. Existing offline selection rules remain.
  Stale requests from a closed or replaced flow cannot redraw the new flow.
- Calibration uses the full available width. Tools and preview sit beside
  direction/Save on wide screens and stack on narrower screens. Help and
  detector diagnostics are disclosures. Recent records and status follow
  the workspace. Canvas measurements now use the same fractional CSS-pixel
  bounds as pointer events; normalized coordinates and letterboxing remain.
- Advanced pages prioritize health and current measurements, group Timing
  fields, collapse technical guidance, contain diagnostic tables, clarify
  preview-only Test Scan wording and separate destructive reset controls.

Changed files in this UI batch:

```text
package.json
public/css/layout-settings.css
public/js/add-device.js
public/js/calibration-page.js
public/js/gate-setup.js
public/js/ui.js
resources/views/layouts/app.blade.php
resources/views/layouts/partials/navigation.blade.php
resources/views/settings/index.blade.php
resources/views/settings/partials/gate-names.blade.php
resources/views/settings/tabs/calibration.blade.php
resources/views/settings/tabs/gates.blade.php
resources/views/settings/tabs/manual.blade.php
resources/views/settings/tabs/plate-camera.blade.php
resources/views/settings/tabs/reader-network.blade.php
resources/views/settings/tabs/status.blade.php
resources/views/settings/tabs/test-scan.blade.php
resources/views/settings/tabs/timing.blade.php
tests/browser/README.md
tests/browser/settings-ui.mjs
tests/fixtures/ui-workspace.php
```

No backend connectivity logic, authentication, schema, detector algorithms,
event-counting rules, archival semantics or historical references changed.
Earlier gate/security/runtime changes remain in the working tree.

## Verification and screenshots

Executed on macOS using headless installed Google Chrome:

- Full Laravel suite: 367 passed, 3,454 assertions.
- Calibration JavaScript suite: 13 passed.
- Browser runner: 128 assertions passed.
- Production Vite build, PHP fixture style/syntax, JavaScript syntax and
  `git diff --check` passed.

Real rendered Settings pages were checked at 1366×768, 1920×1080, 768×900
and 390×844. Browser assertions also exercise short-height sidebar access,
collapsed account access, dialog scrolling, focus trapping/return,
identical-name devices, offline/unconfirmed states, selection refresh,
selection removal, empty lists, scan/refresh errors, credential validation,
gate rename/activation, hidden required fields, ROI resize/drag/undo/redo/
discard, unsaved feedback and video-node preservation.

Login, logout, the mandatory legacy-password transition and representative
Dashboard/Gate Monitor/Registry/Visitors/Activity Logs pages were checked
against isolated fixtures. The 125% check uses equivalent CSS viewport and
DPR (1093×614 at DPR 1.25 for a 1366×768 display), including a letterboxed
4:3 image and normalized pointer editing. Actual Windows Chrome zoom controls,
hardware streams/reconnection, camera alignment against physical traffic
and assistive-technology behavior still need checks on the installation PC.

Review screenshots are local, ignored artifacts under
`storage/app/ui-review/settings/`. The `before-gates.png`,
`before-calibration.png` and `before-status.png` files capture the prior UI
with isolated test data. The browser runner refreshes the `after-*` images,
`device-dialog-1366x600.png` and `calibration-125-percent-layout.png`.
Screenshots are not deployed assets and contain no live installation data.

## HANDOFF FOR NEXT PROMPT

Next batch: Dashboard, Gate Monitor, Registry, Visitors and Activity Logs.
Reuse the shell and shared components; keep page-specific rules scoped.
Settings refinements live in `public/css/layout-settings.css`; the base
stylesheet remains intact. Preserve the Gates/Advanced URLs, the shared
gate-settings form, modal selection/focus behavior, stable video elements
and normalized ROI geometry. Keep secure provisioning, activation, required
password change, roles and detector-service authorization unchanged.
Do not alter the four pre-existing broken historical references or live
configuration for screenshots. Continue using isolated fixtures for writes.
