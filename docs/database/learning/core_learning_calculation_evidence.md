# Table: core_learning_calculation_evidence

Version: 1.0

Document Status: Frozen

Implementation Status: Not Implemented

Last Updated: 2026-08-12

Document Path: database/learning/core_learning_calculation_evidence.md

## Purpose

Immutable junction recording the exact Evidence set used by one Mastery
Calculation, including effective weight, contribution and inclusion rationale.

## Relationships

```text
core_learning_mastery_calculations 1 → N calculation evidence
core_learning_evidence 1 → N calculation evidence
users 1 → N calculation evidence (learner identity)
```

## Business Rules

Each row freezes whether and how one Evidence item contributed to one
Calculation. Tenant and learner equality are composite-FK invariants.

## Fields

| Field | Type | Contract |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED | Primary key. |
| `customer_id` | BIGINT UNSIGNED | Owning tenant. |
| `user_id` | BIGINT UNSIGNED | Learner copied from both parent records for composite enforcement. |
| `mastery_calculation_id` | BIGINT UNSIGNED | Parent Calculation. |
| `evidence_id` | BIGINT UNSIGNED | Evidence included or explicitly excluded by the rule. |
| `evidence_role` | VARCHAR(50) | `included`, `excluded`, or `continuity_input`. |
| `effective_weight` | DECIMAL(9,6) | Frozen non-negative weight applied. |
| `contribution` | DECIMAL(18,6) NULL | Frozen numeric contribution when applicable. |
| `reason_code` | VARCHAR(100) | Deterministic rule reason. |
| `reason_snapshot` | JSON NULL | Optional structured explanation/audit factors. |
| `created_at` | TIMESTAMP(6) NULL | Append time. |

## Constraints And Indexes

* `UNIQUE (customer_id, mastery_calculation_id, evidence_id)`.
* `INDEX (customer_id, user_id, evidence_id, mastery_calculation_id)`.
* FK `(mastery_calculation_id, customer_id, user_id)` references
  `core_learning_mastery_calculations(id, customer_id, user_id)` with
  `RESTRICT`.
* FK `(evidence_id, customer_id, user_id)` references
  `core_learning_evidence(id, customer_id, user_id)` with `RESTRICT`.
* The two composite FKs physically prove that Calculation, Evidence and
  junction share both tenant and learner.
* Evidence Node Definition must resolve to the Calculation Definition directly
  or through the exact approved transition frozen in a carry-forward payload.
  `trg_lrn_calc_evidence_bi_validate` performs this cross-table validation and
  rejects missing/unapproved transition lineage.
* CHECK enforces `effective_weight >= 0`; `excluded` rows require weight zero
  and a non-empty reason code.

## Immutability

Rows are inserted atomically with the parent Calculation and never updated or
deleted. Recalculation creates a new Calculation and a new junction set. The
Evidence set is not hidden in JSON because it is canonical audit lineage.
`BEFORE UPDATE` and `BEFORE DELETE` database triggers reject every mutation.

## Sample Data

`id=90, customer_id=1, user_id=100, mastery_calculation_id=80,
evidence_id=70, evidence_role=included, effective_weight=1.000000,
contribution=0.800000, reason_code=qualified`
