# Table: core_learning_nodes

Version: 1.0

Document Status: Frozen

Implementation Status: Not Implemented

Last Updated: 2026-08-12

Document Path: database/learning/core_learning_nodes.md

## Purpose

Versioned representation of one stable Node Definition inside one Framework
Version. Evidence anchors here so its semantic meaning cannot drift.

## Relationships

```text
core_learning_framework_versions 1 → N core_learning_nodes
core_learning_node_definitions 1 → N core_learning_nodes
core_learning_nodes 1 → N mappings / evidence / relation endpoints
```

## Business Rules

One Definition appears at most once in a Version. Both parent paths must resolve
to the same tenant and Framework; snapshot identity cannot move after insert.

## Fields

| Field | Type | Contract |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED | Primary key. |
| `customer_id` | BIGINT UNSIGNED | Owning tenant. |
| `framework_id` | BIGINT UNSIGNED | Denormalized owning Framework used by composite FKs; immutable. |
| `framework_version_id` | BIGINT UNSIGNED | Owning Framework Version. |
| `node_definition_id` | BIGINT UNSIGNED | Stable identity represented by this snapshot. |
| `code_snapshot` | VARCHAR(120) | Frozen display/business code. |
| `name_snapshot` | VARCHAR(255) | Frozen name. |
| `description_snapshot` | TEXT NULL | Frozen semantic description. |
| `criteria_snapshot` | JSON NULL | Structured observable criteria frozen for this Version. |
| `sequence` | INT UNSIGNED | Deterministic authoring/display order. |
| `status` | VARCHAR(50) | `active` or `retired` within the Version snapshot. |
| `created_by` | BIGINT UNSIGNED | Snapshot author. |
| `updated_by` | BIGINT UNSIGNED | Last draft editor; frozen at publish. |
| `created_at` | TIMESTAMP(6) NULL | Creation time. |
| `updated_at` | TIMESTAMP(6) NULL | Draft update time. |

## Constraints And Indexes

* `UNIQUE (customer_id, node_definition_id, framework_version_id)` is the
  business invariant: one Definition appears at most once per Version.
* `UNIQUE (id, customer_id, framework_id, framework_version_id)` supports
  tenant-safe relation and Evidence composite FKs.
* `UNIQUE (id, customer_id)` supports tenant-safe Mapping and Evidence FKs.
* `INDEX (customer_id, framework_version_id, sequence)`.
* FK `(node_definition_id, customer_id, framework_id)` references
  `core_learning_node_definitions(id, customer_id, framework_id)` with
  `RESTRICT`.
* FK `(framework_version_id, customer_id, framework_id)` references
  `core_learning_framework_versions(id, customer_id, framework_id)` with
  `RESTRICT`.
* Actor FK `(created_by, customer_id)` references `users(id, customer_id)` with
  `RESTRICT`.
* Actor FK `(updated_by, customer_id)` references `users(id, customer_id)` with
  `RESTRICT`.
* These two composite FKs physically prove that Definition and Version belong
  to the same tenant and Framework. Application validation is defense in depth,
  not the invariant's enforcement mechanism.

## Immutability And Delete Rules

Nodes in a published/deprecated/archived Version are immutable and never
hard-deleted. Draft Nodes may be edited only through the owning draft Version
workflow. `retired` is draft authoring state only; it excludes the Node from the
next publish and never removes a Node from an already published Version.
Evidence or Mapping references always use the exact Node ID and do
not follow a Definition automatically to another Version.
Triggers `trg_lrn_nodes_bu_immutable` and
`trg_lrn_nodes_bd_immutable` always reject changes to tenant/Framework/Version/
Definition identity and freeze all remaining fields after publication.

## Sample Data

`id=40, customer_id=1, framework_id=10, framework_version_id=20,
node_definition_id=30, code_snapshot=speaking-fluency, status=active`
