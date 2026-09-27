# AI Authoring Proposal — Implementation Review

Version: 1.2

Document Status: Review

Implementation Status: Partial

Last Updated: 2026-09-26

Document Path: quality/LF-AI-Authoring-Proposal-Implementation-Review.md

## Scope and current disposition

Audit Level: HIGH. Backend + HTTP: **ACCEPTED BY OWNER on 2026-09-17 under a
waiver of independent review** (see § Owner acceptance), after the four
implementation-review findings below were remediated. This is implementer evidence and a non-independent self-review,
not an independent Architecture Review PASS or a new Owner acceptance signature.
The whole contract remains Partial because frontend UI is not implemented.
Real AI, provider activation and frontend chat are not backend completion gates.
Live database apply and deployment are separate; learnforge_db was not modified
by the reported verification. GitHub CI has not been run by these checks.

This curated record replaces the five working-directory Step 7 design,
remediation, migration, service and 2026-09-16 implementation-review reports.
Historical counts below are evidence at their respective stages, not cumulative
test totals or results rerun during this documentation consolidation.

## Owner acceptance — 2026-09-17

Owner decision (verbatim): "miễn trừ review độc lập, chốt nghiệm thu Bước 7".

| Item | Disposition |
| --- | --- |
| Independent implementation review | **WAIVED by Owner — miễn trừ, không phải PASS** |
| Acceptance scope | Step 7 backend + HTTP v1 only: six-table packet, services, owner ports, erasure/recovery, 41 routes |
| Evidence basis | Implementer evidence and a non-independent self-review; nothing was rerun to support this decision |
| Not accepted / remains separate | Frontend UI and chat; real AI provider activation and model quality; apply to learnforge_db and deployment; service/HTTP on MariaDB 10.4; two-connection service races; real Redis worker/scheduler; GitHub CI; baseline Media test repairs |

This waiver covers this acceptance only. It does not extend the 2026-09-15
schema waiver, does not approve any provider, and does not retroactively turn the
self-review into an independent review. A future independent review may still
be commissioned; any finding it raises reopens the affected scope.

## Packet migration apply-safety hardening — 2026-09-26

After the [independent pre-apply review](LF-AI-Migrations-Pre-Apply-Review.md)
(all four AI migrations APPLY-READY WITH DOCUMENTED RISKS individually, whole apply
BLOCKED), the Owner supported hardening M4. At the time this section was written the
migration was believed unapplied; a direct check on 2026-09-27 found the pre-hardening
source already applied to the local dev `learnforge_db` (batch 28) with an identical
schema — see the [pre-apply brief § Trạng thái thật](LF-AI-Migrations-Pre-Apply-Reviewer-Brief.md).
Changes to
`2026_09_15_000100_create_ai_authoring_proposal_packet.php`:

| Review item | Change | Limit |
| --- | --- | --- |
| R1 — origin CHECK window | Course `chk_cct_lmi_origin` is dropped and re-added, together with `chk_cct_lmi_ai_provenance`, in **one** `ALTER` in `up()`; `down()` restores the manual-only CHECK in one statement before dropping the AI columns | Verified on both engines that a failing combined ALTER (4025) leaves the old CHECK enforcing. Does not make M4 atomic as a whole: writers must still be stopped and a restorable backup taken |
| H2 — late trigger failure | Before the first DDL: `LF_AUTHORING_PACKET_PARTIAL_STATE` if any packet table or Course AI column already exists; `App\Support\Database\TriggerCreationPreflight` checks, from `information_schema` metadata only, a direct global/schema `TRIGGER` grant for `CURRENT_USER()`, and `SUPER` when `log_bin=1` with `log_bin_trust_function_creators=0` | Fail-closed: grants held only through a role or at table level are not visible there and are refused. A pass proves the metadata, not that every later statement succeeds; the DEFINER account must still exist after deploy. No probe trigger is created on the target |

Constraint names and clauses are unchanged; `schema:drift --connection=mysql`
passed on fresh MariaDB 11.4.12 and 10.4.21 schemas.

Implementer evidence (not independent): new `AiAuthoringPacketApplySafetyMariaDbTest`
(partial state refused with no DDL; missing TRIGGER refused with schema unchanged;
direct grant passes, role-only grant refused; origin swap is one statement in both
directions) and `TriggerCreationPreflightTest` (decision matrix incl. binlog cases a
test server cannot switch on). With the packet and Course promotion suites: 42 passed
on 11.4.12 and 42 passed on 10.4.21, isolated instances, no `learnforge_db`.
Three mutations on an isolated copy (MariaDB 10.4.21) were each caught by the new
suite: preflight call removed, partial-state guard removed, origin swap split back
into two statements; the file was restored to its SHA-256 and the suite passed again.
M4 SHA-256 after hardening: `bf48be43d2c218d5fe205f240529b47361da108964fbcc28a9cbaf8a143c1994` (before:
`c23c8d7660d36820902f0fa05e89b2d794ef265de960f722d2704be24de05c58`, the hash the
pre-apply review signed).

The pre-apply review re-ran the M4 part (round 2, 2026-09-27): **H2, R1 and L1
CLOSED**, M4 APPLY-READY WITH DOCUMENTED RISKS on 10.4.21 and 11.4.12, no new
finding. It added a runbook condition: triggers run as their DEFINER, so the account
that applies M4 must persist and keep the privileges the trigger bodies use (revoking
SELECT gave 1142, dropping the account gave 1449) — do not apply with a temporary
account. This hardening does not touch B1 (actual `learnforge_db` state), H1 (engine
floor) or the rehearsal gate; the whole apply remains BLOCKED.

## Authority and decision history

The [Authoring contract](../platform/LF-AI-Authoring-Proposal-Contract.md),
[AI domain](../platform/LF-AI.md), [AI table catalogue](../database/ai/README.md)
and their referenced Course/Learning/Media contracts remain the sources of truth.
This quality record does not introduce policy or expand approval scope.

- 2026-09-14: Owner approved AI suggestions with teacher/admin edit/accept/reject;
  admin-only new Node approval through Learning; accept is not publish; apply to
  the next explicitly published Course Version, never historical backfill.
- Design remediation replaced the early five-table/current-revision-pointer
  candidate with the six-table packet, request ledger, source seal, immutable
  histories, explicit cancellation, code-versioned prompt, inherited draft and
  complete explicit rebase. Early v0.3/v0.7 Review statements are superseded.
- 2026-09-15: Owner froze contract v0.8 and authorized migration under a waiver
  of the pre-migration Architecture Review condition for this packet only:
  six AI tables and Course Intent references. Not a repository-wide waiver,
  independent PASS, provider approval or permission to apply to learnforge_db.
- Restricted successor inheritance preserves all six Owner conditions: same
  media_file_id/fingerprint and active usage; current authorization for every old
  source by the copying actor; only human-approved unerased decision fields may
  be copied, never old rationale/citations/excerpts; predecessor/reason/renewed
  review flags are recorded; an already applied create_node becomes reuse_existing
  only if its Node is still valid, otherwise conflict; no Media Read, Knowledge
  or Vision exception is introduced. Source revision change cancels obsolete
  receipts with the named system reason; applied provenance remains immutable.
- 2026-09-16: Owner approved the final service package and HTTP permission
  contract. The earlier four-direction approval did not itself approve every
  assumption; the subsequent full-package decision below superseded pending
  dispositions. No new approval is inferred by consolidating these reports.

### Disposition of the original eleven assumptions

| Item | Current approved/implemented disposition |
| --- | --- |
| 1 Framework basis | Template's current selection for teachers and admins; no admin bypass |
| 2 Excerpts | Do not store source excerpts; derived proposal payload still requires access checks/erasure |
| 3 Model input | v1 extracted_text, transcript, video_frame_text; region/table/formula input deferred |
| 4 Read audit | Implemented through MediaDerivedRetrievalAudit/media_access_logs, read_derived/ai, content-free and fail-closed |
| 5 Errors | proposal_intent_conflict and proposal_successor_required approved; invalid_proposal details are content-free prerequisite/validation codes |
| 6 Configuration | Fail closed before new source reads/request creation; replay still checks current Course authority |
| 7 Node reuse | Exact active compatible identity; differing names/criteria refuse, never overwrite |
| 8 Approval actor | approved_by immutable; retry has its own append-only actor evidence |
| 9 Retry | retry_application implemented on the same failed receipt with fresh checks and atomic owner write; no second receipt/provider call |
| 10 Erasure | Deleted Media/missing Activity erases content; detach immediately hides but does not itself erase; reattach does not restore erased content |
| 11 Successor scope | Explicit selected current anchors, maximum 200, validated/hashed/sealed without truncation; all predecessor sources still checked |

Unkeyed integrity hashes are not anonymization; HMAC adoption, retention/legal
hold, redaction and provider eligibility remain separately governed.

## Implemented scope and traceability

| Layer | Implementation / boundary |
| --- | --- |
| Schema | 2026_09_15_000100_create_ai_authoring_proposal_packet.php: six tables, four nullable Course Intent provenance columns, tenant FKs, CHECKs, 17 history/lifecycle triggers, all-table rollback preflight |
| Generate/review | AiAuthoringProposalService: ledger before provider, conditional claim, zero-item replay, atomic payload/source seal, versioned code prompt, edit/accept/reject and freshness |
| Application | AiAuthoringApplicationService: admin Node approval, preview/confirm/reconfirm/reject target, apply/retry/cancel Intent, original approver retained |
| Publication | AiAuthoringPublicationService within Course publish; all AI Intents revalidated, fail whole publish on conflict; Learning promotion retains ID/hash lineage independent of Intent retention |
| Successor/rebase | AiAuthoringSuccessorService and AiAuthoringRebaseService; six inheritance conditions, explicit source selection, inherited draft and complete map/remove_explicit/cancel_rebase decisions |
| Erasure/recovery | AiAuthoringErasureService, EraseAuthoringProposalsOfDeletedMedia, 15-minute ai:authoring-reconcile-erasure; immediate read denial; controlled running-request recovery only after admin confirms old worker stopped |
| Ownership | AI writes ai_authoring_*; Course owns Intent/context, Learning owns Node/Mapping, Media owns source authority and access audit |
| HTTP | AiAuthoringController, AiAuthoringInput, AiAuthoringHttpReadService and routes/modules/ai-authoring.php; 41 routes: 23 admin, 18 teacher |

HTTP uses existing session/web, tenant, auth, verified, tenant.user and role
middleware plus CSRF. Teacher access requires current active assignment.
Parent chains, receipt scope and current authority are checked, including replay.
Strict original-JSON parsing preserves empty strings, rejects forged/unknown
fields and invalid version/hash/limit guards. Metadata-only keyset listings use
encrypted tenant/actor/parent/filter-bound cursors; source cursors bind current
anchors. Content reads audit before disclosure. Bulk outcomes are per item.
No new publish, canonical-write, provider-setup, erasure or recovery HTTP endpoint.

Canonical Mapping is materialized only through owner publication when both
Course Version and Framework Version are published. Human confirmation,
successor, retry and rebase do not generate new model output. Tests use fake
providers; model quality and actual provider activation are not claimed.

## Evidence by stage (local, attributed)

| Stage / runner | Result and limits |
| --- | --- |
| Migration implementer | MariaDB 10.4.21 packet 20 tests/128 assertions; 11.4.12 affected scope 109/418; fresh schema drift 100 migrations, 48 INFO, zero non-INFO; six AI tables + Course Intent metadata harvested, not guessed |
| Physical probe implementer | Both engines: duplicate UUID, losing claim and source seal races, distinct connections and observed INNODB_LOCK_WAITS; empty down/up and retained-history fail-closed rollback passed |
| Initial service implementer | 58 new tests; related scope 158/792; 37 mutations ultimately caught (early-replay mutation required an additional no-source-reread assertion) |
| Approved follow-up implementer | 196/972 related; 52/267 focused; audit, retry, explicit source scope and non-overwriting Node reuse |
| HTTP implementer | 161/1234 related including 23 HTTP tests; receipt-parent, cursor and citation mutations caught; build passed |
| Self-review, not independent | Fresh Step 7 110/997 (before and after mutation recovery), neighboring 98/463; three DB probes on 11.4; no 10.4 execution in that review |
| Final remediation implementer | 221/1550 related on 11.4.12; focused HTTP/erasure 36/613; four isolated mutations caught; restored-copy 8/70 passed |

Fresh normal test startup rebuilt all 100 migrations in the final remediation.
Subsequent related passes reused that temporary schema with
RefreshDatabaseState::$migrated=true and per-test rollback. This is explicitly
not the same as repeatedly rebuilding schema or running GitHub CI. The eight
Authoring Integration classes are registered in integration-mysql; the standalone
two-connection probe is not registered there. Schema compatibility on 10.4 is
evidence for DDL, not proof of service/HTTP compatibility on that engine.

### Self-review incident and findings

The self-reviewer wrote much of the service/owner-port/test packet, so cannot
sign independent PASS. Its first mutation incorrectly ran on the real working
tree, was killed (exit 137), and left a disabled Template guard. The guard was
restored, app/routes scanned and 110 fresh tests rerun successfully. Original
pre-mutation hashes had been lost during scratch cleanup: later matching hashes
prove stability only after restoration, not the original snapshot. Subsequent
mutations used isolated copies. Keep this limitation in the evidence history.

| Finding | Closure evidence, 2026-09-16 |
| --- | --- |
| P1-R1 payload in exception logs | Removed report(exception) on generation persistence failure; log only class, request UUID and validated five-character SQLSTATE/null. Three real-file canary cases cover QueryException, untrusted SQLSTATE and generic exception; no SQL/message/bindings/trace. Request fails atomically and replay does not call provider again |
| P2-R2 wrong Template path test gap | Listing and generate under wrong Template return 404 with unchanged provider/request counts |
| P2-R3 foreign-Activity bulk test gap | Real sibling-Activity item returns 404 and remains pending with no review/write/provider call; response-only assertions would miss the mutation |
| P2-R4 after-commit erasure test gap | Job afterCommit flag plus in-memory execution counter: zero before outer commit, one after; outer rollback runs zero jobs and retains content |

All four mechanisms independently removed on a separate copy/database made
tests fail (R1 three cases, R2 one, R3 one, R4 three); restored files matched
saved SHA-256 values and eight cases passed again. No root-tree mutation in this
remediation. Original self-review CHANGES REQUIRED is historical, superseded by
this closure evidence, not rewritten into an independent PASS.

### Default-suite reconciliation

| Matched diagnostic run | Passed | Failed/error | Skipped | Assertions |
| --- | --- | --- | --- | --- |
| Working tree inside sandbox | 1230 | 16 | 22 | 10842 |
| Working tree outside sandbox | 1239 | 7 | 22 | 10980 |
| Baseline 6782b45befccc361518227733b9fbed7c411e7d0 outside sandbox | 1239 | 7 | 22 | 10980 |

Implementer compared full JUnit class/test/dataset identities: zero additional
or resolved failures under matched PHP 8.3.33/runtime conditions. Baseline used
a separate git archive with pinned App/Tests/Database namespaces, Reflection
checks, isolated storage and shared ignored dependencies/models. An initial
attempt missing those runtimes was excluded. Default suites exclude Integration;
their unchanged totals do not measure the new Authoring tests.

Seven existing failures: one AudioProcessingLocalReview, five
MediaRevisionLifecycle (expected versions omit +vad, including a failed fixture
lookup), and one VideoTranscriptCaptionLocalReview (expects literal +ffmpeg
instead of compacted version hash). They are not missing-binary diagnoses.
Nine additional sandbox failures: Audio three, Document four, Video two.
Direct probes: say produced 110 near-silent samples inside sandbox versus 42920
outside; LibreOffice office.doc conversion exited 134 inside versus zero with a
17259-byte PDF outside. This establishes execution-environment differences, not
the precise OS denial or every historical run's cause.

The discrepancy blocker was resolved by that matched baseline comparison.
Final remediation default suite again had 1239/7/22, 10980 assertions; its JUnit
names matched the recorded seven, without another baseline rerun in that turn.
Full suite remains red; no assertion was weakened to claim green. Earlier
intermediate schema-scanner/test-fixture failures were corrected implementation
issues, not reclassified as baseline. Historical 16-failure results are retained.

## Reproduction and evidence retention

Use PHP 8.3 and a dedicated disposable MariaDB instance/database, never
learnforge_db. Verify SELECT VERSION(), @@datadir and timestamp defaults first.
Set APP_ENV=testing, DB_CONNECTION=mysql, DB_URL empty, DB_SOCKET to that private
instance and DB_DATABASE/DB_USERNAME/DB_PASSWORD to its test credentials.
Normal `php artisan test tests/Integration/AiAuthoringHttpMariaDbTest.php` rebuilds
test schema; do not use a production connection. Run the eight Authoring test
files and adjacent owner/Media suites for the wider scope above.

The standalone probe is `tests/Support/Ai/authoring-packet-concurrency.php`.
Create a new disposable database (fail if it already exists), migrate normally,
run AiAuthoringProposalPacketMariaDbTest, then run that PHP script on the same
private connection. It requires PROCESS privilege for actual lock-wait observation
and leaves committed fixtures. Drop only the database created for this run after
collecting results. It is a DB-level probe, not a service race test.

Final remediation logs/copy were retained at /private/tmp/lf7-remediation.XeYpGT
(red/green, related suite, four mutations and restored-copy checks). Temporary
databases/instance/datadir were removed; XAMPP was untouched. Earlier reports
referenced ephemeral scratch logs that may no longer exist; retention is not a
promise that those paths are durable audit storage. The five originals were
reported as archived before consolidation at
/private/tmp/lf-step7-doc-backup.Uo81eb/step7-original-reports.zip, but on
2026-09-17 neither that archive nor /private/tmp/lf7-remediation.XeYpGT existed.
The originals were never committed and should be treated as unrecoverable;
this curated record is the only retained Step 7 history.

Final runtime-stage docs:lint, schema:drift --docs-only (100 migrations), changed
PHP Pint, git diff --check and npm run build passed. Documentation consolidation
does not rerun or newly certify those runtime results.

## Remaining limits and acceptance boundary

- Not verified: service/HTTP on MariaDB 10.4; two-connection service races for
  edit/accept/retry/successor; real Redis worker/scheduler; GitHub CI; deployment.
- UI is not implemented; build success is not browser/UI verification.
- Provider activation, model quality, live migration and retention/legal-hold
  policy remain separate. No real AI/chat usage is required to accept backend.
- Independent review was waived by Owner for the 2026-09-17 scoped acceptance
  (miễn trừ, không phải PASS); existing schema waiver retains its exact scope.
- No known open finding remains among P1-R1 and P2-R2–R4. Baseline Media test
  repairs are a separate follow-up, not silently included in this work.
  Update 2026-09-26: the seven were stale test expectations predating the VAD
  amendment and were repaired in the
  [Knowledge backbone record](LF-AI-Knowledge-Backbone-Implementation-Record.md);
  the default suite now has no failures.
