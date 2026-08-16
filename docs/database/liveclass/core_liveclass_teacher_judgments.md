# Table: core_liveclass_teacher_judgments

Version: 1.0

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-08-15

Document Path: database/liveclass/core_liveclass_teacher_judgments.md

## Purpose

Append-only record of one teacher's judgment about one learner against one
versioned Learning Node. It is the immutable submission identity that
`core_learning_evidence.source_id` requires: Learning Foundation accepts
`source_type = teacher_judgment` only against a durable numeric source row, and
no mutable Cohort membership, Enrollment or Session row can serve that purpose.

LiveClass owns this table. Learning Foundation Version 1.1 is Frozen at ten
tables, so the source may not become an eleventh `core_learning_*` table.
Ownership of the judged data stays with Course/LiveClass; Learning receives
referential integrity, never write authority.

## Fields And Rules

| Field | Type | Rule |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED | Immutable numeric identity consumed by `Evidence.source_id`. |
| `customer_id` | BIGINT UNSIGNED | Required tenant ownership. |
| `submission_uuid` | CHAR(36) | Tenant-unique producer identity; also the Evidence and Calculation idempotency key. |
| `cohort_id` | BIGINT UNSIGNED | Cohort context at submission. |
| `cohort_teacher_assignment_id` | BIGINT UNSIGNED | Assignment that authorized the submission. |
| `cohort_student_membership_id` | BIGINT UNSIGNED | Membership observed at submission. |
| `enrollment_id` | BIGINT UNSIGNED | Learning-access authority for the learner. |
| `teacher_id` | BIGINT UNSIGNED | Human judgment actor. |
| `student_id` | BIGINT UNSIGNED | Learner being judged. |
| `framework_id` | BIGINT UNSIGNED | Denormalized Framework key; required by the parent candidate keys below. |
| `basis_framework_version_id` | BIGINT UNSIGNED | Explicit Calculation basis; never inferred as latest. |
| `learning_node_id` | BIGINT UNSIGNED | Exact versioned Learning semantic anchor. |
| `mastery_level_key` | VARCHAR(100) | Teacher-selected level, valid in the frozen basis scale. |
| `mastery_score` | DECIMAL(9,6) NULL | Optional normalized score valid under that scale. |
| `reason` | TEXT | Required human-readable judgment reason. |
| `context_snapshot` | JSON | Frozen Cohort, assignment, membership and rule context. |
| `occurred_at` | DATETIME(6) | When the observed judgment applies. |
| `submitted_at` | DATETIME(6) | Immutable submission time. |
| `supersedes_judgment_id` | BIGINT UNSIGNED NULL | Prior submission corrected by this row. |
| `created_at` | TIMESTAMP(6) NULL | Append timestamp. No `updated_at` business mutation contract. |

`occurred_at` and `submitted_at` are `DATETIME(6)`, not `TIMESTAMP(6)`. A
non-nullable `TIMESTAMP` column silently acquires
`DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` on any server running
`explicit_defaults_for_timestamp = OFF`, which would overwrite a recorded
historical moment on an unrelated update. The same decision governs the
non-nullable occurrence columns in Learning Foundation.

## Keys And Indexes

* `PRIMARY KEY (id)`.
* `UNIQUE (customer_id, submission_uuid)` — producer idempotency.
* `UNIQUE (id, customer_id)` — tenant-safe parent key for the correction chain.
* `UNIQUE (customer_id, supersedes_judgment_id)` — one successor per prior row,
  which makes the correction chain linear rather than a tree.
* `INDEX (customer_id, cohort_id, student_id, occurred_at)`.
* `INDEX (customer_id, learning_node_id, student_id, occurred_at)`.

## Foreign Keys

Every reference is composite and tenant-safe. All actions are
`ON UPDATE RESTRICT ON DELETE RESTRICT`; nothing here cascades.

| Child columns | Parent | Parent key |
| --- | --- | --- |
| `(customer_id)` | `saas_customers` | `(id)` |
| `(cohort_id, customer_id)` | `core_course_cohorts` | `(id, customer_id)` |
| `(cohort_teacher_assignment_id, customer_id)` | `core_course_cohort_teachers` | `(id, customer_id)` |
| `(cohort_student_membership_id, customer_id)` | `core_course_cohort_students` | `(id, customer_id)` |
| `(enrollment_id, customer_id)` | `core_course_enrollments` | `(id, customer_id)` |
| `(teacher_id, customer_id)` | `users` | `(id, customer_id)` |
| `(student_id, customer_id)` | `users` | `(id, customer_id)` |
| `(basis_framework_version_id, customer_id, framework_id)` | `core_learning_framework_versions` | `(id, customer_id, framework_id)` |
| `(learning_node_id, customer_id, framework_id, basis_framework_version_id)` | `core_learning_nodes` | `(id, customer_id, framework_id, framework_version_id)` |
| `(supersedes_judgment_id, customer_id)` | `core_liveclass_teacher_judgments` | `(id, customer_id)` |

The four Course parent keys are supplied by the Phase 4E Course parent-key
prerequisite. `users (id, customer_id)` comes from the Phase 4A prerequisite.

`framework_id` exists because `core_learning_framework_versions` declares no
`(id, customer_id)` candidate key; its tenant-safe key is
`(id, customer_id, framework_id)`. Carrying the Framework key then allows the
four-column Node reference above, which physically proves that the chosen Node
belongs to the declared basis Framework Version, in the declared Framework, in
the same tenant. Without it, that invariant would be application-only.

## CHECK Constraints

* `mastery_score IS NULL OR (mastery_score >= 0 AND mastery_score <= 1)`.
* `TRIM(reason) <> ''`.
* `JSON_VALID(context_snapshot)`.
* `TRIM(mastery_level_key) <> ''`.
* `occurred_at <= submitted_at`.

MariaDB does not allow an `AUTO_INCREMENT` column in a CHECK expression, so
self-correction is not expressed as `supersedes_judgment_id <> id`. The
fail-closed correction trigger rejects a missing/self predecessor before the
self-FK runs; `chk_ltj_004` instead protects the required level key.

## Append-Only Enforcement

Named triggers reject every `UPDATE` and every `DELETE` on this table. A
correction is a new row that sets `supersedes_judgment_id`; history is never
edited. The snapshot columns preserve the authorization facts observed at
submission, so later changes to Cohort membership or teacher assignment cannot
rewrite what the judgment was based on.

The normalized immutable trigger body used for both update and delete is:

```sql
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'LF_TEACHER_JUDGMENT_IMMUTABLE';
END
```

The normalized correction trigger body is:

```sql
BEGIN
    IF NEW.supersedes_judgment_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM core_liveclass_teacher_judgments prior
        WHERE prior.id = NEW.supersedes_judgment_id
          AND prior.customer_id <=> NEW.customer_id
          AND prior.cohort_id <=> NEW.cohort_id
          AND prior.cohort_student_membership_id
              <=> NEW.cohort_student_membership_id
          AND prior.enrollment_id <=> NEW.enrollment_id
          AND prior.student_id <=> NEW.student_id
          AND prior.framework_id <=> NEW.framework_id
          AND prior.basis_framework_version_id
              <=> NEW.basis_framework_version_id
          AND prior.learning_node_id <=> NEW.learning_node_id
          AND prior.occurred_at <=> NEW.occurred_at
          AND prior.submitted_at <= NEW.submitted_at
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'LF_TEACHER_JUDGMENT_CORRECTION_INVALID';
    END IF;
END
```

The `submitted_at` clause keeps the correction ahead of its predecessor in
projection order. The Calculation maps `calculated_at = submitted_at`, and the
Profile projector advances only when `(calculated_at, id)` increases; a
successor carrying an earlier `submitted_at` would still mark the predecessor
Evidence superseded while the Profile silently kept projecting it. `<=` is
correct rather than `<` because the auto-increment `id` already breaks ties in
the successor's favour.

`NOT EXISTS` fails closed when the predecessor is absent, any preserved
identity differs, or the correction would land out of order. Null-safe equality prevents SQL three-valued logic from
turning a future nullable field into a bypass. The self-FK independently
enforces predecessor existence, the unique successor key serializes competing
corrections, and append-only triggers prevent predecessor TOCTOU mutation.

A third named trigger fires before insert. When `supersedes_judgment_id` is not
null it requires the successor to match its predecessor on `customer_id`,
`cohort_id`, `cohort_student_membership_id`, `enrollment_id`, `student_id`,
`framework_id`, `basis_framework_version_id`, `learning_node_id` and
`occurred_at`. Correction
identity is a cross-row rule, so it belongs in the database rather than in the
command that happens to write the row.

## Learning Foundation Mapping

One submission produces exactly one Evidence row, one Calculation and one
Calculation-Evidence link inside a single transaction.

```text
source_type                = teacher_judgment
evidence_type              = expert_judgment
source_id                  = core_liveclass_teacher_judgments.id
source_discriminator       = submission_uuid
producer_idempotency_key   = submission_uuid
user_id                    = student_id
learning_node_id           = learning_node_id
source_occurred_at         = occurred_at
evaluated_at               = submitted_at
value_label                = mastery_level_key
value_numeric              = mastery_score
qualification_rule_key     = teacher_judgment_direct
qualification_rule_version = 1
qualification_rule_snapshot = frozen teacher_judgment_direct rule and source
valid_from                 = null
valid_until                = null
reassessment_due_at        = null
supersedes_evidence_id     = prior judgment Evidence id, or null
recorded_by                = teacher_id

calculation_source         = teacher_override
calculation_rule_key       = teacher_judgment_direct
calculation_rule_version   = 1
calculation_idempotency_key = submission_uuid
user_id                    = student_id
framework_id               = framework_id
node_definition_id         = definition of learning_node_id
basis_framework_version_id = basis_framework_version_id
mastery_level_key          = mastery_level_key
mastery_score              = mastery_score
calculation_rule_snapshot  = frozen teacher_judgment_direct rule
mastery_scale_key/version/snapshot = exact basis Version scale
continuity_policy_snapshot = null
source_node_relation_id    = null
source_calculation_id      = prior judgment Calculation id, or null
mastery_status_result      = established (Owner decision, 2026-08-15)
reassessment_due_at        = null
calculated_at              = submitted_at
calculated_by              = teacher_id
reason                     = judgment reason

calculation_evidence.user_id = student_id
calculation_evidence.evidence_role = included
calculation_evidence.effective_weight = 1
calculation_evidence.contribution = mastery_score, or null
calculation_evidence.reason_code = teacher_judgment_direct
calculation_evidence.reason_snapshot = frozen direct-rule explanation
```

The Calculation must copy `mastery_scale_key`, `mastery_scale_version` and
`mastery_scale_snapshot` verbatim from the basis Framework Version;
`trg_lrn_calcs_bi_validate` compares the snapshot for exact equality.

The qualification snapshot contains the exact `rule_key`, `rule_version` and
`source_type = teacher_judgment` required by the Evidence trigger, plus source
identity and frozen interpretation inputs. The calculation snapshot carries
the same deterministic direct-rule identity.

For a correction, the transaction locks the prior Judgment, Evidence and
Calculation. The successor Evidence sets `supersedes_evidence_id` to the prior
Evidence; the successor Calculation sets `source_calculation_id` to the prior
Calculation. Its Calculation-Evidence row includes only the successor Evidence
with weight one. Old rows remain immutable history. Current Evidence resolution
excludes the old Evidence through its successor, and the Profile projector
advances to the new Calculation.

A correction preserves tenant, Cohort, Cohort Student Membership, Enrollment,
learner, Framework, basis Version, Node, Definition and `occurred_at` identity.
The unique correction key permits one direct successor.

## Owner Decisions — 2026-08-15

```text
Role: LearnForge Architecture Owner
Date: 2026-08-15

1. teacher_judgment_direct Version 1 always produces
   mastery_status_result = established. The rule never emits needs_review or
   reassessment_due, and reassessment_due_at stays null.

2. A teacher other than the original actor may submit a correction successor,
   provided that teacher holds a valid assignment covering occurred_at. Both
   the original and the correcting actor remain in history; an ineligible
   teacher is rejected.
```

Decision 1 makes `mastery_status_result` constant for this producer. It is a
legal value of `chk_lrn_022`, and it stays coherent with the null
`reassessment_due_at` this rule emits. The Profile projection copies it, so a
Teacher Judgment Profile is always `established`.

Decision 2 is safe only because the successor may not move the judged moment.
`occurred_at` is therefore part of preserved correction identity above: the
successor copies the predecessor's `occurred_at`, and the correcting teacher's
assignment must cover that copied value — not a freshly chosen one. Without
that constraint a teacher whose assignment covers only a later window could
re-date a judgment into their own window and then "correct" a period they never
taught. Actor and assignment are frozen anew on the successor row, so the
original actor stays visible in history through the immutable predecessor.

## Enforcement Allocation

Database-enforced: tenant-safe composite identity, required and unique fields,
Node-to-basis-version membership, append-only behavior, correction linearity,
correction identity including preserved `occurred_at`, score range, non-empty
reason and valid snapshot JSON.

Application-enforced inside the same locked transaction: Cohort status
`active`, Enrollment status `active`, current membership, active teacher
assignment covering `occurred_at`, Framework Version `published` status,
mastery level/score validity under the selected basis scale, required
`context_snapshot` keys and canonical UUID syntax. The command compares the
locked rows explicitly: assignment Cohort/teacher must equal
`cohort_id`/`teacher_id`; membership Cohort/student/Enrollment must equal the
judgment; Enrollment learner, Product and Version must equal the membership and
Cohort contract. Prior Learning lineage must also match, and for a correction
the assignment must cover the predecessor's preserved `occurred_at`. Separate
tenant-safe FKs do not prove these cross-row equalities.

No CHECK or trigger covers Cohort or Enrollment lifecycle, and
`trg_lrn_calcs_bi_validate` accepts a matching basis snapshot regardless of
version lifecycle — tests must not claim the engine rejects those.

## Open Gates

The Architecture Owner approved this canonical document, all three trigger
specifications, source migration and simultaneous physical-contract activation
on 2026-08-15. Disposable MariaDB 11.4 rehearsal and independent source review
passed with no open finding. The migration was subsequently deployed on the
`learnforge_db` development database as batch 12 on 2026-08-15. Verification
found the table present with zero rows, ledger migration id 79 and complete
schema drift. Runtime, route, API, UI and production remain excluded.

That sentence records the source-migration acceptance boundary; the later
Runtime Internal authorization below supersedes only its internal-runtime
exclusion.

The Architecture Owner separately authorized Phase 4E Runtime Internal on
2026-08-15: internal service, locked end-to-end transaction,
correction/idempotency and isolated MariaDB integration tests. Route, API,
controller, UI, External Surface and production remain excluded.

## Development Deployment Record

```text
Environment: learnforge_db development
Migration: 2026_08_15_010000_create_liveclass_teacher_judgments
Batch: 12
Ledger id: 79
Table count after deployment: 71
Migration ledger count: 78
Teacher Judgment rows at verification: 0
schema:drift --connection=mysql: PASS
Production deployment: NOT AUTHORIZED / NOT PERFORMED
```
