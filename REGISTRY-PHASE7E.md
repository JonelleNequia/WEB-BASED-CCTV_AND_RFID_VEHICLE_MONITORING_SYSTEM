# Phase 7E — Manual entry form cleanup and final regression

Implemented locally on 2026-11-07. Scope: Phase 7E only. The Phase 7D handoff, manual Guest form/controller/service, Quick Manual Log form/request/service, shared plate rules/scripts, routing, and relevant tests were inspected before editing. No applicable `AGENTS.md` was present in relevant source directories or ancestors. Pre-existing work was preserved.

## Findings and implemented behavior

### Guests → Add guest manually

The browser already used the Phase 2 plate normalizer/validator, and `VisitorController::store()` already accepted blank Plate Number and Vehicle Type. These existing behaviors were reused. A redundant ASCII-only request regex disagreed with the shared plate contract for supported space separators, such as a nonbreaking space. That regex was removed; the existing `PlateIdentifier(20)` rule and 30-character field limit remain authoritative.

The shared `VisitorRecordService::createManual()` now validates optional plate input before creating a profile or storing an image. This also gives direct service callers an error on the correct `plate_number` field, with no profile/image/movement left by invalid input. The existing downstream profile validation remains intact.

The Guest plate field has persistent, associated inline error markup and concise help, reused by the existing shared JavaScript. Invalid text stays available for correction, valid input is uppercased without reordering characters, and correcting or clearing an invalid optional value clears the error. Paste and IME behavior remain the existing shared implementation. Blank Plate Number and Vehicle Type remain valid; no placeholder plate or synthetic type is invented.

Manual Guest saves already followed existing registration eligibility and Review decisions. The old help/success message incorrectly called every result an Unregistered Guest, even when an exact eligible Registry match produced a Registered occurrence. Feedback now reflects the actual **Registered vehicle**, **Review record**, or **Unregistered guest** result. Registered feedback points staff to Activity Logs. Matching, classification and movement rules were not changed. Blank-plate records retain their existing Review behavior; historical Guest occurrences remain Guest after later registration.

Vehicle-type errors appear inline. The manual form includes a form marker so its type-validation failure can reopen the correct drawer without treating an unrelated Review/type action as a manual Guest submission.

### Activity Logs → Quick Manual Log

- Removed the visible **Plate Confidence** and **Station / ROI** controls.
- Renamed **Time** to **Date and Time**, retaining the datetime-local input and entered/default value behavior.
- Kept the remaining existing inputs: Log Type, Plate, Vehicle Type, operational Camera, Date and Time, and optional vehicle/plate images.
- Changed `StoreVehicleEventRequest::roi_name` from required to nullable and made `EventService::create()` tolerate an omitted ROI key. A new form submission therefore stores null ROI and null confidence instead of failing validation/raising an undefined-key error. No calibration/station label, OCR score or detector evidence is fabricated.
- Removed the create page's unused ROI query/import. Historical ROI/confidence columns, full-record details, pending-event completion, detector paths and Advanced settings remain intact.
- Older callers may still submit ROI/confidence; their existing length/numeric limits remain validated. Camera, plate, type, date and permissions remain enforced.

Before the fix, isolated tests reproduced the required-ROI rejection, the direct service's absent-key error, the raw-regex space mismatch, and the inaccurate Registered manual-save feedback. A successful browser submission now saves the chosen **2026-11-03 08:17 Manila** occurrence timestamp with only the remaining required inputs; the same event is returned by the Activity Logs date/time window.

## Verification

All mutation checks used a private source/dependency copy, isolated SQLite databases/storage/configuration, synthetic accounts and fixture devices. The private camera/device paths and Python bytecode guard were verified. Browser off-origin requests were blocked. No live database bootstrap, migrations, reset/reseed, repair, device modification, deployment, publication or push occurred.

| Check | Result |
| --- | --- |
| Full Laravel suite | **789 tests / 8,440 assertions passed**, no failures or skips |
| New `ManualEntryCleanupTest` | **28 tests / 212 assertions passed**, included above |
| New `manual-entry-ui.mjs` | **38 checks passed** |
| Registry status/Owner Name browser suite | **58 checks passed** |
| Plate Phase 2 browser suite | **41 checks passed** |
| Plate evidence/Review browser suite | **41 checks passed** |
| Activity Logs time-filter browser suite | **31 checks passed** |
| Guests quick-filter browser suite | **45 checks passed** |
| Dashboard occurrences browser suite | **73 checks passed** |
| RFID sticker-number browser suite | **52 checks passed** |
| Optional-RFID browser suite | **43 checks passed** |
| Automatic-filter browser suite | **69 checks passed** |
| Registration snapshot browser suite | **22 checks passed** |
| Browser total | **513 checks across 11 suites passed** |
| `npm run test:js` | **17 tests passed** |
| `npm run build` | Passed, **61 modules** transformed |
| PHP/JS syntax, new PHP files' Pint check, `git diff --check` | Passed |

Focused coverage includes:

- Optional blank Guest plate/type, existing Review classification, staff attribution, no fabricated profile/type, and readable Unknown type.
- The four plan example plate formats, another supported format, supported spaces/hyphens, leading zeros, literal `395ISX` / `ISX395`, and the O/0 and I/1 pairs. Invalid punctuation/symbols, control characters, fullwidth text, meaningless and oversized keys are rejected at HTTP and service boundaries without orphaned profile/image/movement data.
- Client inline errors, invalid-submit prevention, clear-to-blank recovery, paste/IME normalization, direct HTML server-validation failure, retained values and drawer reopening.
- An eligible untagged registered plate entered manually: one canonical occurrence in Activity Logs, exclusion from Guests, accurate success feedback and no RFID placeholder. An earlier Guest remains Guest after later registration.
- Quick Manual Log with no configured ROI records, no removed fields or hidden replacements, unchanged remaining validation, exact chosen occurrence time, null confidence/ROI and manual type provenance. Legacy submitted fields still validate, inactive/unknown cameras are rejected, and existing admin/guard/anonymous permissions are retained.
- Final cross-page regression of Registry's passive status light and independent filters, required new Owner Name, optional RFID and staff default; plate/Review matching and unrelated actions; Guest filtering/history; Activity Logs daily time windows and occurrence images; Dashboard identity/Review/gate counts; sticker/UID separation and permanent lost UID behavior.

The new forms were visually inspected at **1366 px**, **390 px** and **320 px**. Quick Manual Log has no page overflow at the narrow widths; the Guest drawer retains readable plate feedback. No new print layout was introduced.

Private artifacts/logs:

```text
/var/folders/zb/05jm3cld3yj8fsbb2cpscjlm0000gn/T/philcst-manual-forms-phase7e-g_n4be0k/
  laravel-final.xml
  check-manual-entry-ui.mjs.txt
  check-registry-status-owner-ui.mjs.txt
  check-plate-phase2-ui.mjs.txt
  check-plate-evidence-ui.mjs.txt
  check-activity-time-ui.mjs.txt
  check-guest-quick-filters-ui.mjs.txt
  check-dashboard-occurrences-ui.mjs.txt
  check-sticker-tags-ui.mjs.txt
  check-optional-rfid-ui.mjs.txt
  check-auto-filters-ui.mjs.txt
  check-registration-snapshots-ui.mjs.txt
  app/storage/app/ui-review/manual-entry/
    guest-plate-error.png
    guest-plate-390.png
    guest-plate-320.png
    quick-manual-desktop.png
    quick-manual-390.png
    quick-manual-320.png
```

## Changed files in this phase

```text
app/Http/Controllers/VehicleEventController.php        unused create-page ROI query/import only
app/Http/Controllers/VisitorController.php             shared rule and accurate manual-save feedback
app/Http/Requests/StoreVehicleEventRequest.php          nullable legacy ROI field
app/Services/EventService.php                          omitted manual ROI handling
app/Services/VisitorRecordService.php                  early optional plate validation
resources/views/vehicle-events/create.blade.php        simplified Quick Manual Log
resources/views/visitors/index.blade.php               manual Guest feedback/help/error markup
tests/Feature/ManualEntryCleanupTest.php               new
tests/browser/manual-entry-ui.mjs                      new
tests/fixtures/manual-entry-ui.php                     new, guarded isolated fixtures
REGISTRY-PHASE7E.md                                    new
```

No migration, schema/dependency update, backfill or existing-data correction is required. A later authorized Windows update should include the coordinated PHP/views and refresh ordinary application/view/browser caches. No external installation was updated.

## Limitations and handoff

Verified on macOS/Chrome and SQLite. Native Windows browsers/date controls, other database engines, physical cameras/readers/printers and production-volume behavior remain unverified. Camera/reader integration checks used isolated application services and synthetic fixtures; they do not establish live hardware accuracy. Detector/Python implementation files were unchanged.

The pre-existing legacy Activity Logs direction-filter inconsistency documented in Phase 7C remains outside this manual-form batch: old rows with type `GUEST` retain a separate direction field and are not included by the old direct ENTRY/EXIT/UNKNOWN filters. This phase does not repair or rewrite those records. Existing-tag sticker relabeling also remains outside the new-tag workflow, as documented in Phase 7D.

No unresolved Phase 7E implementation blocker. **Phase 7E completes the authorized Phase 7A–7E sequence; stop here and await a new task.** Preserve all prior registration/plate/Review/history constraints. No additional repair batch, security work or deployment was started.
