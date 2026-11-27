# Registry Phase 1 — status controls and required new-registration owner

## UI correction — passive status light (2026-11-05)

This correction supersedes the status-column/buttons described in the original Phase 1 report below. The current Vehicles table has a narrow, visually unlabeled first column immediately before Plate. Each row contains one passive 8 px circular light: Active uses the existing `--purple` token, Inactive uses the existing gray `--text-muted` token. Screen-reader text supplies Active/Inactive; the decorative dot is aria-hidden and has no button/link/form/tab stop.

The visible Registration Status header and green/power controls were removed. Activate/Deactivate remain in the existing three-dot menu with the same confirmation, permissions, POST action and feedback. Campus State and both existing filters remain. The table still has nine aligned columns: the light replaces the removed status column. Empty results already use the shared standalone empty-state component outside the table, so no colspan is required or changed.

Only these six files changed in this correction:

```text
resources/views/registry/tabs/vehicles.blade.php
resources/views/components/vehicle-registration-status.blade.php
public/css/app.css
tests/Feature/RegistryStatusOwnerTest.php
tests/browser/registry-status-owner-ui.mjs
REGISTRY-PHASE1.md
```

Verification used a fresh private source copy and isolated SQLite/storage/device paths. Relevant Laravel suites passed **58 tests / 501 assertions**. Chrome checks passed **58 Registry checks**, **69 automatic-filter checks**, and **132 archived-gate/row-menu checks** (**259 total**). They cover computed colors, dot size and first-cell placement, nine-column alignment, accessible names, absence of old controls, menu confirmation/cancel/activation/deactivation, status-label refresh, combined automatic filters, Reset and empty results. The existing owner/untagged/legacy checks in the Registry browser suite also remain green. Production Vite build passed (61 modules), as did PHP/JavaScript syntax, the edited test's Pint check and whitespace checks.

Screenshots were visually inspected at 1366 px, 768 px and 390 px. Narrow layouts preserve readable filters and horizontal table scrolling; the dot stays immediately before Plate. Private artifacts are in:

```text
/var/folders/zb/05jm3cld3yj8fsbb2cpscjlm0000gn/T/philcst-registry-dot-eux3ujjz/app/storage/app/ui-review/registry-status-owner/
  registry-desktop.png
  registry-tablet.png
  registry-mobile.png
```

No backend, Owner Name rules/forms, permissions, live records, devices or migrations changed. Hardware/Windows deployment was not exercised. Stop after this correction and wait for **Go Phase 2**.

## Original Phase 1 implementation and verification

The following records the preceding implementation; the UI correction above is authoritative for the current table status presentation.

Implemented locally on 2026-11-05 (Asia/Manila). Scope: Vehicle Registry status presentation/controls and Owner Name validation. No applicable AGENTS.md was found in the repository or ancestors. Existing unrelated working-tree changes were retained. No migration, backfill, reset/reseed, live data/device change, deployment or push was performed.

## Behavior

Vehicles now show **Registration Status** and **Campus State** in separate columns. Active is a green button; Inactive is a black circular button with a visible adjacent label. Native submit buttons expose current status, the available action and the plate through accessible names. Enter/Space activate the existing confirmation workflow. Existing admin authorization, CSRF, POST status action and success feedback are reused. The existing action-menu entries remain available.

The status service still writes only `status`; recorded campus state, movement timestamps, tag assignments and historical records are preserved. An inactive vehicle can therefore display its recorded Inside state. The vehicle details drawer also distinguishes the two values. Registration Status (`status`) and Campus State (`state`) remain independent automatic filters. At narrower widths filter controls wrap into readable rows and the table scrolls horizontally within its card.

New registrations require Owner Name in both `StoreVehicleRegistrationRequest` and the shared `VehicleRegistryService::register()` method, before inventory writes. `RegistrationOwnerName` reuses `PersonalName`, requires letters, accepts single-word/Unicode names, apostrophes, hyphens and periods, and enforces 100 characters. Examples verified: `Ma. José Dela Cruz`, `O’Connor`, `Anne-Marie`, `李`, and combining-accent names. Space separators are normalized. Empty/whitespace-only values, digits, punctuation-only values, emojis, unsupported symbols, controls and excessive lengths are rejected. Request middleware remembers only offending field names so TrimStrings cannot hide a control character; it does not save the submitted name elsewhere.

Owner Name and Registered by remain distinct. The client shows an accessible inline owner error, retains entered text, clears it after correction, handles paste/autofill/change and synthetic IME composition, and coordinates one Save-button validity state across the owner, staff and existing native required inputs. An incomplete or invalid name cannot be enabled by correcting the other name. Server-side plate, category, tag and eligibility validation remain authoritative.

The no-tag choice reads **No RFID assigned**. Saving with blank RFID is still supported, including with automatic tag-registration enabled: no placeholder tag, assignment or movement is created.

Edit requests retain the existing legacy policy: blank Owner Name and unchanged legacy organization/long names remain readable and editable; changed values use the existing name rules. No historical owners or staff attribution were backfilled. Failed validation, inventory/vehicle save failure and failed staff-default persistence leave the remembered default unchanged under the existing transaction.

## Registration entry-point trace

All application vehicle creation paths converge on:

`POST /vehicle-registry` → `VehicleRegistryController::store()` → `StoreVehicleRegistrationRequest` → `VehicleRegistryService::register()`.

The same Add Vehicle drawer is used by:

- Registry → Vehicles → Add Vehicle.
- Guests Plate Profiles/profile and Dashboard frequent-plate links with `register_plate`; these carry the existing explicit history-transfer context.
- Guests unknown-tag links, Activity Logs RFID Scans/Alerts and alert-summary action URLs with `register_tag`.
- Legacy Registry links that redirect into the same workspace.

These links do not create a vehicle merely by opening the form. Direct shared-service calls also validate a new owner. RFID inventory creation, later assignment/replacement, status changes and editing use their existing separate workflows. Ordinary registration still does not replay or relabel earlier Guest occurrences; the existing explicit transfer contract remains covered by regressions.

## Verification

All mutation checks used private source copies, SQLite databases/storage and camera/device paths. Browser fixtures created random test credentials, served only a private local PHP process and blocked requests outside the fixture origin. No installation database or hardware was used.

| Check | Result |
|---|---|
| Full Laravel suite | **631 tests, 6,063 assertions; zero failures/errors/skips** |
| New Registry regression cases | 34 cases: request/service rejection, valid names, aliases, controls, untagged saves, form/save rollback, entry points, legacy edits, independent filters, permissions and status preservation |
| New Registry browser suite | **46 checks passed** |
| Existing automatic filters | 69 checks passed |
| Existing registration staff | 37 checks passed |
| Existing validation UI | 21 checks passed |
| Existing optional RFID | 40 checks passed |
| Existing vehicle types | 59 checks passed |
| Existing archived gates/Guest labels/row menus | 132 checks passed |
| Existing plate pipeline / literal regression / snapshots | 26 / 19 / 22 checks passed |
| Browser total | **471 checks across 10 suites** |
| JavaScript units | 15 passed |
| Production Vite build | Passed, 61 modules, in private copy |
| Static checks | Changed PHP/JS syntax, new PHP files' Pint and whitespace checks |

Existing successful-registration test fixtures now supply a valid owner. Historical organization/overlength/blank fixtures are created as historical model data, retaining their edit assertions. Tests that previously required ownerless creation or hid Inside for an inactive vehicle now assert the newly authorized behavior. One old row-menu switching interaction uses keyboard activation because the taller status rows can put its target behind the preceding popup; the same single-open-menu assertion and all clipping/focus/fallback checks pass. No row-menu implementation was changed.

Rendered screenshots were reviewed at 1366 px desktop, 768 px tablet and 390 px mobile. Mobile controls were expanded after initial review found cramped selections. Private artifacts:

```text
/var/folders/zb/05jm3cld3yj8fsbb2cpscjlm0000gn/T/philcst-registry-phase1-mhbdgspy/app/storage/app/ui-review/registry-status-owner/
  registry-desktop.png
  registry-tablet.png
  registry-mobile.png
  add-valid-owner.png
```

These are synthetic fixtures. No physical RFID reader/camera, actual OS IME, Windows installation, production database engine or deployment was verified. Python/detector behavior was unchanged and its suite was not rerun. Existing snapshot, movement, lost-UID, staff/default, literal plate, eligibility, type-provenance, gate-history and account tests passed in the Laravel suite.

## Changed files

Application files:

```text
app/Http/Middleware/CaptureNameInput.php
app/Http/Requests/StoreVehicleRegistrationRequest.php
app/Rules/RegistrationOwnerName.php                         new
app/Services/VehicleRegistryService.php
public/css/app.css
public/js/registration-staff.js
public/js/registry.js
resources/views/components/vehicle-registration-status.blade.php  new
resources/views/registry/partials/tag-picker.blade.php
resources/views/registry/partials/vehicle-fields.blade.php
resources/views/registry/tabs/vehicles.blade.php
resources/views/vehicle-registry/edit.blade.php
```

Verification and documentation:

```text
tests/Feature/RegistryStatusOwnerTest.php                    new
tests/fixtures/registry-status-owner-ui.php                  new
tests/browser/registry-status-owner-ui.mjs                   new
tests/Feature/ExistingFieldValidationTest.php
tests/Feature/OptionalRfidAnomalyTest.php
tests/Feature/OptionalRfidMovementTest.php
tests/Feature/Phase6VisitorRankingTest.php
tests/Feature/PlateLiteralReconciliationTest.php
tests/Feature/RegistrationPhase5Test.php
tests/Feature/RegistrationPlatePipelineTest.php
tests/Feature/RegistrationSnapshotsTest.php
tests/Feature/RegistrationStaffNameTest.php
tests/Feature/RegistrationVehicleTypesTest.php
tests/Feature/UiPhase3ClarityTest.php
tests/browser/optional-rfid-ui.mjs
tests/browser/registration-phase5-ui.mjs
tests/browser/registration-staff-ui.mjs
tests/browser/validation-ui.mjs
tests/browser/vehicle-types-ui.mjs
tests/fixtures/plate-correction-worker.php
tests/fixtures/plate-literal-worker.php
tests/fixtures/plate-pipeline-ui.php
tests/fixtures/plate-regression-ui.php
tests/fixtures/registration-phase3-worker.php
tests/fixtures/snapshot-worker.php
tests/fixtures/snapshots-ui.php
REGISTRY-PHASE1.md                                          new
```

No migration or dependency change is required. A future installation update must include the coordinated PHP, Blade, CSS and JavaScript changes and refresh relevant caches/browser assets. New-registration clients must send a valid Owner Name; existing stored blanks do not need repair. Nothing has been deployed to Windows.

## Handoff

Phase 1 is complete locally. Earlier instructions that allowed an omitted owner on a new registration are superseded by this batch; historical edit compatibility remains. Keep Registration Phase 6 snapshot behavior and unrelated repair batches intact. No unresolved implementation blocker was found; physical/Windows acceptance remains unverified. Stop here and wait for **Go Phase 2**. The cancelled Reports phases remain cancelled.
