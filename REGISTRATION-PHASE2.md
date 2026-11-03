# Registration Phase 2: plate normalization and classification

Registration Phase 2 is implemented. No later phase, deployment, live migration,
production data repair, database reset or production reseeding was performed.
No applicable AGENTS.md was present in the project or its ancestor directories.
Existing unrelated staged/unstaged work was preserved; nothing was staged,
committed or pushed by this phase.

## Pipeline inspected and verified issues

1. Registry Add Vehicle, Register this tag and Register this vehicle use
   StoreVehicleRegistrationRequest → VehicleRegistryService. Standalone/drawer
   edits use the same request and service. RFID inventory/assignment remains
   required for creating a registration. Phase 1 staff attribution/default is
   still recorded within the registration transaction.
2. Python plate_reader and the fallback ANPR reader call
   `anpr.normalize_plate_text`, followed by the existing frame-voting confidence
   rules. The detector reports a crossing and subsequently sends its OCR vote
   to VisitorIntegrationController → VisitorRecordService::applyPlateReading.
   Existing event keys join the crossing/visitor record; RFID-backed crossings
   already have a separate authoritative RFID event.
3. Visitors Review and the existing Correct plate dialog ultimately call
   VisitorRecordService::correctPlate. ReviewAction and PlateCorrection are
   the existing audit/learned-correction stores. No separate correction page,
   crossing replay, alternative ingestion path or replacement audit was added.
4. Activity Logs/CSV/print use unified event payloads. MovementCountService
   counts the existing crossings/records; VehicleOccupancyService reports only
   the existing registered RFID state. Visitor profiles aggregate visits.

Verified inconsistencies before this change:

- PHP normalize/display understood certain number-first layouts, but key() did
  not. Registry storage and exact lookup used another normalization path.
- key() stripped arbitrary characters, and registration accepted identifiers
  that existing manual correction could not accept. Invalid symbols could
  therefore be reduced to someone else's valid registration key.
- Python normalization stripped symbols, substituted positional OCR characters
  such as 8/B or Z/2 and extracted candidate substrings before identity lookup.
- The first normalized registry match could be selected without collision
  detection. Learned/fuzzy profiles and low/missing OCR confidence could supply
  a vehicle relationship even though registration evidence was uncertain.
- Plate corrections reloaded no authoritative row under a SQLite write lock;
  audit writes were outside the plain Correct plate transaction. Repeated
  corrections could repeatedly learn/audit the same result.
- Ordinary vehicle registration/plate edits silently linked earlier visits.
- Activity Logs had stale Unregistered Visitor record/detail labels for valid
  plate-only registered events. Modern visitor records were also missing from
  monitoring/event feeds, while some legacy copies remained eligible there.

## Authoritative normalization and matching

`App\Support\PlateNumber` defines the normalization, display, comparison key
and relevant search contract. Middleware normalizes actual submitted plate
fields before validation. Services use the same contract for direct calls.
The client mirrors it for marked plate fields without changing search controls.

| Input | Normalized storage/input | Comparison key | Supported display |
|---|---|---|---|
| `  abc   0001 ` | `ABC 0001` | `ABC0001` | `ABC 0001` |
| `ab-00001` | `AB-00001` | `AB00001` | `AB 00001` |
| `0012 abc` | `ABC-0012` | `ABC0012` | `ABC 0012` |
| `a1-b02` | `A1-B02` | `A1B02` | `A1-B02` |
| `temp-0007` | `TEMP-0007` | `TEMP0007` | `TEMP-0007` |
| `000123` | `000123` | `000123` | `000123` |
| `ABC/0001`, `ABC🚗0001` | symbols retained as invalid evidence | no key | no registered identity |

Rules and compatibility:

- Uppercase ASCII letters; collapse ordinary Unicode space separators and trim
  surrounding spaces. Controls are not silently normalized into a valid key.
  Letters/digits are meaningful; spaces and ordinary hyphens are the existing
  comparison separators. Leading zeros stay intact. No O/0, I/1 or other
  character substitution establishes registration.
- Preserve the already-supported number-first layouts: 4/3 digits + 3 letters,
  or 5/4 digits + 2 letters, become letter-first. No other ordering is invented.
- Retain the existing display grouping of 2–3 letters followed by 3–5 digits;
  other valid identifiers retain their normalized spelling. Registry storage
  does not force a new universal car-plate layout.
- Registry identifiers keep their existing 50-character limit. Visitor/manual
  profile identifiers keep their 20-character key / 30-character display limits.
  An overlong but transport-sized OCR result remains evidence in Review with
  no overflowing profile key. Malformed transport/types or payloads exceeding
  the API's 30-character raw field limit are still rejected with validation.
- New/changed identifiers use the existing manual alphabet: ASCII letters,
  digits, spaces and hyphens, with at least one letter or digit. Unsupported
  legacy registry values may remain unchanged during unrelated edits; they do
  not become registered identity evidence through character removal.
- Exact normalized keys identify registrations. Existing collisions return
  Review; no first-match selection, merge, renumber or guessed repair occurs.
  New colliding registrations/plate edits are rejected inside the write lock.
- Searches use the same separators/case and supported visual-order variants,
  including old stored spacing. Invalid plate-only searches cannot become
  an empty-key wildcard. General owner/tag search remains available.
- While typing, case/spacing update without moving the insertion caret or
  removing a pending trailing space. Blur/submission completes normalization.
  IME composition is left alone until committed; Review Enter shortcuts ignore
  composition keystrokes. Existing forms, menus and submission behavior remain.

Python now uses literal supported-layout candidates, never positional swaps or
substring extraction in its active normalization path. Existing voting thresholds,
frame-majority checks and vehicle-specific layout checks remain intact. Unsupported
OCR layouts can remain unreadable for manual confirmation; valid nonstandard
identifiers are still accepted in registration/manual review. Existing unused
legacy candidate helpers remain outside the active identity path.

## OCR/manual classification decision table

| Evidence for this occurrence | Result |
|---|---|
| Valid OCR, confidence at least existing 0.6 threshold (and at most 1), one eligible exact registry key | Registered, with recorded category |
| Reliable valid OCR, no eligible exact registry key and no existing unresolved match uncertainty | Guest internally; existing UI label Unregistered Visitor |
| Missing/low confidence, unreadable/invalid plate, unsupported key length | Review, no registered identity |
| Multiple normalized registry candidates | Review; registry collision must be resolved separately |
| Exact candidate is inactive | Review with inactive-registration reason; no access/state bypass |
| Learned/fuzzy profile points toward a registration without the actual plate matching it | Review; suggestions never establish registration |
| Valid plate explicitly typed/picked/confirmed by staff, one eligible exact registration | Registered on the same event |
| Valid staff-confirmed plate, no eligible registration at occurrence | Guest internally; Unregistered Visitor in existing UI |
| Empty/invalid typed correction | Validation failure; existing Review state remains |
| Plate conflicts with linked RFID identity | Existing Review queue holds the conflict; verified RFID identity/state/event remain unchanged |
| Staff correction agrees with an existing RFID event for the crossing | Redundant visitor evidence is dismissed; the RFID event remains the single movement |

Eligibility uses actual Registry rows, supported registered categories (including
existing legacy category aliases), active vehicle status, and registration time
at/before the occurrence. This phase does not authorize entry by plate, change
RFID verification, weaken tag-assignment requirements, or introduce lost-tag rules.
Positive historical classification remains a recorded receipt if a vehicle's
current category/status changes later.

The existing explicit **No plate visible** action still lets staff acknowledge
an unidentifiable plate and clear that card, while its classification stays
Review rather than guessing Guest or Registered. Unknown direction can still
require review separately. A typed empty correction cannot invoke this action.

## Event identity, audit, images and counts

The same visitor ID, external key and crossing relationship are retained during
correction. Gate, occurrence time, direction, snapshots and plate-crop paths are
not changed by correction. Available images are reused; missing images are not
fabricated and no capture policy or registered-event capture was added.

Original OCR text/details stay alongside the corrected plate. Late OCR cannot
overwrite staff-confirmed identity or existing original evidence; if correction
happened before any OCR arrived, its first actual later OCR evidence can still
be retained without reclassification. Existing ReviewAction audit records who,
when and before/after; PlateCorrection learning remains available for suggestions.

`PlateProcessingLock::run()` increments the singleton lock row before reading
identity state inside a database transaction (with concurrency retries). This
acquires a real write lock on SQLite, where lockForUpdate alone is ineffective;
row locking also supports the existing SQL architecture. Registration, plate
edits, OCR writes and Review correction use this lock. Correction reloads the
record under the lock and commits classification, profile counts, learning and
audit atomically. Identical retries are successful no-ops for the audit/learning.
A forced audit-write failure test proves the whole correction rolls back.

MovementCountService still counts each logical pass once; classification changes
its category split, not its occurrence or total movement count. Unresolved plate
identity appears under Review. RFID-linked review evidence does not contribute
another movement or Activity Logs row. Existing duplicate/dismissed exclusions
remain. Plate-only identification never calls moveVehicle or replays a crossing:
registered inside/outside state and RFID daily counters remain unchanged.

`VisitorRecordLog` is shared by Activity Logs, CSV/print and modern station/event
feeds. Existing visitor history/profile details show per-visit classification;
the profile header explicitly describes current registration. Original Visitor
terminology, menus and filter options remain. The Review queue gives classification
failure reasons, including when a manual correction still cannot resolve ambiguity.
Automatic filtering/background refresh reads the same corrected records.

## Migration and existing data

`2026_11_03_000001_record_plate_classification.php` is additive:

```text
visitor_records.registration_status    nullable VARCHAR(20)
visitor_records.registration_category  nullable VARCHAR(40)
visitor_records.registration_reason    nullable VARCHAR(200)
plate_processing_locks                singleton id/version row
```

No existing registration/event is bulk-normalized, relinked or reclassified by
the migration. Existing rows retain NULL decision fields and use historical
relationship/review compatibility checks until an actual new OCR/correction
action supplies evidence. The four known broken historical references remain
untouched. Any existing collisions, unsupported identifiers or disputed old
relationships require a separate record-specific audit; this phase did not
inspect private installation records or guess corrections.

Ordinary registration and plate editing now update current profile ownership
without relinking earlier visitor rows. Explicit **Register this vehicle** still
moves the selected profile's history using the existing workflow, but persisted
occurrence classification is retained. Merely registering later cannot silently
turn an earlier Guest event into Registered. Current-profile ownership and
historical occurrence classification are intentionally separate.

Phase 1 `vehicles.registered_by` stays immutable in ordinary edits. Only a
successful new registration changes `system_settings` key
`registration_staff_name`, in the same transaction. OCR/review/correction does
not modify either staff attribution or that default.

## Verification results and limitations

- Full Laravel suite: `PYTHONDONTWRITEBYTECODE=1 php -d memory_limit=512M
  vendor/bin/phpunit --no-progress` — **516 tests, 4,737 assertions passed**.
  Includes **27** focused pipeline scenarios, the **39** Phase 1 staff cases,
  existing security/gate/history/filter regressions and backup compatibility.
  The local default 128 MiB test process exhausted memory after suite growth;
  the explicit development-only 512 MiB limit completed the full suite.
  Production PHP configuration was not changed.
- Real separate-process SQLite contention test: two independent PHP applications
  are held at the same write-lock barrier, then correct the same record.
  Both succeed; exactly one audit, one learned correction, one profile visit,
  one crossing and zero new VehicleEvents remain. Vehicle state and staff
  default stay unchanged. Included in the full-suite totals above.
- `tests/browser/plate-pipeline-ui.mjs` — **26 checks passed**, including case,
  spacing/caret, synthetic IME, preserving invalid symbols, manual error/recovery,
  automatic OCR decisions, correction through Review, Guest resolution, filters,
  CSV/print, original OCR evidence, live monitoring, retries and Phase 1 default.
- Existing browser checks passed: staff registration **37**, automatic filtering
  **69**, classification **27**, prior validation **20**. An initial overloaded
  parallel browser batch triggered the existing wall-clock debounce assertion;
  its dedicated isolated rerun passed. The debounce implementation was unchanged.
- Python `.venv/bin/python -m unittest discover -s school-vehicle-monitoring-detector/tests`
  with bytecode disabled — **163 tests passed**. Existing fake-socket ResourceWarnings
  remain; no failing Python test. Strict OCR normalization assertions now reject
  positional swaps/symbol stripping and retain supported layouts/leading zeros.
- `npm run test:js` — **13 passed**. `npm run build` — **passed**, 61 modules.
- Changed PHP/JavaScript syntax, focused Pint checks for new/support files and
  `git diff --check` passed. Generated bytecode from browser-triggered Python
  helpers was restored to its initial index state, preserving staged cache work.

All writes used in-memory/private temporary databases and isolated browser
fixtures. The browser runner refuses a live database, uses random test credentials
and its own PHP server, blocks outside-origin requests and cleans up afterwards.
PHP/Python dependencies and headless Chrome were available locally. OCR payload
tests and normalization/voting tests are simulated; no physical camera recognition,
real RFID hardware, actual Windows execution/PC restart, OS clipboard/autofill,
native IME or assistive-technology verification was performed. SQLite concurrency
was tested; another database engine was not exercised.

## Changed files in Registration Phase 2

```text
README.md
REGISTRATION-PHASE2.md (new)
app/Http/Controllers/Api/RealtimeLogController.php
app/Http/Controllers/Api/VisitorIntegrationController.php
app/Http/Controllers/VehicleEventController.php
app/Http/Controllers/VisitorController.php
app/Http/Middleware/NormalizePlateInput.php (new)
app/Http/Requests/StoreVehicleRegistrationRequest.php
app/Models/VisitorRecord.php
app/Rules/PlateIdentifier.php
app/Services/EventService.php
app/Services/GuestObservationService.php
app/Services/MatchingService.php
app/Services/PlateProcessingLock.php (new)
app/Services/RfidService.php
app/Services/VehicleRegistryService.php
app/Services/VisitorRecordService.php
app/Services/VisitorReviewService.php
app/Support/MovementRow.php
app/Support/PlateNumber.php
app/Support/StatusBadge.php
app/Support/VisitorRecordLog.php (new)
bootstrap/app.php
database/migrations/2026_11_03_000001_record_plate_classification.php (new)
public/js/plate-input.js (new)
public/js/visitor-review.js
resources/views/layouts/app.blade.php
resources/views/registry/partials/vehicle-fields.blade.php
resources/views/vehicle-events/create.blade.php
resources/views/vehicle-events/show.blade.php
resources/views/vehicle-registry/edit.blade.php
resources/views/visitors/index.blade.php
resources/views/visitors/partials/record-dialogs.blade.php
resources/views/visitors/partials/records.blade.php
resources/views/visitors/partials/review.blade.php
resources/views/visitors/profile.blade.php
school-vehicle-monitoring-detector/anpr.py
school-vehicle-monitoring-detector/plate_reader.py (documentation)
school-vehicle-monitoring-detector/plate_voting.py (documentation)
school-vehicle-monitoring-detector/tests/test_plate_voting.py
tests/Feature/ActivityLogClassificationTest.php
tests/Feature/ExistingFieldValidationTest.php
tests/Feature/Phase6VisitorRankingTest.php
tests/Feature/PlateCorrectionConcurrencyTest.php (new)
tests/Feature/RegistrationPlatePipelineTest.php (new)
tests/Feature/UiPhase3ClarityTest.php
tests/browser/classification-ui.mjs
tests/browser/plate-pipeline-ui.mjs (new)
tests/fixtures/classification-ui.php
tests/fixtures/plate-correction-worker.php (new)
tests/fixtures/plate-pipeline-ui.php (new)
```

Existing test expectations were updated for the authorized differences:
unreadable results are Review, reliable positives supply OCR confidence,
ordinary registration does not silently transfer past links, and only
transport-overlong OCR payloads are rejected instead of retained for Review.

## Pending Windows update steps

1. Use the installation's existing backup procedure. Preserve database,
   `.env`/APP_KEY, uploads/storage, device configuration and model files.
2. During maintenance, stop application writers/services and copy the reviewed
   PHP/Blade/public assets, new classes/middleware, migration and updated Python
   ANPR source. Include normal production build artifacts. No new dependency,
   account provisioning or camera/reader configuration is required.
3. Using configured installation PHP from the application directory, inspect
   `artisan migrate:status`, then run `artisan migrate --force` before using
   updated pages. This phase adds only the fields/lock table listed above;
   the preceding Registration Phase 1 migration must also be installed.
4. Clear/rebuild application caches with the existing update procedure,
   restart PHP/detector services and refresh the browser. Do not reseed,
   reset the database, regenerate APP_KEY or overwrite historical data.
5. Check a real approved sample through OCR and manual Review on the Windows
   PC without assuming that these simulated tests verified the camera/model.
   Check available images, one movement, expected category and unchanged RFID
   state. Collision/historical repair requires separate authorization and audit.

These steps are pending deployment instructions. No live migration, deployment,
publishing, push or external-service modification was performed in this task.

## HANDOFF FOR PHASE 3

Registration Phase 2 is complete. Preserve PlateNumber as the normalization/key
authority, exact eligible registration matching, per-occurrence decision fields,
the shared write-before-read transaction lock, existing event identity/audit and
images, and unchanged plate-only inside-state semantics. Keep Phase 1 staff
attribution/default, account security, gates, validation, color removal and
automatic filters intact. Do not guess repairs for the four broken references
or bulk-reclassify old events. No later-phase terminology, table-menu, lost-tag
or snapshot-capture work was implemented. Wait for **Go Phase 3**.
