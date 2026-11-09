# Readable OCR shown as “Plate unreadable” — focused fix

9 November 2026, Asia/Manila. This batch is separate from deployment. D5A and the
Registry work were preserved; no deployment subphase was advanced. No applicable
AGENTS.md was found in the checkout or ancestors. No live migration, reset,
historical backfill, model download/replacement, device/configuration change,
service stop, deployment, commit, staging, push or publication was performed.

## 1. Confirmed root cause and actual occurrence trace

Two **existing records read only** match the reported 8/8 and 7/7 messages:

| Record | Original occurrence (Asia/Manila) | Persisted OCR evidence | Previous presentation |
| --- | --- | --- | --- |
| Visitor #57, Gate 1, camera 1 | 9 Nov 2026, 08:34:46; `gate-1-track-3-1794184486731` | raw `AAC1869`; vote `AAC-1869`, status `read`, confidence `.789`, 8 matching crop reads out of 10 checked source frames | accepted plate NULL; classification Review; plate status `unreadable`; “Plate unreadable” in recent rows |
| Visitor #55, Gate 2, camera 2 | 9 Nov 2026, 08:34:15; `gate-2-track-35-1794184455777` | raw `AAC1869`; vote `AAC-1869`, status `read`, confidence `.700`, 7 matching crop reads out of 7 checked source frames | same incorrect unreadable presentation |

The stored original `best_guess` is `AAC-1869`; `ocr_plate_number` retains `AAC 1869`.
The text was **not lost by OCR, reordered, or rejected by a plate-format rule**.
The matching detector messages are also present at the inspected log positions
`storage/logs/detector-runtime.log:7618` and `:7608`. Log positions are observations,
not stable code references, because the running installation owns that log.

The observed path is:

1. `plate_reader.py::read_pass` finds plate regions within the original vehicle
   box and reads crops with the existing plate OCR model. Original vehicle/crop
   images remain linked to each occurrence. Inspection of the saved snapshots
   shows a vehicle image being displayed on a handheld screen; these are recorded
   demo observations, not proof of real campus traffic or independent live frames.
2. `read_with_plate_ocr` stores raw text and per-read confidence. Character
   probabilities use the existing mean/weakest-character aggregate, then crop
   sharpness adjusts voting weight by `0.6 + 0.4 * sharpness/(sharpness + 600)`.
   Recomputing from the saved crop scores/sharpness reproduces `.789` and `.700`.
   These are 0–1 scores, not percentages accidentally compared to 0–100.
3. `plate_voting.py::vote_plate` recognizes the literal text. Matching OCR reads
   establish agreement, not legal identifier type or statistical independence.
   A source frame can yield multiple crops; repeated screen images may also agree.
   The historical metadata lacks the full original burst/pixel identities needed
   to prove independence. No independent-evidence claim follows from “8 of 8”.
4. **The decisive policy boundary:**
   [plate_identifier.py](school-vehicle-monitoring-detector/plate_identifier.py:33)
   `review_submission` retains the vote and candidate but deliberately sends
   `plate_number: null`, `plate_status: unreadable`, and
   `identifier_evidence.kind: uncertain`. The installed models recognize text and
   generic regions, not actual plates versus temporary/conduction/MV identifiers.
   This existing Phase 3 safeguard applies even when every crop agrees.
5. `detector_service.py` submits that evidence, including original key, time,
   gate/camera, candidate and crop. D5A's durable operation identity/retry path is
   unchanged. `VisitorIntegrationController::storePlate` validates and passes the
   evidence to `VisitorRecordService::applyPlateReading`.
6. [VisitorRecordService.php](app/Services/VisitorRecordService.php:128) retains
   `ocr_plate_number` but leaves actual plate/key/profile empty, then classifies
   the occurrence as Review. Current OCR cannot claim a staff confirmation merely
   by supplying metadata. `.789` and `.700` exceed the existing `.6` review floor;
   low confidence is **not the blocker for these two original occurrences**.
7. **Two presentation defects:** the former `PlateEvidenceService::reviewReason`
   chose “OCR result requires review” from the outer `unreadable` status and appended
   the inner successful character-vote reason. It hid the real identifier-type
   blocker. The former [VisitorRecord::plateLabel](app/Models/VisitorRecord.php:272)
   displayed “Plate unreadable” solely from that same storage status, ignoring the
   retained candidate. Gate Monitor and Activity Logs inherited that label, while
   Review separately exposed the OCR read. This was inconsistent presentation of
   existing evidence, not proof that recognition had failed.

Registry exact matching/eligibility was not reached as an accepted actual-plate
decision. Readability alone must not turn the occurrence into Registered. Missing
RFID assignment did not cause these Review decisions. Staff confirmation continues
to use exact normalized matching, registration-at-occurrence and existing inactive/
flagged/conflict restrictions, including eligible vehicles without RFID.

## 2. Implemented behavior and before/after

| Evidence | Result after this fix |
| --- | --- |
| No usable text | “Plate unreadable”; empty candidate; reason identifies missing text |
| Recognized but uncertain text, such as #57/#55 | **“Needs review: AAC 1869”**, source “OCR candidate (unconfirmed)”, evidence confidence and **“Identifier type is unconfirmed…”**; actual plate remains NULL and classification remains Review |
| Conflicting/weak OCR | Candidate retained, Review retained, and reason identifies uncertain vote or confidence under the existing threshold |
| Unsupported characters | Literal candidate stays evidence; validation reason explains the contract failure; no character stripping into a Registry match |
| Accepted historical plate | Displays the accepted plate and its existing occurrence classification |
| Staff confirms an actual plate | Displays the confirmed literal plate; eligible unique exact Registry match is Registered, unmatched actual plate is Guest; established restrictions still apply |
| Staff confirms temporary/conduction/MV identifier | “Evidence only”/identifier label and text; no actual-plate matching or plate profile |
| Real Registry/RFID identity conflict | Existing specific conflict reason is preserved; a generic OCR explanation cannot replace it |

No historical classification, status field, image link or plate value was rewritten.
Older stored OCR explanations are interpreted read only from the same occurrence's
saved evidence for accurate display. A separate `accepted_plate_number` distinguishes
actual identity from the existing display-oriented `plate_number` field in unified
log payloads; `plate_candidate`, source, confidence label and review reason/code are
separate fields. The ingestion response also exposes the candidate separately from
its NULL actual plate. No new database status or migration was introduced.

New observations retain bounded latest evidence alongside the existing original and
late-read audit. An empty late read does not erase an earlier candidate; its source
and reason explicitly say it is **earlier**, with its original evidence confidence.
Staff-confirmed plate/temporary/no-plate decisions continue to win over late OCR.

Gate Monitor, Guests records, Review, Activity Logs/Details and existing reports use
the same occurrence candidate/accepted distinction. Print shows the Review reason;
CSV carries it in the existing Status column without changing column structure.
Guest reports label temporary identifiers as evidence only. The existing unreadable
filter now says “Unreadable / needs review”, reflecting the two cases already stored
under its criterion; query/count semantics remain unchanged.

Python diagnostics now say **OCR reads**, not independent “frame reads”, and report
crop reads and source-frame indices separately. Detector console output distinguishes
`needs_review`, candidate, true policy blocker and voting detail rather than calling
every withheld actual plate unreadable. Existing voting/confidence thresholds and
all models are unchanged. `395ISX`, leading zeros, O/0 and I/1 remain literal.

## 3. LIVE versus unavailable source

The inspected runtime said `service_running: true`, both cameras
`camera_running: false`, `source_type: none`, no capture time and “No camera added
for this gate.” That agrees with the source-unavailable overlay, not with healthy
camera footage. The latest activity was the **older occurrence** above.

The detector intentionally publishes a status JPEG when a source is unavailable
(`detector_service.py`, the `publish_status_frame(..., "Camera source unavailable", ...)`
branches). Previously `LivePlayer.hasPicture()` accepted a decoded MJPEG image,
`gate-monitor.js` treated any picture as Live, and transport badges also said Live.
Separately, the page's generic “Live” indicator meant a successful polling request.
These three distinct facts produced the contradiction.

The focused correction:

- Transport badges identify WebRTC/HLS/basic image stream without asserting source
  health. Gate Monitor's polling indicator says **Updates connected**.
- A decoded MJPEG placeholder/leftover still cannot establish a healthy source:
  source configuration and current detector/camera status must also agree. Explicit
  missing source displays **Source unavailable**; missing status displays unverified/
  unavailable state. Fresh independent WebRTC/HLS video can remain visible while
  OCR restarts; the fix does not blank a healthy independent stream indiscriminately.
- State polling bypasses cache and rejects a delayed older response. Source failure
  retains historical rows but cannot revive stale “checking” activity.
- The latest result is labeled **Latest recorded activity**, with its original
  Philippine date/time. Recent rows also include the date. Small wrapping adjustments
  keep these dates/candidates usable at narrow widths. There is no broad redesign.

## 4. Verification actually performed

All write tests ran in a private source copy, databases, media, caches and camera/
device configuration. The working database was inspected with read-only SQLite
connections; saved images were copied into a private evidence directory. No working
service was stopped or physical camera/reader opened for testing.

| Check | Result |
| --- | --- |
| Reproduce before fix | New regression against the original private source failed exactly at expected “Needs review: 395ISX” versus actual “Plate unreadable” |
| Full isolated Laravel | **855 tests, 8,976 assertions passed**, host PHP8.5.3, explicit test-process memory limit256M |
| Final focused PHP | **80 tests, 889 assertions passed**: candidate/empty/legacy/literal/weak/temporary/conflict, staff/late retry, snapshot ownership, Review, D5A receipts and historical behavior |
| Full Python detector regression | **188 passed** using existing packaged arm64 Python3.12 and existing model files; final policy and detector-handoff subsets also passed after diagnostic refinements |
| New source-health browser fixture | **16 passed**, actual player/monitor JS with synthetic local image/status: decoded placeholder, stale response, failed poll, historical date, independent-video continuity and narrow viewport |
| Real Laravel Review/Activity browser | **48 passed**, isolated Chrome: candidate/reason/confidence in Details, Print/CSV consistency, temporary evidence, literal correction, stale invalid input actions, retry and desktop/narrow layouts |
| Existing media authorization browser | **8 passed**; revoked sessions still stop/clear playback |
| D5A lifecycle / real HTTP delivery | **31 lifecycle fixtures passed**; real detector/TagPoster/outbox → four private PHP workers passed lost ACK, concurrent retry, context conflict and maintenance-race checks with one canonical effect |
| Network/stream fixtures | **17 run: 16 passed, 1 skipped** in the new private copy; skipped native Caddy executable fixture because that large runtime was not copied there; D4 native HTTPS acceptance is not implied |
| JavaScript / frontend | **17 JS tests passed**; private-copy Vite production build passed |
| Syntax/preservation | Relevant PHP/JS/Python parsed, diff whitespace check passed; staged index unchanged; `.env` matches the retained D5A baseline; no source deletions |

The tests include exact `395ISX` versus `ISX395`, O/0 and I/1, leading zeros,
eligible untagged Registered and unmatched Guest confirmation, uncertain/temporary
evidence with the same text, empty updates retaining prior evidence, literal staff
decisions surviving late OCR, image/key/gate/camera/time preservation and no duplicate
events or occupancy. Existing full-suite inactive/flagged/identity-conflict, accounts,
reports and lost-UID tests were not loosened. The existing Phase5 assertion was updated
for the intentionally corrected candidate label/Review view. A preexisting color-retention
fixture now freezes its clock so retries actually carry the same occurrence timestamp;
the ownership rule itself was not changed.

Earlier verification attempts exposed fixture issues: the first test-copy camera path
did not contain the existing test's required `framework/testing` suffix; corrected only
the private test environment. The host CLI's128M limit exhausted memory during the full
suite; reran at256M (existing packaged PHP templates remain512M). One existing concurrency
worker exceeded its15-second timeout under concurrent checks; the unchanged concurrency
subset reran **3 tests /58 assertions passed**, followed by the complete passing suite.
No application permissions, ownership checks or timeouts were weakened to hide failures.

### Recorded-image correctness, not just nonempty output

Used the **existing pinned** plate detector and ONNX OCR, with Python socket connections
blocked in the recorded-image process. No replacement models/downloads:

| Original saved evidence copied privately | Expected literal key | Measured result |
| --- | --- | --- |
| #55 crop | `AAC1869` | Correct `AAC-1869`, raw confidence1.000 |
| #57 crop | `AAC1869` | Correct `AAC-1869`, raw confidence1.000 |
| #55 snapshot + its original stored vehicle box | `AAC1869` | Correct candidate, weighted `.726`; one-read weak policy still withholds accepted text |
| #57 snapshot + its original stored vehicle box | `AAC1869` | Correct read, weighted `.840`; identifier type still requires Review |

Thus **2/2 crops and 2/2 saved snapshot/box candidates were correct** on these examples.
The `.726` result is deliberately not promoted merely because its string is correct
in this fixture. Saved snapshots do not recreate the original7/8-read burst; the
original burst decisions are evidenced by saved JSON/logs instead. An initial whole-frame
experiment found no region; rerunning with each occurrence's actual stored vehicle box
exercised the real intended reader path. No crop or box was guessed from Registry text.

Working/package model hashes matched:

- `license_plate_detector.pt`: `2d95861825bb4184404344c9cf809f40fd31dba785fe54e8ba5b9a3583789822`
- `cct_s_v2_global.onnx`: `384bbbd2cea3ef54761d3df70822ef3a349ee1a112aeafddbe0e3ba06bc6e47b`

These examples do not establish population accuracy, independent-frame confidence,
anti-replay detection or actual-versus-temporary model capability. Native Windows/
Intel, physical cameras/RTSP, live RFID and loaded-video checks are **NOT RUN**.
Source-unavailable status was tested with isolated synthetic transport/status, not
by changing the running installation's device configuration. No deployment artifact
was rebuilt; existing D2/D3 packages do not include this fix or D4/D5A.

## 5. Complete changed files for this focused batch

Relative to the captured OCR-fix starting workspace, preserving earlier changes:

```text
app/Http/Controllers/Api/VisitorIntegrationController.php
app/Http/Controllers/GateMonitorController.php
app/Http/Controllers/VehicleEventController.php
app/Models/VisitorRecord.php
app/Services/ListReportService.php
app/Services/PlateEvidenceService.php
app/Services/VisitorRecordService.php
app/Support/MovementRow.php
app/Support/VisitorRecordLog.php
public/css/app.css
public/js/activity-logs.js
public/js/gate-monitor.js
public/js/live-video.js
public/js/ui.js
resources/views/gates/index.blade.php
resources/views/logs/print.blade.php
resources/views/visitors/index.blade.php
resources/views/visitors/partials/records.blade.php
resources/views/visitors/partials/review.blade.php
school-vehicle-monitoring-detector/detector_service.py
school-vehicle-monitoring-detector/plate_identifier.py
school-vehicle-monitoring-detector/plate_reader.py
school-vehicle-monitoring-detector/plate_voting.py
school-vehicle-monitoring-detector/tests/test_identifier_evidence.py
tests/Feature/Phase5VisitorRecordsTest.php
tests/Feature/VehicleColorRemovalTest.php
tests/browser/plate-evidence-ui.mjs
```

Added:

```text
OCR-READABILITY-FIX.md
tests/Feature/OcrCandidatePresentationTest.php
tests/browser/ocr-camera-ui.mjs
```

There are **no migrations, schema changes or model-version changes**. Durable-delivery
envelopes, original event keys, receipt identities and the protected D5A state/controls
were not reformatted or reset. The only detector-service edit is related diagnostics.

## 6. Historical concerns and stopping point

The read-only inspection found 13 records with Review, `unreadable` storage status and
a nonempty OCR candidate. #55 and #57 are concrete examples. They do not need automatic
classification repair: rendering now identifies their candidate and real Review blocker
without rewriting them. Staff should inspect each original occurrence image and use
the existing actual-plate, temporary identifier or no-plate action. A Registry hit or
matching OCR burst alone is not authorization to backfill them as Registered.

If persistent historical reason/status cleanup or bulk decisions are later desired,
prepare a separate record-by-record proposal with original images, camera/time ownership,
audits, exact eligibility at occurrence and occupancy/duplicate implications. This batch
does not authorize or perform that repair. The working development installation may
continue writing on its own; byte-for-byte live DB preservation is not asserted.

Private evidence pointer: `/tmp/philcst-ocr-review-workspace.txt`. It identifies the
read-only observed JSON, copied images/hash observations, `recorded-ocr-measured.log`,
the failing-before/passing-after tests, Laravel JUnit, Python/browser/build/lifecycle
logs, desktop/narrow screenshots and preservation audit. Private images were not added
to source or shipped as demo data.

**Focused fix complete. Stop here; do not advance the next deployment subphase.**
