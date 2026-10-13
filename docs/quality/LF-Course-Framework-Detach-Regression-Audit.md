# Draft framework detach — implementation and regression audit

Date: 2026-10-12
Classification: Existing-Feature Change
Initial Audit Level: HIGH
Final Audit Level: HIGH
Audit Level Escalation: None
Rationale: explicit bulk removal of mutable draft links, transaction/concurrency checks and new endpoint. User requested draft-only detach with confirmation and preserved published course versions.

## Documents and architecture review

Reviewed documentation routing/Guardrails/Regression Audit; mapping intent database lifecycle, Course Template draft/selection fields; Course mapping architecture review and AI P3C receipt semantics. Stable owner boundaries remain unchanged: Course writes its working Template and Intents; Learning definitions, Versions, canonical mappings, Evidence and Course published snapshots remain untouched. Template selection FK coherence requires deleting Intents before clearing both nullable selection columns. No schema/migration or new lifecycle transition introduced. Existing intent deletion permits removal of AI-origin mutable links while applied receipt intent_id remains historical; receipts/proposals are never modified or retried here.

## Current/requested behavior and contract

Previously admins had to remove links individually to change frameworks. New DELETE learning-framework command is available to active customer_admin in the resolved tenant only, on Template.status=draft. It locks the tenant-owned Template, checks exact working_revision and draft status, deletes only its mutable Intents then clears both selection fields and increments revision atomically. Stale/non-draft calls fail before deletion; any failure rolls back. Published Versions/canonical mappings are not queried or modified. The button appears only for selected framework in draft status, uses existing LF confirmation showing removed link count and historical preservation, and returns to selector after success. Existing select/upgrade/destroy behavior remains unchanged. Confirmation counts are protected against stale changes by revision validation. No bulk delete performed on user's database during QA.

## Impact and files

Direct consumers: Course Template authoring Learning tab and new controller/service command. Known indirect consumers: publish sees empty draft intent set; AI applied receipts retain historical identity and cannot recreate intentionally removed Intent through retry. Tenant middleware/auth/role foundation unchanged; teacher route is rejected by controller and owner checks. Files: CourseTemplateLearningMappingController, CourseTemplateLearningMappingIntentService, routes/modules/course.php, learning-mappings partial, VI/EN labels. New files: CourseFrameworkDetachTest, this audit. Earlier selection-UX audit is a separate preceding change.

## Verification and traceability

- Targeted SQLite owner/render tests: 7 passed, 19 assertions. Tenant-specific delete/clear, manual + AI-origin links, revision bump, stale/non-draft rejection, teacher denial, foreign Template denial, forced-update failure rollback, untouched published/canonical tables, confirmation markup and active-status button absence.
- Added MariaDB HTTP test for browser-shaped DELETE command; MariaDB suite is skipped by default SQLite config. Dedicated MariaDB execution not performed.
- Route inspection: tenant/auth/verified/tenant.user/role middleware intact; no public endpoint.
- Pint on changed PHP and git diff --check: passed.
- Blade compile and npm run build: passed.
- Full suite launched, log /private/tmp/lf-detach-full-tests.log. Navigation create test failure reproduces separately: exact class-string assertion on unchanged create view. Audio failure reproduces separately: real fixture transcription returns no_extractable_text. Neither code path changed by detach.
- Browser inspected two draft Templates currently lacking framework selection: selector is visible; no persistent changes submitted. Selected-draft confirmation verified via rendered-view test, not clicked against live customer data.

## Unverified items and risks

Full suite completion/results recorded below when available. MariaDB physical FK/transaction behavior and full live selected-draft confirmation remain unverified; SQLite tests are not a replacement for MariaDB enforcement. Existing working_revision discipline from other mutation services is reused; concurrent commands must continue locking Template/incrementing revision. No merge/commit performed.

Findings By Severity: No introduced BLOCKER/HIGH found in targeted checks; baseline navigation/audio failures outside scope.
Final Verdict: BLOCKED for final HIGH verification signoff while full-suite/MariaDB evidence is incomplete; implementation and targeted checks complete.

Full suite completed: 1438 passed, 23 skipped, 11 failed (11882 assertions), 313 seconds. Failures: 3 Audio, 4 Office document, 3 Video processing checks (processing_failed/no_extractable_text), 1 untouched create-form exact-class assertion. No detach test failed. Targeted final suite separately rerun after adding all tests: 7 passed (19 assertions). Full-suite signoff remains BLOCKED due existing processing failures and unavailable dedicated MariaDB verification.

Tooltip follow-up: LOW presentation-only. Added translated native title tooltip to Detach button explaining draft-only link removal, preserved published versions and confirmation. Changed partial and VI/EN labels; no behavior/payload changes. Targeted 7 tests pass; diff check passes. No CSS/JS build needed. Native tooltip pattern reused from shared action-menu component. Overall HIGH signoff limitations above remain unchanged.

View/Preview tooltip follow-up: LOW, no escalation. Added native title tooltips in VI/EN: View opens framework details without changing course selection; Preview explains retained/removed competency links and confirmation before changes. Partial/locale changes only, no routes or submitted values changed. Targeted 7 tests and diff check pass. No build needed for title-only markup. Overall HIGH signoff limitations remain unchanged.
