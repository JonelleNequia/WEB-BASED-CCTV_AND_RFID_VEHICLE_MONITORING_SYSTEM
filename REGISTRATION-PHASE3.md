# Registration Phase 3: optional RFID, plate movements and permanent lost UIDs

Implemented locally. This is Registration Phase 3, separate from earlier UI,
detector and security batches with the same phase numbers. No applicable
AGENTS.md was found in the repository or its ancestors. No live migration,
installation reset/reseed, deployment, push or external-service change was run.

## 1. Registration and tag-management flows

All current vehicle-registration entry points share the existing Add Vehicle
drawer, request and VehicleRegistryService. RFID is optional there and in the
service. A valid, unique Phase 2 plate and valid Registered by name are still
required. Blank RFID creates no inventory row, dummy UID, reservation or
assignment. Supplied tags still require eligible inventory and unique assignment;
the existing scanned-new-tag workflow remains available. The raw scan field is
also submitted for server validation when JavaScript is unavailable.

Lists and details say **No RFID assigned**. The existing tag drawer offers
**Assign Tag** for an untagged vehicle and **Replace Tag** for a tagged vehicle.
Both retain its ID, original staff attribution, plate, historical relationships,
images and occupancy. Only a successful new registration changes the shared
registration_staff_name default, within its transaction. Ordinary metadata edits
can retain the unchanged historical tag reference even when that tag is lost;
this neither assigns nor reactivates it.

Assignment/replacement/status mutations use the shared database transaction lock.
Replacement compares the form's expected current tag against a fresh locked
vehicle and rejects stale submissions. Previous tags retain their vehicle
relationship. Non-lost old tags retain the existing lost/disabled replacement
choice and disabled-tag enable policy. A lost old tag cannot be downgraded by
choosing disabled during replacement. Existing server-side role restrictions
remain in force; unauthorized mutations are tested.

## 2. Identification and movement decisions

| Evidence | Identification | Movement / occupancy |
| --- | --- | --- |
| Reliable exact plate; one eligible active registration | Registered, including vehicles without a usable tag | One OCR plate event; known IN/OUT sets state |
| Existing staff Review confirms that exact eligible plate | Registered | Same occurrence/event; Staff-confirmed plate source |
| Valid reliable or staff-confirmed plate, no registry match | Guest under existing terminology | Existing visitor movement; no registered occupancy |
| Invalid/unreadable/uncertain plate, canonical collision or inactive registration | Review | No guessed registered state |
| Disabled/unassigned RFID evidence linked to the crossing | Review/flagged under existing restrictions | Plate cannot bypass the linked restriction |
| RFID and plate disagree | Review; verified RFID identity is retained | No second movement or guessed transfer to the other vehicle |
| Known registered identity, UNKNOWN camera direction | Registered identity; one direction Review item | UNKNOWN movement; no occupancy toggle |
| Late genuine RFID for an existing plate occurrence | Reconcile into its existing event | Same event ID and occupancy effect; genuine scan linked |
| Lost UID read, including buffered evidence | Ignored | No new business scan, movement, receive-log row or operator alert; camera processing continues |
| Genuine eligible RFID with camera offline | Existing configured fallback | Current-state fallback retained; an older out-of-order read cannot toggle newer state |

The Phase 2 normalization, exact-match eligibility and confidence threshold are
unchanged. Fuzzy matches and learned OCR corrections cannot establish registration
without the existing Review process. No gate-opening or physical authorization
behavior was added. Vehicle types, global Guest terminology, table menus,
accounts/security, gates and snapshot capture policy remain unchanged.

## 3. One occurrence, chronological occupancy

MovementReconciliationService creates or updates one canonical VehicleEvent for
an eligible plate occurrence, linked by a unique visitor_record_id. It never
creates a synthetic RfidScanLog. Activity Logs, CSV/print, monitoring feeds,
movement counts and dashboard totals omit the redundant evidence row when the
completed canonical movement already represents it. Explicit RFID statistics
continue to use actual RFID evidence. Source labels distinguish OCR plate,
Staff-confirmed plate and real RFID confirmation.

The first explicitly processed occurrence for a vehicle captures its existing
occupancy fields in vehicle_occupancy_baselines. Subsequent reconciliation uses
only explicitly managed completed events, ordered by occurrence time and ID.
IN means inside, OUT means outside; UNKNOWN never toggles. Counters and state are
projected from that baseline and those occurrences, instead of incrementing or
toggling every time evidence changes. Corrections can retract an old plate
effect or move it to the correctly identified vehicle, without replacing newer
known occupancy. Pre-baseline historical counter values are not invented.

Repeated OCR, identical manual corrections and later RFID association reuse the
same occurrence. A late RFID conflict holds the shared event for Review; explicit
resolution to its verified identity completes that same event. Conflicting later
OCR remains visible in Review even when an already verified RFID movement is
retained. UNKNOWN direction has one direction card, with gate/camera evidence;
setting its direction updates both the existing event and crossing. Existing
clearer-picture retries update the same image references, without a new capture.

Classification, audit/profile changes, event reconciliation and occupancy are in
the existing PlateProcessingLock transaction. Its first operation updates the
singleton row before reading identities: this acquires SQLite's writer lock and
also provides a database row serialization point on row-locking databases.
Transactions retry supported concurrency failures. Crossing creation/retries,
RFID ingestion/claim/consumption, assignment/replacement and vehicle/tag status
changes use this same lock order. No production history replay/backfill runs.

## 4. Permanent Lost / Unavailable enforcement

Normal states retain the existing available/assigned/disabled behavior. Moving
any tag to lost creates a canonical UID deny record with actor, time and previous
status. Lost is terminal: no Enable, reassignment, replacement selection,
UID change, deletion or recreation through application paths. Whitespace/case
variants use the existing UID canonicalization; punctuation and leading zeroes
are not silently changed. Inventory actions and selectors exclude lost UIDs;
the permanent label and confirmation explain that reads are ignored and history
is retained. The former vehicle remains registered and can receive another tag.

Enforcement covers registry services, model saving/deleting guards, canonical
inventory lookup, detector matching, buffer selection/claim, ingestion and fusion
consumption. A fresh current assignment is checked before movement. Lost reads
return an unsaved, non-anomalous ignored result before cooldown/business logging.
The kiosk does not replace its camera result or open an alert for that outcome.
Unknown/disabled-tag handling is preserved. Already stored legacy pending reads
that become ineligible cannot move a vehicle; finalization retains the old row
and closes its pending work without creating another row.

The denylist survives the existing explicit full-reset feature. Its optional
tag reference is detached before that feature reuses registry IDs, so the UID
cannot become available through deletion/recreation. Derived occupancy baselines
are included in that feature's activity cleanup. This task did not run a reset
against an installation; reset verification used isolated test data only.

## 5. Changed files and migration

New files for this phase:

```text
app/Console/Commands/ReportLostTagConflicts.php
app/Services/MovementReconciliationService.php
database/migrations/2026_11_03_000002_plate_movements_and_unavailable_tags.php
tests/Feature/OptionalRfidMovementTest.php
tests/Feature/RegistrationPhase3ConcurrencyTest.php
tests/fixtures/registration-phase3-worker.php
tests/browser/optional-rfid-ui.mjs
REGISTRATION-PHASE3.md
```

Modified integration/application files (many already contain earlier work):

```text
README.md
app/Http/Controllers/Api/{CrossingIntegration,FutureIntegration,RealtimeLog,VisitorIntegration}Controller.php
app/Http/Controllers/{Dashboard,RfidScan,Station,VehicleEvent,VehicleRegistry,Visitor}Controller.php
app/Http/Requests/StoreVehicleRegistrationRequest.php
app/Models/{RfidTag,VehicleEvent,VisitorRecord}.php
app/Services/{DetectorRfidMatch,DirectionCorrection,Event,MovementCount,RfidCameraFusion,RfidIngest,SystemReset,VehicleOccupancy,VehicleRegistry,VisitorRecord}Service.php
app/Services/RfidTagMatcher.php
app/Support/RfidIngestResult.php
public/js/{registry,station-kiosk}.js
resources/views/registry/partials/{tag-actions,tag-picker}.blade.php
resources/views/registry/tabs/{tags,vehicles}.blade.php
resources/views/vehicle-registry/edit.blade.php
resources/views/visitors/index.blade.php
resources/views/stations/show.blade.php
resources/views/logs/tabs/alerts.blade.php
resources/views/settings/tabs/test-scan.blade.php (comment only)
```

Updated regression expectations for the authorized behavior:

```text
tests/Feature/{GateDirectionMatrix,Phase3RfidCameraFusion,Phase4Ui,UiPhase2Navigation,UiPhase3Registry,UiPhase4Pages,Phase7MovementCounts,SystemReset}Test.php
tests/Feature/{PlateCorrectionConcurrency,RegistrationPlatePipeline}Test.php
tests/browser/{classification-ui,plate-pipeline-ui}.mjs
```

Migration 2026_11_03_000002 adds a nullable unique visitor-record FK and
occupancy_managed flag to vehicle_events, an initially empty baseline table,
and unavailable_rfid_uids. It imports deny records for existing lost UIDs without
changing their tag/vehicle/history rows or inventing actors. Existing events
default to unmanaged. Existing staff and plate-classification migrations must
also be installed. No new package dependency or detector algorithm is required.
Earlier staged/unstaged changes remain in place and were not committed/staged.

## 6. Verification

- Full Laravel suite: **538 tests, 4,976 assertions passed**. Run with
  `PYTHONDONTWRITEBYTECODE=1 php -d memory_limit=512M vendor/bin/phpunit --no-progress`.
- New focused coverage includes untagged/tagged registration, invalid/duplicate
  plates, attribution/default preservation, IN/OUT/UNKNOWN, manual retries,
  late/older evidence, shared-event conflicts, transaction rollback, lists/counts,
  authorization, assignment/replacement, permanent UID guards, stale buffers,
  ignored API/kiosk reads, pending legacy reads and additive upgrade preservation.
- Three separate-process SQLite races passed: two vehicles compete for one tag;
  competing replacements reject the stale assignment; simultaneous manual plate
  confirmation/RFID evidence produce one event/scan/crossing and one entry.
  Both workers wait behind a real held SQLite write lock before competing.
- Headless installed Chrome, isolated private SQLite/PHP servers: optional RFID
  **35**, plate pipeline **26**, registration staff **37**, classification **27**,
  automatic filters **69**, validation **20**, Vehicle Color **20**, Settings
  **128** browser assertions passed. All browser traffic outside their isolated
  server is blocked. Test servers/databases/credentials are removed on exit.
- Detector Python virtual environment:
  `PYTHONDONTWRITEBYTECODE=1 school-vehicle-monitoring-detector/.venv/bin/python -m unittest discover -s school-vehicle-monitoring-detector/tests`
  — **163 passed**; existing fake-socket ResourceWarnings remain. The root
  virtual environment lacks detector dependencies; use the detector environment.
- `npm run test:js`: **13 passed**. `npm run build`: **passed**, 61 modules.
- Syntax: **97 PHP files** and **13 JavaScript/browser files** passed checks
  across current changed/new files. Focused Pint checks passed for the new PHP
  files and crossing controller. `git diff --check` passed.

These are simulated integration/browser tests on macOS with isolated data.
No physical reader, real camera/OCR accuracy, Windows execution/reboot, OS input
driver or real traffic timing was verified. MySQL contention was not executed;
the separate-process concurrency tests exercised the installation's SQLite
serialization mechanism. Projection work scales with explicitly managed events
for the affected vehicle; large-volume performance was not benchmarked.

## 7. Legacy conflicts and remaining constraints

The four known broken historical references remain untouched. No repair, relink,
merge, deletion or live-data audit was guessed. The new read-only command
`php artisan rfid:lost-conflicts` reports denied UIDs that still have conflicting
live tag statuses or current vehicle assignments. Normal retained pointers to
the same lost historical tag are not treated as a repair request. Canonically
ambiguous tag identities are not selected arbitrarily. Actual installation
conflict results are unknown until its operator runs the command after upgrade.

No known failing automated check or unimplemented Phase 3 requirement remains.
Hardware/Windows validation and any separately authorized historical repair are
pending. Earlier phase reports describe their original behavior; this report
supersedes their plate-only occupancy limitation and reactivation semantics.

## 8. Pending Windows update steps — not executed

1. Make the normal installation backup: database including WAL if applicable,
   .env/APP_KEY, storage/uploads, device/calibration configuration and models.
2. During maintenance, stop application/detector/device writers and install the
   reviewed PHP, Blade, public assets and production build with all new files.
   Preserve configuration and existing data; no provisioning/reseed is needed.
3. From the app directory using installation PHP, inspect `php artisan migrate:status`,
   then run `php artisan migrate --force`. Include the preceding Registration
   Phase 1/2 migrations if pending. This phase's migration adds the columns and
   tables above; it does not reset occupancy or replay historical movements.
4. Run `php artisan rfid:lost-conflicts`. Review any findings manually; this
   command does not repair assignments or the four existing broken references.
5. Clear/rebuild caches with the existing update procedure, restart local
   services and refresh browsers. Do not regenerate APP_KEY, reset or seed data.
6. Verify on approved real traffic: untagged plate IN/OUT and unknown direction,
   manual Review, late RFID without duplicates, later assignment/replacement,
   permanent lost reads without alerts, and independent camera continuation.
   Check historical images/attribution and unchanged devices/gates/accounts.

## 9. HANDOFF FOR PHASE 4

Registration Phase 3 is complete locally. Preserve required immutable original
registered_by and the transactional installation default; Phase 2 exact plate
normalization/classification/audit; the shared first-write database lock; unique
canonical event identity; chronological managed occupancy/baselines; genuine
RFID provenance; permanent UID deny records and ignored stale/lost reads.
Do not bulk replay history, fabricate scans, weaken inactive/conflict checks or
repair the four known references without separate authorization. Accounts,
security, gates, types, terminology, menus and snapshot policy remain outside
this phase. Windows migration/hardware validation is pending. Stop here and
wait for **Go Phase 4**.
