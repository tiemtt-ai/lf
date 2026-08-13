# Table: core_learning_node_definitions

Version: 1.0

Document Status: Frozen

Implementation Status: Not Implemented

Last Updated: 2026-08-12

Document Path: database/learning/core_learning_node_definitions.md

## Purpose

Stable semantic identity of one objective, concept or competency across
Versions of exactly one Framework. It is the long-lived identity used by
Mastery Calculations and Profiles.

## Relationships

```text
core_learning_frameworks 1 → N core_learning_node_definitions
core_learning_node_definitions 1 → N core_learning_nodes
core_learning_node_definitions 1 → N core_learning_mastery_calculations
```

## Business Rules

Definition identity, tenant and Framework never change. Version-specific
wording belongs to Node snapshots, not this stable record.

## Fields

| Field | Type | Contract |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED | Primary key. |
| `customer_id` | BIGINT UNSIGNED | Owning tenant. |
| `framework_id` | BIGINT UNSIGNED | Owning Framework; immutable after creation. |
| `code` | VARCHAR(120) | Stable code unique inside Framework. |
| `node_type` | VARCHAR(50) | `objective`, `concept`, or `competency`. |
| `canonical_name` | VARCHAR(255) | Stable human-readable identity label. |
| `description` | TEXT NULL | Definition-level explanation, not versioned presentation content. |
| `status` | VARCHAR(50) | `active` or `archived`. |
| `created_by` | BIGINT UNSIGNED | Tenant creator. |
| `updated_by` | BIGINT UNSIGNED | Last tenant editor. |
| `created_at` | TIMESTAMP(6) NULL | Creation time. |
| `updated_at` | TIMESTAMP(6) NULL | Update time. |

## Constraints And Indexes

* `UNIQUE (customer_id, framework_id, code)`.
* `UNIQUE (id, customer_id, framework_id)` for composite FK enforcement.
* `INDEX (customer_id, framework_id, node_type, status)`.
* Framework FK uses `(framework_id, customer_id)`; `(created_by, customer_id)`
  and `(updated_by, customer_id)` each reference `users(id, customer_id)` with
  `RESTRICT`.
* `trg_lrn_definitions_bu_identity` rejects every change to `customer_id` or
  `framework_id`, including while the Definition is active/draft-authorable.

## Business Rules

A Definition cannot move between Frameworks or be shared cross-framework.
Archiving prevents appearance in a new draft Version but preserves all Nodes,
Evidence calculations and Profiles. A correction to version-specific meaning
belongs in a new Versioned Node; it does not rewrite the stable identity.

## Sample Data

`id=30, customer_id=1, framework_id=10, code=speaking-fluency,
node_type=competency, canonical_name=Speaking fluency, status=active`
