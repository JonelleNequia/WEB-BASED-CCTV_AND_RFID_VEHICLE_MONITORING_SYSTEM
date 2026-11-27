# Phase 7A — Separate Registered occurrences from Guests

Implemented locally on 2026-11-07 (Asia/Manila). Read the current implementation and Phase 4–6 handoffs. No applicable AGENTS.md was found in the repository or its ancestors. The working tree was clean before this batch; the user's committed work was preserved. Only Phase 7A is implemented.

This handoff supersedes the **Registered Guests quick-filter behavior** described in the historical Phase 5 and 6 reports. Their other filter/report contracts remain in force.

## Verified screenshot cause

The preceding read-only review traced **AAC 1869, November 3, 2026 at 7:48 PM** to VisitorRecord **#16**, saved with `registration_status=registered`, linked to Registry vehicle **#4** and completed canonical movement **#15**. The Registry entry predates that occurrence, and the normalized current lookup has one exact match. Neither the crossing nor canonical event has an RFID scan linked.

The row was already classified Registered. The confirmed defect was the default Guests query including Registered evidence. The screenshot concerns **no RFID read for that occurrence**; it does not establish that the Registry vehicle had no RFID assignment. A separate isolated fixture verifies a genuinely untagged registration through real ingestion and staff confirmation.

No historical record or classification was repaired. The old `plate_ocr` occurrence has no new-style staff identifier-type metadata; this batch does not retroactively apply the new OCR policy to it or claim to validate its original camera/model accuracy. Current uncertain OCR still follows the Phase 3 Review rules.

## Implemented behavior

- `GuestRecordQuery` excludes `registeredAtOccurrence()` IDs before pagination and all optional criteria. This covers saved decisions and the existing nullable legacy fallback. It is a server query restriction, not a hidden-row/UI-only change.
- The same query supplies Guests list totals, Print rows/summaries and CSV. Registered camera, manual-source and historical evidence are excluded regardless of Show status or other criteria. Activity Logs still exposes their canonical movements/evidence through its existing occurrence reconciliation.
- A genuine saved Guest remains Guest after later registration or explicit relationship transfer. A present `vehicle_id`, current Registry plate, current category/status or missing RFID is not substituted for occurrence classification.
- Exact matching, literal plate order, eligibility, RFID conflict restrictions, Review and classification services are unchanged. A confirmed eligible untagged plate can become Registered; uncertain evidence stays in Review under the existing rules. No placeholder RFID tag is created.
- The Registered quick filter is removed through the shared filter definition. The remaining buttons are **All**, **Unregistered Guests** and **Manual entries**. Manual means saved `source=manual` within the non-Registered set. Existing Review evidence remains eligible under All/Manual as before; the Unregistered Guests quick criterion excludes Review. Review queues/counts retain their existing task semantics, including legitimate direction-review work.
- The guest-presence estimate no longer counts Registered IN rows, including saved Registered rows without profile/vehicle relationships. It retains the **complete** per-profile timeline before checking the final state: a later Registered OUT still closes an earlier Guest IN. Existing current-profile eligibility for the estimate is retained; the estimate is not a rewrite of historical Guest classification.
- Search, gate, plate result, Show, ordering, pagination, archived-gate history, Reset, Back/Forward, accessible selection and row dialogs retain their existing behavior. The three remaining chips fit at 390 px and wrap when needed at 320 px.

Registered records may still be inspected through existing historical detail/profile relationships and Activity Logs. Plate Profiles and Review are separate existing workflows; their historical queries were not converted into a new current-vehicle directory filter.

## Legacy URLs

The obsolete `record_filter=registered` is no longer a supported filter value. Validation gives this explanation:

> The Registered filter has been removed from Guests. Registered movements remain in Activity Logs.

- A normal HTML **list** bookmark redirects to the canonical Guests list, removes that invalid criterion and resets page one while retaining other valid criteria. The explanation is visible on the destination page. It does not redirect guards into an admin-only Activity Logs route.
- Direct **Print/CSV** requests return 422 with the explanation, rather than silently exporting the now-different All dataset. Other valid criteria are retained in the return-to-filters link.
- JSON/automatic-filter requests also return 422. A legacy browser-history entry therefore leaves the previous valid rows mounted and disables both export targets until correction or Reset. Existing request-version checks still prevent a delayed response from restoring an old selection or export URL.

## Verification

All mutation checks used a private source copy, isolated SQLite databases/storage/media and separate camera/device paths. Browser fixtures use random accounts, private PHP servers and off-origin request blocking. No live database migration, reset, reseed, production-data repair, device change, deployment, publication or push was performed.

| Check | Result |
|---|---|
| Full Laravel suite | **721 tests / 7,678 assertions**, zero failures, errors or skips |
| New separation feature suite | **5 tests / 157 assertions** |
| Guests quick-filter browser suite | **45 checks passed** |
| Optional RFID browser suite | **43 passed** |
| Registry/Guests reports browser suite | **64 passed** |
| Plate pipeline browser suite | **26 passed** |
| Plate evidence/Review browser suite | **41 passed** |
| Existing automatic filters browser suite | **69 passed** |
| Existing Activity time browser suite | **31 passed** |
| Browser total | **319 checks across seven suites** |
| JavaScript units | **17 passed** |
| Frontend production build | Passed, 61 modules; private output only |
| Static checks | Changed PHP/JavaScript syntax, new regression file's Pint and `git diff --check` passed |

The five new regression cases failed before the implementation: Registered leakage in Guests/list counts, history mixed with Registered rows, full-page/report membership, guest-presence counts and obsolete-filter acceptance.

Final isolated checks include:

- A real service-created registration with no RFID assignment and no movement created by registration itself. Detector API ingestion initially produces Review; staff confirms the actual exact plate, the same occurrence becomes Registered, and its one canonical event remains in Activity Logs. Guests list/Print/CSV exclude it. Correction retries retain one event/audit entry and create no RFID inventory row.
- A genuine Guest and an earlier Guest whose plate is registered later, including explicit history relationship transfer. The earlier occurrence stays Guest in list, counts, Print, CSV and Activity Logs; later Registered evidence for the same plate is excluded from Guests.
- Multi-page membership with mixed Guest, Review and Registered records; manual-source Registered records; active, duplicate and dismissed Show values; combined search/gate/plate-result filters; archived gates; empty results; full filtered report summaries and CSV ID parity.
- The existing 96-row legacy/evidence matrix still checks the model's Registered scope against Activity Logs presentation and checks that All/Unregistered Guest list membership excludes Registered consistently. Legacy nullable evidence is not negated incorrectly through SQL NULL semantics.
- A Registered OUT after an earlier Guest IN, Registered IN rows with missing relationships, and existing no-plate Guest/Review evidence. The presence estimate excludes Registered without resurrecting an earlier open Guest trip.
- Browser pagination, Print/CSV ID comparison, historical Guest retention, legacy bookmark recovery, direct old report rejection, old automatic-history errors, disabled stale export links, rapid changes, Reset, Back/Forward and existing row dialogs/note saving.

Existing tests that explicitly expected Registered rows in Guests were updated to assert the new boundary while retaining classification, identity, OCR, staff attribution, movement, audit and Activity Logs checks. Original OCR remains inspectable through retained profile history. No implementation changes were made to matching/reconciliation, detector output, occupancy or RFID handling.

Desktop screenshots were inspected at **1366 px**, with narrow layouts at **390 px and 320 px**. Print/CSV behavior was verified against existing layouts and matching IDs; this phase does not change report layout or Activity Logs plate-image presentation.

Private synthetic artifacts/logs:

```text
/var/folders/zb/05jm3cld3yj8fsbb2cpscjlm0000gn/T/philcst-guests-phase7a-0ufcmurm/
  laravel-final.xml
  check-guest-quick-filters-ui.mjs.txt
  check-optional-rfid-ui.mjs.txt
  app/storage/app/ui-review/guest-quick-filters/
    desktop-manual.png
    mobile-manual.png
    compact-manual.png
```

Checks ran on macOS/Chrome and SQLite. Windows browsers, other database engines, physical readers/cameras/printers and production-size history performance remain unverified. The detector/Python files are unchanged and Python tests were not rerun for this query/UI boundary change. No hardware accuracy claim is made.

## Changed files

```text
app/Http/Controllers/VisitorController.php
app/Http/Middleware/ValidateListFilters.php
app/Services/GuestRecordQuery.php
app/Services/VisitorPresenceService.php
tests/Feature/GuestQuickFilterTest.php
tests/Feature/GuestsRegisteredSeparationTest.php          new
tests/Feature/Phase6VisitorRankingTest.php
tests/Feature/RegistrationPlatePipelineTest.php
tests/Feature/RegistryGuestReportTest.php
tests/browser/guest-quick-filters-ui.mjs
tests/browser/list-reports-ui.mjs
tests/browser/optional-rfid-ui.mjs
tests/browser/plate-pipeline-ui.mjs
tests/fixtures/guest-quick-filters-ui.php
REGISTRY-PHASE7A.md                                     new
```

No migration, schema change, dependency update or data backfill is required. A later authorized installation update should include the coordinated PHP changes and refresh normal application caches. No external installation was updated.

## Handoff

Phase 7A is complete locally with no unresolved implementation blocker. Preserve genuine historical Guest decisions and the distinction between no RFID assignment and no RFID read. Do not repair or relabel the reviewed screenshot occurrence simply because it is now excluded from Guests.

**Stop and wait for Go Phase 7B.** Activity Logs plate crops/Print images (7B), Dashboard presentation/count scope (7C), manual sticker labels (7D) and manual form cleanup (7E) remain unimplemented in this batch. The shared guest-presence estimator's Registered exclusion is the only count-helper change; Dashboard UI and general movement-count logic are unchanged.
