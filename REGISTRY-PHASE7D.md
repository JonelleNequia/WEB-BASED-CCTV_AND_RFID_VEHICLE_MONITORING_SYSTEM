# Phase 7D — Printed RFID sticker number

Implemented locally on 2026-11-07. Scope: Phase 7D only. Applicable source paths and ancestors contained no `AGENTS.md`. The Phase 7C handoff and current tag registration, inventory, selectors, reports, reader listener, assignment/replacement, lost-UID handling and tests were inspected before editing. Pre-existing Phase 7A–7C changes were preserved.

## Implemented behavior

Registry → RFID Tags now offers **Add RFID Tag** alongside the existing **Register Tags** bulk workflow. The new drawer has two separate required fields:

| Field | Storage and purpose |
| --- | --- |
| Tag number | Exact printed digits in existing `vehicle_rfid_tags.display_number`; leading zeros are retained |
| Electronic RFID UID | Existing `uid` / `tag_uid` fields and normalization; used for reader identity, assignment and lost-UID enforcement |

For example, printed `000123` and electronic UID `0000EPC789` remain separate. The existing numeric `tag_number` is **123**, retaining numeric ordering and the existing unique-number constraint. No existing tag IDs, numeric numbers, UIDs, assignments or stored labels are changed.

New printed numbers follow the existing positive whole-number range **1–999999**, using ASCII digits only. Leading-zero representations can occupy up to **20 digits**, the existing display field's storage limit. Zero, blank input, signs, decimal/exponent notation, separators, letters, unsupported characters and oversized values are rejected. An oversized paste is retained for correction rather than truncated to a different number.

The new `RfidTagNumber` rule is enforced by both existing HTTP aliases and `VehicleRegistryService::registerRfidTag()`. A direct service call cannot bypass printed-number validation. If both numeric and printed fields are supplied, they must describe the same number. Request errors use the visible Tag number / Electronic RFID UID labels.

### Uniqueness and compatibility

- The existing numeric uniqueness rule remains authoritative: `000123`, `00123` and `123` cannot enroll separate tags. New manual registration stores the literal display text and its numeric equivalent together.
- Existing numeric inventory numbers and existing numeric display labels are reserved, including older display labels whose numeric inventory value differs. Historical labels are read as stored; no backfill or automatic relabel is performed.
- Tag creation uses the existing `PlateProcessingLock` transaction, and the existing numeric/display unique indexes remain in place. No new schema or lock mechanism was introduced; a dedicated new concurrent-reader/hardware test was not performed.
- Legacy callers posting `tag_number` retain their contract. Bulk scanning and Add Vehicle's existing automatic enrollment continue using `auto_number`, leaving `display_number` null when no printed value is supplied.
- Automatic numbering retains the existing maximum-plus-one convention and skips numeric display labels already reserved by legacy data. The server returns the next number for bulk feedback. At 999999, automatic enrollment stops with a clear message; staff can use Add RFID Tag to choose an unused number within the existing range. It does not silently exceed the range or renumber inventory.

### Admin experience and display

The drawer provides associated inline errors, keyboard focus, required-field gating, paste and IME recovery, and retained values after validation/save failure. Server failures reopen the drawer with the appropriate field error. Capturing a UID through the existing UHF listener fills **only UID**, stops after one read, and waits for Save. A keyboard reader's Enter terminator also does not enroll a tag automatically. The separate bulk drawer retains its deliberate scan-and-save behavior.

`RfidTag::number_label` presents `display_number` with the old numeric value as fallback. The same label now appears in inventory, available-tag selectors, Vehicles, current/previous-tag Details, Edit/Replace Tag, confirmation/feedback messages, and Registry Print/CSV. JSON retains numeric `tag_number` and adds the display label without replacing UID. Existing report columns, ordering and filters remain unchanged. Vehicle list search also includes the printed label as tag text; this remains partial list search, not RFID identity matching.

Bulk registration remains available. Existing nonnumeric historical display labels remain readable even though new number entry uses the established numeric format. The updated tag script follows the app's file-modification versioning convention for browser cache refresh.

## Verification

All mutation checks ran in a private source copy, temporary SQLite databases, isolated storage/configuration and synthetic accounts/devices. The browser reader endpoint was mocked with synthetic UID responses; no physical reader was contacted for this verification. No live migration, reset/reseed, data repair, device modification, deployment, publication or push occurred.

Before implementation, the new tests reproduced the absent manual display-number path and loss of the requested printed-label presentation. Final checks:

| Check | Result |
| --- | --- |
| Full Laravel suite | **761 tests / 8,228 assertions passed**, no failures or skips |
| New `RfidStickerNumberTest` | **28 tests / 301 assertions passed**, included above |
| Final focused sticker/legacy registry checks | **32 tests / 322 assertions passed** |
| New `sticker-tags-ui.mjs` | **52 browser checks passed** |
| Existing `registry-status-owner-ui.mjs` | **58 browser checks passed** |
| Existing `auto-filters-ui.mjs` | **69 browser checks passed** |
| Existing `optional-rfid-ui.mjs` | **43 browser checks passed** |
| Browser total | **222 checks passed** |
| `npm run test:js` | **17 tests passed** |
| `npm run build` | Passed, **61 modules** transformed |
| PHP/JS syntax, new PHP files' Pint check, `git diff --check` | Passed |

Verified behavior includes:

- Literal leading-zero storage/display independent of normalized UID, lower/upper range boundaries and a 20-digit padded value. Both tag-creation HTTP aliases and direct service calls reject invalid inputs without creating tags, vehicles, assignments or scans.
- Canonical numeric duplicates with different padding, legacy-number collisions, preserved older display labels, mismatched numeric/printed payloads, duplicate UIDs, retained form values and transaction rollback on a synthetic save failure.
- Existing automatic/legacy creation, next-number feedback and exhaustion, plus guard/anonymous rejection of unauthorized enrollment.
- Assignment of a manually numbered tag, electronic-UID lookup/ingestion, replacement with another manually numbered tag, current/previous labels and preserved history. Marking the old UID lost blocks its reactivation, enrollment under another printed number, and further movement effects. The printed number itself does not become an unavailable electronic UID.
- Browser invalid input, paste length, IME composition, error recovery, keyboard UID capture, reader-filled UID without enrollment, explicit Save, bulk scanning, duplicate feedback and retry, selectors, Details, replacement, and loss feedback.
- Filtered RFID Print/CSV membership and literal number/UID content; preserved staff-name default, optional No RFID assigned, required new Owner Name, status indicator and independent Registry filters. The full Laravel suite retains the earlier Guests separation, Activity Logs crop/time and Dashboard regressions.

The new drawer was visually inspected at **1366 px**, **390 px** and **320 px**. Both inputs, inline errors and actions fit the narrow viewport. Print preview was checked in Chrome; no new print layout or PDF generator was introduced.

Private test workspace/artifacts:

```text
/var/folders/zb/05jm3cld3yj8fsbb2cpscjlm0000gn/T/philcst-sticker-tags-phase7d-pj9hz42p/
  laravel-final.xml
  check-sticker-tags-ui.mjs.txt
  check-registry-status-owner-ui.mjs.txt
  check-auto-filters-ui.mjs.txt
  check-optional-rfid-ui.mjs.txt
  app/storage/app/ui-review/sticker-tags/
    add-tag-desktop.png
    add-tag-390.png
    add-tag-320.png
```

## Changed files in this phase

```text
app/Http/Controllers/VehicleRegistryController.php
app/Models/RfidTag.php
app/Rules/RfidTagNumber.php                             new
app/Services/ListReportService.php
app/Services/VehicleRegistryService.php
public/js/registry-tags.js
public/js/registry.js
resources/views/registry/index.blade.php
resources/views/registry/partials/add-tag-drawer.blade.php new
resources/views/registry/partials/register-tag-drawer.blade.php
resources/views/registry/partials/tag-picker.blade.php
resources/views/registry/tabs/tags.blade.php
resources/views/registry/tabs/vehicles.blade.php
resources/views/vehicle-registry/edit.blade.php
tests/Feature/RfidStickerNumberTest.php                 new
tests/browser/sticker-tags-ui.mjs                       new
tests/fixtures/sticker-tags-ui.php                      new
REGISTRY-PHASE7D.md                                    new
```

No migration, schema/dependency update, reseed, existing-data correction or device configuration update is required. A later authorized Windows installation update should include all coordinated PHP, Blade and public JavaScript files and refresh ordinary application/view/browser caches. No external installation was updated.

## Limitations and handoff

Verified on macOS/Chrome and SQLite with isolated fixtures. Windows browsers, other database engines, physical RFID readers/stickers, live reader timing and production-volume performance remain unverified. UID ingestion was tested through real application services, and browser reader capture through synthetic responses; neither establishes physical hardware accuracy. Detector/Python implementation files were unchanged.

No unresolved Phase 7D implementation blocker. Existing inventory was not edited to infer sticker values; adding a printed number to an already-enrolled UID would require a separately scoped existing-tag edit workflow. The pre-existing legacy Activity Logs direction-filter issue documented in Phase 7C remains outside this task.

**Stop and wait for Go Phase 7E.** Phase 7E covers optional manual Guest plate validation, Quick Manual Log field cleanup and the final cross-page regression check. Preserve numeric sticker uniqueness, literal printed labels, UID-only identity and permanent lost UID handling, along with all earlier registration/plate/Review/history constraints.
