# Registry Phase 5 — Guests record quick filters

Implemented locally on 2026-11-05 (Asia/Manila). Read the Phase 3 and Phase 4 handoffs and inspected the Guests query, occurrence presentation, automatic filters/history handler and Activity Logs filter bar. No applicable AGENTS.md was found in the repository or its ancestors. Previous working-tree changes were preserved.

## Buttons and exact criteria

The Guests records list now has the existing Activity Logs chip styling, wrapping and native keyboard links. Exactly one applied button has the purple selected style and `aria-current="true"`.

| Button | Criterion within the existing VisitorRecord list |
|---|---|
| All | No quick-filter restriction; other criteria still apply. |
| Registered | Existing `isRegisteredAtOccurrence()` classification: saved `registration_status=registered`; for undecided legacy rows only, no plate Review requirement and a linked vehicle created no later than the occurrence. |
| Unregistered Guests | Existing Guest classification: neither Registered nor Review. Saved Guest decisions remain Guest after later registration or profile/history linking. |
| Manual entries | Saved `source=manual`, independently of occurrence classification and the Show criterion. Staff correction of a camera observation does not make it a manual entry. |

These are filters over records already present on Guests, not a new query of every registered movement. Activity Logs has additional source tables and its own Manual log type; **Manual entries** here explicitly describes the VisitorRecord source. Registered and Unregistered criteria match Activity Logs for the same occurrences, including registered evidence represented there by a canonical movement event.

The model query scopes reuse the existing plate Review SQL predicate and historical vehicle relationship. Non-null ID subqueries avoid incorrect negation of nullable legacy OCR columns. Filtering does not run registration matching, change eligibility, infer registration from a readable plate, or persist a new classification. The legacy fallback is preserved rather than rewritten or backfilled.

All preserves plate search, gate, plate result, Show and other existing criteria. Show continues to mean counted/duplicate/dismissed/all records; it is independent of the quick criterion. A hidden form field keeps the applied quick criterion synchronized through the existing automatic-filter handler. There is no second dropdown for this criterion.

Criteria changes return to page one. Pagination, automatic filter requests and Back/Forward retain the selection. Existing cancellation/version checks reject delayed responses. Debounced search text is retained when a chip is clicked before its search request runs. Reset restores the existing counted-record default and clears the quick selection and other filters. Historical archived-gate bookmarks remain usable; archived gates are not added to operational selectors.

The table heading is now **Guest records**, since the existing list can include Registered and Review evidence. The existing Unregistered Guests, Plate Profiles and Review tabs remain. Review behavior, row menus and dialogs are unchanged. Narrow layouts wrap the quick buttons and retain horizontal table scrolling with readable columns. No polling interval was introduced: Guests retains its existing automatic filter refresh behavior.

## Verification

All database mutations ran in a private source copy with isolated SQLite fixtures, storage, media and camera/device configuration paths. Browser fixtures use random credentials and a private local PHP server; off-origin requests are blocked. No installation database, live records, device configuration or external service was changed.

| Check | Result |
|---|---|
| Full Laravel suite | **705 tests / 7,107 assertions**, zero failures, errors or skips |
| New Guest quick-filter feature suite | **7 tests / 88 assertions** |
| New Guests quick-filter browser suite | **37 checks passed** |
| Existing automatic filters browser suite | **69 passed** |
| Existing plate evidence / Review browser suite | **41 passed** |
| Existing Activity time filter browser suite | **31 passed** |
| Browser total | **178 checks across four suites** |
| JavaScript unit tests | **17 passed** |
| Production frontend build | Passed, 61 modules, private build output only |
| Static checks | Changed PHP/Blade and browser JavaScript syntax; new PHP files pass Pint; `git diff --check` passes |

Before implementation, the new tests reproduced missing quick filtering, missing links/selection and acceptance of invalid quick criteria. Final fixtures cover each button, legacy nullable evidence, saved Guest/Registered/Review decisions, current Registry changes, a later-registered Guest and manually corrected camera records. A 96-row decision/evidence matrix compares filtered membership with the existing Activity Logs presentation across pages, including early/late/missing vehicle relationships, low confidence, unreadable evidence, reviewed records and dismissed records.

An integration test confirms an actual plate through the existing Review endpoint, then checks the same occurrence's Registered Guest-list membership against its canonical Activity Logs event. Reading the filters creates no extra movement/audit row and leaves both the occurrence and vehicle unchanged.

Additional fixtures verify combined search/gate/plate result/Show, archived gates, empty results, more than one page, All, Reset, invalid direct criteria, guard access and signed-out protection. Browser checks cover selected color and accessible state, keyboard activation, rapid switching with an intentionally delayed response, pending search text, Back/Forward, archived bookmarks and refreshed Correct plate / Correct type / Add note / Dismiss dialogs. A note is saved and reopened after a results replacement.

Screenshots were visually inspected at **1366 px and 390 px**. Narrow buttons and inputs fit the viewport, and the table scrolls horizontally instead of crushing plate text. Synthetic artifacts and logs are private:

```text
/var/folders/zb/05jm3cld3yj8fsbb2cpscjlm0000gn/T/philcst-guests-phase5-04c4onlx/
  laravel-final.xml
  check-guest-quick-filters-ui.mjs.txt
  app/storage/app/ui-review/guest-quick-filters/desktop-manual.png
  app/storage/app/ui-review/guest-quick-filters/mobile-manual.png
```

Checks ran on macOS/Chrome and SQLite. Windows browsers, other production database engines and large-history load performance were not exercised. No physical camera/reader verification is claimed. Python/detector files are unchanged and the Python suite was not rerun for this filtering phase. No live-data audit or repair was performed.

## Files and handoff

Changed relative to the preserved Phase 4 working tree:

```text
app/Http/Controllers/VisitorController.php
app/Http/Middleware/ValidateListFilters.php
app/Models/VisitorRecord.php
public/css/auto-filters.css
resources/views/visitors/index.blade.php
tests/Feature/GuestQuickFilterTest.php                   new
tests/browser/guest-quick-filters-ui.mjs                 new
tests/fixtures/guest-quick-filters-ui.php                new
REGISTRY-PHASE5.md                                     new
```

No migration, data correction, dependency update or new JavaScript asset is needed. For a later authorized installation update, include the coordinated PHP, Blade and public CSS changes and refresh the normal application/browser caches. Nothing was deployed, published or pushed.

Phase 1's passive Registry status lights, required new Owner Name and optional RFID remain. Preserve Phase 2 literal plate order/exact identity and Phase 3 evidence/Review rules. Preserve Phase 4 daily Manila time windows and shared Activity Logs Print/CSV behavior. Do not reinterpret the older visitor-model phase numbers in code/test comments as this seven-phase plan.

No unresolved implementation blocker remains. **Stop after Phase 5 and wait for Go Phase 6.** Registry and Guests Print/CSV remain Phase 6 scope; no Phase 6 work was started.
