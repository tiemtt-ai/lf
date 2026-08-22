# Table: core_learning_mastery_calculations

Version: 1.0

Document Status: Frozen

Implementation Status: Implemented

Last Updated: 2026-08-22

Document Path: database/learning/core_learning_mastery_calculations.md

## Purpose

Append-only record of one automated calculation, teacher override or
version-continuity carry-forward for one stable Node under one basis Framework
Version.

## Relationships

```text
users 1 → N core_learning_mastery_calculations
core_learning_node_definitions 1 → N calculations
core_learning_framework_versions 1 → N calculations (basis)
core_learning_node_relations 1 → N carry-forward calculations
```

## Business Rules

Every calculation is immutable, idempotent and explicitly ordered. Override and
carry-forward are new decisions with actor/relation lineage, never updates.

## Fields

| Field | Type | Contract |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED | Primary key. |
| `customer_id` | BIGINT UNSIGNED | Owning tenant. |
| `user_id` | BIGINT UNSIGNED | Learner entity. |
| `framework_id` | BIGINT UNSIGNED | Denormalized Framework proving Definition/basis compatibility. |
| `node_definition_id` | BIGINT UNSIGNED | Stable semantic identity. |
| `basis_framework_version_id` | BIGINT UNSIGNED | Exact Version whose scale and semantics govern the result. |
| `calculation_source` | VARCHAR(50) | `system`, `teacher_override`, or `carry_forward`. |
| `calculation_idempotency_key` | VARCHAR(191) | Required stable producer/command key for all calculation sources. |
| `mastery_level_key` | VARCHAR(100) | Result level from the frozen basis scale. |
| `mastery_score` | DECIMAL(9,6) NULL | Optional normalized score. |
| `calculation_rule_key` | VARCHAR(100) | Frozen calculation rule key. |
| `calculation_rule_version` | VARCHAR(50) | Frozen calculation rule version. |
| `calculation_rule_snapshot` | JSON | Complete aggregation/override rule. |
| `mastery_scale_key` | VARCHAR(100) | Scale key copied from basis Version. |
| `mastery_scale_version` | VARCHAR(50) | Scale version copied from basis Version. |
| `mastery_scale_snapshot` | JSON | Exact scale applied. |
| `continuity_policy_snapshot` | JSON NULL | Required for `carry_forward`; includes source/target transition and policy. |
| `source_node_relation_id` | BIGINT UNSIGNED NULL | Required authorising transition relation for carry-forward. |
| `source_calculation_id` | BIGINT UNSIGNED NULL | Required lineage for carry-forward or correction. |
| `mastery_status_result` | VARCHAR(50) | `established`, `needs_review`, or `reassessment_due`; canonical Profile source. |
| `reassessment_due_at` | TIMESTAMP(6) NULL | Rule-derived advisory date projected unchanged to Profile. |
| `reason` | TEXT NULL | Required human-readable reason for override. |
| `calculated_at` | DATETIME(6) | Decision time. |
| `calculated_by` | BIGINT UNSIGNED NULL | Human actor; null for approved system calculation. |
| `created_at` | TIMESTAMP(6) NULL | Append time. |

## Constraints And Indexes

* `INDEX (customer_id, user_id, node_definition_id,
  basis_framework_version_id, calculated_at)`.
* `UNIQUE (id, customer_id, user_id)` supports Calculation Evidence lineage.
* `UNIQUE (id, customer_id, user_id, node_definition_id,
  basis_framework_version_id)` supports Profile projection integrity.
* `INDEX (customer_id, source_calculation_id)`.
* `UNIQUE (customer_id, calculation_source, calculation_idempotency_key)`
  rejects duplicate job/command delivery.
* FK `(user_id, customer_id)` and nullable actor FK
  `(calculated_by, customer_id)` reference `users(id, customer_id)`.
* FK `(node_definition_id, customer_id, framework_id)` and FK
  `(basis_framework_version_id, customer_id, framework_id)` reference their
  composite parent keys. These FKs physically prove one tenant and Framework.
* Source Calculation lineage uses a composite same-tenant/same-learner FK.
* FK `(source_node_relation_id, customer_id, framework_id)` references
  `core_learning_node_relations(id, customer_id, framework_id)`; it is required
  only for carry-forward and must be an approved transition whose target is the
  basis Version and whose effective policy permits the operation.
* Override requires actor and reason. Carry-forward requires source Calculation
  and frozen continuity policy. System calculation forbids an unapproved
  continuity payload.
* Level key and optional score must be valid under `mastery_scale_snapshot`.
  `trg_lrn_calcs_bi_validate` enforces source-specific required fields and
  validates rule/scale snapshots against the basis Version before insert.
* Canonical ordering is `(calculated_at, id)` ascending; the greatest tuple is
  newest. `id` is the deterministic tie-breaker at equal microsecond time.

## Immutability And Decay Decision — E3

Calculations are immutable and never hard-deleted. Automatic Mastery decay is
not part of v1. Explicit reassessment, validity or future decay policy can only
produce a new Calculation and a new Profile projection; it cannot mutate prior
Evidence, score or Calculation history.

`BEFORE UPDATE` and `BEFORE DELETE` database triggers reject every mutation of
a Calculation row. Recalculation, correction and override are insert-only.

## Sample Data

`id=80, customer_id=1, user_id=100, framework_id=10,
node_definition_id=30, basis_framework_version_id=20,
calculation_source=system, calculation_idempotency_key=mastery-job-5001,
mastery_level_key=proficient, mastery_status_result=established`
