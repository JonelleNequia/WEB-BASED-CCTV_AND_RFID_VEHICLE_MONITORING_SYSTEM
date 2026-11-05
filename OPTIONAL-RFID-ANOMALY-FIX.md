# Optional-RFID registration / Activity Logs anomaly fix

Date: 2026-11-03. Registration Phase 6 and all other repair batches remain paused.

## Findings and exact triggering path

The reported combination is reproducible without a missing-tag validation failure or a repeated-entry trigger:

1. `GuestObservationController.php:527` writes the literal classification marker **`Unregistered guest`** into `vehicle_events.anomaly_reason` for a legacy `guest_cctv` event, with `match_status=no_pass_alert`.
2. A reliable exact plate read, or staff plate confirmation, identifies an eligible registration. `MovementReconciliationService::syncPlate()` adopts the compatible same-key event through the canonical resolver, retaining its ID. It changes the origin to `plate_ocr`/`plate_review`, stores the registered category and applies the managed movement. Previously it retained that old Guest marker.
3. `VehicleEventController::vehicleEventLogType()` calls the linked movement **Registered**. Its status/alert builders consider a nonblank anomaly reason on the new non-Guest origin an **Anomaly**. The row displays the reason as its subtitle. Consequently **Registered + Unregistered guest + Anomaly** appears even though the captured category is **Faculty & Staff** in the reproducer.

Thus the contradictory subtitle in this verified path is a **stale reason**, not a new registration-category scheme. The reason string is stored; the `Anomaly` label and `is_alert` result are computed from it and the event origin/status. VehicleEvent has no separate reason-code enum or anomaly boolean for this case. The exact stored marker is `Unregistered guest`; the exact computed status is `Anomaly`.

The reproduction used the actual legacy ingestion endpoint and both OCR and staff-confirmation paths, followed by three identical retries. It failed before the fix because the reason remained non-null. The separate legacy plate-only presentation failed with `Tag not read`, and Full Details failed to show a real repeated-IN anomaly/reason. All those regression cases pass after the fix.

**Follow-up screenshot evidence:** The subsequently supplied screenshot shows the top row as Registered, Registry record, IN, subtitle **Unregistered guest**, and **ANOMALY**, with earlier Guest IN occurrences below it. That confirms the displayed combination matches the reproduced stale-reason defect: the existing row template renders `alert_reason` in that subtitle. A genuine repeat-entry reason would instead explain that the vehicle was already inside and an OUT was missed; earlier IN rows alone do not establish that trigger. The screenshot also shows the old Tag not read presentation on historical plate rows, addressed by the neutral presentation change above.

**Remaining evidence limitation:** The screenshot does not expose the occurrence's database ID or canonical links, and the available local database had no stored non-null event anomaly reasons in the read-only query. The visible symptom and matching code path are confirmed; the screenshot's exact persisted record has not been located or repaired. No private record identities were published.

## Missing RFID, registration and repeated IN

- A valid untagged registration is supported. Registration alone creates **zero movements, scans, inventory tags or occupancy baselines**, and no alert. It remembers staff attribution/default transactionally as before.
- Ordinary registration updates current profile ownership, without relinking/reclassifying historical passes. Explicit selected-profile transfer can link their records, but retains persisted occurrence classification and does not replay movements. Repeating that transfer creates no movement or anomaly.
- A later eligible exact plate-confirmed IN/OUT uses the existing canonical event/chronological occupancy workflow. It creates no fabricated RFID evidence.
- Genuine repeated-IN anomalies remain. The existing RFID rule in `RfidCameraFusionService.php:315` flags a camera IN when the vehicle is already inside unless that same managed movement was already applied. Its reason says **“already inside (an OUT was missed).”** `DirectionCorrectionService.php:56` has the corresponding existing staff-set-IN rule. A prior same-plate IN in a screenshot alone does not prove which rule produced the current badge.
- The plate reconciliation path does not independently turn every second IN into a new anomaly. This task preserves existing policy rather than adding or suppressing a new repeat-entry rule.
- Ineligible categories, inactive/flagged RFID evidence and RFID/plate identity conflicts retain their existing restrictions. Optional RFID does not bypass exact matching, registration creation-time eligibility, status or category rules.

## Implemented changes

- During an eligible same-occurrence plate promotion, clear **only** the exact legacy `Unregistered guest` marker when its original origin is `guest_cctv` and match status is `no_pass_alert`. All other reasons remain; the genuine RFID path still returns through its existing logic. No blanket untagged-vehicle anomaly suppression was added.
- Unrepresented historical registered plate rows use neutral **Plate identified / Camera · plate only** presentation. Their state says it was not recorded for that occurrence, without pretending old unmanaged history updated occupancy. Guests history says **Registered vehicle · plate identified** rather than treating absent RFID as a missed-tag warning. Review, unreadable and unknown-direction statuses remain.
- Full Record Details now reuses the same event status/category payload as Activity Logs and shows an escaped **Alert reason** for actual alerts. The existing quick Details already includes the reason. A legitimate repeated-IN event stays Anomaly in both places.
- Counts, print grouping and CSV schema were not changed. CSV retains the existing status column; print remains a movement/category report without a new anomaly-reason column. Corrected canonical rows, category/log-type filters and exports use the same stored classification. The RFID Alerts tab/summary retain their existing saved-scan scope; they are not redefined to count every plate-only event badge. Repeated genuine RFID IN produces one saved alert, including after retries.

## Files changed in this task

Application:

- `app/Services/MovementReconciliationService.php`: narrow legacy-marker cleanup on promotion.
- `app/Support/VisitorRecordLog.php`: neutral plate-only historical presentation.
- `app/Http/Controllers/VehicleEventController.php`: share Activity Logs payload with Full Details.
- `resources/views/vehicle-events/show.blade.php`: consistent status/category and real alert reason.
- `resources/views/visitors/partials/records.blade.php`: neutral registered plate-identification subtitle.

Verification/documentation:

- `tests/Feature/OptionalRfidAnomalyTest.php`: ten regression cases, including data-provider variants.
- `tests/Feature/RegistrationPlatePipelineTest.php` and `tests/Feature/Phase6VisitorRankingTest.php`: update the intentional neutral-label expectation. The latter is an older visitor-model suite, not Registration Phase 6 work.
- `tests/browser/optional-rfid-ui.mjs`: actual legacy Guest-copy adoption, rendered row and Details checks.
- `tests/browser/plate-pipeline-ui.mjs`: updated label assertion.
- `OPTIONAL-RFID-ANOMALY-FIX.md`: this report.

No migration, dependency or detector/device change. Existing unrelated working-tree edits are preserved.

## Verification and limits

All mutations used a private source copy with isolated SQLite databases, storage, cache, camera/device configuration and local test servers. Existing packages were reused without installation. Browser requests outside each fixture origin were blocked; no cameras/readers or running application services were contacted or changed.

- Before application changes: the focused suite reproduced the stale marker on OCR/staff promotion, missed-tag warning and missing Full Details reason.
- Focused suite after the fix: **10 tests, 157 assertions passed**.
- Full Laravel suite: **581 tests, 5,588 assertions passed; zero errors/failures/skips**. Includes existing disabled/flagged and genuine RFID/plate conflict tests, out-of-order/baseline reconciliation, literal plate order, lost UID enforcement, staff/default and type provenance, and separate-process concurrency.
- Full Chrome suite: **577 assertions across 11 suites passed** (optional RFID 40; other suites 537).
- JavaScript units: **15 passed**. Production Vite build: **passed, 61 modules**, in the private copy.
- New PHP test file passed Pint's read-only style check; `git diff --check` passed.

No actual Windows installer/update, hardware, real traffic or Python inference verification. Python/detector code was not changed. Existing backup/restore permanence and other findings from SYSTEM-FLOW-REVIEW remain outside this paused repair scope; this task does not claim to fix them.

## Existing-data proposal, not performed

This prevents the stale marker on subsequent eligible promotions. It does **not** bulk-clear already-promoted historical rows or relabel earlier Guest occurrences. If the reported installation already has a `plate_ocr`/`plate_review` event carrying the marker, a separately authorized read-only audit should identify candidates by exact marker, canonical occurrence links, captured category, compatible camera/time/direction, no genuine conflicting RFID evidence and the original Guest-copy provenance. Return candidate counts/evidence and a narrowly scoped repair plan before any data changes. Genuine repeat-IN/flagged/conflict reasons must remain untouched.

No live migrations, reset/reseed, record repair, device modification, deployment, publication or push was performed. Registration Phase 6 and other repair batches remain paused. Stop after this task.
