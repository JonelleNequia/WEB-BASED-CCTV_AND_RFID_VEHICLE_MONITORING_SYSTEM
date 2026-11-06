# Registry Phase 4 — Activity Logs daily time window

Implemented locally on 2026-11-05 (Asia/Manila). Read REGISTRY-PHASE3.md and inspected the existing date filters, unified row mapping, automatic refresh/history, summaries, Print and CSV paths before editing. No applicable AGENTS.md was found in the repository or its ancestors. Earlier working-tree changes were preserved.

## Implemented behavior

Activity Logs → All Events now has **From Time** and **To Time** beside the date controls. Valid changes apply automatically and combine with the existing plate, gate, category, movement, status, owner, log-type and date/period criteria.

The window repeats on **each selected Manila calendar date**. Nov 3–5 with 08:00–10:00 includes that interval on all three days. It includes the complete end minute: 10:00:59 is included, 10:01:00 is excluded. Equal times select one complete minute. From Time alone means that time through the end of each day; To Time alone means midnight through the end of that minute. Time-only filtering works without dates. Two blank times apply no time restriction.

Filters use the occurrence time shown for each row:

| Row source | Occurrence timestamp |
|---|---|
| Vehicle event | `event_time` |
| Older Guest observation | `observed_at` |
| RFID-only scan | `scan_time` |
| Visitor record | `seen_at` |

Receipt/creation time remains separate and does not establish time-window membership. The existing sort order is preserved. Display, CSV timestamps and Print rows use Asia/Manila. Date boundaries start at Manila midnight and are converted to the application's storage timezone for the existing SQL date constraints; they are not rounded back to storage-zone midnight. This also works when the application is configured for UTC.

`ActivityLogTimeWindow` supplies shared validation and the daily-minute predicate. Date filtering remains in the existing source queries; the time predicate runs on their existing unified row collection **before** summaries and pagination. No database-specific time SQL or schema change was introduced.

## Validation, refresh and exports

- Server dates require valid `YYYY-MM-DD` calendar dates and ordered endpoints. Times require `HH:MM` from 00:00 through 23:59. Malformed values, arrays, seconds in minute-only inputs, reversed dates and From Time later than To Time are rejected.
- The UI shows field-level errors, retains the last valid rows/totals and valid URL while input is invalid, and explains: **Overnight periods require separate selections**. Partial/native-invalid inputs use the existing validity checks; server 422 field errors also appear inline. Corrections clear the errors.
- Pending or invalid criteria remove Print/CSV targets as well as marking the controls disabled. The capture handler blocks modified clicks before the Print handler can reconstruct a stale link. Successful filtering restores the newly applied export URLs. Older delayed responses cannot overwrite newer criteria.
- Valid times survive pagination, automatic polling, Back/Forward and other filter changes. Changed criteria return to page one. Reset clears both time fields and the existing date/filter selections.
- Automatic-filter validation failures return 422 JSON and preserve the mounted results. Invalid direct HTML date/time requests return an explicit 422 error page, including Print/CSV requests; they do not redirect into a broader list or automatic download. A direct invalid URL has no previously mounted valid table to retain.
- The All Events layout now lets its grid/results shrink on narrow screens, preserving horizontal table scrolling while keeping filter controls within the viewport. The initial 390 px screenshot exposed clipped controls; the final browser check verifies all visible filter inputs fit.

Table totals, Print Current List and bulk CSV use the same filtered unified collection. Print and CSV include every match beyond the current page. Bulk CSV also does so when called directly without `all=1`; the previous default exported only ten rows. Existing explicit record-level CSV exports remain single-record exports. CSV retains its existing fourteen columns and the Print layout/columns are unchanged.

Print's filter summary shows the applied **daily time range and Asia/Manila**, including the end-minute behavior. Existing named Print date presets still explicitly choose their own date period; they retain the daily time window and other criteria. Print Current List preserves the table's selected dates. Print options, snapshot selection and the return-to-filters link retain the time criteria.

Authorization, archived-gate history, classifications, snapshots, Registry controls, required new Owner Name, optional RFID and Phase 2/3 plate/evidence behavior remain covered by existing checks. No live records, device settings, detector behavior, migrations or dependencies were changed. No deployment, publication or push was performed.

## Verification

All mutation checks used a private source copy and isolated SQLite databases, media/storage and device paths. Browser fixtures create random test accounts and a private PHP server, and block off-origin requests.

| Check | Result |
|---|---|
| Full Laravel suite | **698 tests / 7,019 assertions; zero failures, errors or skips** |
| New time-window feature suite | **20 cases**, including repeated daily intervals, whole-minute boundaries, one-sided/blank/equal windows, midnight, UTC storage across all four sources, direct validation, combined filters, archived gates, permissions and date presets |
| New Activity time browser suite | **31 checks passed** |
| Existing automatic filters | **69 passed** |
| Existing snapshot / archived gates and menus | **22 / 132 passed** |
| Existing Registry status/owner | **58 passed** |
| Existing plate pipeline / evidence Review | **26 / 41 passed** |
| Browser total | **379 checks across seven suites** |
| JavaScript units | **17 passed** |
| Frontend production build | Passed, 61 modules, private output only |
| Static checks | Changed PHP/Blade and JavaScript syntax, new PHP files' Pint, `git diff --check` |

Initial regressions reproduced the missing time restriction and missing time validation before implementation. Isolated fixtures contain forty-five rows, including **thirty-six matches across three table pages** for Nov 3–5 / 08:00–10:00. Tests compare actual matching event-ID sets across all table pages, Print and CSV without adding export columns. Print/CSV retain all thirty-six even when requested from page two/three. The CSV header remains fourteen columns.

Additional fixtures verify 07:59:59, 08:00:00, 10:00:00, 10:00:59 and 10:01:00; Philippine midnight and the last minute of the day; receipt time different from occurrence time; every unified source with UTC-configured storage; combined criteria and historical archived gates. Browser checks cover inline rejection/recovery, preserved rows, disabled/stale export targets, Reset, pagination/history, malformed server input and a delayed older response following rapid time changes.

Screenshots were visually inspected at 1366 px and 390 px and for Print preview. Private artifacts and JUnit results are under:

```text
/var/folders/zb/05jm3cld3yj8fsbb2cpscjlm0000gn/T/philcst-time-phase4-79sv9c1f/
  laravel-final.xml
  check-activity-time-ui.mjs.txt
  app/storage/app/ui-review/activity-time/
    desktop-window.png
    mobile-window.png
    invalid-window.png
    print-window.png
```

These contain synthetic data. Tests ran on macOS/Chrome and SQLite. Windows-native date/time controls, other production database engines, physical printing and large-history load performance were not exercised. Python/detector files are unchanged and the Python suite was not rerun for this phase. No hardware-dependent verification is claimed.

## Changed files

This list is relative to the preserved Phase 3 working tree.

```text
app/Http/Controllers/VehicleEventController.php
app/Http/Middleware/ValidateListFilters.php
app/Support/ActivityLogTimeWindow.php                   new
public/css/auto-filters.css
public/js/auto-filters.js
resources/views/errors/filters.blade.php
resources/views/logs/tabs/events.blade.php
tests/Feature/ActivityLogTimeFilterTest.php              new
tests/browser/activity-time-ui.mjs                      new
tests/fixtures/activity-time-ui.php                     new
REGISTRY-PHASE4.md                                      new
```

No migration, database repair or backfill is needed. A later authorized installation update should include the coordinated PHP, Blade and public asset files and refresh the usual application/browser caches. Nothing was deployed to the Windows installation. Existing historical timestamp values are preserved and interpreted using the application's existing timestamp casts/storage timezone; no live-data audit or timestamp repair was performed.

Phase 4 is complete locally. No unresolved implementation blocker remains. Keep the Phase 1–3 behavior and scope constraints intact. Stop here and wait for **Go Phase 5**.
