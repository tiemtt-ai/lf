# Table: core_learning_node_relations

Version: 1.0

Document Status: Frozen

Implementation Status: Not Implemented

Last Updated: 2026-08-12

Document Path: database/learning/core_learning_node_relations.md

## Purpose

Stores semantic relations inside one Framework Version and continuity-aware
transitions between Versions of the same Framework.

## Relationships

```text
core_learning_framework_versions 1 → N core_learning_node_relations (owner)
core_learning_nodes 1 → N core_learning_node_relations (source/target)
core_learning_node_relations 1 → N carry-forward calculations
```

## Business Rules

Semantic relations belong to one Version. Transition relations belong to the
target Version, remain inside one Framework and have one audited review result.

## Fields

| Field | Type | Contract |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED | Primary key. |
| `customer_id` | BIGINT UNSIGNED | Owning tenant. |
| `framework_id` | BIGINT UNSIGNED | Shared Framework of both endpoints; immutable. |
| `owning_framework_version_id` | BIGINT UNSIGNED | Authoring owner: the shared Version for semantic scope, target Version for transition scope. |
| `relation_scope` | VARCHAR(50) | `semantic` or `version_transition`. |
| `relation_type` | VARCHAR(50) | Scope-specific vocabulary below. |
| `source_learning_node_id` | BIGINT UNSIGNED | Directed source Node. |
| `target_learning_node_id` | BIGINT UNSIGNED | Directed target Node. |
| `source_framework_version_id` | BIGINT UNSIGNED | Denormalized source Version. |
| `target_framework_version_id` | BIGINT UNSIGNED | Denormalized target Version. |
| `continuity_policy` | VARCHAR(50) NULL | Transition-only: `no_carry_forward`, `allow_as_input`, `carry_forward`, `requires_review`. |
| `continuity_policy_key` | VARCHAR(100) NULL | Approved policy identity. |
| `continuity_policy_version` | VARCHAR(50) NULL | Frozen policy version. |
| `continuity_policy_snapshot` | JSON NULL | Complete immutable transition policy. |
| `review_status` | VARCHAR(50) | `not_required`, `pending`, `approved`, or `rejected`. |
| `resolved_continuity_policy` | VARCHAR(50) NULL | Approved result for a `requires_review` policy: `no_carry_forward`, `allow_as_input`, or `carry_forward`. |
| `approved_by` | BIGINT UNSIGNED NULL | Required approval actor when policy permits carry-forward. |
| `approved_at` | TIMESTAMP(6) NULL | Required approval time when policy permits carry-forward. |
| `reviewed_by` | BIGINT UNSIGNED NULL | Actor resolving a pending review, including rejection. |
| `reviewed_at` | TIMESTAMP(6) NULL | One-way review resolution time. |
| `review_reason` | TEXT NULL | Required for approved/rejected review resolution. |
| `created_by` | BIGINT UNSIGNED | Tenant author. |
| `created_at` | TIMESTAMP(6) NULL | Creation time. |
| `updated_at` | TIMESTAMP(6) NULL | Draft/review-resolution update time. |

## Vocabulary And Checks

* Semantic types: `prerequisite`, `part_of`, `supports`; source and target
  Versions must be equal and continuity fields must be null.
* Transition types: `equivalent_to`, `supersedes`, `splits_into`, `merges_into`;
  Versions must differ and continuity fields are required.
* `source_learning_node_id <> target_learning_node_id`.
* Relation type must match scope. `related` is forbidden.
* Semantic relations use `review_status = not_required`. A transition whose
  policy is `requires_review` starts `pending`; other transition policies use
  `not_required` or are created pre-approved with complete approval audit.

## Constraints And Indexes

* Composite FKs `(source_learning_node_id, customer_id, framework_id,
  source_framework_version_id)` and the target equivalent reference
  `core_learning_nodes(id, customer_id, framework_id, framework_version_id)`.
  Both endpoint FKs share the one `framework_id` column, physically forbidding
  cross-tenant and cross-Framework relations.
* `UNIQUE (customer_id, relation_scope, source_learning_node_id,
  target_learning_node_id)` forbids contradictory types for one ordered pair.
* `UNIQUE (id, customer_id, framework_id)` supports carry-forward lineage.
* Index both endpoint directions and `(customer_id, relation_scope,
  source_framework_version_id, target_framework_version_id)`.
* A row-local CHECK enforces the scope vocabulary, equal/different Version
  rule, endpoint inequality and continuity-field nullability. The composite FKs
  above—not application validation—prove endpoint Version, tenant and Framework.
* `(approved_by, customer_id)`, `(reviewed_by, customer_id)` and
  `(created_by, customer_id)` each reference `users(id, customer_id)` with
  `RESTRICT`; review/approval actors are nullable as pairs.
* CHECK requires pending/not-required rows to have null review audit; approved
  rows require reviewed and approved audit with matching actor/time; rejected
  rows require reviewed audit, null approval audit and a reason.
* FK `(owning_framework_version_id, customer_id, framework_id)` references the
  Version composite key. CHECK requires it to equal the shared Version for a
  semantic relation and the target Version for a transition.
* `trg_lrn_relations_bi_validate` rejects `prerequisite` and `part_of` cycles
  using the existing same-Version graph; `supports` may be cyclic by design.

## Lifecycle

Relations are created while `owning_framework_version_id` is draft and publish
atomically with that Version graph. After publish, semantic fields are frozen.
For a pending `requires_review` transition, the update trigger permits exactly
one resolution: `pending → approved|rejected`, filling `approved_by`,
`approved_at`, `review_reason` and, when approved,
`resolved_continuity_policy`. No field may be cleared or changed afterward.

A carry-forward Calculation may use only an approved relation whose effective
policy permits it. Triggers `trg_lrn_relations_bu_immutable` and
`trg_lrn_relations_bd_immutable` implement this one-way exception and otherwise
reject post-publish update/delete. Draft updates may not change `customer_id`,
`framework_id`, endpoint IDs or `owning_framework_version_id`.

## Sample Data

`id=50, customer_id=1, framework_id=10, owning_framework_version_id=21,
relation_scope=version_transition, relation_type=equivalent_to,
source_learning_node_id=40, target_learning_node_id=41,
continuity_policy=requires_review, review_status=pending`
