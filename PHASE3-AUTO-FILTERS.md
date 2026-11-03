# Phase 3 — automatic search and filtering

Implemented after authorization of Phase 3 only. Phase 1 validation, Phase 2
color retirement and prior security, gate-management, UI and runtime changes
remain in place. No routes, database fields, account types or filters were added.

## Lists covered

| Existing workspace | Existing criteria |
| --- | --- |
| Registry / Vehicles | Text, category, state, active status |
| Registry / RFID Tags | Inventory status and existing status links |
| Visitors / Records | Plate, gate, plate result, record status |
| Visitors / Plate Profiles | Plate and existing sort order |
| Activity Logs / All Events | Plate, owner, gate, dates, category, movement, match status, log-type chips, quick timeframes |
| Activity Logs / RFID Scans | History text, gate and result |
| Activity Logs / Alerts | Plate/tag text and alert type |

Text waits 300 ms after idle typing, including a single character and `0`.
Existing selects and valid dates apply immediately. The scan-history backend's
`empty()` check was replaced with `filled()` so the valid search `0` is no longer
ignored. No other search or classification business rule was changed.

## Request and UI behavior

- `auto-filters.js` enhances only explicitly marked GET list forms. It requests
  HTML from the existing authorized routes and imports the marked read-only
  results, pagination, summaries, chips and record-detail JSON. Filter controls,
  disclosure panels, print checkbox and edit dialogs remain mounted.
- Focus, caret/selection, raw text and other selected criteria remain intact.
  Only current-list filter links, Reset and pagination are intercepted. Opening
  another workspace, full record, CSV or print report keeps its normal action.
  Registry row actions now use delegation so newly rendered rows stay usable.
- Criteria changes clear pagination. Pagination retains criteria. Successful
  changes update browser history; Back/Forward fetch the matching state and
  restore controls from the server's defaults/selected values. New explicit
  date ranges replace quick timeframes, and timeframe links clear explicit dates.
- A keystroke invalidates the previous request before its debounce expires.
  Transport cancellation and a monotonically increasing request version guard
  prevent old search or poll responses from updating results, counts or links.
  IME intermediate composition is withheld until composition ends.
- Activity Logs uses one shared request owner for filtering and its existing
  four-second polling. Polls use applied criteria, refresh the complete matching
  result/pagination state, and pause on page two or later, pending/invalid input,
  composition, hidden pages, open menus/dialogs or focused result controls.
  Opening a dialog cancels an outstanding refresh before it can detach an action.
- Loading and empty feedback are visible. Native date checks and server 422
  field errors identify invalid criteria. Failed, timed-out, unauthorized or
  unreadable responses keep successful results and provide Retry. Invalid
  automatic requests receive field errors rather than a broader redirected list.
- Toolbar exports wait until criteria are successfully applied. Their URLs then
  use the matching server filters; print snapshot selection survives refreshes.
  Per-record modal details and print use the latest record data. Scoped print
  CSS preserves the existing print sheet through the new list wrapper.
- Submit-only filter buttons are absent with JavaScript enabled. Existing
  Reset, filter disclosures, Export, Print, Save as PDF and record actions remain.
  `noscript` GET-submit fallbacks remain for browsers with JavaScript disabled.
  Report-page snapshot behavior remains as before; it is outside list filtering.
- All scripts/styles are local. No new dependency or internet connection is
  required. New/changed public script URLs use the existing file-time versioning
  convention so installed browsers load updated code.

Creation, record edits, review actions, calibration, account/security forms and
destructive actions are not marked for automatic submission. Existing
authentication and admin boundaries remain enforced on the server.

## Verification

All mutation fixtures use isolated databases, never installation records.

- Full Laravel suite: **432 passed, 3,919 assertions**, including **12 new focused
  cases** for all seven list contracts, unchanged mutation-form boundaries,
  server validation, one-character/zero filtering, query-preserving pagination,
  matching CSV/print filters and authentication/authorization.
- `node tests/browser/auto-filters-ui.mjs`: **69 assertions passed** in headless
  installed Chrome. Tests cover rapid
  typing/debounce, loading/empty/error states, retained controls/caret/selection,
  combined filters, Reset, Back/Forward, IME events, stale requests both during
  debounce and after newer results, retries, row actions and unsubmitted edits.
  It also covers all seven workspaces, sort/pagination, valid/invalid dates,
  current export URLs, retained print checkbox, page-two polling suppression,
  automatic polling resumption on page one, delayed poll versus new search,
  counts/details consistency, blocked exports
  after failure, server field errors and per-record print-mode layout.
- Earlier browser runners passed: **20 Phase 1**, **20 Phase 2** and **128 Settings
  assertions**. The Phase 2 runner now recognizes the shared HTML poll request
  rather than the retired page-level JSON poll; its color assertions remain.
- `npm run test:js`: **13 passed**. `npm run build`: passed, **61 modules**.
- Changed PHP and JavaScript syntax, focused Pint and scoped diff checks passed.

No failing automated test remains. The final browser run includes the per-record
print compatibility fix and automatic polling resumption after pagination.

The new browser runner provisions its own private temporary SQLite database,
credentials, sessions, device/config directories and ephemeral PHP server. It
blocks browser requests outside that server and removes its temporary files and
server afterward. Delayed-response tests deliberately ignore AbortSignal so
they verify the version guard, not just successful transport cancellation.

Physical Windows IME interaction, assistive technology, printer output, the
actual Windows runtime and camera/RFID hardware were not verified. Browser tests
use synthetic composition events and print-media emulation. No deployment,
push, external-service update, working-database migration or live-record test
write was performed. The four known broken historical references are unchanged.

## Existing Windows installation

No Phase 3 database migration, reset, reseeding or dependency installation is
needed. During the usual backed-up application update, include the new local
CSS/JavaScript and partials along with the modified PHP/Blade/JavaScript files.
Clear/rebuild application/view caches with the existing update procedure and
restart the local PHP process as appropriate. Keep `.env`, database, uploads,
device settings and model files intact. Earlier gate/security migrations retain
their separate upgrade instructions. This phase does not require a detector
script update.

## Files changed in Phase 3

Earlier edits in shared files are preserved.

```text
README.md
PHASE3-AUTO-FILTERS.md (new)
app/Http/Middleware/ValidateListFilters.php
app/Services/RfidService.php
public/css/auto-filters.css (new)
public/js/auto-filters.js (new)
public/js/activity-logs.js
public/js/registry.js
resources/views/components/table.blade.php
resources/views/logs/index.blade.php
resources/views/logs/tabs/alerts.blade.php
resources/views/logs/tabs/events.blade.php
resources/views/logs/tabs/scans.blade.php
resources/views/partials/auto-filter-assets.blade.php (new)
resources/views/partials/auto-filter-status.blade.php (new)
resources/views/partials/filter-errors.blade.php
resources/views/registry/index.blade.php
resources/views/registry/tabs/tags.blade.php
resources/views/registry/tabs/vehicles.blade.php
resources/views/visitors/index.blade.php
resources/views/visitors/partials/plates.blade.php
tests/Feature/AutomaticListFilteringTest.php (new)
tests/browser/auto-filters-ui.mjs (new)
tests/browser/vehicle-color-ui.mjs
tests/fixtures/auto-filters-ui.php (new)
```

## HANDOFF FOR NEXT PROMPT

Stop after Phase 3 and wait for **“Go Phase 4”**. Registration classification was
not changed. For the authorized classification fix, trace authoritative
registration relationships and keep displayed rows, filters, summaries, feeds
and exports consistent. Do not infer registration from readable plate text or
bulk-relabel historical records. Preserve the known broken references, earlier
security/gate/runtime work, Phase 1 optional-name/legacy-organization rules and
Phase 2 ignored-color compatibility. Keep automatic filtering, shared polling,
request versions, mounted controls/dialogs and export safeguards intact.
