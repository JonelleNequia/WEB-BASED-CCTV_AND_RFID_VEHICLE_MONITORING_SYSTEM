# Registry Phase 6 — Registry and Guests list reports

Implemented locally on 2026-11-06 (Asia/Manila). Read REGISTRY-PHASE4.md and REGISTRY-PHASE5.md and traced the list queries, automatic filters, permissions and existing Activity Logs reports before editing. No applicable AGENTS.md was found in the repository or its ancestors. Existing working-tree changes were preserved.

## Supported reports and columns

Each supported list now has **Print** and **CSV** beside its controls. Both include the complete filtered dataset in list order, with stable record IDs and no pagination limit or repeated records.

| List | Columns, in order | Summary |
|---|---|---|
| Registry → Vehicles | ID, Plate, Owner, Category, Vehicle Type, Registration Status, Campus State, Tag No., RFID UID, Tag Status, Registered by, Last Seen | Registration Status; Campus State |
| Registry → RFID Tags | ID, Tag No., RFID UID, Status, Vehicle Plate, Owner, Assignment, Last Scan, Scans | Tag Status |
| Guests records | ID, Time (Asia/Manila), Plate / identifier evidence, Classification, Source, Gate, Direction, Plate Result, Record Status, Vehicle Type, Type Source | Classification; Source |

Print can add an **Image** column to Guests records. Its image is the occurrence's attached snapshot, or its attached plate crop when no snapshot is available; the caption identifies which is shown. It never borrows a different movement's image. Missing files and image-load failures show **No image**. Images use fixed-size, contained thumbnails so a plate crop remains visible in full. Vehicle and tag inventory records have no suitable directly attached image field, so those reports do not offer the image option. CSV has no images or image URLs.

Owner and Registered by remain separate. Guest classification follows the occurrence, and vehicle type provenance remains a separate column. Confirmed temporary identifiers are explicitly labelled evidence, rather than presented as actual plates. Reports do not alter historical classifications, movements, vehicle state, assignments, remembered staff defaults or lost-UID permanence.

## Queries, ordering and filters

- Vehicles reuse `VehicleRegistryService::registeredVehicles()`: search, category, registration status and campus state. Registration status and campus state remain independent; matching untagged vehicles are retained. Ordering is plate then ID, with the ID tie-breaker making legacy duplicate display values deterministic.
- RFID Tags reuse the existing inventory ordering and a shared status-filter method. Available/assigned precedence, then the existing tag-number/UID ordering, remain intact. Historical vehicle associations are described as **Previously on vehicle** where applicable.
- Guests use `GuestRecordQuery`, extracted unchanged from the list controller. Reports therefore use the same search, gate, plate result, Show and quick criterion, ordered by occurrence time descending then ID descending. **Manual entries** means saved `source=manual`, independently of classification. Registered/Unregistered use Phase 5 occurrence semantics; later Registry edits do not reclassify a saved Guest. Review evidence is excluded by the Unregistered quick criterion, while All retains the existing list's behavior.
- Guests Show defaults to counted records, including on direct report requests. Duplicate, dismissed and all-record selections remain independent. Archived-gate historical filters remain available. Plate Profiles and Review remain separate tabs and are not additional report datasets.

Registry lists were already **unpaginated** and retain that behavior. Guests retains its existing 20-row pagination. Reports ignore pagination limits for all three lists. No new UI pagination scheme was introduced in this reporting batch.

`data-auto-link` and `data-auto-export` connect the new actions to the existing automatic-filter implementation. Applied responses update both URLs with the displayed results. Pending and invalid criteria remove the targets and disable exports, including modified clicks. Cancelled/delayed responses cannot restore older links. Reset, pagination and Back/Forward keep their existing behavior. No automatic-filter JavaScript change was required.

The RFID Tags table received a scoped layout fix after the narrow browser check found its columns pushing report actions off-screen. Its table now scrolls independently while the controls and report actions wrap within the viewport. Registry's passive status lights and the existing Guests quick-filter controls are preserved.

## Print preview and CSV

Print preview reuses Activity Logs' A4 landscape sheet, report stylesheet and browser Print/Save as PDF workflow. It includes configured school/system names, the dataset title, applied criteria, complete matching count, Manila generation time, signed-in preparer, full filtered summaries and signature lines. Text wraps; table headers repeat on record pages; rows avoid page breaks; controls disappear on paper. The return link retains the applied list criteria. Empty results have an explicit message. The image toggle reloads the same filtered report without automatically reopening the print dialog.

CSV uses UTF-8 with BOM, comma-separated fields, CRLF record endings and explicit standard CSV quoting/escaping. It preserves stored plate/UID strings and leading zeros; it does not reorder characters, cast identifiers to numbers or use formula expressions to force text. User text starting with a formula prefix, including after leading ASCII whitespace/control characters, or starting with a tab/newline, is prefixed with an apostrophe. Quotes, commas, Unicode and embedded line breaks are covered by regression tests.

**CSV import note:** CSV has no column-type metadata. The file retains `001ABC` and `0000123000` literally, but spreadsheet programs may infer numeric types when opening a CSV directly. Import Plate and RFID UID columns as **Text** to retain leading zeros in the spreadsheet. Formula-like values intentionally include a protective apostrophe in CSV; the printable report displays the original escaped text.

## Routes, authorization and validation

```text
GET /registry/print                  registry.print
GET /registry/export/csv             registry.export.csv
GET /visitors/print                  visitors.print
GET /visitors/export/csv             visitors.export.csv
```

Registry endpoints are under the existing admin middleware, matching Registry access. Guests endpoints use the same signed-in access as Guests, including guards. Existing session/password security middleware remains in place. Direct requests validate the same dataset filter values plus supported tab/image/print options. Invalid direct requests return 422 instead of redirecting into a broader report or download; JSON requests receive validation errors. Unsupported report tabs, arrays and invalid gate/status/quick criteria are rejected.

## Verification

All mutation checks ran in a private source copy with isolated SQLite databases, storage/media and camera/device paths. Browser fixtures create random test credentials and private local PHP servers and block off-origin traffic. No live database, installation configuration, account, device or external service was modified.

| Check | Result |
|---|---|
| Full Laravel suite | **716 tests / 7,517 assertions**, zero failures, errors or skips |
| New report feature suite | **11 cases / 410 assertions**, passed again after the final list-layout change |
| New list reports browser suite | **64 checks passed** |
| Existing automatic filters browser suite | **69 passed**, including a final run after the Tags layout fix |
| Existing Guests quick-filter browser suite | **37 passed** |
| Existing Activity time filter browser suite | **31 passed** |
| Browser total | **201 checks across four suites** |
| JavaScript unit tests | **17 passed** |
| Frontend production build | Passed, 61 modules; private output only |
| Static checks | Changed PHP/Blade and JavaScript syntax; new PHP files pass Pint; `git diff --check` passes |

The new feature suite first reproduced missing report routes/actions. Final fixtures compare ordered IDs and counts across lists, Print and CSV using **65 matching rows per dataset**. Guests spans four list pages. Coverage includes combined criteria, each Phase 5 quick criterion, Show, a historical Guest registered later, untagged vehicles, archived gates, empty results, direct validation and admin/guard/signed-out access. CSV is compared cell-by-cell with report data, allowing only the documented formula protection. Tests retain literal leading-zero/look-alike identifiers, Unicode, quotes and multiline fields.

Browser checks cover all three actions, filter-link synchronization, invalid/pending criteria and preserved results, modified clicks, rapid changes with a delayed response, Back/Forward, Reset through the existing filter suite, images on/off, authenticated images, missing/load-failed image placeholders and narrow layouts at **390 px**. Print/Save as PDF controls were checked against a stubbed browser print function; actual headless Chrome PDF generation was tested separately.

Four synthetic browser-generated PDFs were inspected through native macOS PDFKit rendering/text extraction (Poppler was unavailable):

| PDF | Pages |
|---|---|
| Vehicles | 7 |
| RFID Tags | 4 |
| Guests without images | 5 |
| Guests with images | 9 |

All 65 corresponding identifiers survive in each complete PDF. Record pages retain repeated headers and omit preview controls. Rendered pages were visually checked for margins, readable columns, wrapped text, image sizing, page numbers and signature flow. Screenshots of desktop previews and narrow list controls were also inspected. The Tags report's signatures can flow onto a final signature-only page under the existing signature-block convention.

Private synthetic artifacts and logs:

```text
/var/folders/zb/05jm3cld3yj8fsbb2cpscjlm0000gn/T/philcst-reports-phase6-f4ubla68/
  laravel-final.xml
  check-list-reports-ui.mjs.txt
  app/storage/app/ui-review/list-reports/
    vehicles.pdf
    tags.pdf
    guests-no-images.pdf
    guests-images.pdf
    pdf-review.json
    *-preview.png
    *-mobile.png
    *-page-*.png
```

Limitations: macOS/Chrome and SQLite were tested. Windows browser/OS print dialogs, other database engines, Excel/LibreOffice import UI, physical printers and production-scale history loads were not exercised. Headless PDFs verify browser print rendering, not a physical print job. Python and detector files are unchanged relative to this batch's baseline; no Python or hardware verification is claimed. No live-data audit or repair was performed.

## Changed files and installation handoff

Relative to the preserved Phase 5 working tree:

```text
app/Http/Controllers/ListReportController.php            new
app/Http/Controllers/VehicleRegistryController.php
app/Http/Controllers/VisitorController.php
app/Http/Middleware/ValidateListFilters.php
app/Services/GuestRecordQuery.php                        new
app/Services/ListReportService.php                       new
app/Services/VehicleRegistryService.php
app/Support/SpreadsheetCsv.php                          new
public/css/auto-filters.css
public/css/list-report.css                              new
public/js/list-report.js                                new
resources/views/partials/list-report-actions.blade.php   new
resources/views/registry/tabs/tags.blade.php
resources/views/registry/tabs/vehicles.blade.php
resources/views/reports/list.blade.php                   new
resources/views/visitors/index.blade.php
routes/web.php
tests/Feature/RegistryGuestReportTest.php                 new
tests/browser/list-reports-ui.mjs                        new
tests/fixtures/list-reports-ui.php                       new
REGISTRY-PHASE6.md                                      new
```

No migration, database correction, dependency change or live deployment is needed for implementation. A later authorized installation update should include the coordinated routes, PHP, Blade and public assets and clear the normal route/view/application caches; browser assets use the existing cache-version convention. No database reset, reseed, repair, deployment, publication or push was performed.

The existing Activity Logs time-window implementation, Print/CSV handlers, report stylesheet/script and automatic-filter JavaScript are unchanged. Phase 4's Activity time browser suite passes. **Activity Logs plate-image display remains Phase 7 scope.** Preserve Phase 1 required new Owner Name/optional RFID, passive status lights, Phase 2 literal plate matching and Phase 3 evidence/Review rules.

Phase 6 is complete locally. No unresolved implementation blocker remains. **Stop and wait for Go Phase 7.**
