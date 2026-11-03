# Phase 1 — existing-field validation

Implemented after authorization of Phase 1 only. Existing security, gate
management, UI and runtime work remains in place. No new database columns,
fields, account types or filters were introduced.

## Behavior and compatibility

- Owner Name stays optional. Omission or a completely empty value is allowed;
  whitespace-only input is rejected before Laravel's trimming middleware can
  hide it. New or changed values must contain letters, allowing Unicode letters,
  combining marks, spaces, apostrophes, hyphens and periods. Single-word names
  and repeated owner names are allowed. Registry and visitor-review lengths
  remain 100 and 120 characters respectively for new/changed names.
- Per the user's compatibility decision, an unchanged existing organization
  name can be submitted during unrelated edits, including legacy punctuation,
  digits and length. A new/changed organization name must satisfy the same
  personal-name rule; there is no owner-type field to distinguish it. No
  existing names are rewritten by an upgrade or migration.
- Plates must have a meaningful existing normalized key. Visitor/profile
  paths enforce their existing storage limits: 20 key characters and 30
  formatted characters. Registry's 50-character limit remains. There is no
  new national plate-format requirement. Unchanged legacy values are exempt
  from newly introduced plate constraints on edit/verification paths.
- RFID normalization, separators, leading zeros, existing plate/tag
  uniqueness and category compatibility remain intact. Password, activation
  and permission policies are unchanged.
- A supplied camera ID must belong to its supplied gate. Camera-ID-only event
  ingestion now resolves its existing gate rather than indexing a missing
  field. Archived gates remain valid historical filters and invalid new-record
  destinations.
- Invalid nested Settings/calibration structures, direction arrays and malformed
  JSON metadata return validation errors instead of casts or silent replacement.
  New/changed camera source values respect the existing 255-character column;
  unchanged legacy sources remain editable alongside other settings.
- Optional manual/completion confidence no longer raises an undefined-key error;
  a genuine zero is retained. Existing ranges and upload checks remain enforced.
- Existing list/report filters validate scalar types, allowed choices, dates
  and date order. Browser list errors retain valid criteria and explain rejected
  ones; JSON receives 422 field errors. Invalid report/export requests return
  a recovery page instead of redirecting into a broader download.
- Forms explain name rules and preserve input. A blank optional custom vehicle
  type no longer incorrectly selects “Others” after a validation failure in the
  Registry drawers or standalone edit form.

## Files changed in this phase

This list excludes the earlier staged gate, security, UI and runtime changes.

```text
app/Http/Controllers/Api/CrossingIntegrationController.php
app/Http/Controllers/Api/FutureIntegrationController.php
app/Http/Controllers/Api/VisitorIntegrationController.php
app/Http/Controllers/GuestObservationController.php
app/Http/Controllers/VisitorController.php
app/Http/Middleware/CaptureNameInput.php (new)
app/Http/Middleware/ValidateListFilters.php (new)
app/Http/Requests/CompleteVehicleEventRequest.php
app/Http/Requests/SaveCalibrationRequest.php
app/Http/Requests/SaveSettingsRequest.php
app/Http/Requests/StoreGuestObservationRequest.php
app/Http/Requests/StoreVehicleEventRequest.php
app/Http/Requests/StoreVehicleRegistrationRequest.php
app/Http/Requests/UpdateGuestObservationRequest.php
app/Rules/CameraAtGate.php (new)
app/Rules/PersonalName.php (new)
app/Rules/PlateIdentifier.php (new)
app/Rules/ValidGate.php
app/Services/EventService.php
app/Services/VisitorReviewService.php
bootstrap/app.php
resources/views/errors/filters.blade.php (new)
resources/views/logs/index.blade.php
resources/views/partials/filter-errors.blade.php (new)
resources/views/registry/index.blade.php
resources/views/registry/partials/vehicle-fields.blade.php
resources/views/settings/tabs/manual.blade.php
resources/views/vehicle-registry/edit.blade.php
resources/views/visitors/index.blade.php
resources/views/visitors/partials/review.blade.php
routes/web.php
tests/Feature/ExistingFieldValidationTest.php (new)
tests/Feature/UiPhase3RegistryTest.php
tests/Feature/VisitorReviewTest.php
tests/browser/validation-ui.mjs (new)
PHASE1-VALIDATION.md (new)
```

The two existing test fixtures now supply valid newly entered personal names;
legacy organization compatibility has explicit separate coverage. No migration
was added or run against the working installation in this phase.

## Verification

Executed locally on macOS:

- `php artisan test --compact`: **406 passed, 3,694 assertions**, including
  39 new focused validation cases. Laravel tests use isolated SQLite databases.
- `node tests/browser/validation-ui.mjs`: **20 assertions passed** with installed
  headless Chrome. It provisions and removes its own temporary SQLite database,
  credentials, sessions, configuration directories and ephemeral PHP server;
  browser requests outside that server are blocked. It exercises rejected and
  corrected names in standalone edit and Add Vehicle, preserved plate/type/tag
  inputs, rejected list criteria, and export recovery.
- `npm run test:js`: **13 passed**.
- `npm run build`: passed (61 modules).
- PHP syntax: 25 changed/new non-Blade PHP files passed. Focused Pint checks,
  browser-script syntax and `git diff --check` passed.

Camera/reader hardware, physical traffic and the Windows runtime were not
tested. No deployment, external service mutation or live-record test writes
were performed. The four previously known broken historical references remain
untouched; this phase does not attempt data repair.

## Existing Windows installation

Phase 1 requires no data migration, reset, reseeding, credential changes or new
runtime dependency. During a normal backed-up application update, include the
new middleware/rules/views as well as modified source files. Clear/rebuild
application route/config/view caches using the installation's existing update
procedure and restart its PHP process as appropriate. Existing `.env`, SQLite
database, uploads and device configuration must be retained. Earlier pending
gate/security migrations remain governed by their own upgrade instructions.

## Handoff for the next authorized phase

Stop after Phase 1. Wait for **“Go Phase 2”** before removing active Vehicle Color
inputs, validation, rendering and exports. Preserve stored color columns/history
and accept deprecated integration color without persisting it in that phase.
Automatic search remains Phase 3; Activity Logs classification remains Phase 4.
The known scan-search handling of `0` and RFID-only classification issue are
deferred to those phases. Do not infer registration from readable plate text or
repair historical references by guessing relationships. Keep all previous gate,
security, UI, runtime and Phase 1 compatibility behavior.
