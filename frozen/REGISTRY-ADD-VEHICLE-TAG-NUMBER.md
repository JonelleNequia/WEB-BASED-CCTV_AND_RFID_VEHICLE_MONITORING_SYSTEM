# Add Vehicle — manual RFID tag number

Implemented 2026-11-07. This follow-up covers Add Vehicle only. No applicable AGENTS.md was found. The user confirmed that Tag number is required **only when assigning RFID**; registration without RFID remains supported.

## Behavior

- Removed `(optional)` from the RFID step heading.
- Added a manually entered **Tag number**, separate from the electronic UID. Selecting inventory or reading a UID does not autofill this field.
- New tag numbers reuse `RfidTagNumber`: positive ASCII digits, numeric value 1–999999, at most 20 digits including preserved leading zeros. New UIDs use this number instead of automatic numbering.
- An existing inventory tag requires its saved number exactly. Existing legacy labels such as `G-01` are accepted only when confirming that same tag. This exception cannot create a new UID with an invalid number or relabel another tag. Existing numbers/labels are not overwritten.
- No RFID and a blank Tag number remain valid. A number alone cannot create a placeholder assignment.
- Client validation supplies associated inline errors, disables Save while invalid or checking the UID, preserves pasted text/leading zeros, handles IME and change events, and clears errors on correction. Stale UID responses cannot replace a newer choice. Server errors retain the form and number for correction.
- Request and shared registration service both enforce the number. Inventory uniqueness and assignment execute inside the existing `PlateProcessingLock` transaction. Failures leave no new vehicle/tag assignment and do not change the remembered staff name. Conflicting selected ID/scanned UID is rejected.
- RFID inventory operations and Replace Tag retain their existing workflows; new registration is still plate-based when RFID is absent. Registration creates no movement.

## Changed application files

```text
app/Http/Requests/StoreVehicleRegistrationRequest.php
app/Rules/RfidTagNumber.php
app/Services/VehicleRegistryService.php
public/js/registration-staff.js
public/js/registry.js
resources/views/registry/partials/tag-picker.blade.php
resources/views/registry/tabs/vehicles.blade.php
```

Added `tests/Feature/VehicleRegistrationTagNumberTest.php` (**25 tests / 231 assertions**). Expanded `tests/browser/registry-status-owner-ui.mjs` for conditional required status, invalid values, paste/change/IME recovery, new and existing tag saves, legacy labels, server-error recovery and narrow layouts. Registration staff/validation browser submissions and existing Laravel/browser/concurrency fixtures now explicitly supply their tag numbers instead of relying on the retired registration auto-number behavior.

## Verification

All database mutations and browser saves used a private application copy, isolated SQLite/storage/device configuration, guarded synthetic fixtures, and blocked off-origin browser requests.

| Check | Result |
| --- | --- |
| Full Laravel suite | **814 tests / 8,678 assertions passed**, no skips |
| Registry status/owner and new tag-number browser checks | **83 passed** |
| Registration staff browser checks | **37 passed** |
| Validation browser checks | **21 passed** |
| RFID sticker-number browser checks | **52 passed** |
| Optional RFID browser checks | **43 passed** |
| Automatic-filter browser checks | **69 passed** |
| Snapshot browser checks | **22 passed** |
| Passing browser total | **327 checks across seven suites** |
| JavaScript tests | **17 passed** |
| Frontend build | Passed, 61 modules |
| PHP/JS syntax, focused Pint check, diff whitespace | Passed |

The new field and inline error were visually inspected at 390 px and 320 px. Desktop browser checks cover successful saves and the existing status/owner controls.

An additional older `classification-ui.mjs` suite fails at its assumption that readable detector text is already an Unregistered Guest. It fails at the **same assertion on HEAD before this change**, verified by restoring the original application files/fixture only in the private copy and restoring the working source afterward. Its fixture predates the current identifier-confirmation policy in `PlateEvidenceService::observe()` / `reviewReason()`. Classification rules were not changed or suppressed to make this old test pass. Baseline log: `check-classification-baseline.txt` in the private workspace below.

```text
/var/folders/zb/05jm3cld3yj8fsbb2cpscjlm0000gn/T/philcst-manual-forms-phase7e-g_n4be0k/
  check-*.txt
  app/storage/app/ui-review/registry-status-owner/add-tag-number-390.png
  app/storage/app/ui-review/registry-status-owner/add-tag-number-320.png
/tmp/philcst-add-vehicle-tag-final.xml
```

No migration or database backfill is required. No live records, device settings, deployment, publication or push was performed. Existing unnumbered inventory records are not silently numbered by this form; they need a separate inventory review. API/service callers creating a vehicle with RFID now need `display_number`, matching the form's Tag number; untagged calls may omit it. Other inventory/Replace Tag APIs keep their existing contract.

Verified on macOS/Chrome and SQLite. Native Windows behavior, physical RFID readers/cameras and other database engines remain unverified. Reader checks used fixtures, not hardware. No further phase or unrelated repair was started.
