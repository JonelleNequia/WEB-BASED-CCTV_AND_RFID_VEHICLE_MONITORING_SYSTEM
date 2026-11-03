# Registration Phase 4: physical vehicle types and their evidence

Implemented on 2026-11-03 (Asia/Manila). No applicable AGENTS.md was present
in the repository or its ancestors. This is Registration Phase 4, separate
from older UI/classification phases numbered 4. Phase 5 is not implemented.

No live migration, data repair/backfill, reset/reseed, dependency installation,
weight download/training, deployment, publication or push was performed in
this phase. Earlier staged/unstaged work remains in place; the index was not
changed. The preceding approved database repair was a separate task.

## 1. Verified causes and implementation references

- `app/Support/VehicleType.php` previously reduced types to Car, Motorcycle
  and Truck/Bus. E-bike and Tricycle became Motorcycle; Truck and Bus lost
  their distinction. Registry choices were a different four-item list in
  `VehicleRegistryService::vehicleTypes()`, while manual entry accepted free
  text and Correct Type allowed only the three broad labels.
- `VehicleRegistryService::normalizeVehicleType()` silently returned Car
  for blank input. EventService's separate normalization changed hyphens to
  spaces, losing electric labels. The new shared contract validates supplied
  values, preserves equivalent aliases and rejects unsupported new values.
  The Add Vehicle form now starts with an explicit required choice, rather
  than silently selecting Car. Manual visitor type remains optional.
- `school-vehicle-monitoring-detector/vehicle_type.py` collapsed named model
  classes and used box size/aspect ratio to turn Truck/Bus votes into Car.
  Model-label voting/second-pass evidence remains; geometric relabelling is
  removed. Equally weighted conflicting votes stay Unknown instead of using
  the previous Car tie preference.
- `VehicleEvent::display_vehicle_type` previously preferred detector text
  over the stored effective type. `VisitorRecordService::applyPlateReading`
  overwrote vehicle_type, while correctType updated only the visitor row.
  `VehicleTypeEvidenceService::resolve()` and `syncEvent()` now protect
  occurrence snapshots and synchronize the existing canonical event.
- RFID scan presentation read the current registry type through its vehicle
  relation, so registry editing could relabel historical scan displays.
  New scans capture the registry type in their existing payload JSON; old
  scans without that receipt say Unknown instead of inventing a past type.
- `plate_voting.py::vote_plate()` rejected all two-letter layouts on Car
  and on unfamiliar type names. That incorrectly rejected supported plates
  on new manual types. All types now use the same existing literal layouts
  and voting/confidence rules. No type mismatch changes plate identity.

VehicleType refers to physical vehicle type. VehicleCategory remains the
separate Faculty & Staff / registered visitor / registration-classification
contract. The old VehicleType::category method is only a compatibility name
for physical-type normalization. Coarse diagnostic comparison uses a separate
comparisonGroup method and is explicitly labelled as family agreement, not
electric/subtype recognition; it is never used for identity or display.

## 2. Canonical choices and legacy aliases

The shared PHP contract, labels and rule are VehicleType::TYPES/HINTS and
ValidVehicleType. Registration, both edit forms, manual entry, pending event
completion and existing Correct Type actions share them.

Requested choices: **E-bike, E-trike, E-car, Car, Truck, Tricycle, Motorcycle**.
Retained legitimate labels: **Bus, Van, SUV, AUV, MPV, Pickup, Jeepney, Sedan,
Hatchback, Scooter, Electric scooter, Truck/Bus**. The last is a truthful
broad/legacy label, not a merger of newly detected Bus and Truck classes.

| Equivalent submitted names | Canonical value |
| --- | --- |
| E-bike, e bike, e_bike, ebike, electric bike/bicycle | E-bike |
| E-trike, e trike, e_trike, etrike, electric trike/tricycle | E-trike |
| E-car, e car, e_car, ecar, electric car | E-car |
| motorbike, motor cycle, motor | Motorcycle |
| auto rickshaw | Tricycle |
| pickup truck | Pickup |
| jeep | Jeepney (existing PH meaning) |
| car van | Car (existing broad alias) |
| truck / bus, truck_bus | Truck/Bus |
| Canonical labels with different case/ordinary spacing | Their canonical spelling |

Electric scooter remains distinct from E-bike. Distinct types are not
collapsed by spelling normalization. Unsupported new values are rejected on
the server. Existing noncanonical values can be retained unchanged during
unrelated edits, including through the legacy Others field; they are not
silently title-cased or discarded. Others is a compatibility input, not an
unrestricted new category. Required/optional field behavior remains intact.
Existing automatic filters, selected criteria, form errors and menus remain;
there was no dedicated vehicle-type filter to replace or expand.

## 3. Installed model metadata and actual application mappings

Read directly from the locally installed files with Ultralytics, without
downloading or replacing weights. Both vehicle models expose 80 COCO names:

| Model-advertised class ID | Actual name | Operational application label |
| --- | --- | --- |
| 2 | car | Car |
| 3 | motorcycle | Motorcycle |
| 5 | bus | Bus |
| 7 | truck | Truck |

Class 1, bicycle, exists in the models but remains outside the existing
operational counting allowlist. Other non-vehicle names remain excluded.
Neither vehicle model advertises E-bike, E-trike, E-car or Tricycle.
The separately installed plate-detector model advertises only
`0: license_plate`; its ID is not a vehicle-type ID.

Verified vehicle model files and SHA-256:

```text
school-vehicle-monitoring-detector/models/yolov8n.pt
f59b3d833e2ff32e194b5bb8e08d211dc7c5bdf144b90d2c8412c47ccfc83b36
school-vehicle-monitoring-detector/models/yolov8s.pt
1f47a78bf100391c2a140b7ac73a1caae18c32779be7d310658112f7ac9aa78a
```

config.py selects yolov8n.pt for tracking; the configured second type check
uses yolov8s.pt with the existing local-file fallback. Runtime mapping still
uses the loaded model's advertised names, not assumed numeric IDs. A test
uses unrelated IDs to verify this. The table records observed installed IDs;
it is not a hardcoded assumption about future weights.

Actual class names, frame/second-check scores, confidence, class IDs and
existing detection metadata remain available. The legacy geometric setting
keys are retained for configuration compatibility, but no longer decide a
vehicle's type. Tracking, direction voting, capture timing and snapshots are
unchanged. Current PHP automatic ingestion accepts the installed broad classes
and the legacy Truck/Bus label; unsupported raw strings remain diagnostic
evidence, not proof of an electric subtype.

## 4. Detector, registry and staff decision table

| Evidence/context | Effective occurrence type | Provenance / behavior |
| --- | --- | --- |
| Explicit staff correction | Staff's canonical choice | Staff confirmed; same occurrence, before/after audit |
| Explicit manual entry type | Submitted canonical choice | Manual entry; no fabricated detector result |
| Unique eligible registered identity, recorded type available | Captured registry type | Registry record; independent of broad visual class |
| Registered type absent, supported model output available | Actual supported broad class | Detector; no default Car |
| Unregistered occurrence, supported model output | Actual supported class | Detector; registration remains independent |
| Missing/invalid/unsupported automatic type or tied votes | Unknown | Raw available output retained separately |
| Late generic OCR/crossing/RFID evidence | Existing staff/registry snapshot retained | Raw evidence may update; identity/count/state not replayed |
| Registry type edited later | Future occurrences use the new value | Existing occurrence and RFID receipt snapshots stay unchanged |
| Unmarked historical values | Existing stored evidence retained | Historical record; no bulk relabelling |

Current registry values remain separately visible on linked vehicle details.
They are not substituted for a captured historical occurrence type. Type
changes do not alter gate, direction, time, images, plate crops, classification,
tag relationships, occupancy or movement count. Type mismatches do not change
plate-match eligibility, inactive restrictions or lost-UID enforcement.

Correct Type retains VehicleTypeCorrection rows (detector answer, corrected
answer, actor, crossing) and also records the effective before/after values in
ReviewAction. Repeating the same correction is idempotent. Canonical event
details reuse that same correction route; table menus were not changed. The
existing legacy observation edit/verify path retains its before/after/actor/time
receipt in its existing metadata, and late metadata cannot claim a staff source.

Minimal compatible storage uses the protected `_application_vehicle_type`
namespace in existing JSON fields: visitor OCR details, event/legacy observation
detection metadata and RFID payload receipts. It stores source, raw detector
text and the captured registry value where applicable. Incoming integration
metadata cannot overwrite the application's staff/registry marker. Original OCR
details remain separate; an application-only type marker does not prevent the
first late OCR evidence from being saved after a manual plate correction.

No migration is needed. Only explicitly processed/new occurrences receive these
receipts. Existing history is not scanned or rewritten. Logs, live payloads,
details and printed reports show the effective type and its source. CSV appends
Vehicle Type Source while preserving the existing preceding columns.

## 5. Automatic-recognition limits

The installed models cannot reliably output electric propulsion or Tricycle
as named classes. E-bike/E-trike/E-car/Tricycle remain selectable and can come
from registry identity or staff input. A vehicle classified as bicycle may
remain outside automatic counting under the existing allowlist; manual entry
and existing RFID workflows remain available. No claim is made that every
requested manual type will automatically generate a crossing.

Future fine-grained recognition requires an appropriately labelled model,
representative local training/validation data and independent evaluation under
the installation's viewpoints, lighting and traffic. Propulsion may not be
visually distinguishable. Model selection, matching application mappings and
performance/accuracy validation would be separate authorized work. No plate-text,
RFID-presence or arbitrary box-dimension heuristic replaces that evidence.

## 6. Changed files and migration

New: ValidVehicleType, VehicleTypeEvidenceService, RegistrationVehicleTypesTest,
the Python registration-type tests, vehicle-types-ui.mjs and this report.
Full phase-specific manifest follows; earlier changes in these files are kept.

```text
README.md
REGISTRATION-PHASE4.md
app/Console/Commands/DetectionAccuracy.php
app/Http/Controllers/Api/CrossingIntegrationController.php
app/Http/Controllers/Api/FutureIntegrationController.php
app/Http/Controllers/Api/RealtimeLogController.php
app/Http/Controllers/Api/VisitorIntegrationController.php
app/Http/Controllers/GuestObservationController.php
app/Http/Controllers/VehicleEventController.php
app/Http/Controllers/VisitorController.php
app/Http/Requests/CompleteVehicleEventRequest.php
app/Http/Requests/StoreGuestObservationRequest.php
app/Http/Requests/StoreVehicleEventRequest.php
app/Http/Requests/StoreVehicleRegistrationRequest.php
app/Http/Requests/UpdateGuestObservationRequest.php
app/Models/GuestVehicleObservation.php
app/Models/RfidScanLog.php
app/Models/VehicleEvent.php
app/Models/VisitorRecord.php
app/Rules/ValidVehicleType.php
app/Services/DetectionAccuracyService.php
app/Services/EventService.php
app/Services/GuestObservationService.php
app/Services/MovementReconciliationService.php
app/Services/RfidCameraFusionService.php
app/Services/RfidIngestService.php
app/Services/VehicleRegistryService.php
app/Services/VehicleTypeEvidenceService.php
app/Services/VisitorRecordService.php
app/Support/VehicleType.php
app/Support/VisitorRecordLog.php
public/js/activity-logs.js
resources/views/logs/partials/event-row.blade.php
resources/views/logs/print.blade.php
resources/views/registry/partials/vehicle-fields.blade.php
resources/views/registry/tabs/vehicles.blade.php
resources/views/vehicle-events/show.blade.php
resources/views/visitors/index.blade.php
resources/views/visitors/partials/records.blade.php
resources/views/visitors/partials/review.blade.php
school-vehicle-monitoring-detector/config.py
school-vehicle-monitoring-detector/detector_service.py
school-vehicle-monitoring-detector/plate_voting.py
school-vehicle-monitoring-detector/vehicle_type.py
school-vehicle-monitoring-detector/tests/test_plate_voting.py
school-vehicle-monitoring-detector/tests/test_registration_vehicle_types.py
school-vehicle-monitoring-detector/tests/test_vehicle_type.py
tests/Feature/RegistrationPlatePipelineTest.php
tests/Feature/RegistrationVehicleTypesTest.php
tests/Feature/VehicleColorRemovalTest.php
tests/Feature/VehicleTypeAccuracyTest.php
tests/browser/optional-rfid-ui.mjs
tests/browser/registration-staff-ui.mjs
tests/browser/validation-ui.mjs
tests/browser/vehicle-types-ui.mjs
```

There is **no Phase 4 migration**, new table, dependency or model weight.

## 7. Verification and limitations

- Full Laravel suite: **551 tests, 5,169 assertions passed** using isolated
  SQLite, including the existing separate-process assignment/replacement and
  evidence-reconciliation concurrency tests. One overloaded parallel run hit
  the existing 15-second process-fixture timeout; the dedicated concurrency
  rerun and subsequent complete run passed without changing that timeout.
- Thirteen focused PHP cases cover every choice, aliases/rejection, unchanged
  legacy edits through both input shapes, registry snapshots, manual/raw/source
  separation, Unknown types, staff correction/audit/idempotence, late crossing/
  OCR/RFID, no duplicate movement or occupancy change, historical RFID receipts,
  source labels, details/print/CSV and all three legacy capture routes.
- Detector Python suite: **168 tests passed**. Includes direct metadata checks
  of the installed n/s models, a packaged bus-image second-pass inference,
  arbitrary-ID mapping tests, no geometric subtype assertions, Unknown/ties,
  and supported plate layouts across all manual types. Existing fake-socket
  ResourceWarnings remain; no Python test failed in the final run.
- Headless installed Chrome: **421 assertions across nine suites passed**:
  vehicle types 59, optional RFID 35, plate pipeline 26, registration staff 37,
  classification 27, automatic filters 69, validation 20, Vehicle Color 20,
  Settings 128. Type tests cover actual form submissions, event-only correction,
  late generic evidence, registry editing without historical relabelling, CSV,
  source labels, keyboard radio selection and a 390px viewport. Browser runners
  use private databases, test-only random credentials and isolated device files;
  external requests are blocked and their servers/data are removed on exit.
- JavaScript unit tests: **13 passed**. Production Vite build passed (61 modules).
  Changed PHP/JavaScript syntax, Python AST, focused Pint and git diff checks
  passed. Generated tracked bytecode was restored to its starting contents,
  preserving earlier staged work.

The tests do not establish real-world type accuracy. No Windows execution,
physical RFID/camera traffic, camera placement/lighting validation or real
labelled local accuracy benchmark was performed. The packaged sample inference
and model metadata are the actual model checks, distinct from simulated payloads.
Accounts/security, gates/filter behavior, menus and capture policy remain outside
this phase. The four known broken historical references remain untouched.

## 8. Pending Windows update steps — not performed

1. Follow the existing backup/maintenance procedure. Preserve database,
   .env/APP_KEY, uploads, device/calibration configuration and all model weights.
2. Install the reviewed PHP/Blade/public assets and changed Python files with
   the normal production build. No new package, training or model download is
   required. Include the new rule and evidence service.
3. There is no Phase 4 migration. If earlier Registration Phase 1–3 migrations
   are missing on that PC, apply their previously documented update procedure
   before using those features; do not reset/reseed or repair history.
4. Clear/rebuild normal application/view caches, restart local PHP/detector
   workers to load the updated mappings, and hard-refresh browsers.
5. Check approved real samples for every needed local type: correct displayed
   provenance, retained raw broad class, staff correction surviving late evidence,
   unchanged occupancy/counts/history, and readable supported plate layouts.
   Evaluate automatic accuracy independently before claiming deployment accuracy.

## 9. HANDOFF FOR PHASE 5

Registration Phase 4 is complete locally. Preserve the shared physical-type
contract and distinct electric types; model-name-derived IDs; Unknown fallback;
registry/manual/staff occurrence snapshots and protected JSON namespace; genuine
raw detector evidence; existing correction audit and canonical event identity.
Keep type updates separate from occupancy projection and history transfer.
Preserve registered_by/default transactions, exact plate eligibility, optional
RFID, chronological occupancy, lost-UID permanence, security, gates, filters,
capture policy and the four known broken references. No schema update or model
replacement is part of this phase. Windows/hardware validation remains pending.
Stop here and wait for **Go Phase 5**.
