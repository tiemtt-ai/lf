# Learning Foundation Phase 4E Teacher Judgment Design

Version: 0.1

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

## Recommended Source Contract

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

* composite tenant-safe foreign keys wherever parent composite keys exist;
* tenant-unique `submission_uuid` and producer idempotency key;
* linear correction chain; correction inserts a successor;
* every UPDATE and DELETE rejected physically;
* no cascade delete of source, actor, learner, assignment or membership;
* snapshots preserve the submitted authorization facts when mutable Course
  membership or assignment later changes.

This is a design proposal, not authorization to create the table or migration.

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

The initial direct rule proposal is:

```text
calculation_source = teacher_override
calculation_rule_key = teacher_judgment_direct
calculation_rule_version = 1
evidence_role = included
effective_weight = 1
```

The selected level and optional score must be valid in the exact Framework
Version mastery scale. Evidence uses:

```text
source_type = teacher_judgment
evidence_type = expert_judgment
source_id = core_liveclass_teacher_judgments.id
source_discriminator = submission_uuid
recorded_by = teacher_id
```

This rule proposal requires explicit Owner approval before implementation. It
must not be inferred merely from the existing `teacher_override` vocabulary.

## Default-Deny Authorization Proposal

Only a `teacher` principal may submit, and only when all conditions are true in
one tenant-scoped transaction:

* principal is the active `teacher_id` on the referenced Cohort assignment;
* assignment date range covers the approved judgment occurrence date;
* learner is the `student_id` on the referenced current Cohort membership;
* the membership Enrollment belongs to the same learner, tenant, Product and
  Version and is eligible under the approved lifecycle rule;
* Learning Node and explicit basis Framework Version belong to the tenant and
  Framework and are operationally eligible;
* no request field can override tenant, teacher actor or recorded-by identity.

`customer_admin`, student, AI, Track and unassigned teachers are denied by
default. Admin submission or override is not implied by the admin role.

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
* duplicate key with changed payload and double submit concurrency;
* source/Evidence/Calculation mutation or deletion;
* partial write after failure at every transaction stage;
* AI/Track access and any source other than `teacher_judgment`.

Verification must include MariaDB 11.4 with real CHECK/FK/trigger enforcement,
tenant/authorization feature tests, transaction rollback, concurrent duplicate
submission and complete schema drift.

## Owner Decisions Required Before Implementation

1. Approve LiveClass ownership and the proposed
   `core_liveclass_teacher_judgments` append-only source contract.
2. Approve or reject the `teacher_judgment_direct` Version 1 calculation rule.
3. Decide allowed Cohort and Enrollment lifecycle states and any post-Cohort
   submission window.
4. Confirm that `customer_admin` cannot submit Teacher Judgment in Phase 4E.
5. Authorize the source-table database documentation and architecture review;
   migration remains a later, separate authorization.

## Readiness Verdict

`BLOCKED FOR IMPLEMENTATION` — Phase 4E design preparation is complete enough
for Owner decisions, but immutable source persistence and authorization/rule
lifecycle are not yet Approved. No migration, source code, route, UI or real
database operation is permitted from this document.
