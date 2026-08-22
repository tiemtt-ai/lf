# Table: core_learning_node_mappings

Version: 1.0

Document Status: Frozen

Implementation Status: Implemented

Last Updated: 2026-08-22

Document Path: database/learning/core_learning_node_mappings.md

## Purpose

Immutable declaration that a verified source-domain snapshot teaches,
practices or assesses one versioned Learning Node. Learning owns the mapping;
the source domain retains ownership of the source object.

## Relationships

```text
core_learning_nodes 1 → N core_learning_node_mappings
Course published Version object 1 → N mappings (generic source registry)
users 1 → N mappings (create/invalidate actors)
```

## Business Rules

Mapping connects immutable published content to one versioned Node. Semantic
fields never mutate; an error is resolved by one audited invalidation and a new
corrected Mapping.

## Fields

| Field | Type | Contract |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED | Primary key. |
| `customer_id` | BIGINT UNSIGNED | Owning tenant. |
| `learning_node_id` | BIGINT UNSIGNED | Target versioned Node. |
| `source_type` | VARCHAR(100) | Versioned whitelist key. |
| `source_id` | BIGINT UNSIGNED | Immutable source object identity. |
| `source_discriminator` | VARCHAR(191) | Required canonical snapshot/version discriminator; empty families normalize to `-`. |
| `mapping_role` | VARCHAR(50) | `teaches`, `practices`, or `assesses`. |
| `weight` | DECIMAL(9,6) NULL | Optional authoring contribution, `0..1`; not a Mastery result. |
| `source_snapshot` | JSON | Minimal immutable source label/type/version audit payload. |
| `created_by` | BIGINT UNSIGNED | Authorized tenant actor. |
| `created_at` | TIMESTAMP(6) NULL | Creation time; mappings have no mutable update lifecycle. |
| `invalidated_at` | TIMESTAMP(6) NULL | Audited time a wrong mapping stopped being eligible. |
| `invalidated_by` | BIGINT UNSIGNED NULL | Authorized tenant actor performing invalidation. |
| `invalidation_reason` | TEXT NULL | Required explanation; semantic fields remain unchanged. |

## Phase 1 Source Whitelist

| `source_type` | Physical source table | `source_discriminator` |
| --- | --- | --- |
| `course_version_lesson` | `core_course_template_version_lessons` | Published `template_version_id` encoded as decimal text. |
| `course_version_activity` | `core_course_template_version_activities` | Published `template_version_id` encoded as decimal text. |

Course mappings resolve only published immutable Version Lessons/Activities.
Working Template objects are forbidden. Assessment sources remain closed until
their physical snapshot/result contracts exist and a reviewed amendment opens
them.

## Constraints And Indexes

* `UNIQUE (customer_id, source_type, source_id, source_discriminator,
  learning_node_id, mapping_role)`.
* `INDEX (customer_id, learning_node_id, mapping_role)` and source lookup index.
* CHECK enforces `weight IS NULL OR (weight >= 0 AND weight <= 1)`.
* FK `(learning_node_id, customer_id)` references the Node tenant key.
  `(created_by, customer_id)` and nullable `(invalidated_by, customer_id)` each
  reference `users(id, customer_id)` with `RESTRICT`.
* No polymorphic source FK and no cross-Domain lookup trigger. The Course-owned
  adapter validates the registry, source existence, published immutability and
  tenant before calling Learning. Learning verifies the adapter signature,
  whitelist key and frozen source snapshot in the same transaction. A scheduled
  reconciliation reports lineage drift; it never silently deletes a Mapping.

## Immutability And Deletion

Mapping semantic fields are immutable after creation. A wrong mapping is
invalidated by setting `invalidated_at`, `invalidated_by` and a non-empty
`invalidation_reason` together; it is never hard-deleted or semantically edited.
The resolver excludes invalidated rows from new qualification while historical
Evidence continues to reference its frozen source interpretation. A corrected
mapping is a new row. A `BEFORE UPDATE` trigger rejects every update except the
single null-to-non-null invalidation transition and a `BEFORE DELETE` trigger
rejects deletion. Archived source objects remain resolvable.
Missing sources fail closed for new writes and are reported as lineage failure;
existing Learning history is retained.

## Sample Data

`id=60, customer_id=1, learning_node_id=40,
source_type=course_version_activity, source_id=900,
source_discriminator=20, mapping_role=practices, weight=0.500000`
