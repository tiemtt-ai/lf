# Course framework change: reset all draft competency links

Status: User approved 2026-10-13; implemented; focused checks passed, broad release verification blocked
Date: 2026-10-13
Audit level: HIGH (changes existing version-switch behavior)

User decision: remove all attached competencies before switching versions. The last two preview presentation changes have been reverted; build and seven existing detach tests pass.

Current contract: CourseTemplateLearningMappingIntentService::upgrade recomputes an authorized same-framework published target under transaction and revision lock, deletes working intents, and recreates links matching stable node definitions. It blocks non-manual origins. The published course version mappings are separate and immutable. Framework select refuses a different selection while draft intents exist; explicit detach handles clearing them.

Proposed behavior:
- A different framework version clears all mutable lesson/activity competency intents, including matching nodes, and selects the explicitly chosen published target atomically.
- A different framework uses the explicit detach confirmation before new selection; no implicit deletion on selecting a dropdown.
- Always present a clear confirmation naming the current/target basis and number of affected links. Cancel does not write anything.
- Warn that switching back cannot restore removed links automatically.
- Retain tenant ownership, author permissions, revision checks, transaction rollback and immutable published course snapshots.
- Non-manual/AI links require a reviewed decision: retain the current version-upgrade block, or allow deletion using the existing detach owner behavior without modifying receipts or provenance history.
- No schema/migration changes.

Required verification before release: reset-all service/HTTP regression including matching nodes, stale revision, foreign tenant, rollback, cancel/no-op, AI origins, and unchanged published course mappings; update existing retained-link expectations and corresponding documentation; run required HIGH checks.

Risk: this replaces a documented/tested retain-matching behavior with destructive reset behavior and changes backward compatibility. Under LF-Architecture-Guardrails Existing-Feature Change Guardrail, implementation pauses for Review → Approve → Implement.


## Approved implementation
The user explicitly approved confirmation before resetting all attached draft competencies on version/framework change, with Cancel and published snapshot preservation. Version upgrade now removes all mutable intents, including AI origin, without recreating matching definitions or touching provenance receipts. Same-framework explicit target/revision/transaction checks remain; an explicit TenantContext ownership assertion precedes upgrade. Different-framework change retains the detach-then-select workflow and its draft-status/revision guard, with confirmation at detach; selecting a replacement for an existing unlinked basis also uses the shared confirmation dialog. No migration.

Focused verification: 11 tests / 37 assertions passed, including manual+AI reset, other-tenant preservation, stale requests, invalid/current targets, forced update rollback and unchanged published snapshots. Existing MariaDB retain-matching expectation updated to reset-all; dedicated MariaDB persistence run remains unavailable under default SQLite. Blade compilation and diff check passed. Browser opened the version confirmation and pressed Cancel; the selected v2 and its Hangul link remained. Screenshot /private/tmp/learnforge-reset-confirm.png.


Final verification: full default suite completed with 1444 passed, 23 skipped, 10 failed (11905 assertions), 164.46 seconds. Failures are the previously observed AudioProcessingLocalReviewTest (3), DocumentProcessingLocalReviewTest (4), and VideoTranscriptCaptionLocalReviewTest (3), outside the changed paths. Log: /private/tmp/lf-framework-reset-full-tests.log. MariaDB HTTP suite: 37 skipped under SQLite (/private/tmp/lf-framework-reset-http-tests.log); no claim of production-engine write verification. Pint, Blade compilation and diff checks passed. Build from preview rollback remains current (no subsequent asset changes). Focused test also verifies switching back leaves links empty.

Release verdict: implemented with focused PASS; HIGH release signoff remains BLOCKED by existing media-suite failures and unexecuted MariaDB HTTP checks. No data deletion or version switch was submitted against the user's database during browser verification.


## Direct change follow-up — user authorized 2026-10-13
The replacement selector is now always visible. Selecting a different basis opens the shared LFConfirm immediately; acceptance submits the select command once, without a Save step. Cancel restores the current dropdown value and sends no write. The select command accepts an optional expected revision; the browser includes it, allowing atomic delete of all draft intents and update of both framework selection fields. Existing clients omitting revision remain blocked from changing linked selections. Same-selection submission is a no-op; published target/tenant/author guards remain. No schema changes. Updated help/tooltips reflect the direct workflow.

Verification: 12 focused tests / 43 assertions passed, including direct different-framework selection, stale revision, forced rollback, manual/AI links and other-tenant preservation. Blade cache, Pint and diff checks passed. Browser confirms immediate modal naming the target and Cancel restores current v1. No user's data was submitted. Screenshot /private/tmp/learnforge-direct-framework-confirm.png. Prior full-suite and MariaDB limitations above remain; full suite was not rerun for this follow-up.

## Single picker presentation — 2026-10-13
Removed the separate version-switch picker and preview panel from the Learning tab, including their unused view variables. Shared framework/version picker remains the single visible change flow and opens confirmation on change. Legacy service/endpoint retained for compatibility. Updated HTTP view expectations; focused suite 12 passed / 46 assertions, Blade compilation, Pint and diff passed. Browser verified one picker and no separate preview; screenshot /private/tmp/learnforge-single-framework-picker.png. Existing full-suite/MariaDB limitations unchanged. No user data submitted.

## First selection consistency — 2026-10-13
LOW UI follow-up: the shared selector now opens the same immediate Apply confirmation even without an existing selection. Removed separate Save/Cancel buttons; modal Cancel restores the blank placeholder for first selection. No service changes. Blade compilation and diff checks pass. Browser verified unselected template 2, opening confirmation on choosing v3, then Cancel returning to blank without any write; returned to template 3. Screenshot /private/tmp/learnforge-first-selection-confirm.png. Prior release verification limitations remain unchanged.

## Custom grouped combobox — 2026-10-13
MEDIUM presentation/interaction change: replaced native select with Alpine select-only combobox, grouped listbox options and light-green current option. Same hidden framework value, expected revision and confirmation owner flow. Current value changes only after confirmation; Cancel leaves it intact. Keyboard arrows, Home/End, Enter/Space, Escape and Tab supported; active-descendant semantics and group labels added; outside click closes. Build, Blade compilation, focused suite and diff checks passed. Browser verified groups/current green, keyboard ArrowDown+Enter opening correct target confirmation and Cancel retaining v3; screenshot /private/tmp/learnforge-custom-combo.png. No user writes submitted. Prior HIGH release limitations unchanged.

## Framework read-only popup — 2026-10-13
MEDIUM UI change: View framework opens a native modal dialog within the Course page, showing explicitly selected framework/version and existing authorized nodes (name, code, description). No navigation to Learning administration and no extra cross-domain queries. Native dialog provides focus containment/Escape; Close and backdrop close supported. Build, Blade compilation, 12 tests / 46 assertions and diff passed. Logged-in browser verified selected v3 with ten nodes, Close returns to unchanged Course URL. Screenshot /private/tmp/learnforge-framework-popup.png. Published data/write behavior unchanged; prior release limitations remain.

## Attachment form UX and local feedback — 2026-10-13
MEDIUM existing UI change: searchable name/code checkbox list (accent-insensitive), bounded scrolling, selected count, highlighted checked rows and Cancel resetting temporary inputs/closing form. Empty selection is prevented locally with inline alert. Server validation reopens the originating picker using old input; restores source and selected ids. Attach success redirects to originating picker anchor, opens it and shows local status instead of page-wide success. Legacy callers without the optional UI picker token preserve redirect behavior; writes/tenant/auth/owner service unchanged. Picker token is restricted to numeric Course picker IDs for redirect anchor. Added MariaDB HTTP feedback assertion (not executed under SQLite). Build, Blade compilation, Pint, diff and 12 focused tests / 46 assertions passed. Browser verified search, empty-selection alert and Cancel without customer writes; success persistence not submitted during QA. Screenshot /private/tmp/learnforge-mapping-form.png. Prior release/MariaDB limitations remain.

## Attachment grid simplification — 2026-10-13
Removed search/filter/no-result UI per user request. Attachment form is wider with two desktop columns and one column below 768px; bounded scroll remains. Lesson-only hidden source, selection count, Cancel and inline feedback preserved. Build/Blade/diff checks passed. Browser desktop inspected; 375px verified one grid column, overflow-y auto and no document overflow; temporary viewport reset. Screenshot /private/tmp/learnforge-mapping-two-columns.png.

## Full-width attachment row — 2026-10-13
Moved each attachment disclosure/form to its own table row with colspan=3 below the corresponding lesson. Removed desktop skills-column resizing. Form spans table width minus padding, with two desktop grid columns and one mobile scroll column. Build/Blade/diff passed. Browser measured form 757px vs table 789px (32px padding); 375px mobile one column, scroll auto and no horizontal overflow; viewport restored. Screenshot /private/tmp/learnforge-mapping-full-width.png. No data behavior changes.

## Modal attachment form — 2026-10-13
Moved attachment trigger back into the lesson's original row and changed the form to native dialog titled with that lesson. Two-column desktop/one-column mobile list preserved. Hidden source remains that lesson; successful standard redirect updates original row and reopens same modal with local feedback. Cancel resets temporary selections and closes; Close/Escape/backdrop supported. Existing owner writes unchanged. Build, Blade and 12 focused tests passed; browser verified correct lesson title and Cancel without writes. Screenshot /private/tmp/learnforge-mapping-modal.png. Live successful write not submitted during QA; prior database verification limits unchanged.

## Successful attach returns to lesson row — 2026-10-13
Moved success feedback from modal to original lesson skills cell; successful redirect now targets the lesson row anchor and leaves modal closed. Updated row reads persisted mapping state on the redirected request. Modal auto-opens only on validation errors for its originating picker, retaining input. Updated MariaDB HTTP assertions for row anchor/status/closed modal (requires MariaDB; not executed here). Blade compilation, Pint/diff and 12 focused tests / 46 assertions passed. No live user's attach submitted during QA; no claim of Ajax/in-place update without page reload. Prior integration limitations remain.

## Async attachment — 2026-10-13
MEDIUM additive HTTP/UI change: the existing attach endpoint returns JSON for browser requests with Accept application/json and a valid picker token, preserving ordinary redirects. Fetch submits FormData with existing CSRF and same-origin credentials, disables repeat submission, and displays JSON validation errors locally. After success an authorized GET refreshes only #course-learning via server markup, updating chips, available options, counts and working revision without browser navigation/reload. Modal closes, focus returns to the lesson action and the scoped flashed success message appears at the row. Refresh failures distinguish a saved operation from a failed write and advise against duplicate attachment. Added MariaDB async HTTP test; not executed under default SQLite. Build, diff and 12 focused tests / 46 assertions passed. No successful live customer write submitted; end-to-end MariaDB and previous broad-suite limitations remain.

## Confirmed async removal — 2026-10-13
Existing lesson skill remove forms now use shared LF confirmation (Cancel sends no request) and the same async handler as attachment. Confirmed DELETE method override preserves owner authorization and tenant-scoped service writes. JSON response flashes scoped row status, then background GET refreshes learning markup without navigation. Updated chips, available choices, counts/revision come from server; focus returns to row action. Network/validation errors displayed locally; legacy/orphan removal remains a normal confirmed redirect. Added MariaDB async removal HTTP regression (requires dedicated DB; not run here). Build, Blade, Pint/diff and 12 focused tests passed; no live deletion performed. Prior release limitations remain.

## Auto-hide attachment success — 2026-10-13
LOW UI follow-up: only scoped attachment/removal success status now fades after 3000ms via Alpine initialization, including markup inserted by background refresh. Inline errors remain persistent. Blade compilation and diff passed; no write behavior changes.

## Flat attachment dialog — 2026-10-13
LOW CSS-only follow-up removes nested form/list/item borders and outer dialog border. Whitespace and subtle hover/selected backgrounds provide grouping; keyboard focus outline retained. Two-column/mobile scroll behavior and writes unchanged. Build/diff passed; browser screenshot inspected (/private/tmp/learnforge-mapping-flat.png).

## AI entry compact layout after reset — 2026-10-13
LOW scoped UI update to existing single-activity entry: neutral background/border, shorter VI/EN help, smaller labels, fixed 42px aligned selector/button, mobile stacked full-width button. View suggestions disabled until a choice exists. Existing GET, proposal authority and creation disabled state preserved. Build, Blade/diff and AI entry tests passed. Browser verification could not locate the current selector; no live command submitted.
