# Phase 4 — Activity Logs classification

Implemented after authorization of Phase 4. The earlier validation, color
retirement, automatic filters, account security, gate management and runtime
repairs remain in place. No migration, new field, filter option, route or
dependency was introduced.

## Confirmed causes and correction

`VehicleEventController::rfidOnlyLogPayload()` previously used `registered` as
the default log type after checking only older pass/guest cases. Consequently,
unknown and unassigned scans appeared in the Registered chip and CSV output.
Old unknown scans stored as `guest` could instead inflate visitor counts. A
separate raw-category query could admit an unknown scan with stale registered
category text into the Registered Visitor category filter.

The original three regression cases failed before the fix: unknown/unassigned
scans returned `registered`, and a legacy unknown scan returned `no_pass_alert`.
They now return the existing RFID-scan record distinction, with their Unknown
tag / Unassigned tag result retained, no inferred vehicle category, and no
visitor count. Existing All, Alerts, RFID Scans and movement controls continue
to find these records; no new filter control was added.

The schema also defaults `verification_status` to `verified`. That default by
itself cannot prove registration on an unlinked record. The new
`RfidScanLog::hasRecordedRegistration()` accepts positive evidence:

- A real linked Registry vehicle, except where the recorded result explicitly
  says unknown/unassigned.
- A verified historical receipt with a recognized registered category snapshot,
  including the supported older Parent/Student/Guard categories. This preserves
  known past registration when the former vehicle relation is absent.

A bare verified/default row without either kind of evidence remains an RFID
scan and displays **Registration unknown**, with a review badge. Its stored
verification value, payload and relationships are not rewritten. RFID history's
existing Registered result filter uses the same evidence rule.

`RfidLogClassification` produces the shared RFID row fields used by the unified
list, JSON responses, modal data, summary, CSV and print pipeline. RFID category
filtering uses the effective row category after classification. The station feed
also recognizes the existing legacy-unknown predicate. Legacy guest-pass and
assigned old Guest records keep their supported distinctions. An inactive
registered vehicle can still be registered while its access result is flagged;
registration and permission are separate decisions.

## Readable plates and future ingestion

The camera plate-reading path did not classify a vehicle as registered merely
because text was readable. It obtains a profile/vehicle relationship through
the existing Registry/matching rules. Activity Logs additionally requires the
linked vehicle's creation time to be on or before the recorded visit before
showing plate-only registration. Those established rules remain unchanged.

New API regression tests send readable and unreadable plates, plus untrusted
`registered`, `vehicle_id` and category hints. Without an authoritative Registry
match they remain Unregistered Visitors. Valid registered plate-only recognition
remains supported and does not toggle vehicle parking state. Registering a
vehicle later can keep the existing explicit history relationship transfer while
earlier activity remains unregistered at event time.

The RFID ingestion service already resolves tag/vehicle verification itself;
it does not trust a client's plate or registration flag. The current buffering,
camera-crossing and offline-fallback behavior was not changed. This phase fixes
computed classification/filtering of affected records, rather than changing
detector decisions or inventing a persisted registration flag.

No specific production camera-only record was supplied or reproduced as becoming
Registered from readability alone. The confirmed RFID fallback/default defects
above are the implemented correction; the camera cases have explicit regression
coverage. Existing matches, registration-time checks and historical transfers
were not disabled to hide an unconfirmed cause.

## Historical impact and separate repair proposal

This is a computed display/filter correction. Existing stored scans, categories,
verification results, plate/profile/vehicle relationships and timestamps remain
intact. Tests compare raw database rows before and after queries. The four known
broken historical references were not altered. No bulk relabel, plate-based
relinking, reset or reseeding was performed.

The confirmed defects do not require a stored-data repair: upgraded reads use
the corrected classification. Bare verified records without positive evidence
will now show Registration unknown instead of making an unsupported claim.

If a particular historical record still has a disputed classification, the
separate proposal is a read-only audit of that record's source, saved verification,
actual foreign keys, profile links, event time and registration time. Conflicting
or missing evidence must be reported for review. Any subsequent repair needs its
own explicit scope and backup; no relationship or label should be guessed from
plate text. That audit/repair was not performed against live records here.

## Verification

- Full Laravel suite: **449 passed, 4,097 assertions**, including **17 new
  classification cases**. Coverage includes unknown/unassigned/legacy unknown
  scans, stale categories/links, database defaults, historical verified receipts,
  Faculty/Registered Visitor/legacy categories, inactive vehicles, inventory-only
  tags, old passes, future readable/unreadable ingestion, legitimate plate-only
  registration, later registration, manual-source distinctions, category/result
  filters, counts, station output, CSV/print and unchanged raw historical rows.
- `node tests/browser/classification-ui.mjs`: **27 assertions passed** in installed
  headless Chrome. Covers mixed classifications, Registered/Unregistered/Alerts
  chips, category filtering, search, counts, details, current-list CSV/print,
  live refresh and the retained color-removal behavior.
- `node tests/browser/auto-filters-ui.mjs`: **69 assertions passed**, retaining
  Phase 3 debounce, IME, focus/caret, combined filters, history, pagination, error
  handling, request-order/polling protection and per-record print behavior.
- `npm run test:js`: **13 passed**. `npm run build`: passed, **61 modules**.
- Changed PHP and browser-script syntax, focused new-file Pint and diff checks
  passed. No failing automated test remains.

All mutations used isolated test databases/fixtures. Browser workers refuse an
installation database, create private temporary credentials/config/session files
and an ephemeral local server, block external browser requests and clean up on
exit. No deployment, push or external-service modification was performed.
Physical hardware, the Windows runtime, actual printer output and production
record-specific evidence were not verified.

## Existing Windows installation

No Phase 4 migration, dependency installation or data reset is required. During
the usual backed-up update, include the new `RfidLogClassification` support class
and the changed RFID model/service and log controllers. Clear/rebuild application
caches with the existing installation update procedure and restart the local PHP
process as appropriate. Keep the database, `.env`, uploads and device settings.
No detector-script update is required by this phase. Earlier pending gate/security
migrations retain their own upgrade instructions.

The existing `log_type` output may now contain `rfid_scan` for unclassified/unknown
RFID-only rows; consumers must not assume every non-pass row is registered. The
existing RFID movement and Alerts views remain available for filtering them.

## Files changed in Phase 4

```text
README.md
PHASE4-CLASSIFICATION.md (new)
app/Models/RfidScanLog.php
app/Services/RfidService.php
app/Support/RfidLogClassification.php (new)
app/Http/Controllers/VehicleEventController.php
app/Http/Controllers/Api/RealtimeLogController.php
tests/Feature/ActivityLogClassificationTest.php (new)
tests/browser/classification-ui.mjs (new)
tests/fixtures/classification-ui.php (new)
```

## HANDOFF FOR NEXT PROMPT

All four authorized implementation phases are complete. Preserve their validation,
legacy-name compatibility, ignored-color behavior, automatic-list request guards,
mounted edit controls, authoritative registration evidence and event-time history
rules. Keep earlier account/gate/runtime work and stable IDs intact. Do not infer
registration from readable text, restore a Registered default for unlinked scans,
or repair the four historical references by guessing. Any production audit,
historical-data repair, hardware check or deployment is a separately scoped task.
