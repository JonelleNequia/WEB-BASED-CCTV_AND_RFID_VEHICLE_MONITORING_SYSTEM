# Phase 7C — Dashboard occurrences, Review and operational gates

Implemented locally on 2026-11-07. This batch covers Phase 7C only. The Phase 7A/7B work and other pre-existing changes were preserved. No applicable `AGENTS.md` was found in the project, relevant source directories, or ancestors. The Phase 7A and 7B handoffs were read.

## Findings and implemented behavior

### Live activity

The previous Dashboard had two independent identity problems:

1. `DashboardController::unmirroredGuestObservationsQuery()` excluded legacy evidence only when an event with the same external key had origin `guest_cctv` or `guest_manual`. A linked canonical `plate_ocr`/`plate_review` event could therefore appear alongside its older evidence. Converted visitor evidence was not consistently excluded, and visitor-only occurrences were not queried directly.
2. `dashboard-live.js::streamKey()` keyed browser rows by title, summary, displayed minute and badge. Separate movements with identical visible text could collapse into one browser row. Identical plate/time labels are not sufficient evidence of a duplicate.

`DashboardActivityService` now presents the latest eight occurrences using the existing canonical-event/visitor/legacy relationships and hidden-copy scopes. Completed canonical movements take precedence over their visitor evidence; otherwise the active visitor occurrence represents the crossing. Legacy evidence is omitted when an existing visitor or visible canonical event represents its explicit key/relationship. No fuzzy plate/time matching or live-history rewrite was added. A pending canonical record can become the visible completed record without creating another movement.

Each row carries a source-qualified stable key (`vehicle_event:id`, `visitor_record:id`, or `guest_observation:id`). Browser polling uses that key, preserving separate same-plate/same-minute events and retaining DOM identity across updates. The older RFID feed payload also receives stable keys and uses the corrected legacy-evidence query.

The title contains one clear **IN**, **OUT**, or **Direction unknown** label. Canonical rows use the recorded event type, so stale detector metadata cannot override a staff-corrected direction. Status badges no longer repeat Entry/Exit or Direction unknown. Visitor classifications continue using `VisitorRecordLog` and the occurrence's persisted decision; a later Registry edit does not reclassify an earlier Guest. No plate normalization, eligibility, RFID, occupancy or anomaly rules were changed.

These causes were reproduced with isolated records, including linked RFID, visitor, legacy and canonical evidence. The original screenshot's individual occurrence IDs cannot be established from plate/time labels alone; separate movements are deliberately retained.

### Review and attention

The old nested Review link was inside an `<x-stat>` that itself rendered as a link. The browser could reparent that invalid nested-anchor markup. The old Dashboard count also omitted completed registered unknown-direction cards, and the live-state payload had no Review count.

- `VisitorReviewService::totalCount()` now supplies the existing Review tab total to both Dashboard and `VisitorController`: visitor Review cards plus completed registered direction cards. Existing per-visitor queue/action counts remain unchanged.
- The **Needs Attention** panel contains an independent, keyboard-accessible **N to review** link, including zero. `metrics.review_count` refreshes it through the existing poll.
- The alert-only KPI is labelled **Alerts**, making its existing alert count distinct from the Review queue. No new combined/double-counted attention total was introduced. The alert list says **No anomalies or unknown tags** when empty, without claiming the entire Review queue is clear.

### IN / OUT Counts → By Gate

`MovementCountService::dashboardPeriods()` projects the existing period counts onto `Gate::ordered()`, the same active, retained gates used by Gate Monitor. It retains configured ordering and current names, including zero-count gates. Inactive and archived gates are excluded from Dashboard By Gate, while overall totals/categories and historical `counts()` remain unchanged.

The Dashboard explains that totals include inactive/archived-gate history while By Gate shows current operational gates. Archived history remains queryable in Activity Logs. Polling rebuilds gate rows for all four periods, handling rename, archive, newly configured zero-count gates, and no-operational-gate empty states while preserving the selected period.

### Layout

Removed **Today's traffic** and its browser chart renderer. The existing hourly/traffic fields on the live-state API remain available for compatibility. Counts span the page; Live activity and Needs Attention share the next desktop row; rankings retain their existing separate tabs below. Narrow layouts stack the cards, wrap segmented controls, retain readable count columns and place activity badges below the text on small screens. These CSS changes are scoped to Dashboard elements.

## Verification

All mutation checks ran in a private source copy with isolated SQLite databases, storage and app-relative camera/device configuration. Browser fixture preparation and updates require a guarded temporary testing database. Fixture device addresses are synthetic and off-origin browser requests are blocked. No live database bootstrap, migration, reset/reseed, repair, device operation, deployment, publication or push occurred.

Before the fix, the new regression suite reproduced missing occurrence keys, the historical/all-gates Dashboard scope, the absent live Review metric and incorrect direction presentation. Final results:

| Check | Result |
| --- | --- |
| Full Laravel suite | **733 tests / 7,927 assertions passed**, no failures or skips |
| New `DashboardOccurrenceTest` | **7 tests / 138 assertions passed**, included above |
| Final focused Dashboard/layout checks | **11 tests / 199 assertions passed** |
| New `dashboard-occurrences-ui.mjs` | **73 checks passed** |
| Existing `guest-quick-filters-ui.mjs` | **45 checks passed** |
| Existing `activity-time-ui.mjs` | **31 checks passed** |
| Existing `auto-filters-ui.mjs` | **69 checks passed** |
| Browser total | **218 checks passed** |
| `npm run test:js` | **17 tests passed** |
| `npm run build` | Passed, **61 modules** transformed |
| PHP/JS syntax, new PHP files' Pint check, `git diff --check` | Passed |

Verified cases include:

- One visible canonical movement from linked RFID/visitor/legacy evidence; active visitor-only Guest/Review occurrences; pending-to-completed representation; two distinct same-plate events with identical visible time/text; stable browser nodes over repeated polls.
- Recorded/staff-corrected directions despite conflicting older detector metadata; unknown direction for canonical, visitor and legacy sources; no repeated direction badge.
- Zero and nonzero Review totals matching the Review tab, resolving visitor and canonical direction cards through existing actions, refresh down to zero, keyboard navigation, and valid link nesting. Existing admin/guard/unauthenticated authorization remains enforced.
- Today, Week, Month and Year boundaries; operational zero-count gates; renamed, inactive and archived gates; full-history overall totals and per-gate occurrence comparisons with Activity Logs. Legacy IN/OUT/unknown directions also agree with Activity Logs Print summaries.
- Polling after gate rename/archive/addition, preservation of the selected Month period, no-operational-gate empty state, and retention of the last valid activity during a failed poll.
- Existing Registry/Guests automatic filters, permissions, row actions, history, IME/race behavior and Activity Logs daily Manila time filters. The full Laravel suite also retains Phase 7A separation and Phase 7B occurrence-image regression coverage.

Desktop **1366 px** and narrow **390 px / 320 px** screenshots were inspected. The 320 px check reproduced a gate-header overflow during development; the final compact header, fixed count-column sizing, wrapped ranking tabs and stacked activity badges pass the page-overflow check.

Private artifacts/logs:

```text
/var/folders/zb/05jm3cld3yj8fsbb2cpscjlm0000gn/T/philcst-dashboard-phase7c-7ib9y7il/
  laravel-final.xml
  check-dashboard-occurrences-ui.mjs.txt
  check-guest-quick-filters-ui.mjs.txt
  check-activity-time-ui.mjs.txt
  check-auto-filters-ui.mjs.txt
  app/storage/app/ui-review/dashboard-occurrences/
    desktop.png
    narrow-390.png
    narrow-320.png
```

## Changed files in this phase

```text
app/Http/Controllers/DashboardController.php
app/Http/Controllers/VisitorController.php             shared Review total only
app/Services/DashboardActivityService.php               new
app/Services/MovementCountService.php                   Dashboard projection only
app/Services/VisitorReviewService.php                   shared totalCount helper
public/css/app.css                                     scoped Dashboard styles
public/js/dashboard-live.js
resources/views/dashboard/index.blade.php
tests/Feature/DashboardOccurrenceTest.php               new
tests/Feature/DashboardRankingTest.php                  updated direction/status expectation
tests/Feature/UiPhase4PagesTest.php                     removed-chart expectation
tests/Feature/VisitorReviewTest.php                     Review counter markup
tests/browser/dashboard-occurrences-ui.mjs              new
tests/fixtures/dashboard-occurrences-ui.php             new, guarded temporary fixtures
REGISTRY-PHASE7C.md                                     new
```

## Limitations and next handoff

- Verified on macOS/Chrome and SQLite. Windows execution, other database engines, physical cameras/readers and production-volume performance remain unverified. Detector/Python implementation files were unchanged; no live hardware-accuracy claim is made.
- Existing legacy Activity Logs rows retain type `GUEST` and store their movement direction separately. Their directions are counted correctly by the existing Print summary, but the old direct `event_type=ENTRY/EXIT/UNKNOWN` filters omit those legacy rows. This pre-existing filter inconsistency was observed during verification and was not changed in this Dashboard-only phase. Comparisons include the actual occurrence direction and Print summary; a separate authorized Activity Logs correction can address the old filters without rewriting historical evidence.
- No existing-record repair or backfill was performed. No migration, schema/dependency update or live-data correction is required for Phase 7C. A later authorized Windows update should include the coordinated PHP/views/static assets and refresh ordinary application/browser caches. No external installation was updated.

Phase 7C is complete locally. **Stop and wait for Go Phase 7D.** Phase 7D is the printed RFID sticker number, separate from electronic UID; Phase 7E is manual form cleanup. Preserve the existing optional-RFID registration, required Owner Name for new registrations, literal-order exact plate matching, Review evidence, occurrence history, Guests separation and Activity Logs crop/time-filter behavior.
