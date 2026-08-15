# Learning Foundation Phase 4E Course Parent-Key Prerequisite Review

Version: 0.5

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

The full-table names are intentional. Three target tables use abbreviated
local prefixes for existing operational indexes, while Cohort Students and the
equivalent User tenant-parent prerequisite use full names. This prerequisite
follows `uk_users_id_customer` for readability and rollback safety across the
same class of tenant identity keys; it does not claim that abbreviated local
names are invalid.

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
* match forward satisfaction by exact ordered unique definition regardless of
  index name;
* in `down()`, match both the canonical name and exact definition, and remove
  an index only when the migration can prove that it created that index;
* return without mutation when a satisfied key was adopted under another name,
  or when creation ownership cannot be proven.

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
| Rollback removes only canonical exact keys created by this migration | Creation-ownership evidence plus before/after schema inventory |
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
* Section G: PASS — all four canonical Course table docs record the planned,
  not-yet-implemented candidate keys and link back to this gate.
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

## Independent Review Round 2

Round 2 on repository commit `e8bcf95` passed the documentation gates and
confirmed that the MEDIUM and two LOW Round 1 findings were materially closed.
One residual LOW ambiguity remained: forward adoption is name-agnostic, while
rollback must never remove a pre-existing adopted key.

Version 0.3 closes that ambiguity. Forward matches the exact ordered unique
definition. Rollback requires canonical name, exact definition and positive
creation-ownership evidence; otherwise it performs no mutation. This rule is a
required implementation acceptance condition, not an assumption inferred from
the migration being present in Laravel's migration table.

## Canonical Documentation Update

Version 0.4 records the four candidate keys in the canonical Course table docs
as approved planned prerequisites with `Implementation Status: Not
Implemented`. The physical schema contract remains unchanged intentionally:
all four parent tables are currently `implemented`, and the contract has no
per-index planned state. Adding absent indexes now would falsely claim physical
implementation and make fresh reconstruction drift.

Under the Schema Drift Standard, the four exact keys must be added to
`LF-SCHEMA-CONTRACT.json` in the same authorized change as the forward
migration. That combined change must pass the direct contract inventory check
and `schema:drift --fresh`; docs-only PASS before migration is not evidence
that the physical keys exist.

## Development Database Read-Only Preflight

An independently reported read-only preflight ran on 2026-08-15 against the
development database `learnforge_db` using only `SELECT` and
`information_schema`. Engine: MariaDB 10.4.21. No DDL, DML or repair ran.

| Table | Rows | Null tenant | Orphan tenant | Exact/equivalent key | Result |
| --- | ---: | ---: | ---: | --- | --- |
| `core_course_cohorts` | 4 | 0 | 0 | none | READY |
| `core_course_cohort_teachers` | 10 | 0 | 0 | none | READY |
| `core_course_cohort_students` | 7 | 0 | 0 | none | READY |
| `core_course_enrollments` | 11 | 0 | 0 | none | READY |

All eight inspected identity columns are `BIGINT UNSIGNED NOT NULL`. All four
tables use InnoDB and `utf8mb4_unicode_ci`. No wrong-order, partial/non-unique
equivalent or occupied canonical name was found. Therefore all four forward
branches require creating the canonical key; none is an adopted no-op.

The development tables contain only 4–11 rows and are at most approximately
0.23 MB. This proves development-schema compatibility, not production lock
risk. Before deployment, the operator must repeat table sizing and online-DDL
capability checks on the actual target environment and validate
`ALGORITHM=INPLACE, LOCK=NONE`; an unsafe or unsupported result blocks
deployment rather than silently falling back to a locking algorithm.

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

`INDEPENDENT DOCUMENT REVIEW AND DEVELOPMENT PREFLIGHT PASS; BLOCKED FOR
MIGRATION AUTHORIZATION`.

The proposed parent-key contract is internally consistent and preserves Course
ownership and the independent review findings are closed. Canonical table-doc
amendments and development read-only preflight are complete. Explicit Owner
migration authorization is still required; the physical schema contract
changes only with that migration. Production sizing remains a deployment gate.
No migration or database mutation is authorized by this verdict.
