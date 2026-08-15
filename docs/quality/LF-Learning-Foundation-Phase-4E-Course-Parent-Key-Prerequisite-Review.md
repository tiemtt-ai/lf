# Learning Foundation Phase 4E Course Parent-Key Prerequisite Review

Version: 0.11

Document Status: Review

Implementation Status: Implemented

Last Updated: 2026-08-15

Document Path: quality/LF-Learning-Foundation-Phase-4E-Course-Parent-Key-Prerequisite-Review.md

## Classification

Classification: Existing-Feature Change.

Initial and Final Documentation Audit Level: HIGH. The proposal changes index
contracts on four released Course tables so a LiveClass-owned source can use
tenant-safe composite foreign keys. It touches tenant isolation, cross-domain
ownership, schema, migration rehearsal and backward compatibility.

The initial review was documentation-only. The Architecture Owner later
authorized the prerequisite migration source, physical contract update and
isolated MariaDB rehearsal. Real-database migration and deployment remain
excluded.

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

## Implemented Migration And Rollback Contract

The authorized forward Course-prerequisite migration contains only the four
named unique keys. Its accepted contract is:

* fail closed unless preflight invariants hold;
* treat an exact equivalent ordered unique index as an idempotent no-op;
* fail closed on wrong-order, partial, non-unique or name-conflicting indexes;
* use the repository-supported online DDL strategy where the engine permits;
* make no data, primary-key, lifecycle or foreign-key change;
* match forward satisfaction by exact ordered unique definition regardless of
  index name;
* an exact key under a non-canonical name is adopted as a no-op and is never
  removed by this migration;
* a canonical exact key is treated as a completed step of this migration so a
  retry after interrupted non-transactional DDL can finish remaining tables;
* `down()` removes only canonical-name keys with the exact definition and
  rejects ambiguity when another exact key also exists.

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
* Section G: PASS — all four canonical Course table docs record the
  source-implemented, not-deployed candidate keys and link back to this gate.
* Section H: PASS for authorized source implementation and isolated rehearsal;
  production deployment remains separately gated.

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

## Interrupted-DDL Recovery Decision

Version 0.7 supersedes the Version 0.3 ownership mechanism for canonical keys.
MariaDB commits each `ALTER TABLE` independently, and Laravel records the
migration ledger only after `up()` returns. If execution stops between the
four tables, strict per-run ownership evidence creates a retry deadlock: the
new migration instance sees a canonical key but has neither an instance marker
nor a ledger row.

The implementation uses the recoverable rule proven by the
interruption test: an exact canonical key is a completed migration step and
retry continues with the remaining tables. The Architecture Owner explicitly
ratified this extension on 2026-08-15.
An exact non-canonical key remains an adopted no-op and rollback preserves it.
Pre-deployment read-only preflight must record whether canonical keys were
absent; unexpected pre-existing canonical keys require operator review because
the database cannot distinguish them from a partially completed prior run.

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

## Migration Implementation Authorization

```text
Role: LearnForge Architecture Owner
Date: 2026-08-15
Decision: Approved Phase 4E Course parent-key prerequisite migration
Authorized: one forward migration; simultaneous schema-contract update;
            isolated disposable MariaDB rehearsal and tests
Excluded: real LearnForge database migration; production deployment;
          Teacher Judgment source table/runtime/API/UI
```

## Implementation And Rehearsal Evidence

Migration source:
`2026_08_15_000000_add_course_tenant_composite_uniques.php`.

The migration performs all-table preflight before forward DDL and all-table
rollback preflight before any drop. It rejects null/orphan
tenant ownership and conflicting composite indexes, uses
`ALGORITHM=INPLACE, LOCK=NONE`, supports exact alternate-name no-op and scopes
rollback to canonical exact definitions. Rollback also refuses ambiguity and
dependent child composite foreign keys before mutating any parent. The
implementation accepts canonical exact keys on retry to recover from partial
non-transactional DDL under the ratification recorded below.

## Canonical-Key Adoption Ratification

```text
Role: LearnForge Architecture Owner
Date: 2026-08-15
Decision: Approved canonical-key adoption recovery semantics
Approved: an exact canonical UNIQUE (id, customer_id) is treated as a
          completed migration step after interrupted DDL
Required: deployment preflight verifies canonical-key state before execution
Excluded: production deployment and migration on the real LearnForge database
```

## Schema-Scoped Foreign-Key Guard Decision

The rollback child-FK guard uses a schema-filtered
`information_schema.KEY_COLUMN_USAGE` query on MySQL/MariaDB rather than
walking `Schema::getTables()`. The framework helper exposed 890 tables across
databases visible to the development connection and made four parent checks
take approximately 632 seconds. The scoped implementation reduced `down()` to
approximately one second while preserving fail-before-mutation behavior.

The independent Round 4 review accepted this hardening with zero findings. It
confirmed current-schema parent filtering, ordered referenced-column matching,
bound table input and safe detection of cross-schema children that reference a
current-schema parent. The Architecture Owner's canonical-key adoption and
this schema-scoped guard are the accepted implementation decisions; neither
authorizes production deployment.

The physical contract now records the four exact unique definitions in the
implemented parent-table records. Direct source inspection confirms four
contract entries; successful migration self-verification and fresh drift
confirm the physical definition.

```text
Targeted SQLite: 11 tests / 60 assertions                   PASS
Course/Tenant regression: 152 tests / 2,129 assertions     PASS
Full PHPUnit: 728 passed / 1 skipped / 8,196 assertions    PASS
MariaDB 10.4 fresh schema: 77 migrations                    PASS
MariaDB 11.4.12 forward migration                           PASS
Contract/fresh schema drift                                 PASS
MariaDB 11.4 exact ordered named unique indexes: 4/4         PASS
MariaDB 11.4 rollback: 4 indexes -> 0                        PASS
MariaDB 11.4 re-forward: 0 -> 4 indexes                      PASS
MariaDB 11.4 child composite FK blocks rollback: 4 remain   PASS
MariaDB schema-scoped rollback benchmark: ~632s -> ~1.0s    PASS
Disposable database cleanup: 0 remaining                   PASS
Scoped Pint                                                 PASS
docs:lint / schema:drift --docs-only / git diff --check     PASS
Real LearnForge database migration                          NOT RUN
```

The first rehearsal attempt stopped before acceptance because another process
introduced a same-timestamp migration concurrently. The duplicate untracked
migration was removed, repository state was reconciled to canonical commit
`718bc63`, and the successful rehearsal used exactly 77 migration files.
The standalone 11.4 cleanup initially retried before the server process had
fully exited; the known PID was then stopped and the exact temporary datadir
was deleted. Final inspection found no Phase 4E MariaDB test directory or test
database.

After rollback hardening, MariaDB 11.4.12 was rehearsed again from a fresh
schema. A dependent child composite FK produced the expected fail-closed
exception before any parent index was removed; all four canonical keys
remained. After removing only the probe table, rollback produced zero keys and
re-forward restored all four. The rehearsal database, server and exact
temporary datadir were deleted.

## Development Database Deployment

```text
Role: LearnForge Architecture Owner
Date: 2026-08-15
Decision: Approved development deployment of the parent-key prerequisite
Authorized: learnforge_db backup; read-only preflight; the prerequisite
            migration run through --path only
Excluded: bare `php artisan migrate`; Phase 4B/4C deployment; production
```

A full logical backup preceded the first DDL: `mysqldump --single-transaction
--routines --triggers --events`, 224 KB, 60 `CREATE TABLE` statements, dump
completion marker present, empty stderr. It is stored outside the repository.

The final read-only preflight repeated the result above — 4/4 READY, zero null
and zero orphan tenants, no equivalent or name-conflicting index. Execution
used `php artisan migrate --path=…add_course_tenant_composite_uniques.php
--force` and completed in 157.28 ms.

```text
Four canonical keys are exact UNIQUE (id, customer_id)      4/4  PASS
Pre-existing indexes byte-identical (10 / 6 / 15 / 13)       PASS
Row counts unchanged (4 / 10 / 7 / 11)                       PASS
Table count 60 -> 60                                         PASS
core_learning_* tables present: 0                            PASS
trg_lrn_* triggers present: 0                                PASS
Migration ledger 74 -> 75, exactly one new row               PASS
User-composite and Learning Foundation still pending         PASS
Full PHPUnit after deployment: 728 passed / 1 skipped        PASS
```

`schema:drift --connection=mysql` reports `failed` by design: the residue is
exactly ten `table.missing` findings for `core_learning_*` and two
`migration.pending` findings for the deliberately withheld migrations. No
finding concerns the four Course parents, which confirms the contract matches
the development database including the new keys.

Rollback was then exercised against real data — the one scenario every earlier
rehearsal had only covered on empty or ephemeral schemas. `migrate:rollback
--path=…` removed the four canonical keys in 985.99 ms, leaving every
pre-existing index and every row intact; re-applying in 139.72 ms restored an
index inventory identical to the pre-rollback state, with unchanged
`id`/`customer_id` checksums on all four tables. Twenty-two checks passed
across both directions.

That hazard has since been retired. The Architecture Owner opened the Learning
Foundation gate on the development database later the same day, and the two
previously withheld migrations were applied — `add_user_tenant_composite_unique`
in batch 10 and `create_learning_foundation_tables_and_triggers` in batch 11.
`learnforge_db` now holds 70 tables, all 77 ledger rows, and
`schema:drift --connection=mysql` reports `passed` with zero pending and zero
missing-source migrations. Production runbooks still require `--path` scoping,
because nothing about that opening extends to a production target.

## Findings By Severity

```text
BLOCKER  0
HIGH     0
MEDIUM   0
LOW      0
```

Production table sizing and online-DDL capability remain unverified deployment
items. They do not authorize fallback to a locking algorithm.

Governance gate: PASS. The Architecture Owner ratified canonical-key adoption,
and the independent final re-review reported zero findings.

## Final Verdict

`SOURCE, CONTRACT, REHEARSAL, INDEPENDENT REVIEW, OWNER RATIFICATION AND
DEVELOPMENT DEPLOYMENT PASS; PRODUCTION NOT AUTHORIZED`.

The proposed parent-key contract is internally consistent and preserves Course
ownership and the independent review findings are closed. Canonical table-doc
amendments, development read-only preflight, migration source, physical
contract and isolated rehearsal are complete. The prerequisite is now deployed
on the development database `learnforge_db` under the authorization recorded
above, with backup, forward, rollback and re-apply evidence against real rows.
Phase 4B/4C has since been deployed to the same development database under a
separate Owner decision recorded in the Architecture Review. Production table
sizing and
online-DDL capability remain unverified deployment gates. This verdict does not
authorize production deployment or the Teacher Judgment source.
