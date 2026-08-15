# Learning Foundation Phase 4E Teacher Judgment Design

Version: 0.6

Document Status: Review

Implementation Status: Not Implemented

Last Updated: 2026-08-15

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
   Migration and runtime implementation still require separate authorization.

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

## Remaining Gates Before Implementation

1. Deploy and accept the separately authorized Course parent-key prerequisite;
   source implementation cannot assume undeployed parent candidate keys.
2. Close independent Database/Architecture Review for the source table and
   end-to-end transaction. Canonical documentation and planned schema-contract
   registration are complete.
3. Obtain separate Owner authorization for the source migration and runtime
   implementation.
4. Implement and pass the required MariaDB 11.4 and authorization test matrix.

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
editorial correction. Separate source/runtime authorization remains the
implementation gate.

## Readiness Verdict

`DATABASE AND END-TO-END TECHNICAL REVIEW PASS; PENDING SOURCE IMPLEMENTATION
AUTHORIZATION` — the source table and planned schema contract exist with zero
open review findings. No migration, source code, route, UI or real database
operation is permitted from this document.
