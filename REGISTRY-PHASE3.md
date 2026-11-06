# Registry Phase 3 — OCR evidence and identifier review

Implemented locally on 2026-11-05 (Asia/Manila), after reading REGISTRY-PHASE2.md and tracing the current readers, character voting, both detector submission callbacks, Laravel ingestion, Review and canonical-event/RFID reconciliation. No applicable AGENTS.md was found in the repository or its ancestors. The existing Phase 2 working-tree changes were preserved.

## Decision rules

The current detector locates generic candidate plate regions and recognizes characters. Its outputs do **not** reliably distinguish an actual plate from a temporary plate, conduction sticker or MV file number. Character pattern, length, high OCR confidence, vehicle type and a Registry match cannot supply that missing evidence.

Consequently, **new automatic OCR observations require staff identifier-type confirmation**, including clear, exact Registry matches. This is a deliberate operational change: there is no automatic plate-only Registered classification from the present detector until staff confirms an actual plate. Independent valid RFID identification retains its existing workflow.

| Evidence/decision | Behavior |
|---|---|
| Unreadable, incomplete, invalid or low-confidence OCR | Review, with a reason and retained raw evidence/candidate images. No actual plate/profile/registration identity is created. |
| Ambiguous character/layout vote | Review. Equally weighted incompatible layouts no longer select a winner as a clear read. The existing 60% majority threshold is reused. |
| Clear characters but unclassified identifier type | Review, explaining that the image must establish actual plate versus temporary identifier. A matching registration does not bypass this. |
| Staff saves/confirms an actual plate | Apply Phase 2's generic identifier validation, literal normalization and exact matching. A unique active eligible registration at the occurrence becomes Registered; an unmatched/ineligible-category plate remains Guest. Collisions, inactive registrations and RFID restrictions/conflicts remain Review. |
| Staff confirms Temporary plate, Conduction sticker or MV file number | Save kind/text as evidence only. Clear the observation's actual `plate_number`, `plate_key`, profile and plate-derived vehicle link. Do not learn a plate correction from the temporary identifier. Retain the observation, OCR evidence, images and audit history. |
| No plate visible | Preserve the existing checked-no-plate decision and counting behavior. |
| Later OCR/retry after staff confirmation | Preserve the staff plate/type decision and existing occurrence. Keep distinct late diagnostics without generating another decision, movement or audit entry. |

Phase 2 formats remain supported when staff confirms an actual plate: `ABC 1234`, `ABC 123`, `123ABC`, `A123BC`, additional generic formats and leading-zero identifiers. `395ISX` and `ISX395`, `O` and `0`, and `I` and `1` remain distinct. Temporary identifiers are not inferred from these patterns; two observations with the same text can receive different staff decisions based on their evidence.

## Implementation and verified defects

- **Python character recognition remains separate from type classification.** `plate_identifier.review_submission()` retains the character vote/candidates/raw reads, declares the type uncertain, and sends no actual plate in either the initial or Left-zone plate callback. Its best guess remains evidence. The parallel legacy Guest submission also receives no unconfirmed actual plate. No new classifier, model download, device configuration or OCR confidence threshold was introduced.
- **Candidate images survive failed recognition.** The fallback ANPR reader now retains an available candidate crop even when its text is invalid; the detector analyzer carries that crop through to submission. The fast reader already retained its candidate crop. No characters are stripped into a matching identity.
- **Laravel also enforces the distinction.** `applyPlateReading()` stores candidates/raw details but does not create actual-plate fields, profiles or registration identity before staff confirmation. A detector cannot spoof the application's protected staff-decision metadata, including through arbitrary `ocr_details` fields.
- **Evidence uses existing JSON and audit conventions.** `PlateEvidenceService` stores the protected decision in `ocr_details_json._application_plate_evidence`: kind, staff attribution/time, evidence text, original incoming OCR and bounded distinct later reads. Original evidence is retained; later diagnostics are limited to ten distinct payloads. Existing Python diagnostics remain bounded to twenty reads per collector and two hundred characters per read, with truncation explicitly marked. No migration is required. Existing pre-Phase-3 staff confirmations are respected when a late callback first adds the metadata; there is no installation-wide rewrite.
- **Temporary handling stays in Review.** The existing card now has an expandable temporary-identifier section with the three explicit types and an evidence-only text input. Save plate and Confirm explicitly mean actual plate; familiar layouts are never automatically labelled temporary. Raw OCR can be expanded and linked images inspected through the existing viewer. Guests and Activity Logs/Details distinguish a temporary identifier from an actual plate and display Evidence only.
- **Stale-input regression reproduced and fixed.** Before the fix, invalid `395/ISX` produced 422 for Save plate **and** No plate visible, Dismiss and Set direction. `VisitorController` now decodes the action and the shared Review service validates only that action's inputs. Actual-plate confirmation/change still runs Phase 2 validation, including direct service calls. Unrelated fields cannot block another action, while invalid direction, reason, profile or temporary-type arguments are still rejected. The legacy Guest correction modal also ignores the plate field when its Plate unreadable action is selected.
- **Browser and plain forms agree.** Save plate retains inline validity/paste/IME recovery. Other actions do not depend on stale invalid or composing plate input. Keyboard actions use normal submit validation. No-JavaScript Review now displays server field errors and success feedback on the page; previously success/error feedback relied on JavaScript toasts. Errors remain by the actual-plate input rather than appearing under the new temporary fields.
- **Other confirmed Review defects fixed.** Existing Previous/Skip buttons had no click handlers; they now navigate the same cards as the keyboard. Set direction updates both the displayed direction and the stale Direction unknown badge.
- **One occurrence remains authoritative.** Corrections, temporary decisions and retries reuse the existing record/event resolver and shared identity transaction. IDs, occurrence time, gate, direction and linked media survive. A temporary correction can retract a plate-derived occupancy projection while retaining the event/history; confirming the actual plate again reuses that event. Existing geometric duplicate detection remains. Text alone does not prove that two independent observations are a duplicate; staff can use the existing Dismiss → Duplicate action.
- **Late RFID cannot overwrite a confirmed Guest plate silently.** The conflict guard now also covers a staff-confirmed unmatched plate with no prior registered event. It retains the evidence, creates/reuses one pending canonical event, and holds occupancy until staff resolves the conflict. A resolution reuses that event and applies occupancy once. Existing real RFID identity and lost/disabled-tag restrictions remain. Vehicle-type provenance continues to use an independently identified RFID event's snapshot while OCR identifier type is pending.

## Verification

All mutation checks ran in a private source copy with isolated SQLite databases, storage, media and camera/device paths. Browser fixtures use random test accounts, a private PHP server and blocked off-origin browser requests. No installation database or device was used. No live migration, reset/reseed, historical repair, deployment, publication or push was performed.

| Check | Final result |
|---|---|
| Full Laravel suite | **678 tests / 6,869 assertions**, zero failures/errors/skips |
| New evidence/Review feature suite | **29 cases**: eleven literal actual formats, all temporary types, unreliable evidence, stale actions, eligibility/collisions, spoof resistance, permissions, legacy staff decisions, media/event reuse and late RFID conflict |
| Full Python discovery | **185 passed** |
| JavaScript units | **17 passed** |
| New evidence browser suite | **41 passed**, including plain HTML forms without JavaScript |
| Existing Phase 2 plate browser suite | **41 passed** |
| Plate pipeline / literal regressions | **26 / 19 passed** |
| Optional RFID / vehicle types / snapshots | **41 / 61 / 22 passed** |
| Registry status-owner / staff / validation | **58 / 37 / 21 passed** |
| Automatic filters / menus-gates | **69 / 132 passed** |
| Browser total | **568 checks across 12 suites** |
| Frontend build | Passed, 61 modules, private output only |
| Static checks | Changed PHP/Blade/JS/Python syntax, new PHP files' Pint, whitespace checks |

The four initial regression cases failed before implementation: premature automatic Registered classification and the three stale-input actions. They pass after the fix. New tests exercise same-pattern observations with different staff-confirmed types, absent/eligible/inactive/ineligible/colliding Registry matches, actual and temporary corrections, original/late OCR, protected metadata, event/media identity, real RFID conflict resolution and occupancy retry behavior.

Existing fixture scenarios whose subject is a confirmed actual plate now perform an explicit staff confirmation. They retain movement, history, attribution/default, snapshot, type, optional-RFID, chronology and lost-UID assertions. Legacy fuzzy-matching tests construct historical snapshots and exercise the retained matcher directly; they do not permit unclassified new OCR to create profiles. One retry fixture now reuses the original timestamp instead of calling `now()` twice, preventing a second-boundary delay from fabricating an occurrence-ownership conflict.

The Phase 2 browser suite now waits for drawer/Save readiness and includes field-validity diagnostics after an intermittent immediate Save-state assertion. The existing plate-pipeline browser query expectation was also aligned with the uppercase plate-search input introduced in Phase 2.

The full Laravel suite includes SQLite multi-process correction, snapshot, assignment and RFID races. An earlier run hit the existing fifteen-second replacement-worker timeout; the isolated concurrency suite and final full run passed without a timeout change. Physical database-engine concurrency on Windows was not exercised.

Local detector fixtures use generated images and fake detector/OCR outputs through the real fast/fallback readers, voting, analyzer and submission code. They verify literal handoff, raw evidence, uncertainty and retained candidate images. They **do not verify physical camera performance, model accuracy, RFID reader hardware or Windows deployment**. IME checks use synthetic composition events.

Private logs, JUnit results and synthetic screenshots are under:

```text
/var/folders/zb/05jm3cld3yj8fsbb2cpscjlm0000gn/T/philcst-plate-phase3-u89afyjh/
  laravel-final.xml
  check-plate-evidence-ui.mjs.txt
  app/storage/app/ui-review/plate-evidence/
    stale-input-direction.png
    temporary-mobile.png
    activity-evidence.png
```

Screenshots were inspected at desktop and 390 px widths. The temporary form, images and validation remain readable on mobile; existing tables retain horizontal scrolling. The desktop check verifies that a successful direction action removes its old warning. These artifacts contain synthetic data.

## Changed files in Phase 3

This list is relative to the preserved Phase 2 working tree, not a list of all cumulative Git changes.

```text
app/Http/Controllers/VisitorController.php
app/Models/ReviewAction.php
app/Models/VisitorRecord.php
app/Services/PlateEvidenceService.php                         new
app/Services/RfidCameraFusionService.php
app/Services/VehicleTypeEvidenceService.php
app/Services/VisitorRecordService.php
app/Services/VisitorReviewService.php
app/Support/VisitorRecordLog.php
public/css/app.css
public/js/activity-logs.js
public/js/plate-input.js
public/js/plate-validation.js
public/js/visitor-review.js
resources/views/visitors/partials/records.blade.php
resources/views/visitors/partials/review.blade.php
school-vehicle-monitoring-detector/anpr.py
school-vehicle-monitoring-detector/detector_service.py
school-vehicle-monitoring-detector/plate_identifier.py
school-vehicle-monitoring-detector/plate_voting.py
school-vehicle-monitoring-detector/tests/test_identifier_evidence.py  new
school-vehicle-monitoring-detector/tests/test_plate_contract.py
tests/Feature/ActivityLogClassificationTest.php
tests/Feature/DetectedEventIngestionTest.php
tests/Feature/OptionalRfidAnomalyTest.php
tests/Feature/OptionalRfidMovementTest.php
tests/Feature/Phase5VisitorRecordsTest.php
tests/Feature/Phase6VisitorRankingTest.php
tests/Feature/Phase7MovementCountsTest.php
tests/Feature/PlateEvidenceReviewTest.php                      new
tests/Feature/PlateLiteralReconciliationTest.php
tests/Feature/PlateMatchingTest.php
tests/Feature/PlatePhaseTwoContractTest.php
tests/Feature/RegistrationPlatePipelineTest.php
tests/Feature/RegistrationVehicleTypesTest.php
tests/Feature/VehicleColorRemovalTest.php
tests/browser/optional-rfid-ui.mjs
tests/browser/plate-evidence-ui.mjs                            new
tests/browser/plate-phase2-ui.mjs
tests/browser/plate-pipeline-ui.mjs
tests/browser/vehicle-types-ui.mjs
tests/fixtures/ConfirmedActualPlate.php                       new
tests/fixtures/plate-evidence-ui.php                          new
tests/fixtures/plate-pipeline-ui.php
tests/fixtures/registration-phase3-worker.php
tests/fixtures/snapshot-worker.php
tests/fixtures/snapshots-ui.php
tests/js/plate-input.test.mjs
REGISTRY-PHASE3.md                                            new
```

## Installation and handoff

No migration or dependency change is needed. For a later authorized update, include the coordinated PHP, Blade, browser assets and Python helper/service files, refresh the application's normal caches/browser assets and restart the detector through its usual maintenance process. Nothing was deployed to the existing Windows installation.

No historical rows were bulk reclassified and no live legacy-data audit was performed. Existing unsupported/overlength plates, normalized collisions, earlier automatically classified identifiers or previous mistaken staff decisions may need a separately reviewed audit. This batch does not claim that such live records were counted or repaired. Historical profile/audit records are retained when a staff correction detaches an occurrence; a temporary occurrence itself has no plate-profile relationship.

The material detector limitation remains: no reliable automatic identifier-type classifier exists in the available model outputs. Expect more Review items for new OCR observations. An actual automatic classifier would require independent reliable visual/context evidence and separate validation, not a pattern or Registry-based shortcut.

Phase 1's passive purple/gray Registry lights, Campus State and independent filters, required new Owner Name, legacy edit compatibility and optional RFID remain. Phase 2's literal contract, exact identity/eligibility and partial list search remain. Existing staff/default attribution, historical classification, snapshots, occupancy chronology, lost-UID permanence and vehicle-type provenance are covered by the passing regression suites.

Stop after Phase 3. Wait for **Go Phase 4**.
