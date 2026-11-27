# Targeted regression — literal plates and canonical event reconciliation

Registration Phase 6 remains paused. This fix supersedes the older rule that
transposed number-first plate layouts. No live migration, reset, seeding, data
repair, device/configuration change, deployment, publication or push was run.
All mutation tests use isolated databases and fixture files.

## 1. Verified defect causes

- `app/Support/PlateNumber.php:10` formerly invoked a number-first-to-letter-first
  conversion; its display/key/search paths also treated the reversed layout as
  equivalent. `public/js/plate-input.js:8` performed the same conversion on
  blur/change/submit. Python `anpr.normalize_plate_text` likewise transposed OCR.
  Thus **395ISX became ISX-395**, rather than retaining the entered identity.
- `app/Services/MovementReconciliationService.php:30` formerly searched only
  `vehicle_events.visitor_record_id`. A legacy event without that link was
  missed, so the eligible-plate branch constructed a new event with an already
  occupied external key. The unique constraint correctly rejected the insert.
- `VisitorController::review` did not handle an unexpected service failure;
  Laravel's debug response contained the SQL exception, which
  `public/js/visitor-review.js:54` displayed directly from `result.message`.
- Learned corrections could replace an OCR read with another plate, and the
  Review form prefilled a suggestion instead of the actual read. These were
  additional identity-conversion risks; the incident's registry selection did
  not require either mechanism.

The defects were reproduced **before the runtime fix**: two focused PHP cases
failed, and the isolated browser flow entered `395ISX`, observed `ISX-395`, then
received/displayed the duplicate-key exception. Before-run results are ignored
local artifacts under `storage/framework/testing/plate-*-before.*`.

## 2. Why this existing event was missed

Read-only inspection of the incident installation found:

- Guest record **8**, crossing **9** and event **11** share the external key.
- Event 11 is a `guest_cctv` ENTRY at the same camera, time and IN direction.
- Its `visitor_record_id` is null. Neither it nor the crossing has an RFID link.

The previous visitor-ID-only lookup could not see event 11. A later insert
attempted to reuse its key and failed. The failed correction transaction did
not persist the attempted relationship. The incident rows were read again after
verification and remain uncorrected; they were never used as mutation fixtures.

The new `app/Services/CanonicalMovementEventService.php:15` resolves the canonical
record link, external key, and authoritative crossing/RFID event links under the
existing `PlateProcessingLock`. An unlinked camera event needs matching camera,
gate, occurrence time, direction and compatible origin. Genuine RFID candidates
need an authoritative scan/crossing relationship. An already managed plate event
can have its missing record link restored. An unmanaged registered key-only event
is not automatically reprojected or counted again.

Different owners, incompatible evidence or multiple competing events produce a
controlled **409 conflict**, preserving all existing evidence. No unique
constraint was removed, event deleted, alternate key generated or incompatible
row blindly overwritten.

## 3. Why the attempted insert selected a registered vehicle

The installation contains active registry vehicle **8**, plate **ISX-395**, with
Faculty & Staff classification and type Car. There is no literal `395ISX`
registration in the inspected registry candidates. The crossing and event have
no RFID identity to supply that relationship.

The former normalizer converted the entered `395ISX` into the key `ISX395`.
`VehicleRegistryService::plateMatches` then found that eligible registry entry.
That explains both the attempted vehicle relationship and registry type Car;
it was a false match **relative to the literal staff-entered plate**. This does
not establish that the existing registry row itself is wrong: its original
registration evidence was not reconstructed or altered.

With this fix, `395ISX` and `ISX395` are distinct. A unique eligible literal match
is Registered; no eligible literal match is Guest; ambiguous, inactive or
conflicting evidence stays Review under the existing restrictions. The observed
vehicle description alone never determines the registration decision.

## 4. Implemented changes and file manifest

- PHP normalization, display, keys and SQL search preserve alphanumeric order.
  Uppercasing, ordinary space normalization and safe separator comparison remain.
  Supported number-first layouts are recognized without transposition. No O/0,
  I/1 substitution, substring extraction or reverse-layout search is used.
- Browser inputs mirror that literal contract, preserving IME/caret behavior.
  Python ANPR keeps number-first text literal, and voting groups it separately
  from letter-first identities. Existing OCR confidence/voting thresholds remain.
- Learned corrections are suggestions requiring Review. Staff input is not
  prefilled from a fuzzy suggestion; explicit Pick remains an intentional action.
- `VisitorRecordService::classifyPlate`, `MovementReconciliationService::syncPlate`
  and `RfidCameraFusionService::applyMovement` use the shared occurrence resolver.
  Correction, classification, profile/learning changes, audit, linking and
  chronological occupancy projection stay in the shared transaction. An adopted
  guest event is not mistaken for an already-applied registered movement, so
  genuine RFID/state contradictions retain their anomaly flag. Retry and
  late-evidence paths retain one event and do not create synthetic RFID scans.
- Existing snapshots remain referenced when an event is adopted. Late legacy
  camera messages are serialized and cannot reset a confirmed canonical event
  or erase its RFID relationship, plate, time or snapshots. Existing legacy
  event updates also check ownership/context before writing by key.
- `VisitorController::attemptCorrection` logs unexpected exceptions through
  Laravel and returns a concise safe error, with a specific safe conflict
  message. Plain HTML redirects retain the entered plate and correct card.
  The browser ignores raw failed-response messages and requires `ok: true`
  before treating a response as success. Validation errors retain the input.
  A new attempt clears stale success feedback; errors appear near the top of
  the current card as an accessible alert.
- The normal JavaScript test script includes both offline unit suites using
  explicit file arguments suitable for the Windows workflow.

The complete regression-only manifest appears below. It is measured against the
working tree at the start of this request, preserving the earlier Phase 5 work.
The ignored patch `storage/app/plate-literal-regression-review.patch` includes
only this regression, including new files. **No migration or dependency added.**

30 regression files:

```text
README.md
REGRESSION-LITERAL-PLATE-RECONCILIATION.md
app/Exceptions/OccurrenceConflict.php
app/Http/Controllers/GuestObservationController.php
app/Http/Controllers/VisitorController.php
app/Services/CanonicalMovementEventService.php
app/Services/MovementReconciliationService.php
app/Services/RfidCameraFusionService.php
app/Services/VisitorRecordService.php
app/Support/PlateNumber.php
package.json
public/js/plate-input.js
public/js/visitor-review.js
resources/views/visitors/partials/review.blade.php
school-vehicle-monitoring-detector/anpr.py
school-vehicle-monitoring-detector/plate_voting.py
school-vehicle-monitoring-detector/tests/test_literal_plate_order.py
school-vehicle-monitoring-detector/tests/test_plate_reader.py
school-vehicle-monitoring-detector/tests/test_plate_voting.py
tests/Feature/DetectedEventIngestionTest.php
tests/Feature/Phase8CleanupTest.php
tests/Feature/PlateLiteralConcurrencyTest.php
tests/Feature/PlateLiteralReconciliationTest.php
tests/Feature/PlateMatchingTest.php
tests/Feature/RegistrationPlatePipelineTest.php
tests/browser/plate-pipeline-ui.mjs
tests/browser/plate-regression-ui.mjs
tests/fixtures/plate-literal-worker.php
tests/fixtures/plate-regression-ui.php
tests/js/plate-input.test.mjs
```

## 5. Regression results and limitations

- Full Laravel suite: **571 tests, 5,431 assertions passed**, with no skipped
  tests. Thirteen new focused cases contribute **157 assertions**. They cover
  literal keys/search/registration/profile lookup, Guest versus Registered,
  compatible key-only legacy and canonical events, genuine RFID identity,
  conflicting key owners/camera/time/direction/origin, rollback, old input,
  idempotent audit/profile/counts, original OCR, snapshots and late legacy data. An additional case verifies that adopting a guest event
  does not suppress an actual RFID/state contradiction.
- The new separate-process SQLite test deliberately holds the shared mutex while
  **two manual corrections, OCR and RFID** reach the barrier on independent
  connections. After release, all commit into the original event ID, with one
  RFID scan, one correction audit, at most one profile visit and one movement.
  The reversed registration remains outside with zero entries. Existing
  correction/RFID and gate-removal contention tests also pass. MySQL and Windows
  concurrency were not executed.
- Headless installed Chrome on macOS: **572 assertions across 11 suites passed**:
  new regression 19; existing Phase 5 132, plate pipeline 26, vehicle types 59,
  optional RFID 35, registration staff 37, classification 27, validation 20,
  Vehicle Color 20, Settings 128 and automatic filters 69. The regression
  reproduces Guests → Review → Save plate using representative private data,
  tests real SQLite failure with `APP_DEBUG=true`, a controlled conflict,
  validation input retention, raw failed-response rejection, false-success
  rejection and non-JavaScript input/error handling. Other suites retain the
  earlier screen-size, zoom-equivalent, row-menu and gate-filter checks.
- Python detector suite: **171 tests passed**, including three new literal-order
  normalization/voting cases. Existing crop tests now expect each crop's literal
  read rather than transposing it. No model weights, training or capture policy
  changed. Existing fake-socket ResourceWarnings remain; no test failed.
- `npm run test:js`: **15 passed**. Production Vite build passed, **61 modules**.
  Changed PHP/JS syntax, new PHP fixture/test/service Pint checks, Python AST and
  `git diff --check` passed. Generated bytecode was restored to the request's
  starting contents. The existing Git index was not staged or changed.

An ignored fixture screenshot is available at
`storage/app/ui-review/plate-regression/after-safe-error.png`. It shows the safe
inline error with the literal plate retained, and contains no installation data.
No physical RFID/camera traffic, Windows installation execution, real browser
zoom control or production accuracy test is claimed. No check was disabled to
make the suites pass.

## 6. Existing-data compatibility and separately authorized concerns

Stored plates are **not rewritten**. Previously saved `ISX395` values remain that
identity; the software cannot infer whether somebody originally typed `395ISX`.
Legacy reversed values no longer prove a match to a new literal plate. Existing
learned mappings remain stored, but cannot silently replace the literal read.
Explicit historical profile merges and registration evidence may need a separate
review if their identities were affected by older behavior; no automatic
historical identity repair was performed.

The incident's existing event can be linked only when a newly submitted
correction verifies its same-occurrence evidence and commits. Incompatible or
multiple-event cases require an authorized evidence review, not automated
merging/deletion. Existing known broken historical references were left untouched.
No repair command or migration was added.

For a later approved Windows update, preserve the usual backup, database,
`.env`/APP_KEY, uploads, calibration/device configuration and model weights.
Install the reviewed PHP/Blade/public JS and Python ANPR/voting files; refresh
normal caches, restart the appropriate application/detector processes and hard
refresh browsers. **No regression migration is needed.** Those update actions
were not performed here.

## 7. Phase 6 working-state status — paused

No Registration Phase 6 implementation had started in this working tree at the
start of this request, and none was introduced here. The existing Phase 5
changes remain separate in the working tree. This fix retains existing snapshot
references during reconciliation and uses already-existing capture endpoints in
isolated tests; it does not change snapshot triggers, frequency, capture policy,
retention or storage provisioning. Account security, Guest labels, archived-gate
filters, row menus, permanent lost UIDs, staff attribution, type provenance and
chronological occupancy behavior remain intact.

Stop after this regression fix. Do not begin snapshots for all camera-confirmed
registered and guest events until Phase 6 is explicitly authorized again.
