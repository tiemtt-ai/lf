# Table: core_course_template_version_lesson_prerequisites

Version: 1.0

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-08-09

Document Path: database/course/core_course_template_version_lesson_prerequisites.md

Related ADR:
[ADR-0015 — Course Lesson Multiple Prerequisites](../../adr/ADR-0015-Course-Lesson-Multiple-Prerequisites.md)

---

# Purpose

Stores the immutable effective prerequisite graph for published Course
Template Version Lessons.

# Relationships

```text
core_course_template_versions 1 → N version_lesson_prerequisites
core_course_template_version_lessons 1 → N dependent edges
core_course_template_version_lessons 1 → N prerequisite edges
```

# Fields

| Field | Type | Null / Default | Meaning |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | PK, required | Snapshot relationship identity. |
| `customer_id` | BIGINT UNSIGNED | required | Tenant owner. |
| `template_version_id` | BIGINT UNSIGNED | required | Owning immutable Version. |
| `version_lesson_id` | BIGINT UNSIGNED | required | Dependent Version Lesson. |
| `prerequisite_version_lesson_id` | BIGINT UNSIGNED | required | Required Version Lesson. |
| `sort_order` | INT UNSIGNED | required, default `0` | Stable prerequisite display/evaluation order. |
| `created_at` | TIMESTAMP | nullable | Snapshot creation time. |
| `updated_at` | TIMESTAMP | nullable | Snapshot finalization audit time only. |

# Business Rules

* Both Version Lessons belong to the exact tenant and Template Version.
* Publish creates the complete effective edge set atomically.
* `all_previous_lessons_completed` is expanded into explicit immutable edges.
* `selected_lessons_completed` copies the selected working edges.
* Published rows are immutable and are never recalculated from working order.
* Runtime Progress checks use the same tenant, student, Enrollment, Product
  and Version learning cycle.
* Missing or inconsistent prerequisite data fails closed.

# Constraints And Indexes

```sql
UNIQUE uk_cctvlp_edge
    (customer_id, version_lesson_id, prerequisite_version_lesson_id);
INDEX idx_cctvlp_version
    (customer_id, template_version_id);
INDEX idx_cctvlp_lesson
    (customer_id, template_version_id, version_lesson_id, sort_order);
INDEX idx_cctvlp_prerequisite
    (customer_id, template_version_id, prerequisite_version_lesson_id);
```

Foreign keys use `RESTRICT`. Published Version Lessons and prerequisite edges
cannot be deleted independently.

# Immutability

After the parent Version becomes `published`, no prerequisite edge may be
inserted, updated or deleted. A later Template reorder or prerequisite edit
affects only a future Version.

# Migration Rollback

The snapshot relationship table may be removed only after a lossless
preflight. A Version Lesson can be downgraded solely when it uses
`selected_lessons_completed`, match `all`, and has exactly one edge. The edge
is copied to `unlock_after_version_lesson_id` before restoring
`previous_lesson_completed`. Any expanded `all_previous` graph, `any` policy,
multiple/missing edge set or incompatible edge refuses rollback before data or
schema changes.

# Tenant Isolation

Every query includes `customer_id = TenantContext::customerId()`. Parent
Version, dependent Lesson, prerequisite Lesson, Enrollment and Progress must
share the same tenant boundary.

---

End of Document
