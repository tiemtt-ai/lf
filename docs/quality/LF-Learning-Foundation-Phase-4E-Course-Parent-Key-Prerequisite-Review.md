# Learning Foundation Phase 4E Course Parent-Key Prerequisite Review

Version: 0.2

Document Status: Review

Implementation Status: Not Implemented

Last Updated: 2026-08-15

Document Path: quality/LF-Learning-Foundation-Phase-4E-Course-Parent-Key-Prerequisite-Review.md

## Classification

Classification: Existing-Feature Change.

Initial and Final Documentation Audit Level: HIGH. The proposal changes index
contracts on four released Course tables so a LiveClass-owned source can use
tenant-safe composite foreign keys. It touches tenant isolation, cross-domain
ownership, schema, migration rehearsal and backward compatibility.

This review is documentation-only. It does not authorize a migration, source
code, a real-database write or deployment.

## Documents And Source Reviewed

* LF Architecture Guardrails and Development Standards.
* LF Regression Audit and Architecture Review Checklist.
* ADR-0016 and LF-Core-Learning.
* LF-Core-Course and LF-Core-LiveClass.
* Phase 4E Teacher Judgment Design.
* Canonical table documents and creation migrations for the four Course
  parents.
* Phase 4A User composite-key prerequisite record and migration pattern.

Source inspection confirms that every parent has a primary key on `id` and a
required `customer_id`, but none has an exact `UNIQUE (id, customer_id)` key.
Existing business keys do not substitute for that candidate key.

## Proposed Physical Contract

| Parent table | New named unique key |
| --- | --- |
| `core_course_cohorts` | `uk_core_course_cohorts_id_customer (id, customer_id)` |
| `core_course_cohort_teachers` | `uk_core_course_cohort_teachers_id_customer (id, customer_id)` |
| `core_course_cohort_students` | `uk_core_course_cohort_students_id_customer (id, customer_id)` |
| `core_course_enrollments` | `uk_core_course_enrollments_id_customer (id, customer_id)` |

The column order is deliberate because each future child reference has the
shape `(parent_id, customer_id)`. The keys do not replace primary or business
keys and do not change Course lifecycle, ownership or runtime authority.

The future `core_liveclass_teacher_judgments` table may reference:

```text
(cohort_id, customer_id)
    -> core_course_cohorts(id, customer_id)
(cohort_teacher_assignment_id, customer_id)
    -> core_course_cohort_teachers(id, customer_id)
(cohort_student_membership_id, customer_id)
    -> core_course_cohort_students(id, customer_id)
(enrollment_id, customer_id)
    -> core_course_enrollments(id, customer_id)
```

All future delete actions remain `RESTRICT`. These keys do not authorize or
create the child table.

## Required Read-Only Preflight

Before a migration is written or rehearsed, an explicitly authorized operator
must verify each target schema without mutation:

1. all four tables and both columns exist with compatible unsigned types;
2. `customer_id` is `NOT NULL` on every table;
3. no row has a null or orphan tenant;
4. exact ordered unique indexes, wrong-order indexes and partial overlaps are
   inventoried separately;
5. every table uses an engine and collation compatible with the future child;
6. table size and operational lock risk are recorded.

An exact `UNIQUE (id, customer_id)` under any name is an idempotent satisfied
contract and the migration must no-op for that table. A wrong-order key,
partial overlap, non-unique index, conflicting canonical name, null/orphan
tenant or schema mismatch is `BLOCKED`. No data repair, rename or index
replacement is authorized by this review.

Duplicate `(id, customer_id)` pairs cannot exist while `id` remains the primary
key. That fact does not remove the tenant-integrity and schema checks above.

## Future Migration And Rollback Contract

Subject to separate Owner authorization, use one forward Course-prerequisite
migration containing only the four named unique keys. The migration must:

* fail closed unless preflight invariants hold;
* treat an exact equivalent ordered unique index as an idempotent no-op;
* fail closed on wrong-order, partial, non-unique or name-conflicting indexes;
* use the repository-supported online DDL strategy where the engine permits;
* make no data, primary-key, lifecycle or foreign-key change;
* drop only the four exact keys in `down()` and fail closed on ambiguity.

The prerequisite migration must be deployed and accepted before the future
Teacher Judgment source migration. Rollback of a parent key is forbidden while
a child foreign key depends on it; combined deployment/rollback ordering must
encode that dependency explicitly.

## Impact And Compatibility Analysis

* Domain ownership remains Course for all four parent records.
* LiveClass receives referential integrity, not write authority over Course.
* Existing primary keys, business unique keys and query results are unchanged.
* Added indexes increase storage and write-index maintenance; table size and
  online-DDL behavior remain rehearsal evidence requirements.
* Existing consumers remain compatible because no column or key is removed.
* Tenant isolation is strengthened: a child cannot pair a parent ID from one
  tenant with another `customer_id`.
* Historical rows are not rewritten or deleted.

## Required Verification Matrix

| Invariant | Required evidence |
| --- | --- |
| Existing exact/equivalent key is not duplicated | Source test plus `information_schema.statistics` probe |
| Null/orphan tenant fails closed | Negative preflight tests |
| Four exact named keys exist after forward migration | MariaDB 11.4 schema assertion |
| Schema contract records all four exact keys | Direct contract inventory compared with `information_schema.statistics` |
| Existing Course foreign keys and flows remain intact | Course module and tenant regression tests |
| Cross-tenant child reference is impossible | Disposable MariaDB negative FK probe |
| Fresh reconstruction succeeds | Isolated `migrate:fresh` and schema drift |
| Rollback removes only the four keys | Before/after schema inventory |
| Real LearnForge data is untouched | Explicit target guard and cleanup evidence |

Schema drift remains a reconstruction and missing-object gate. It is not
evidence that the schema contract records these keys because the current
analyzer does not reject extra indexes. Contract coverage must use the direct
inventory comparison above.

The final HIGH implementation audit also requires targeted, Course/shared and
full tests, scoped formatting, documentation lint, schema drift and final diff
review. All database verification must use an explicitly disposable MariaDB
environment and delete it on success or failure.

## Architecture Checklist Result

* Section A: PASS — Course ownership is unchanged.
* Section B: PASS IN DESIGN — composite keys strengthen tenant identity.
* Section C: N/A — no version or snapshot lifecycle changes.
* Section D: PASS IN DESIGN — no business-state transition changes.
* Section E: PASS IN DESIGN — exact candidate keys and rollback are specified.
* Section F: PASS IN DESIGN — Guardrails and ADR-0016 remain compatible.
* Section G: PENDING — canonical Course table docs are not yet amended.
* Section H: BLOCKED — independent re-review, preflight and Owner migration
  authorization remain outstanding.

## Independent Review Round 1

The independent documentation review on 2026-08-15 confirmed the four parent
shapes and the need for exact composite candidate keys. It raised one MEDIUM
evidence gap and two LOW editorial/contract ambiguities:

| Finding | Remediation in Version 0.2 |
| --- | --- |
| MEDIUM — schema drift cannot prove contract inventory | Added a direct contract-to-`information_schema.statistics` acceptance row and limited the drift claim. |
| LOW — abbreviated index names are error-prone | Replaced all four names with full-table canonical names below MariaDB's identifier limit. |
| LOW — equivalent-index handling was contradictory | Exact ordered unique is now idempotent no-op; wrong-order/partial/conflicting definitions are BLOCKED. |

## Findings By Severity

```text
BLOCKER  0
HIGH     0
MEDIUM   0
LOW      0
```

Unverified items are gates rather than closed evidence: selected real-schema
preflight, physical MariaDB rehearsal and regression tests have not run.

## Final Verdict

`REMEDIATED; PENDING INDEPENDENT RE-REVIEW; BLOCKED FOR MIGRATION`.

The proposed parent-key contract is internally consistent and preserves Course
ownership, but canonical table-doc amendments, independent re-review,
read-only preflight and explicit Owner migration authorization are still
required. No migration or database operation is authorized by this verdict.
