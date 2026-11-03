# Registration Phase 1: Registered by

This is the new registration-staff Phase 1 request, separate from the completed
validation, Vehicle Color, automatic-filtering and Activity Logs batches.
No later phase is included. No applicable AGENTS.md was found in the project
or its ancestor directories.

## Implemented behavior

The Add Vehicle drawer has a required, editable **Registered by** field for
the staff member processing registration. Owner Name remains a separate,
optional field. Existing Registry Add Vehicle, unknown-scan **Register this
tag**, and Visitors **Register this vehicle** links use the same drawer and
POST handler. Direct HTTP submissions and direct calls to
`VehicleRegistryService::register()` enforce staff-name validation too.
RFID inventory-only registration and tag replacement do not create a vehicle
registration and do not require or change this name.

Names allow Unicode letters/combining marks, space separators, straight/common
curly apostrophes, hyphens and periods, up to 100 Unicode code points. Single
names and repeated staff names are allowed. Digits, unsupported symbols,
emojis, controls, blank/space-only and punctuation-only values are rejected.
The existing PersonalName validator is reused without the legacy-owner
exception; the new wrapper also accepts the left curly apostrophe. Surrounding
and repeated ordinary space separators normalize to single spaces. Controls
are preserved until rejection, rather than being silently normalized away;
only this new field is excluded from the generic TrimStrings middleware.

The inline message updates on input/change, blur and composition completion.
An untouched blank field has no visible required error. Known-invalid values
disable saving, stay visible and editable, and expose an accessible inline
message/invalid state. Correcting the value clears the message and restores
saving. IME composition pauses new messages and blocks incomplete submission.
Server validation and existing server-error handling remain in place. No new
alerts, browser storage, background writes or polling were introduced.

## Storage and transaction boundary

`vehicles.registered_by` stores the submitted normalized name on each new
registration. The existing details drawer and standalone Edit Vehicle page
show it; older rows display **Not recorded**.

The existing `system_settings` table stores the installation-wide default:

```text
setting_key:   registration_staff_name
setting_value: last successfully registered staff name
```

No setting row is seeded or required initially; its missing value reads as an
empty string. After each successful new registration, an upsert changes the
default inside the SAME database transaction as the vehicle, tag assignment
and existing visitor-history transfer. A failed setting write rolls back the
new vehicle and auto-created tag as well. Outer transaction rollbacks naturally
roll back the setting too. The existing settings model is used directly, so
remembering a staff name does not rewrite device/runtime configuration or
start device services. There is no per-account default or in-memory cache.
The default survives server/PC restarts through the installation database and
is included in the existing whole-database backup mechanism.

The editable text is descriptive registration attribution; it does not replace
the signed-in account, actor, role, session or any existing audit information.
The new field is not writable through the settings configuration form.

## Compatibility and scope

Migration `2026_11_02_000001_add_registered_by_to_vehicles.php` adds one nullable
VARCHAR(100) column. It does not modify existing rows, names, identifiers,
relationships or configuration. Historical rows keep NULL; no name is guessed.
The column is nullable for compatibility, while application validation requires
it on new registrations.

Ordinary edits leave the recorded staff name and shared default unchanged,
including when an update request supplies `registered_by`. This phase exposes
recorded attribution as read-only on existing registrations. Older registrations
can still be edited without supplying it. Previous records are never renamed
when another registration changes the default.

Validation failures retain the entered staff value instead of substituting the
default. Cancelled forms do not persist anything. The mounted form is outside
automatic result replacements, so another registration or background list
refresh does not overwrite edited text. A freshly loaded new-registration
form reads the current database default.

The dormant demo-only VehicleRegistrySeeder supplies an explicit Demo Registrar
for its own newly created sample records so its service call remains valid;
it was not run against an installation. Installation provisioning remains
unchanged. Login/accounts/MFA/recovery, matching, RFID processing, vehicle types,
gate filters, Guest terminology, menus and snapshots were not changed. The
four known broken historical references were not repaired or modified.

## Verification

- `PYTHONDONTWRITEBYTECODE=1 php artisan test --compact`: **488 passed,
  4,360 assertions**, including **39** focused RegistrationStaffNameTest cases.
  These cover initial/missing defaults, required HTTP/service validation,
  Unicode and invalid input, normalization, repeated names, different signed-in
  admins sharing the same default, success/replacement, failed validation,
  forced SQLite write failure/transaction rollback, previous attribution,
  historical editing, and an actual additive schema upgrade over existing
  records/relationships/configuration in an isolated database.
- `node tests/browser/registration-staff-ui.mjs`: **37 checks passed** in
  installed headless Chrome. Includes initial blank state, blur/inline errors,
  disabled submit, Unicode/control/length cases, paste/autofill-style input,
  synthetic IME events, cancellation, normalized successful UI submission,
  details, editable prefills, server-error recovery, failure preserving the
  default, successive registrations, preservation of an open edited form
  through automatic list updates, and a real isolated PHP-process restart
  preserving the default. Includes a narrow viewport and script-error check.
- Existing browser regressions: validation **20**, automatic filters **69**,
  and Activity Logs classification **27** checks passed.
- `npm run test:js`: **13 passed**.
- `npm run build`: **passed**, 61 modules.
- PHP syntax checks for changed application/migration/fixture files, JavaScript
  syntax, focused Pint checks for new PHP files, and `git diff --check` passed.

Two existing tests exposed midnight-sensitive fixtures while the suite ran:
VehicleColorRemovalTest advances 20 minutes across an existing same-day match
rule; GateDirectionMatrixTest advances simulated passes while asserting today's
counts. Both now fix their test clock at local noon. Their assertions and
production matching/counting logic are unchanged. The final full run passed.

PHP tests use in-memory/temporary SQLite; browser runners create private
temporary databases, random credentials, isolated settings/device directories
and their own local PHP processes. The new runner also restarts only its own
process. No live database migration, reset, reseed, data mutation, deployment,
push, publication or external-service change was performed.

Actual Windows runtime/PC reboot, physical camera/RFID hardware, OS IME/autofill
and screen-reader behavior were not tested. IME/paste/autofill checks use DOM
events; a real PHP-process restart was tested. No automated failure remains.

## Update steps for the existing Windows installation

1. Use the installation's existing backup procedure before updating; retain
   the database, `.env`/APP_KEY, uploads/storage, device configuration and models.
2. During the normal maintenance window, stop the application writers/services
   and copy the reviewed PHP, Blade, public JavaScript and migration files.
   Include the new rule and `public/js/registration-staff.js`. No new dependency
   or detector change is required. Include the normal production frontend build
   artifacts in the application update.
3. From the application directory, use the installation's configured PHP to
   inspect `artisan migrate:status`, then run `artisan migrate --force`.
   This phase adds only the nullable Registered by column. Any still-pending
   migrations from earlier gate/security batches follow their separate reports.
4. Clear/rebuild application caches using the existing update procedure, restart
   the application and refresh the browser. The existing Windows updater already
   performs migrations/cache rebuilding as part of its ordinary release update.
   For a manual update, do not rerun seeders, reset/migrate:fresh, overwrite the
   database or regenerate APP_KEY. Do not roll back the new column after actual
   registrations have stored staff attribution.
5. Check an older registration remains readable/editable. If no successful
   post-upgrade registration has happened, Registered by starts blank. The next
   real successful registration supplies the installation default automatically.

These are handoff steps; the live installation was not upgraded in this task.

## Changed files in this phase

Some files already contain preserved changes from earlier batches. No files
were staged, committed or reset by this phase.

```text
README.md
REGISTRATION-PHASE1.md (new)
app/Http/Controllers/VehicleRegistryController.php
app/Http/Requests/StoreVehicleRegistrationRequest.php
app/Models/Vehicle.php
app/Rules/RegistrationStaffName.php (new)
app/Services/VehicleRegistryService.php
bootstrap/app.php
database/migrations/2026_11_02_000001_add_registered_by_to_vehicles.php (new)
database/seeders/VehicleRegistrySeeder.php
public/js/registration-staff.js (new)
public/js/registry.js
resources/views/registry/tabs/vehicles.blade.php
resources/views/vehicle-registry/edit.blade.php
tests/Feature/RegistrationStaffNameTest.php (new)
tests/Feature/ActivityLogClassificationTest.php
tests/Feature/DirectRfidRegistryTest.php
tests/Feature/ExistingFieldValidationTest.php
tests/Feature/GateDirectionMatrixTest.php
tests/Feature/Phase2GuestPassModelTest.php
tests/Feature/Phase4VisitorCategoriesTest.php
tests/Feature/Phase6VisitorRankingTest.php
tests/Feature/UiPhase3RegistryTest.php
tests/Feature/VehicleColorRemovalTest.php
tests/Feature/VehicleRegistryTest.php
tests/browser/registration-staff-ui.mjs (new)
tests/browser/validation-ui.mjs
tests/fixtures/auto-filters-ui.php
tests/fixtures/classification-ui.php
```

Existing test registrations now explicitly supply test-only staff attribution;
historical fixtures built directly as models remain nullable as appropriate.

## HANDOFF FOR PHASE 2

Registration-staff Phase 1 is complete. The API/form field is `registered_by`,
the immutable registration snapshot is `vehicles.registered_by`, and the shared
default is `system_settings.registration_staff_name`. Apply the additive
migration during the installation update, not as a test against live data.
Keep server-side validation and the registration/default write in one transaction.
Preserve all earlier security, gate management, validation, color removal,
automatic filtering and Registered-status fixes, unrelated worktree/index edits,
historical relationships and the four known broken references. No later-phase
requirements were implemented or assumed. Wait for **Go Phase 2**.
