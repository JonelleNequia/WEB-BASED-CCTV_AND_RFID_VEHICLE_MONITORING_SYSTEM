# Automatic plate classification — remove blanket Review

9 November 2026, Asia/Manila. Focused follow-up authorized by the user's “ayusin mo”
after discussing why all camera OCR required confirmation. No applicable AGENTS.md
was found in the checkout or ancestors. Earlier Registry, OCR presentation, D4 and
D5A work remains. This does not advance deployment.

## Behavior and cause

The regression was reproduced before editing: an eligible untagged vehicle's clear
`395ISX` reading returned Review instead of Registered. Both
`plate_identifier.py::review_submission` and
`VisitorRecordService::applyPlateReading` unconditionally withheld actual plates.
This was the old Phase 3 policy, rather than an RFID requirement or recognition error.

The new operational policy is:

| Observation | Result |
| --- | --- |
| Accepted valid plate-region OCR and one exact eligible active registration at occurrence time | Registered, including vehicles without RFID |
| Accepted valid plate-region OCR without an eligible registration | Guest, with the literal plate |
| Missing/invalid/weak or conflicting OCR | Review; retain candidate, raw evidence and images |
| Explicit uncertain identifier kind, temporary/conduction/MV evidence, or an observed temporary-label cue | Review; not an accepted actual plate |
| Inactive registration, normalized collision, learned/fuzzy identity ambiguity or linked RFID restriction/conflict | Existing Review policy |
| Staff-confirmed plate, temporary identifier or no-plate decision followed by late OCR | Preserve the staff decision |

Review also retains the existing unknown-direction workflow; readable plate text
cannot supply a missing IN/OUT direction. Genuine movement anomaly handling remains.

The current models still **do not prove legal identifier type**. Automatic acceptance
now treats an otherwise clear plate-region reading as a plate unless explicit contrary
evidence is present. It does not wait for a type classifier on every occurrence.
Explicit OCR words such as `CONDUCTION STICKER`, `MV FILE` and `TEMPORARY PLATE`
are Review cues. Length, layout and Registry similarity are not temporary classifiers.
An unlabeled temporary identifier that looks like an ordinary plate can still be
accepted; this limitation is not solved by the current models or claimed away.

## Implementation and safeguards

- Python's existing callback interface now sends `plate_status: read` and literal
  plate text for accepted readings; uncertain results still carry a separate guess.
  Raw reads and original vote remain attached. Both initial and Left-zone callbacks
  already use this shared function.
- Laravel independently checks the plate contract, outer acceptance, confidence,
  inner vote consistency, explicit identifier evidence and temporary-label cues.
  The existing `.6` confidence floor is retained. Python's `.8` strong-single-read
  and `.6` character/layout majority thresholds are unchanged; model files are unchanged.
- Accepted OCR creates/uses the existing profile/matching/classification path.
  Learned or fuzzy aliases cannot replace the literal plate or establish registration.
  Different suggested profiles remain Review. Exact normalized Registry matching,
  eligibility, collision handling and registration-at-occurrence remain authoritative.
- The application records accepted evidence as detector-sourced, never staff-confirmed.
  Incoming protected staff metadata still cannot override a real staff decision.
- The existing canonical-event resolver and reconciliation transaction handle movement
  effects. Retrying an occurrence keeps its key, event, gate, time, images and counts.
  A reported RFID match without an authoritative scan link stays Review instead of
  creating another plate-derived movement or guessing a scan relationship.
  Equal plate text alone does not dismiss a separate occurrence as a duplicate.
  The crossing's geometric guard and external-key ownership/uniqueness remain.
- Existing queries and presentations consume the resulting occurrence classification:
  automatic Registered records stay out of Guests/list reports and remain in Activity
  Logs. Actual uncertainty still displays its candidate and reason.

No migration, configuration toggle, new category, model download, bulk relabel or live
history repair was introduced. Existing pending Review records and queued old uncertain
payloads are not silently promoted by deployment or page refresh. Staff can resolve
them through existing Review actions. New accepted observations use the new policy.

## Verification

All writes used a private source copy, SQLite fixtures, storage, synthetic accounts,
camera status and delivery state. Actual saved images from the earlier investigation
were read from its private evidence directory. No live HTTP ingestion, DB write,
service restart, physical device access or live configuration mutation was performed.

| Check | Result |
| --- | --- |
| Before-fix reproduction | Clear eligible untagged plate returned Review; the new automatic-classification regression failed as expected |
| Final relevant Laravel suite | **362 tests / 4,159 assertions passed**, PHP8.5.3, private SQLite/storage/config; includes the final RFID-link guard, plate/Review/Registry, Guests separation, activity classification, snapshots, RFID/fusion, optional-RFID and durable delivery cases |
| Python detector regression | **189 passed** with existing packaged arm64 Python and model inputs |
| Browser: Review and automatic classification | **54 passed**; automatic untagged Registered, unmatched Guest, no false plate Review cards/warnings, existing uncertain Review actions, Details, Print/CSV and narrow layout |
| Browser: plate pipeline | **26 passed**; exact normalization, corrections, filters and consistent occurrence classification |
| Browser: source availability | **16 passed**; placeholder/source-health correction preserved |
| D5A lifecycle | **31 passed** |
| Real HTTP delivery | Passed with four private PHP workers: one canonical event/scan/crossing, three receipts; lost ACK, concurrent retries, ownership conflict, writer-fence race and wrong-generation/auth checks |
| JavaScript units | **17 passed** |
| Syntax and preservation | PHP syntax and whitespace checks passed; source has no deletions; live `.env` and staged index unchanged |

Full-suite verification was attempted. The early run completed 884 tests with one
new test-fixture assertion failure: assigning IN to an already-IN event intentionally
does not invoke the existing direction-correction anomaly rule. The regression was
corrected to exercise UNKNOWN → IN and its subsequent OCR retry. A later full run hit
the private runner's 240-second limit under host load. Another was stopped before
refreshing the isolated copy for the final RFID-link guard. A clean final full-suite
pass is **not claimed**. The final relevant suite above passed after all application
changes; its broader run took 3 minutes 48 seconds under the same host load.

An actual saved-image OCR rerun also timed out under current host load (180 seconds;
then 150 seconds with fixture-only thread limits). Its earlier successful accuracy
results remain historical in `OCR-READABILITY-FIX.md`, not fresh verification for this
batch. Replaying the two saved analyzer votes, before their obsolete blanket type
marker, verifies future `AAC-1869` submissions become `read` at `.700` and `.789`.
This is policy verification using saved JSON, not a new inference/hardware result.
It does not modify the original observations.

The focused feature regressions cover automatic untagged Registered IN/OUT, unmatched
Guest and later registration, literal formats/leading zeros/order/lookalikes, low or
missing confidence, no text, invalid characters, vote disagreement, explicit temporary
and uncertain evidence, temporary-label cues, collisions/inactivity/category eligibility,
linked RFID conflict/restrictions, distinct same-plate occurrences, genuine anomaly
preservation, original images/context, and retries with no additional effects.

Existing Review fixtures now explicitly mark identifier-type uncertainty instead of
depending on the removed blanket rule. Legacy fuzzy-matcher tests reconstruct historical
state from withheld evidence so current automatic profiles do not contaminate those
fixtures. Their legacy matching assertions remain. Assertions that specifically required
every clear reading to be Review now assert automatic classification instead.

The real HTTP delivery fixture initially lacked a running-camera status. Its RFID read
therefore correctly used the independent offline/state fallback, with no authoritative
link to the subsequent camera crossing. Automatic OCR exposed that unsuitable one-event
fixture assumption. The fixture now explicitly models a running calibrated camera,
allowing the existing RFID buffer/crossing link to be exercised. No production resolver
was weakened to associate unrelated records by matching plate or timestamp.

Physical cameras/RTSP/RFID, native Windows/Intel, and installed-service acceptance remain
unverified. Browser tests use isolated local HTTP and synthetic media/status. No complete
deployment archive was rebuilt; existing D2/D3 packages do not include this correction.

## Changed files

Application/detector:

```text
app/Services/PlateEvidenceService.php
app/Services/VisitorRecordService.php
school-vehicle-monitoring-detector/plate_identifier.py
```

Tests/fixtures:

```text
tests/Feature/AutomaticPlateClassificationTest.php (new)
tests/Feature/ActivityLogClassificationTest.php
tests/Feature/GuestsRegisteredSeparationTest.php
tests/Feature/Phase5VisitorRecordsTest.php
tests/Feature/PlateEvidenceReviewTest.php
tests/Feature/PlateLiteralReconciliationTest.php
tests/Feature/PlateMatchingTest.php
tests/Feature/PlatePhaseTwoContractTest.php
tests/Feature/RegistrationPlatePipelineTest.php
tests/browser/plate-evidence-ui.mjs
tests/fixtures/plate-evidence-ui.php
tests/lifecycle/http_delivery.py
school-vehicle-monitoring-detector/tests/test_identifier_evidence.py
school-vehicle-monitoring-detector/tests/test_plate_contract.py
```

Documentation:

```text
OCR-AUTOMATIC-CLASSIFICATION.md (new)
OCR-READABILITY-FIX.md (policy-supersession note)
REGISTRY-PHASE3.md (policy-supersession note)
```

Private evidence pointer: `/tmp/philcst-auto-plate-workspace.txt`. This is test evidence,
not an installation backup. The live `.env` and staged index are checked separately;
active development services may continue their own database/log writes, so no assertion
of byte-for-byte live database immutability is made.

The running detector must load the changed Python module through the normal controlled
restart workflow before new camera observations use it. No live restart was performed.
Historical Review items remain unchanged. Stop after this fix; deployment stays paused.
