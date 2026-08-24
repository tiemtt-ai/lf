# Table: core_course_template_learning_mapping_intents

Version: 1.0

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-08-23

Approval Date: 2026-08-23

Approved By: Architecture Owner

Document Path: database/course/core_course_template_learning_mapping_intents.md

Related ADR: [ADR-0017 — AI-Assisted Learning Authoring](../../adr/ADR-0017-AI-Assisted-Learning-Authoring.md)

## Purpose

Stores a Course Template draft author's intent to map a working Lesson or
Activity to a Node in one explicitly selected, published Learning Framework
Version. An Intent is draft-only Course state; it is not a canonical Learning
Mapping and must never create Evidence or Mastery.

## Fields

| Field | Contract |
| --- | --- |
| `id` | BIGINT UNSIGNED primary key. |
| `customer_id` | Tenant owner; required. |
| `template_id` | Working Course Template owner; its selected Framework Version is stored on `core_course_templates`. |
| `source_type` | `course_template_lesson` or `course_template_activity`. |
| `source_id` | Working Lesson/Activity identity matching `source_type`. |
| `framework_id`, `framework_version_id` | Exact published Learning Framework/Version selected by the Template. |
| `learning_node_id` | Versioned Node belonging to that Framework Version; must be active. |
| `mapping_role` | `teaches`, `practices`, or `assesses`. |
| `weight` | Nullable decimal in `[0,1]`; pedagogical contribution, never confidence. |
| `origin` | Phase 1 is `manual` only; `ai_proposal` is reserved until Proposal persistence/review exists. |
| `created_by`, `created_at`, `updated_by`, `updated_at` | Tenant-scoped audit fields. |

## Constraints

* `core_course_templates.selected_learning_framework_id` and
  `selected_learning_framework_version_id` are nullable draft fields with a
  tenant-safe composite FK; every Intent must match that exact selection.
* Unique `(customer_id, template_id, source_type, source_id, learning_node_id, mapping_role)`.
* Store `framework_id` and use composite FK `(learning_node_id, customer_id,
  framework_id, framework_version_id)` to the Learning Node tenant/version key.
* CHECK restricts Phase 1 `origin` to `manual`, source vocabulary, role and
  weight range. Indexes: `(customer_id, template_id)` and
  `(customer_id, learning_node_id, mapping_role)`.
* Every owner and actor FK is tenant-scoped.
* A Course Template selects exactly one Framework Version explicitly; no
  `latest` or publish-time resolution is stored or permitted.
* Source existence/type/Template containment and published Learning Version/Node
  checks are enforced by the Course-to-Learning owner service in the transaction.

## Publish Promotion

Course publishing snapshots working Lessons/Activities. In the same transaction,
the Course adapter reads all current Intents, replaces each working source ID by
the matching newly-created Version Lesson/Activity ID, and asks Learning to
create `core_learning_node_mappings`. The canonical Mapping uses the published
Course Version ID encoded as decimal text for `source_discriminator`; it supplies
the required immutable `source_snapshot` (label, type and Version identity) and
the trusted Course-adapter signature. Learning revalidates the whitelist,
signature, source snapshot, tenant, published Framework Version and active Node
in the same transaction. A missing source snapshot, deleted/unmapped working
source, retired Node, deprecated/archived Framework Version or any mismatch
fails publish and rolls back the entire Course Version snapshot.

Product bindings remain locked to their exact published Course Version. A later
Template publish creates new canonical Mappings for the new Version only; it
never modifies, deletes or silently rebinds prior Product/Course mappings.

## Lifecycle

Mapping Intent follows the permissions and editing lifecycle currently applied
to Course Template authoring. Phase 1 adds no separate status gate for Intent.
When Course Template authoring gains a unified lifecycle gate, Intent must
follow that gate as well.

This is safe only while an Intent has no effect before publish: canonical
Mapping is created solely by promotion. If a future component reads Intent
before publish, this decision must be reviewed again. The one Intent-specific
rule is coherence, not lifecycle: a selected Framework Version cannot change
while the Template has Intents, and each Intent is composite-FK-bound to that
exact selection. Canonical Mapping is immutable after promotion and is
corrected by the approved invalidation lifecycle in
`core_learning_node_mappings`.

## Authorization

`customer_admin` is the Phase 1 Course author and may confirm `manual` Intent
directly. AI Proposal/review authorization is deferred with Proposal persistence.
Manual Intent does not become stale when its working source changes; AI Intent
staleness is deferred with Proposal fingerprint persistence. The Template surface
must list orphaned Intents and let the author remove them before publish, because
an orphan intentionally fails the publish transaction closed.
