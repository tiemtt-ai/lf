# Learning Foundation Phase 4E Teacher Judgment Design

Version: 1.8

Document Status: Review

Implementation Status: Partial

Last Updated: 2026-08-17

Document Path: quality/LF-Learning-Foundation-Phase-4E-Teacher-Judgment-Design.md

## Authorization And Classification

The LearnForge Architecture Owner authorized Phase 4E design and
implementation preparation on 2026-08-15. Execution against the real
LearnForge database, migration, production deployment, AI and Track remain
excluded.

Classification: Existing-Feature Change. Initial Audit Level: HIGH because the
flow crosses tenant/role authorization, Course/LiveClass membership, immutable
lineage, Learning Evidence, append-only Calculation and Profile projection.

## Sources Of Truth

* ADR-0016 and LF-Core-Learning own Evidence and Mastery semantics.
* LiveClass is the documented producer Domain for Teacher Judgment.
* `core_course_cohort_teachers` is the Cohort teacher assignment authority.
* `core_course_cohort_students` identifies current Cohort membership;
  `core_course_enrollments` remains the learning-access authority.
* `core_learning_evidence` stores qualified immutable Evidence.
* `core_learning_mastery_calculations` is append-only decision history.
* `core_learning_mastery_profiles` is the rebuildable current projection.

## Confirmed Gap

`core_learning_evidence.source_id` requires an immutable numeric Teacher
Judgment submission identity. No such source table or event exists. A user ID,
mutable Cohort membership, Enrollment or Session row cannot substitute for the
submission identity. Implementing 4E without this source would create lineage
that cannot be independently replayed or audited.

The source must not become an eleventh `core_learning_*` Foundation table.
Learning Foundation Version 1.1 is Frozen at ten tables, and LiveClass is the
documented producer Domain.

## Approved Design Direction

The LearnForge Architecture Owner resolved the five Phase 4E design decisions
on 2026-08-15:

1. LiveClass owns the append-only
   `core_liveclass_teacher_judgments` source contract.
2. `teacher_judgment_direct` Version 1 is the initial direct calculation rule.
3. Submission is allowed only while the Cohort, Enrollment, current Membership
   and teacher assignment are active and eligible in the same tenant. The
   judgment occurrence date must fall inside both the Cohort operating period
   and assignment range. Phase 4E provides no post-Cohort submission window.
4. `customer_admin`, student, AI, Track and unassigned teachers cannot submit
   Teacher Judgment.
5. Source-table database documentation and architecture review are authorized.
   Migration and runtime implementation require separate authorization.

These decisions approve the design direction only. They do not change the
document status or authorize a migration, real-database operation, API, UI or
deployment.

## Approved Source Contract Direction

Prepare a separately reviewed LiveClass-owned append-only table:

```text
core_liveclass_teacher_judgments
```

Minimum contract:

| Field | Rule |
| --- | --- |
| `id` | Immutable numeric identity used by `Evidence.source_id`. |
| `customer_id` | Required tenant ownership. |
| `submission_uuid` | Tenant-unique immutable client/producer identity. |
| `cohort_id` | Cohort context at submission. |
| `cohort_teacher_assignment_id` | Assignment used for authorization. |
| `cohort_student_membership_id` | Membership observed at submission. |
| `teacher_id` | Human judgment actor. |
| `student_id` | Learner being judged. |
| `learning_node_id` | Exact versioned Learning semantic anchor. |
| `basis_framework_version_id` | Explicit Calculation basis; never inferred as latest. |
| `mastery_level_key` | Teacher-selected result under the frozen basis scale. |
| `mastery_score` | Optional normalized score valid under that scale. |
| `reason` | Required human-readable judgment reason. |
| `context_snapshot` | Frozen Cohort, assignment, membership and rule context. |
| `occurred_at` | When the observed judgment applies. |
| `submitted_at` | Immutable submission time. |
| `supersedes_judgment_id` | Optional prior submission corrected by this row. |
| `created_at` | Append timestamp; no business update contract. |

Required physical behavior:

* composite tenant-safe foreign keys for every tenant-owned parent;
* tenant-unique `submission_uuid` and producer idempotency key;
* linear correction chain; correction inserts a successor;
* every UPDATE and DELETE rejected physically;
* no cascade delete of source, actor, learner, assignment or membership;
* snapshots preserve the submitted authorization facts when mutable Course
  membership or assignment later changes.

This contract direction is approved for canonical database documentation and
architecture review. It is not authorization to create the table or migration.

## Released-Parent Composite-Key Prerequisite

The released Course tables do not currently expose the composite candidate
keys required by tenant-safe child foreign keys:

| Parent | Required candidate key |
| --- | --- |
| `core_course_cohorts` | `UNIQUE (id, customer_id)` |
| `core_course_cohort_teachers` | `UNIQUE (id, customer_id)` |
| `core_course_cohort_students` | `UNIQUE (id, customer_id)` |
| `core_course_enrollments` | `UNIQUE (id, customer_id)` |

The primary key on `id` makes duplicate row identities impossible, but it does
not provide the exact composite parent key needed by MariaDB for the proposed
child foreign keys. These four additions are HIGH Existing-Feature Changes to
released Course contracts. They require read-only preflight, separate Course
regression/tenant-boundary audit, named forward migrations and isolated
MariaDB rehearsal before the Teacher Judgment source migration can be
authorized. Existing equivalent indexes must be detected and never duplicated.

## Recommended End-To-End Transaction

```text
Authorize and lock Cohort/teacher/membership/basis
    -> append Teacher Judgment source
    -> append expert_judgment Evidence
    -> append teacher_override Calculation
    -> append Calculation-Evidence lineage
    -> project Mastery Profile
    -> commit once
```

The command is idempotent by `submission_uuid`. A retry returns the existing
source, Evidence, Calculation and Profile outcome only when the complete frozen
payload matches. A reused key with a different payload fails closed.

The approved initial direct rule is:

```text
calculation_source = teacher_override
calculation_rule_key = teacher_judgment_direct
calculation_rule_version = 1
calculated_by = teacher_id
reason = teacher judgment reason
source_calculation_id = prior direct Calculation id for correction, else null
source_node_relation_id = null
continuity_policy_snapshot = null
evidence_role = included
effective_weight = 1
calculation_evidence.reason_code = teacher_judgment_direct
```

The selected level and optional score must be valid in the exact Framework
Version mastery scale. Evidence uses:

```text
source_type = teacher_judgment
evidence_type = expert_judgment
source_id = core_liveclass_teacher_judgments.id
source_discriminator = submission_uuid
producer_idempotency_key = submission_uuid
user_id = student_id
learning_node_id = learning_node_id
source_occurred_at = occurred_at
evaluated_at = submitted_at
value_label = mastery_level_key
value_numeric = mastery_score
qualification_rule_key/version = teacher_judgment_direct / 1
valid_from / valid_until / reassessment_due_at = null
supersedes_evidence_id = prior judgment Evidence id for correction, else null
recorded_by = teacher_id
```

Both Evidence producer fields are populated deliberately. The unique producer
contract uses `producer_idempotency_key`; `source_discriminator` preserves the
immutable source-level discriminator. Reusing the UUID with a different frozen
payload must fail closed.

The qualification snapshot freezes `rule_key`, `rule_version`,
`source_type = teacher_judgment`, source identity and interpretation inputs.
The Calculation copies learner, Framework, Node Definition, basis Version,
result level/score and the exact basis scale key/version/snapshot. Version 1
uses the Owner-approved `mastery_status_result = established`, sets
`calculated_at = submitted_at` and has no reassessment date. The
Calculation-Evidence row copies
`user_id`, includes
the new Evidence with weight one, uses the nullable score as contribution and
freezes `reason_code = teacher_judgment_direct` with an explanation snapshot.

Correction locks the prior source, Evidence and Calculation. The new Evidence
sets `supersedes_evidence_id`; the new teacher-override Calculation sets
`source_calculation_id`; its lineage includes only the successor Evidence.
Historical rows remain immutable, current-effective Evidence excludes the
superseded row, and Profile projection advances to the successor Calculation.

This rule was explicitly approved by the Owner on 2026-08-15. Its physical and
runtime contracts must still pass database and implementation review.

## Approved Default-Deny Authorization Direction

Only a `teacher` principal may submit, and only when all conditions are true in
one tenant-scoped transaction:

* principal is the active `teacher_id` on the referenced Cohort assignment;
* Cohort status is `active`; `draft`, `completed` and `archived` are denied;
* assignment date range covers the approved judgment occurrence date;
* learner is the `student_id` on the referenced current Cohort membership;
* the membership Enrollment has `status = active` and belongs to the same
  learner, tenant, Product and Version;
* Learning Node and explicit basis Framework Version belong to the tenant and
  Framework and are operationally eligible;
* no request field can override tenant, teacher actor or recorded-by identity.

The transaction compares locked rows, not merely independent tenant-safe FKs:
assignment Cohort/teacher must match the command; membership
Cohort/student/Enrollment must match; Enrollment learner, Product and Version
must match membership and Cohort. Correction preserves tenant, Cohort, Cohort
Student Membership, Enrollment, learner, Framework, basis Version, Node,
Definition and the predecessor's `occurred_at`. The Owner-approved successor
actor may differ only when independently eligible through an active assignment
covering that preserved `occurred_at`.

`customer_admin`, student, AI, Track and unassigned teachers are denied by
default. Admin submission or override is not implied by the admin role.

The command must be submitted no later than the Cohort end boundary. There is
no normal post-Cohort correction command in Phase 4E. A correction during the
allowed window creates a successor row; after closure, history remains
immutable unless a separately reviewed future workflow is approved.

## Required Negative Matrix

At minimum, tests must reject:

* cross-tenant actor, learner, Cohort, assignment, membership, Node or basis;
* inactive/unassigned teacher and role tampering;
* learner outside the Cohort or mismatched Enrollment;
* disallowed Cohort/Enrollment lifecycle;
* assignment date outside the judgment occurrence date;
* unpublished/wrong-Framework Node or ambiguous basis;
* unsupported source/evidence/calculation type;
* invalid level/score or rule snapshot;
* malformed producer UUID or incomplete authorization snapshot;
* assignment, membership or Enrollment rows that are individually tenant-safe
  but mutually inconsistent;
* correction of an unrelated learner, Cohort, Enrollment, Framework, basis,
  Node, Evidence or Calculation;
* duplicate key with changed payload and double submit concurrency;
* source/Evidence/Calculation mutation or deletion;
* partial write after failure at every transaction stage;
* AI/Track access and any source other than `teacher_judgment`.

### Enforcement Allocation

Database enforcement must cover tenant-safe composite identity, required and
unique fields, append-only source/Evidence/Calculation behavior, correction
linearity, supported Learning vocabularies, exact rule/scale snapshots and
Calculation-to-Evidence lineage.

Application authorization inside the locked transaction must cover Cohort
`active` status, Enrollment `active` status, current Membership, active teacher
assignment and date range, Framework Version `published` status and the
operational eligibility of the versioned Node. The existing Learning trigger
accepts a matching basis snapshot regardless of lifecycle status; therefore
tests must not claim that MariaDB currently rejects a `draft_snapshot` basis.

Verification must combine MariaDB 11.4 CHECK/FK/trigger tests for database-owned
rules with tenant/authorization feature tests for application-owned rules. It
must also cover transaction rollback, concurrent duplicate submission and
complete schema drift.

## Runtime Internal Authorization

```text
Role: LearnForge Architecture Owner
Date: 2026-08-15
Decision: Approved Phase 4E Runtime Internal
Authorized: internal service; locked end-to-end transaction;
            correction and idempotency; MariaDB integration tests
Excluded: route; API; controller; UI; External Surface; production
```

This authorization does not create a callable external surface. The internal
service must remain unreachable from HTTP until External Surface receives a
separate approval after the MariaDB negative matrix and independent code
review pass.

## Remaining Gates Before External Surface

1. Complete independent runtime and migration code review by a reviewer who did
   not author them. Review by the author closes no gate however thorough it is.
   Performed on 2026-08-17 and recorded in
   [Phase 4E Runtime Independent Code Review](LF-Learning-Foundation-Phase-4E-Runtime-Independent-Code-Review.md):
   verdict **FAIL**. All five items were remediated on 2026-08-17 as recorded
   below. The gate stays open until the reviewer re-examines them: remediation
   by the author does not close a review any more than the original code did.
   Re-reviewed the same day: B1 to B4 accepted as closed in code, B5 not closed.
   The gate remains open on B5 and on four re-review items recorded in that
   document's Re-Review section.
2. Obtain separate authorization before route, API, controller or UI work. This
   now covers the user-facing Framework authoring surface as well: the internal
   authoring path exists, but no screen or endpoint reaches it.
3. Complete the separate production deployment gate.

## Timestamp Convention

```text
Role: LearnForge Architecture Owner
Date: 2026-08-17
Decision: Learning writers store naive wall-clock in the application timezone,
          matching every other writer in LearnForge. Inbound timestamps must
          carry an explicit offset and are converted before they are stored.
```

The risk was never UTC versus local. It was divergence. A database that applies
one convention everywhere can still be converted wholesale later; one where a
single immutable table speaks a different language cannot, because the row
records nothing about which convention produced it. Storing UTC would not have
removed the split — it would have moved it from the writer to every reader of a
Course timestamp, multiplying the number of places that can be wrong and making
each depend on an assumption about `config/app.php` at the time some other
domain wrote the row.

Two facts make the decision cheap. Vietnam observes no daylight saving, so wall
clock is monotonic and no local time repeats. And both `chk_ltj_005` and
`trg_lrn_evidence_bi_validate` (`evaluated_at >= source_occurred_at`) compare
two columns written under the same convention, so the frozen physical contract
is untouched: no migration, no trigger change.

Storage stays ambiguous because the whole database is ambiguous. The input
contract does not: a caller must state an offset. Accepting a naive string is
precisely how a caller's local "now" came to be read as a moment seven hours
away, and the destination row is protected by `trg_ltj_bu_immutable` and
`trg_ltj_bd_immutable`, so there is no repair path afterwards.

Applied at six sites: `submitted_at` and inbound `occurred_at` in
`TeacherJudgmentService`, its stored-timestamp reader,
`LearningFrameworkAuthoringService`, and both the `projected_at` writer and the
ordering comparison in `LearningMasteryProfileProjector`. The review listed
five; the projector's writer was found while applying the fix.

## Independent Review Remediation — 2026-08-17

| Finding | Remediation |
| --- | --- |
| B1 timestamp convention | Owner decision above, applied at six sites |
| B2 Cohort/assignment date window | Resolved as a consequence of B1: the window compares dates cut from a value that is no longer shifted |
| B3 correction rewrote `occurred_at` | The caller's value is no longer overwritten. A mismatch is now rejected by `priorIdentityMatches()` and `trg_ltj_bi_correction` instead of being silently satisfied, and a valid retry no longer reports an idempotency conflict |
| B4 Cohort end boundary | `submitted_at` is now bounded by the Cohort end date, distinct from the occurrence bound that already existed |
| B5 test gaps | The five fail-closed cases assert exact error codes; the teacher role rule, the offset contract and the correction moment each gained a test |

## Re-Review Remediation — 2026-08-17

The first remediation narrowed the gate without closing it. Four items followed.

| Item | Remediation |
| --- | --- |
| R1 correction replay untested | The correction is now submitted twice and the retry asserted as a replay. The earlier test replayed the *first* record, which never touched the branch B3 broke, so any reintroduced normalization of `occurred_at` on the correction path would have passed unnoticed |
| R2 `COHORT_WINDOW_CLOSED` untested | The rule arrived in the same change that closed a missing-test finding, and its code appeared once in the repository — where it is thrown. Now driven by a Cohort whose period has ended while the occurrence stays inside it |
| R3 pre-existing rows in the old convention | Counted across all nine tables on `learnforge_db`: zero. Nothing to reconcile, and no Owner decision needed |
| R4 offset pattern | A two-digit `+07` is valid ISO-8601 and is now accepted. The pattern anchors on a time before the offset, which rejects `not-a-date+07:00` as a domain error instead of letting it escape as `InvalidFormatException`, and still rejects a bare `YYYY-MM-DD` whose trailing `-15` would otherwise read as an offset. A syntactically valid but impossible date raises `OCCURRED_AT_INVALID` |

Cohort eligibility was also split out of the assignment guard into
`LF_TEACHER_JUDGMENT_COHORT_DENIED`. Recording the collision was the wrong
remedy: the point of asserting exact codes is that each rule can be proven on
its own, and two rules sharing a code prove neither. That a caller cannot tell
them apart is an argument about the external surface, and it runs the other
way — keep the internal codes precise and collapse them at the API boundary if
that is wanted, rather than losing the distinction where the rule is enforced.

Two negative branches named by the Owner decisions but never exercised were
added: an active teacher holding no assignment on the Cohort, and an occurrence
outside the assignment date range.

Every `LF_TEACHER_JUDGMENT_*` authorization code now appears in at least one
test. MariaDB 10.4.21 against an isolated database: 16 tests, 82 assertions.

MariaDB 10.4.21 against an isolated database: 13 tests, 69 assertions across
both runtime suites. Default suite 733 tests, 8,241 assertions, one skipped.
`learnforge_db` untouched at 71 tables and 11 users.

## Framework Authoring Path

Teacher Judgment could not be exercised at all while nothing created a Framework
Version or Node, so `LearningFrameworkAuthoringService` provides that path for
internal callers on 2026-08-16. It creates a Framework, a draft Version, a Node
Definition and a Node, and publishes the Version. It exposes no route or
controller, so it does not consume the external-surface gate above.

Two rules in it have no database backstop and are therefore application-owned:
a Node may only be added while its Version is `draft_snapshot`, because
`core_learning_nodes` has no before-insert trigger and the engine would
otherwise accept a Node added to a published Version; and a Framework must still
be `active` to receive new Versions or Definitions.

Which roles may author a Framework is not decided. The service requires an
active user in the tenant and nothing more, because no Owner decision names an
authoring role. That silence is a deferred decision, not permission.

Verified on MariaDB 10.4.21 against an isolated database: five tests and 24
assertions. The load-bearing case authors a Framework end to end and then
submits a real Teacher Judgment against the Node it produced, asserting the
whole chain — source row, Evidence, Calculation, Calculation Evidence and the
projected Profile — resolves to that Node. Asserting only that rows were
inserted would have proved the service writes, not that the gate is closed. The
remaining cases cover the frozen-after-publish rule, one-way publish, invalid
scales failing as domain errors before reaching `trg_lrn_frameworks_bi_scale`,
and tenant scoping for Framework, actor and missing tenant context.

Both this suite and `TeacherJudgmentRuntimeMariaDbTest` were absent from the CI
MariaDB job and ran only on developer machines; both are now listed there.

## Runtime Internal Evidence

The internal service implements one locked transaction from Judgment through
Evidence, teacher-override Calculation, Calculation Evidence and Mastery
Profile. It owns correction lineage and producer UUID replay without exposing
an HTTP caller.

Disposable MariaDB 11.4.12 verification on 2026-08-15 passed four tests with
33 assertions: successful append/projection, exact replay, correction by a
different eligible teacher, five application-owned fail-closed rules and
rollback after a late Calculation conflict. The full default suite passed 732
tests with 8,241 assertions and one skipped test. The disposable database,
server and datadir were removed after verification.

The fail-closed matrix drives each of the five rules through one denied state:
a `closed` Cohort, a `cancelled` Enrollment, an `inactive` membership, an
`inactive` assignment and a `deprecated` basis.

The published-basis rule carries the remaining risk, because it is the only
application-owned rule with no database backstop —
`trg_lrn_calcs_bi_validate` accepts any basis whose scale snapshot matches,
whatever its lifecycle. Its two other denied states are therefore covered
separately on 2026-08-16. Version lifecycle is one-way, so each state needs its
own fixture: `archived` is reached through `deprecated`, and `draft_snapshot`
requires a second version that was never published, with its own Node. Both
submissions are rejected with exactly `LF_TEACHER_JUDGMENT_BASIS_INVALID` and
leave no source row; the assertion checks that exact code rather than a prefix,
so it cannot pass on rejection by an earlier rule. The suite is now five tests
and 37 assertions.

## Independent Database And End-To-End Review

Round 1 found three MAJOR, one MEDIUM and one LOW documentation gap: incomplete
correction lineage, incomplete Learning field mapping, unstated cross-row
Course coherence, incomplete scale/snapshot enforcement allocation and a stale
schema-contract gate. Version 0.4 and table-doc Version 0.2 closed all five.

Round 2 reported technical PASS with no physical or trigger incompatibility.
Two policy choices were correctly separated as Owner decisions rather than
silently approved implementation behavior:

1. whether direct Teacher Judgment always projects
   `mastery_status_result = established` in Version 1;
2. whether a different currently eligible teacher may submit a correction, or
   only the original actor may do so.

The Architecture Owner approved both policy choices on 2026-08-15. Independent
review then required a fail-closed normalized insert-trigger body and preserved
Cohort Student Membership identity; Version 0.6 and table-doc Version 0.5 add
both. Round 4 closed the technical review with zero open findings after one
editorial correction.

## Source Migration Authorization

```text
Role: LearnForge Architecture Owner
Date: 2026-08-15
Decision: Approved canonical table document and three trigger specifications
Authorized: source migration; simultaneous schema-contract activation;
            disposable MariaDB rehearsal and tests
Excluded: runtime; API; UI; real-database deployment; production
```

## Readiness Verdict

The first independent source review found that separate MariaDB DDL statements
could leave an unrecoverable partial install and that rollback could orphan
Learning Evidence lineage. The migration was hardened before acceptance:

* all ten foreign keys are created atomically with the table;
* exact parent candidate keys and schema-wide trigger-name vacancy are checked
  before installation;
* any DDL interruption fails closed without automatically deleting physical
  objects; recovery follows the explicit runbook below;
* rollback refuses a non-empty source table or existing
  `source_type = teacher_judgment` Learning Evidence;
* rollback verifies all three trigger owners and normalized bodies before
  dropping any physical object;
* non-MySQL test schemas skip this physical-enforcement migration consistently
  with the released Learning migration.

Disposable MariaDB 11.4.12 evidence on 2026-08-15:

```text
fresh 78-migration reconstruction                         PASS
20 columns / 5 canonical indexes / 10 FK / 5 CHECK        PASS
3 exact trigger bodies                                    PASS
correction, immutability, tenant and duplicate probes     PASS
trigger-name conflict rejected before table creation      PASS
hard interruption leaves partial state fail-closed         PASS
wrong-owner trigger made rollback fail before mutation    PASS
non-empty rollback rejected without mutation              PASS
down / re-forward                                         PASS
schema:drift --connection=mysql                           PASS
isolated database and datadir cleanup                     PASS
```

The rehearsal also found that MariaDB rejects a CHECK referencing an
AUTO_INCREMENT id. `chk_ltj_004` was therefore normalized to the portable
non-empty `mastery_level_key` rule; self-correction remains fail-closed through
the normalized correction trigger and composite self-FK.

## Interrupted-DDL Recovery Runbook

MariaDB commits each DDL statement independently. If the migration stops after
the table is created but before Laravel records the ledger row, do not rerun it
and do not drop anything automatically.

1. Stop application writes and preserve the MariaDB error plus migration log.
2. Confirm the migration ledger has no
   `2026_08_15_010000_create_liveclass_teacher_judgments` row.
3. Run `schema:drift --connection=mysql` and export `SHOW CREATE TABLE`, all
   `information_schema` columns/indexes/FKs/CHECKs and all triggers attached to
   the table.
4. Require Database/Architecture review to compare the full physical shape
   and trigger bodies with the canonical contract. Counts or names alone are
   insufficient.
5. If any Judgment row or `source_type = teacher_judgment` Evidence exists,
   stop; deletion is forbidden and a forward repair migration is required.
6. Only a DBA with explicit recovery authorization may remove an empty partial
   table and its verified owned triggers. Then rerun the source migration.
7. Re-run full schema drift, trigger behavior, correction, tenant isolation and
   cleanup verification before reopening writes.

This runbook is deliberately manual: an automated retry cannot prove ownership
after a process crash and therefore must never guess that an existing table or
trigger is safe to delete.

Round 4 independent source re-review closed with zero code or architecture
findings. The latest full application verification passed 732 tests with 8,241
assertions and one skipped test (733 total).

The migration was subsequently verified as batch 12 on the `learnforge_db`
development database. The database now has 71 tables and 78 migration-ledger
rows; the Teacher Judgment table was empty at verification. Selected-connection
schema drift and the 11/11 correction/immutability behavior probe passed.

`SOURCE MIGRATION TECHNICAL ACCEPTANCE PASS — DEPLOYED ON DEVELOPMENT, NOT
PRODUCTION` — runtime, route, API, UI and production remain excluded.
