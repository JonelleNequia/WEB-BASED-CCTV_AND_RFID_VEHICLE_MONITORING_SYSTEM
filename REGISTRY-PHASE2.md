# Registry Phase 2 — literal plates, validation, exact identity and search

Implemented locally on 2026-11-05 (Asia/Manila), for Phase 2 of the current seven-phase plan. No applicable AGENTS.md was found in the repository or its ancestors. Phase 1's passive purple/gray registration lights, separate Campus State/filter, required new-registration Owner Name and optional RFID remain in place.

No live migration, reset/reseed, historical repair, device/configuration update, deployment, publication or push was performed. All mutation tests used private source copies, isolated SQLite databases/storage and private device paths. No dependency or migration change is required.

## Verified inconsistencies and fixes

| Path | Finding | Result |
|---|---|---|
| Registry request/shared service and legacy manual events | Some paths accepted identifiers longer than downstream 20-character machine columns. A display value with separators could also overflow those columns despite a valid compact key. | New/changed identifiers enforce the existing downstream limits. New machine-column writes use a bounded compact key; readable `plate_text` retains the occurrence display. Nothing is truncated into a different identity. |
| Unchanged legacy edit exception | `trim()` in the equality exception could hide a new trailing control character. | The exception now uses the same strict normalizer as comparison. Existing unchanged legacy values remain editable. |
| Guest duplicate fingerprints | A separate helper stripped arbitrary characters, allowing invalid legacy text to resemble a valid plate. | Fingerprints use `PlateNumber::key`; unsupported text cannot establish duplicate identity. |
| Exact registration matching | Filtering ineligible categories before counting normalized collisions could leave one apparently unique eligible result. | All exact candidates count toward ambiguity before eligibility is applied. Multiple keys use existing Review behavior; a sole ineligible record is not made eligible. |
| Activity Logs | RFID-only All Events and Alerts used raw plate `LIKE`, unlike other plate filters. | Plate portions use the shared separator-equivalent partial search. Tag UID search and existing event/date/gate/alert-type criteria remain. The Alerts regression was reproduced failing before its query fix. |
| Legacy detector `/events` OUT | Open-session lookup compared raw strings, so safe separator/case variants could miss the original entry. | Compare exact normalized keys, retaining the existing oldest-open-session selection. Partial, invalid and look-alike values cannot close that session. Historical session text is unchanged. |
| Browser manual fields | Uppercasing existed, but plate-specific validity/error recovery was inconsistent across Registry, Guests and Review. | A shared validator adds inline errors, paste/composition handling and required length/alphabet checks. Registry Save coordinates plate, owner and staff validity. Review Save plate respects the client guard before its existing AJAX handler. Server validation remains authoritative. |
| Detector readers/voting | The active OCR path rejected valid mixed layouts such as `A123BC`; discarded invalid reads were unavailable as text evidence. | Generic supported mixed layouts retain their literal token, use existing confidence/voting thresholds, and carry bounded raw diagnostics through fast/fallback readers and the real submission boundary. Invalid/uncertain results remain unreadable/Review evidence. |

Existing rules that already met the requirements were reused: safe separators, literal exact keys, familiar display grouping, profile suggestions separate from registration identity, occurrence-time registration eligibility, inactive/flagged and RFID-conflict restrictions, canonical-event correction/retry, occupancy chronology and historical classification.

## Plate contract

- Uppercase ASCII letters; normalize Unicode space separators to ordinary spaces and collapse/trim those spaces. Tabs, newlines, fullwidth characters, emojis, underscores, slashes and other unsupported characters are not stripped into identity.
- Comparison removes only ordinary spaces and ASCII hyphens after validating the whole identifier alphabet. Preserve every alphanumeric character, its order and leading zeros. No `O`/`0`, `I`/`1`, substring extraction or transposition fallback.
- `ABC 1234`, `ABC 123`, `123ABC`, `A123BC` and the additional `AB-00123` are accepted. The validator is generic rather than an enumeration of these formats.
- `395ISX` stays `395ISX`; it differs from `ISX395`. `AO0123` differs from `A00123`; `AI0123` differs from `A10123`.
- Familiar two/three-letter-first display layouts retain their existing space grouping in PHP/browser display and dash grouping in detector output. Those are separator-equivalent keys. Other literal layouts retain their order. Registration storage retains the normalized manual representation.
- New/changed plates: at most **20 letters/digits in the key**, at most **30 characters in formatted display**, plus the existing request/transport limit (Registry/manual event fields allow at most 50 input characters; Guest/Review UI inputs retain their existing 30-character limit). Machine `plate_number` fields receive a safe key or null, never a truncated identifier; `plate_text` remains the readable evidence.
- Unchanged historical Registry/manual-event values can use the existing legacy exception. No backfill or rewrite was run. A new plate cannot borrow that exception by adding a control character.
- Manual syntax still supports existing nonstandard identifiers, including numeric identifiers. OCR retains its existing minimum-five-character, mixed-letter/digit plausibility gate. These are input-quality policies separate from shared comparison normalization. Temporary-identifier classification belongs to Phase 3.

PHP `PlateNumber`, browser `PhilcstPlate`, and Python `plate_identifier` share a **27-case JSON corpus**, including terminal controls and boundary lengths. PHP/JS/Python compare the same literal keys. Python tests additionally exercise the reader/vote/handoff representation.

## Entry points and behavior preserved

Registry create/edit, including the shared drawer opened from Guests/profile links or RFID scan links, still converges on the existing registration request/service. Both enforce new plate limits; the service retains the existing registration lock and duplicate check. Failed saves preserve entered input and the remembered staff default; a valid untagged save creates no placeholder tag, assignment or movement. Existing legacy-owner edit compatibility remains.

Guests manual entry/correction and Review corrections reuse their server rules and shared key logic. Corrections and retries retain the same canonical event; they do not replay counts or create another movement. Invalid raw OCR text is never converted to a registered identity. Exact matching counts all normalized candidate collisions before category/status eligibility. Profile/fuzzy suggestions and partial list filters do not establish vehicle identity.

Plate-only Guests, Plate Profiles and All Events search fields uppercase manual input without applying registration-only completeness rules. Registry's mixed plate/owner/tag search remains a mixed search. Searches still combine with existing filters and distinguish literal order, leading zeros and look-alike characters. RFID Scans already used separator-aware plate search and was retained.

Raw OCR diagnostics are bounded to 20 reads per collector, 200 characters per read, with an explicit truncation marker, confidence and engine. Overlong raw text stays diagnostic evidence rather than a truncated plate candidate. A bounded untruncated best guess can be shown through the existing unreadable/Review path. No OCR model, allowlist, confidence threshold, detector movement rule, camera or reader assignment was changed.

## Verification

The private workspace is:

```text
/var/folders/zb/05jm3cld3yj8fsbb2cpscjlm0000gn/T/philcst-plate-phase2-xm8erygd/
```

Browser suites each create a fresh private database and random test credentials, serve a private local PHP process, block off-origin browser requests, and remove their temporary test databases. Dependency packages were reused read-only. Detector checks used local generated image fixtures and fake model/OCR outputs through actual reader, analyzer, voting and submission code; no physical camera or reader was invoked.

| Check | Result |
|---|---|
| Full Laravel suite | **649 tests / 6,398 assertions; zero failures, errors or skips** |
| New focused contract suite | 17 cases covering the shared contract, eight registration/ingestion/correction formats, exact identities, collisions, invalid evidence, combined searches, guest deduplication, edit/length rejection and legacy detector exit |
| JavaScript unit tests (`npm run test:js`) | **16 passed** |
| Full Python unittest discovery | **180 passed** |
| New plate browser suite | **41 checks passed** |
| Existing plate pipeline / literal regressions | **26 / 19 passed** |
| Registry status/owner / optional RFID | **58 / 40 passed** |
| Registration staff / validation | **37 / 21 passed** |
| Automatic filters / snapshot / vehicle type / menus-gates suites | **69 / 22 / 59 / 132 passed** |
| Browser total | **524 checks across 11 suites** |
| Production frontend build (`npm run build`) | **Passed, 61 modules**, private output only |
| Static checks | Changed PHP/Blade, JavaScript and Python syntax; focused new PHP files' Pint; `git diff --check` |

The full Laravel run includes existing permissions, inactive/flagged restrictions, RFID/plate conflicts, staff/default rollback, required-owner/legacy edits, optional RFID, literal-order reconciliation, historical classification, snapshots, lost UID permanence, type provenance, canonical movement and occupancy tests. Browser suites cover actual saves, automatic filters, inline paste/IME recovery, correction/retry and existing status-menu actions. IME coverage is synthetic browser composition events, not physical OS IME testing.

Screenshots were visually inspected for Registry/Guest/Review inline errors and literal search; existing Registry status tests also exercised desktop/narrow layouts. Synthetic screenshots are under:

```text
app/storage/app/ui-review/plate-phase2/
  registry-plate-error.png
  guests-plate-error.png
  review-plate-error.png
  literal-search.png
```

That `app/` directory is inside the private workspace, not the installation's storage.

## Changed files

Application and UI:

```text
app/Http/Controllers/AlertController.php
app/Http/Controllers/Api/FutureIntegrationController.php
app/Http/Controllers/GuestObservationController.php
app/Http/Controllers/VehicleEventController.php
app/Http/Requests/CompleteVehicleEventRequest.php
app/Http/Requests/StoreVehicleEventRequest.php
app/Http/Requests/StoreVehicleRegistrationRequest.php
app/Rules/PlateIdentifier.php
app/Services/EventService.php
app/Services/MovementReconciliationService.php
app/Services/RfidCameraFusionService.php
app/Services/VehicleRegistryService.php
app/Services/VisitorRecordService.php
app/Support/PlateNumber.php
public/css/app.css
public/js/plate-input.js
public/js/plate-validation.js                                 new
public/js/registration-staff.js
public/js/registry.js
public/js/visitor-review.js
resources/views/layouts/app.blade.php
resources/views/logs/tabs/events.blade.php
resources/views/vehicle-events/create.blade.php
resources/views/vehicle-events/show.blade.php
resources/views/vehicle-registry/edit.blade.php
resources/views/visitors/index.blade.php
resources/views/visitors/partials/plates.blade.php
school-vehicle-monitoring-detector/anpr.py
school-vehicle-monitoring-detector/detector_service.py
school-vehicle-monitoring-detector/plate_identifier.py          new
school-vehicle-monitoring-detector/plate_reader.py
school-vehicle-monitoring-detector/plate_voting.py
```

Tests and documentation:

```text
school-vehicle-monitoring-detector/tests/test_literal_plate_order.py
school-vehicle-monitoring-detector/tests/test_plate_contract.py      new
school-vehicle-monitoring-detector/tests/test_plate_reader.py
school-vehicle-monitoring-detector/tests/test_plate_voting.py
tests/Feature/PlatePhaseTwoContractTest.php                          new
tests/browser/plate-phase2-ui.mjs                                    new
tests/browser/plate-pipeline-ui.mjs
tests/browser/plate-regression-ui.mjs
tests/browser/registration-staff-ui.mjs
tests/browser/validation-ui.mjs
tests/fixtures/plate-contract.json                                  new
tests/fixtures/plate-phase2-ui.php                                  new
tests/js/plate-input.test.mjs
REGISTRY-PHASE2.md                                                  new
```

Older browser tests now expect invalid client input to remain inline instead of waiting for a blocked POST. The staff-default server-failure case uses a syntactically valid duplicate plate, so it still verifies the server rollback/default contract. Detector shape-only tests now accept whole mixed identifiers, retaining tests that invalid characters and look-alike substitutions cannot fabricate another identity.

## Limitations and next-phase notes

- SQLite/macOS and Chrome were exercised. Physical cameras/readers, actual OCR/model accuracy, Windows deployment and the production database engine were not verified. Hardware acceptance remains outstanding; local fixtures validate code behavior, not detection accuracy.
- No live-data inventory or repair was run. Historical unsupported characters, normalized keys over 20 characters, display text over 30 characters, and multiple vehicles sharing a normalized key may require a separate read-only audit and reviewed resolution. These are conditions demonstrated with isolated legacy fixtures, not claims of counted live defects. Historical values are preserved; collisions cannot be selected as unique registrations.
- Existing multi-action Review request validation checks a submitted nonempty `plate` before dispatching the chosen action (`VisitorController::review`). Consequently, an invalid value left in that input can still produce a plate error for another action. Action-specific validation/workflow changes remain for Phase 3; this batch validates Save plate and leaves the existing other-action behavior intact. That edge was identified from the current source, not verified on live records.
- No new schema/dependency upgrade is needed. A later installation update must carry the coordinated PHP/Blade/JS/CSS and Python helper files, refresh normal application/browser caches, and restart the detector through its existing maintenance procedure. None of those deployment operations was performed here.

Phase 2 is complete locally. Keep Phase 1's passive status dots, required new Owner Name, legacy edit compatibility and optional RFID. Preserve historical classification, staff attribution/default, exact literal identity, lost-UID permanence, snapshots, occupancy chronology and type provenance. Temporary identifier classification and Manual Review workflow decisions remain in Phase 3. Stop here and wait for **Go Phase 3**.
