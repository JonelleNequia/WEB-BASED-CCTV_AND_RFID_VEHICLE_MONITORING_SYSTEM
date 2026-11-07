# Phase 7B — Activity Logs occurrence plate images

Implemented locally on 2026-11-07. Scope: Activity Logs plate crops and the existing Activity Logs Print preview. Phase 7A changes already in the working tree were preserved. No applicable `AGENTS.md` was present in the project, relevant source directories, or ancestor directories.

## Behavior and media provenance

- Activity Logs now shows a small, keyboard-accessible plate-crop thumbnail beside Plate, using the Guests table's `plate-cell` / `thumb-plate` conventions. The vehicle snapshot stays in its existing separate column. A missing crop displays **No plate image**.
- Clicking either thumbnail opens the existing image viewer. Its title and image alternative text distinguish **Plate image** from **Snapshot**. Crop images use `object-fit: contain` so the thumbnail does not cut off characters.
- `VehicleEventController::vehicleEventLogPayload()` resolves only the canonical event's `plate_image_path` through `OccurrenceMedia::url()`. It deliberately avoids the VehicleEvent accessor's generic plate-placeholder fallback. `VisitorRecordLog::fields()` uses that occurrence's own `plate_image_url` accessor. Legacy observations without a separate crop field and unlinked RFID scans return a null crop.
- Existing occurrence reconciliation and snapshot synchronization already attach uploaded crops to the authoritative canonical event. This phase does not change those writers, matching, classification, image ownership, or movement counts. No lookup by latest vehicle image, plate profile, similar plate, or nearby timestamp was added.
- Missing files resolve to null under the existing media resolver. Existing public media paths remain supported; private evidence continues using the authenticated occurrence-media endpoint. No media is moved or regenerated.
- Both server-rendered rows and the retained JavaScript JSON renderer carry the crop. Automatic HTML filtering/polling, Back navigation and delegated image-viewer actions retain the occurrence association.

## Print and CSV

The Activity Logs Print menu and preview now say **Include images**. The existing `snapshots=1` query parameter and checkbox hooks remain compatible with old report URLs. When selected, Print shows the snapshot in its existing column and the crop beneath the plate in the Plate column, with independent missing-image labels. When deselected, both image types are omitted.

The same full filtered collection still drives the report: plate/date/daily Manila time/gate and other criteria, ordering, summaries, authorization and archived-gate history are unchanged. CSV retains its existing fourteen columns, with no image URLs or embedded images. Detail-modal single-record printing retains its existing workflow.

## Verification

All mutation tests used a private source copy, isolated SQLite databases, private storage/configuration, synthetic accounts and non-operational fixture devices. No live database was bootstrapped for these checks. No live migrations, resets, repairs, device changes, deployment, publishing or push occurred.

Before the implementation, the new regression checks reproduced the missing crop field in Activity Logs JSON/Print. Final checks cover:

- Both images, snapshot only, crop only and neither, for canonical events and visitor records.
- Missing-file paths and legacy/scan-only records, without borrowing an image from another occurrence. Existing public crop paths remain unchanged.
- Two service-processed IN/OUT occurrences of `395ISX`, staff correction from `3951SX`, repeated correction and late OCR retry: two canonical events retain their own distinct crops, snapshots, identities, times, gate/camera and directions.
- Authenticated private evidence access and existing forbidden/unauthenticated request behavior.
- Full filtered reports beyond the current list page; matching Print/CSV plate sets; unchanged CSV column count and image-free content.
- Browser keyboard image enlargement, independent snapshot/crop viewer labels, automatic filtering and polling, Back navigation, and missing-image replacement without stale row images. The retained JSON live-rendering branch is exercised separately as well.
- Include images on/off, Print and Save as PDF controls, Manila time filters, Guests quick filters and existing Registry/Guests reporting.

| Check | Result |
| --- | --- |
| Focused `ActivityLogPlateImagesTest` | 5 tests, 110 assertions passed |
| Full Laravel suite | 726 tests, 7,788 assertions passed; no failures or skips |
| New `activity-plate-images-ui.mjs` | 58 checks passed |
| Existing `registration-snapshots-ui.mjs` | 22 checks passed |
| Existing `activity-time-ui.mjs` | 31 checks passed |
| Existing `list-reports-ui.mjs` | 64 checks passed |
| Existing `guest-quick-filters-ui.mjs` | 45 checks passed |
| Browser total | 220 checks passed |
| `npm run test:js` | 17 tests passed |
| `npm run build` | Passed; 61 modules transformed |
| PHP/JS syntax, new PHP files' Pint check, `git diff --check` | Passed |

Screenshots were visually inspected at 1366 px, 390 px and 320 px. Narrow screens retain the existing horizontally scrollable table; the crop stays within its Plate cell. Three synthetic PDFs were generated and inspected using the PDF skill and native PDFKit: the eight-row image matrix (2 pages), a 36-row image report (5 pages), and the same report without images (4 pages). Both long reports contain every one of the 36 plates exactly once, with repeated table headings on record pages. Rendered pages show separate images, readable placeholders, existing margins/page numbers and no split record rows.

Private test workspace and artifacts:

```text
/var/folders/zb/05jm3cld3yj8fsbb2cpscjlm0000gn/T/philcst-plate-images-phase7b-a7lij_zp/
  laravel-final.xml
  check-activity-plate-images-ui.mjs.txt
  app/storage/app/ui-review/activity-plate-images/
    desktop.png
    narrow-390.png
    narrow-320.png
    crop-viewer.png
    matrix-print.png
    matrix-images.pdf
    multipage-images.pdf
    multipage-no-images.pdf
    pdf-review.json
```

## Changed files in this phase

```text
app/Http/Controllers/VehicleEventController.php
app/Support/VisitorRecordLog.php
public/css/activity-report.css
public/css/app.css
public/js/activity-logs.js
resources/views/logs/partials/event-row.blade.php
resources/views/logs/print.blade.php
resources/views/logs/tabs/events.blade.php
tests/Feature/ActivityLogPlateImagesTest.php            new
tests/Feature/EventLogReportIntegrationTest.php
tests/browser/activity-plate-images-ui.mjs            new
tests/browser/registration-snapshots-ui.mjs
tests/fixtures/activity-plate-images-ui.php             new
REGISTRY-PHASE7B.md                                    new
```

The existing snapshot browser test now counts the snapshot column specifically, since the Plate column can legitimately contain a second image. The report integration test expects the updated Include images label. Other pre-existing changes were preserved.

## Limitations and handoff

Verified on macOS, Chrome and SQLite with synthetic images. Windows browser/printer behavior, physical cameras/readers, live OCR crop quality and production-scale media performance remain unverified. Python/detector implementation files were not changed; a hardware/detector accuracy claim is outside this presentation phase.

No migration, dependency update, backfill or existing-data correction is needed. Old occurrences that never captured a crop, or whose image file is missing, intentionally show No plate image; no crop can be recovered from the current record alone. A future authorized Windows update should include these coordinated PHP/view/static-asset files and refresh ordinary application/browser caches. No external installation was updated.

No unresolved implementation blocker. **Stop and wait for Go Phase 7C.** Dashboard activity deduplication, Review-card placement and operational gate counts belong to 7C. Manual sticker tag numbers (7D) and manual form cleanup (7E) remain outside this batch. Preserve Phase 7A's occurrence-based Guests exclusion and the existing independent Registry filters, required new Owner Name, optional RFID and plate/Review contracts.
