# Learning Framework UI and Search Regression Audit

Date: 2026-10-12

Classification: Existing-Feature Change

Initial Audit Level: MEDIUM
Final Audit Level: MEDIUM
Audit Level Rationale: Module UI and additive list search; no change to owner services, tenant boundaries, authorization, persistence, lifecycle or immutable payloads.
Audit Level Escalation: None
Escalation Triggers Reviewed: No shared infrastructure, schema, migration, cross-domain write, route/middleware, historical data or source-of-truth changes.

## Current Behavior and Requested Behavior

Previously the list had no search; detail tabs/actions used large button presentation. Request: improve framework/competency UI and add list search.
Implemented: GET keyword search across name, code and description; case/Latin-accent insensitive matching with literal Unicode matching; result/total count, clear search and distinct no-result state. Compact existing tabs, detail summary surface, rating cards, version metadata and action sizes; return-to-list link.

## Documents Reviewed

Documentation entry point/index/routing; Architecture Guardrails and routed governance references; LF-OS; Development Standards/Existing-Feature Change Safety Protocol; Implementation Rules; ADR-0016; Core Learning; framework and version table docs; CSS architecture; Admin Form/List Design Standard; Regression Audit.

## Source Of Truth and Invariants

LearningFrameworkReadService remains the only tenant-scoped read authority. Search filters its already-authorized collection. Framework authoring service remains write authority. Published versions and their own mastery scale snapshots remain immutable. Existing role middleware, customer_id ownership, active/archived list inclusion, ordering, draft actions, form payloads and historical view selection are preserved.

## Impact Analysis, Dependencies and Consumers

Only framework index controller, two views, module-scoped existing CSS and VI/EN labels change. Existing shared card, controls, action menu, outlined create action and course-template tab presentation are reused without modifying their definitions. No route, service, migration, schema, runtime/mastery or Course mapping changes.

## Files Changed and New Files

Changed: app/Http/Controllers/LearningFrameworkController.php; resources/views/learning-frameworks/index.blade.php and show.blade.php; resources/css/admin/admin-pages.css; resources/lang/vi/lf.php and en/lf.php.
New Files: tests/Feature/LearningFrameworkIndexTest.php; this audit.

## Tests Added or Updated

New HTTP tests run the real read service against a read-only SQLite fixture (not a substitute for MariaDB physical enforcement). Coverage: name/code/description; case/accent normalization; blank search and archived records; no-result state; invalid arrays/overlong input; cross-tenant exclusion; teacher denial; Korean literal matching and unmatched emoji.

## Commands and Results

- Baseline LearningRuntimeFoundationTest: 14 passed (27 assertions).
- Baseline LearningFrameworkAuthoringHttpMariaDbTest: 29 skipped because default test database is SQLite; no baseline failure.
- Final LearningFrameworkIndexTest + LearningRuntimeFoundationTest + CourseLearningPathFoundationTest: 32 passed (178 assertions).
- Pint on changed PHP files: passed.
- npm run build: passed (65 modules).
- git diff --check: passed.
- Full suite: not required for scoped MEDIUM read/presentation changes; related module suites used.
- Route listing/migration/transaction checks: not applicable; those files/flows unchanged.

## Requirement-To-Test Traceability

| Requirement/invariant | Evidence |
| --- | --- |
| Search name/code/description, VI without accents | HTTP parameterized tests + live Codex search `tieng han` returns 1/2 |
| Preserve multilingual literals | Korean and emoji HTTP test |
| Clear search and no results | HTTP empty-state test + live clear/no-result interactions |
| Tenant/role safety | Real read-service cross-tenant fixtures + teacher forbidden test |
| Original list/archive/order | Blank search test; service ordering unchanged |
| Detail content/scale/history | Live Codex tabs, add form expand/collapse, version 1 read-only view |
| Responsive | Live browser widths 375/768/1366/1440; document width never exceeds viewport for list/content/scale/history |
| Build/format/diff | Commands above |

## Browser QA

Used user's authenticated Codex tab on hkca.localhost:8010. Checked search submission, retained keyword, clear, result counts, no-match state, tab navigation, historical version content, add-form disclosure and return-to-list. Inspected desktop screenshots and mobile scale layout. Temporary viewport restored; tab returned to unfiltered list. No content was submitted or published. Screenshot: /private/tmp/learnforge-framework-ui.png.

## Unverified Items and Remaining Risks

MariaDB authoring integration suite was not rerun against a dedicated MariaDB test database. Its write/trigger paths are unchanged; real MariaDB-backed page reads were verified in browser. Search retains the existing full-collection read and does not introduce pagination; very large catalogs may need separate pagination optimization. Existing shared mobile header/sidebar behavior remains outside this module's scope.

Findings By Severity: No BLOCKER/HIGH/MEDIUM findings introduced; LOW scalability limitation documented above.
Final Verdict: PASS WITH DOCUMENTED RISKS

## Follow-up: competency list search (2026-10-12)

Classification: Existing-Feature Change. Initial/Final Audit Level: MEDIUM; escalation: None. Requested behavior: search detail competencies by name and code. Implementation: local Alpine filter of the authorized working-node list, with instant matching, Latin-accent/case normalization, result count, clear/focus action and a distinct no-result notice. Full working collection, tab count, draft comparison and all submitted forms remain unchanged. No controller/service/route/schema changes in this follow-up. Dependencies: existing Alpine initialization and shared filter controls; only the content-list region consumes the filter.

Files changed in follow-up: show.blade.php, admin-pages.css, VI/EN lf.php. New Files: None. Documentation authority and invariants remain as reviewed above.

Verification: baseline index suite 9 passed; final index + LearningRuntimeFoundation suites 23 passed (73 assertions). Frontend build passed; git diff --check passed. Browser verified code `kor-hangul-01` -> 1/10 visible; unaccented name `doc va viet` -> 1/10; unmatched keyword -> 0/10; description-only `Nhận diện` -> 0/10 (only names/codes searched); clear -> 10/10 and search focus restored. Checked widths 375, 768, 1366, 1440: no document overflow; viewport restored. No persistence actions were submitted. Screenshot: /private/tmp/learnforge-competency-search.png.

Requirement traceability: name/code matching, instant results, empty/clear state and unchanged full count verified in live browser; runtime and index invariants covered by related suites. New isolated PHP tests would not execute Alpine; actual browser interaction was used for targeted verification. Build/diff checks pass. Unverified Items: no new dedicated browser automation test file, no new write-flow test (write flows unchanged). Remaining Risks: search requires the existing Alpine runtime; without JavaScript the full list remains visible. Findings By Severity: None introduced. Final Verdict for follow-up: PASS.

## Follow-up: disclosure form cancel controls (2026-10-12)

Classification: Existing-Feature Change. Initial/Final Audit Level: MEDIUM. Rationale: module-local disclosure interaction/reset behavior; no write, tenant, authorization, snapshot or schema changes. Escalation: None. Current behavior: forms had submit alone; requested behavior: cancel/close beside Save and improved competency editor. Changed show/competency-edit/scale-edit views and module CSS; New Files: partials/cancel.blade.php. Shared cancel partial resets native form, closes owning details and returns focus to its summary; scale cancel restores original Alpine levels. Competency editor now fills its available row; footer gaps and mobile actions keep Cancel beside Save. Existing payloads and immutable identity controls preserved.

Verification/traceability: browser opened and canceled all four forms (metadata, add competency, competency edit, scale); edited competency description then canceled/reopened: original description restored; added fifth scale level then canceled/reopened: original four levels restored. No Save submitted. Open competency editor checked at 375/768/1366/1440: no overflow; viewport restored. Baseline from previous follow-up: 23 passed; post-change related suites: 23 passed (73 assertions). Frontend build and git diff --check passed. No PHP changes, so PHP formatter not applicable. Full suite/MariaDB write suite not required for module-local frontend controls. Source of truth, consumers and documentation authority remain as above. Unverified Items: no new automated browser file; live interaction verifies actual Alpine/native reset. Remaining Risks: cancel depends on existing Alpine. Findings By Severity: None. Final Verdict: PASS. Screenshot: /private/tmp/learnforge-competency-cancel.png.

## Follow-up: create framework presentation (2026-10-12)

Classification: Existing-Feature Change. Initial/Final Audit Level: LOW. Rationale: opt-in module presentation only; cancel navigation already existed. Escalation: None; no validation, query, permission, ownership, write or historical changes. Requested: consistent create UI and Cancel beside Create. Source of truth/invariants/documents: unchanged, shared admin form standard applies. Files changed: create.blade.php, admin-pages.css, LearningFrameworkIndexTest.php; New Files: None. Added create surface namespace, neutral compact section heading/helper, subtle Advanced section and adjacent footer actions on desktop/mobile. Existing required fields, old input/errors, defaults, submitted payloads and cancel route preserved.

Requirement traceability/verification: create HTTP test checks Cancel precedes submit and cancel navigation leaves record count unchanged; index suite 10 passed (51 assertions); Pint test passed, npm build passed, git diff --check passed. Browser tested Cancel returns to list, then reopened create; checked 375/768/1366/1440 with no document overflow and equal top coordinates for Cancel/Create at every width. Temporary viewport reset. No create submit performed. Screenshot: /private/tmp/learnforge-create-framework.png. Dependencies/consumers: only create view, existing shared form/card/footer; shared definitions unchanged. Unverified Items: create persistence not retested (unchanged); full suite not required for LOW. Remaining Risks: None introduced. Findings By Severity: None. Final Verdict: PASS.

## Follow-up: inline version history (2026-10-12)

Classification: Existing-Feature Change. Initial/Final Audit Level: MEDIUM; escalation None. Rationale: module-local presentation/navigation interaction; no historical data or snapshot contract changed. Requested: open/close content at its version row without reload. Implementation: existing read-only version markup extracted into partials/version-view.blade.php and rendered within each non-draft row; Alpine tracks one expanded version, toggle label Xem/Đóng, aria-expanded/controls and brief opacity transition with reduced-motion support. Existing ?version= deep links initialize the correct panel; draft links retain original behavior. Source of truth, snapshot comparisons, scale authority, tenant read service and permissions unchanged. Consumer: history view only. Files changed: show.blade.php, admin-pages.css. New Files: partials/version-view.blade.php.

Verification/traceability: live browser version 2 opens directly inside row with 9 competencies and correct comparison; close -> zero visible panels; ?version=7 opens version 1 with 8 competencies; switching to version 3 closes version 1 and shows its 10 competencies. URL remains unchanged during local toggle. No persistence actions or editable controls added. Widths 375/768/1366/1440 have no document overflow; viewport reset. Baseline from prior work: related suites passed; final index/runtime suites 24 passed (78 assertions). Blade compilation, frontend build and git diff --check passed. CSS history row selectors narrowed to direct children to preserve nested rating cards. Historical write tests not rerun because write paths unchanged. Unverified Items: dedicated automated browser file not added; targeted Alpine behavior verified live. Remaining Risks: rendering all already-loaded versions increases HTML size for very large histories. Findings By Severity: LOW size limitation only. Final Verdict: PASS WITH DOCUMENTED RISKS. Screenshot: /private/tmp/learnforge-history-inline.png.
