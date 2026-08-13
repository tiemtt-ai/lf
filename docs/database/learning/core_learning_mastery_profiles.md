# Table: core_learning_mastery_profiles

Version: 1.0

Document Status: Frozen

Implementation Status: Not Implemented

Last Updated: 2026-08-12

Document Path: database/learning/core_learning_mastery_profiles.md

## Purpose

Rebuildable read model for the current Mastery state of one user, stable Node
Definition and basis Framework Version. Calculation history remains canonical.

## Relationships

```text
users 1 → N core_learning_mastery_profiles
core_learning_node_definitions 1 → N profiles
core_learning_framework_versions 1 → N profiles (basis)
core_learning_mastery_calculations 1 → 0..1 profile authority
```

## Business Rules

Profile is a rebuildable projection, not history. Its identity and every
projected value must match the authoritative current Calculation.

## Fields

| Field | Type | Contract |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED | Primary key. |
| `customer_id` | BIGINT UNSIGNED | Owning tenant. |
| `user_id` | BIGINT UNSIGNED | Learner entity. |
| `framework_id` | BIGINT UNSIGNED | Denormalized Framework proving Definition/basis compatibility. |
| `node_definition_id` | BIGINT UNSIGNED | Stable semantic identity. |
| `basis_framework_version_id` | BIGINT UNSIGNED | Version-specific basis; never inferred as latest. |
| `current_calculation_id` | BIGINT UNSIGNED | Calculation from which every projection value derives. |
| `mastery_level_key` | VARCHAR(100) | Projected current level. |
| `mastery_score` | DECIMAL(9,6) NULL | Projected current score. |
| `mastery_status` | VARCHAR(50) | `established`, `needs_review`, or `reassessment_due`. |
| `calculated_at` | TIMESTAMP(6) | Source Calculation time. |
| `reassessment_due_at` | TIMESTAMP(6) NULL | Projected advisory date from the Calculation/rules. |
| `projected_at` | TIMESTAMP(6) | Projection update time. |
| `created_at` | TIMESTAMP(6) NULL | Projection creation time. |
| `updated_at` | TIMESTAMP(6) NULL | Projection update time. |

## Constraints And Indexes

* `UNIQUE (customer_id, user_id, node_definition_id,
  basis_framework_version_id)` is the canonical Profile identity.
* `UNIQUE (customer_id, current_calculation_id)` prevents one Calculation from
  becoming authority for unrelated Profiles.
* `INDEX (customer_id, user_id, basis_framework_version_id, mastery_status)`.
* FK `(user_id, customer_id)` references `users(id, customer_id)`.
* FK `(node_definition_id, customer_id, framework_id)` and FK
  `(basis_framework_version_id, customer_id, framework_id)` prove Definition
  and basis belong to one tenant and Framework.
* FK `(current_calculation_id, customer_id, user_id, node_definition_id,
  basis_framework_version_id)` references the matching Calculation composite
  key. This physically proves the projection identity.
* `trg_lrn_profiles_bi_projection` and `trg_lrn_profiles_bu_projection` require
  projected level/score/time/status to equal `mastery_level_key`,
  `mastery_score`, `calculated_at` and `mastery_status_result` on the referenced
  Calculation, and reject a stale or unrelated projection write.

## Projection And Query Rules

The projector locks the unique Profile identity and upserts only when the new
Calculation is the authorized successor under deterministic ordering. Replays
use `(calculated_at, id)` and are idempotent. Rebuild deletes/recreates
projection rows only inside an approved maintenance process; it never deletes
Calculation history.

“Current Mastery” must be queried with an explicitly resolved basis Version.
If context/policy cannot resolve exactly one basis, the application returns
version-labelled Profiles or fails closed. It must not select the newest row
blindly. AI and other consumers have read-only access and cannot write Profiles.

## Sample Data

`id=1000, customer_id=1, user_id=100, framework_id=10,
node_definition_id=30, basis_framework_version_id=20,
current_calculation_id=80, mastery_level_key=proficient,
mastery_status=established`
