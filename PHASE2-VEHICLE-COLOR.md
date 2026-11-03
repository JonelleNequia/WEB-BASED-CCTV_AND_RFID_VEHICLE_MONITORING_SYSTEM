# Phase 2 — retire Vehicle Color

Implemented only after authorization of Phase 2. Phase 1 validation and the
earlier security, gate-management, UI and runtime changes remain in place.

## Implemented behavior

- Manual event entry, pending-details completion and manual visitor entry no
  longer offer or require Vehicle Color. Guest update/verification and plate
  ingestion ignore submitted color. Services no longer persist those values.
- Vehicle details, matched-entry details, visitor profiles/rankings/review,
  Activity Logs rows/details, print reports, CSV exports and live-log responses
  omit the color field. CSV headers and rows lose the same column. The public
  Activity Logs script URL is versioned so an update refreshes cached code.
- Deprecated `vehicle_color` and `detected_vehicle_color` inputs are accepted
  and discarded, including malformed or oversized values, without weakening
  validation of other fields or authorization. The input middleware handles
  JSON, multipart/form and mutation-query input before receive-log storage.
- Older detector metadata is sanitized recursively, including valid JSON-string
  metadata: retired vehicle-color keys, `color_analysis` and `color_error` are
  removed from new input. Plate evidence, direction, timestamps, confidence,
  track identity and generic overlay `color` values remain. Invalid metadata
  still receives the Phase 1 validation errors. No historical audit log is
  cleaned or rewritten by this change.
- The Python detector no longer runs or submits the vehicle-color analysis or
  intermediate color-only update. Snapshot capture, OCR/voting, plate crops,
  final OCR updates, RFID timing, movement decisions and status-overlay colors
  remain. Both the plate-detector and fallback OCR paths are covered. Unused
  color-classification utilities in `anpr.py` remain outside the active pipeline.

## Historical and integration compatibility

No columns, relationships or model compatibility attributes were removed. No
migration, backfill, reset, reseeding or installation-data rewrite was performed.
Existing color columns remain readable for historical queries and recoverable
from backups. Editing, verifying, completing or merging a late detector update
does not clear or replace existing event/observation/visitor color attributes.
Historical conversion/profile aggregation continues to retain legacy evidence.

The legacy internal matching rules and thresholds were not retuned. Stored
legacy evidence can still support those rules. New events have no color signal:
an easy plate confusion with matching type and plausible time can remain MEDIUM;
weaker evidence stays LOW for review. We do not invent replacement evidence or
promote matches to compensate. Newly generated match explanations omit color;
existing free-text historical explanations are not bulk-rewritten. RFID and
exact-plate rules and registration classification were not changed.

Older detector versions can continue sending their payloads; color is ignored
while valid plate/capture updates are accepted. New live/report payloads and CSV
headers intentionally omit the color field, so external consumers of those
outputs must use header names rather than the old column position. Database
backup/restore retains the existing schema and color data.

## Verification

Executed locally on macOS with isolated data:

- Full Laravel suite: **420 passed, 3,839 assertions**, including 13 focused
  color-retirement cases and an additional backup/restore preservation case.
  Existing ingestion/review/report assertions now expect ignored color; legacy
  matching tests explicitly seed historical evidence instead of new color input.
- Detector suite: **163 passed**, including three new regressions for snapshot/
  plate updates and both OCR paths. Some pre-existing fake socket/device tests
  emit unclosed-socket `ResourceWarning` messages; the suite exits successfully.
- `node tests/browser/vehicle-color-ui.mjs`: **20 assertions passed** in installed
  headless Chrome. Covers manual save and pending completion without color,
  historical details, real live-log refresh, details modal, print/CSV, visitor
  input and narrow-screen layout. It uses its own private temporary SQLite
  database, credentials, session/config files and ephemeral PHP server; external
  browser requests are blocked and temporary files/server are removed on exit.
- `node tests/browser/validation-ui.mjs`: **20 assertions passed** again, covering
  the Phase 1 rejection/correction and input-preservation behavior.
- `npm run test:js`: **13 passed**. `npm run build`: passed, 61 modules.
- PHP syntax: 40 changed/new non-Blade files passed across the working batches.
  Three changed Python files parsed without bytecode writes. New PHP-file Pint
  checks, JavaScript syntax and `git diff --check` passed.

No live records were used for mutation tests. No deployment, publishing, push or
external-service change was performed. Physical camera/RFID behavior and the
actual Windows runtime were not tested. The four known broken historical
references remain untouched. No failing automated test remains.

## Existing Windows installation

This phase requires no database migration or new dependency. During the usual
backed-up application update, copy the changed PHP/Blade/public JavaScript files
and include the new middleware/support class. Clear/rebuild application caches
with the installation's existing update procedure. Restart the local PHP and
detector processes as appropriate after copying the updated detector script.
Keep the database, `.env`, uploads, device configuration and model files intact.
An older detector can remain temporarily because deprecated color is ignored.
Earlier pending gate/security migrations follow their separate upgrade steps.

## Files changed in Phase 2

Some files also contain preserved edits from earlier batches. No migration or
model file was changed by this phase.

```text
README.md
PHASE2-VEHICLE-COLOR.md (new)
app/Http/Controllers/Api/FutureIntegrationController.php
app/Http/Controllers/Api/RealtimeLogController.php
app/Http/Controllers/Api/VisitorIntegrationController.php
app/Http/Controllers/GuestObservationController.php
app/Http/Controllers/VehicleEventController.php
app/Http/Controllers/VisitorController.php
app/Http/Middleware/DiscardVehicleColor.php (new)
app/Http/Requests/CompleteVehicleEventRequest.php
app/Http/Requests/StoreGuestObservationRequest.php
app/Http/Requests/StoreVehicleEventRequest.php
app/Http/Requests/UpdateGuestObservationRequest.php
app/Services/EventService.php
app/Services/GuestObservationService.php
app/Services/PlateMatchService.php
app/Services/VehicleRegistryService.php
app/Services/VisitorRecordService.php
app/Support/DeprecatedVehicleColor.php (new)
bootstrap/app.php
public/js/activity-logs.js
resources/views/logs/partials/event-row.blade.php
resources/views/logs/tabs/events.blade.php
resources/views/vehicle-events/create.blade.php
resources/views/vehicle-events/show.blade.php
resources/views/visitors/index.blade.php
resources/views/visitors/partials/plates.blade.php
resources/views/visitors/partials/review.blade.php
resources/views/visitors/profile.blade.php
school-vehicle-monitoring-detector/detector_service.py
school-vehicle-monitoring-detector/video_test.py
school-vehicle-monitoring-detector/tests/test_vehicle_pass.py
tests/Feature/BackupRestoreTest.php
tests/Feature/DetectedEventIngestionTest.php
tests/Feature/EventLogReportIntegrationTest.php
tests/Feature/GuestObservationReviewTest.php
tests/Feature/PlateMatchingTest.php
tests/Feature/VehicleColorRemovalTest.php (new)
tests/browser/vehicle-color-ui.mjs (new)
tests/fixtures/vehicle-color-ui.php (new)
```

## HANDOFF FOR NEXT PROMPT

Phase 2 is complete. Stop and wait for **“Go Phase 3”** before automatic search/
filter updates. Preserve existing filters, query links, exports, permissions,
polling and Phase 1 input validation while implementing debounce/focus/IME/
request-order handling. Do not auto-submit edit or destructive forms. Keep
deprecated color ignored and historical columns/data intact. Activity Logs
registration classification remains Phase 4; do not relabel history or guess
relationships. Preserve the four broken historical references and all earlier
gate/security/runtime work.
