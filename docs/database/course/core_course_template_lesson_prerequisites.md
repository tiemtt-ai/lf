# Table: core_course_template_lesson_prerequisites

Version: 1.0

Status: Approved Foundation

Last Updated: 2026-07-26

Document Path: database/course/core_course_template_lesson_prerequisites.md

Related ADR:
[ADR-0015 — Course Lesson Multiple Prerequisites](../../adr/ADR-0015-Course-Lesson-Multiple-Prerequisites.md)

---

# Purpose

Stores normalized prerequisite edges between working Course Template Lessons.
The table is mutable authoring data and is never consumed directly by
students.

# Relationships

```text
core_course_templates 1 → N lesson_prerequisites
core_course_template_lessons 1 → N dependent edges
core_course_template_lessons 1 → N prerequisite edges
```

# Fields

| Field | Type | Null / Default | Meaning |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | PK, required | Relationship identity. |
| `customer_id` | BIGINT UNSIGNED | required | Tenant owner. |
| `template_id` | BIGINT UNSIGNED | required | Owning working Template. |
| `lesson_id` | BIGINT UNSIGNED | required | Dependent Lesson. |
| `prerequisite_lesson_id` | BIGINT UNSIGNED | required | Lesson that must be completed. |
| `sort_order` | INT UNSIGNED | required, default `0` | Stable authoring display order for selected prerequisites. |
| `created_at` | TIMESTAMP | nullable | Creation audit time. |
| `updated_at` | TIMESTAMP | nullable | Update audit time. |

# Business Rules

* Both Lessons must belong to the exact `customer_id` and `template_id`.
* Both Lessons must be in the same canonical content lane.
* The prerequisite must precede the dependent Lesson.
* Self-reference and duplicate edges are prohibited.
* Rows are meaningful only when the dependent Lesson uses
  `selected_lessons_completed`.
* `all_previous_lessons_completed` is derived from canonical authoring order
  and does not persist a mutable working expansion in this table.
* Authoring mutations and publish serialize through the parent Template lock.

# Constraints And Indexes

```sql
UNIQUE uk_cctlp_edge
    (customer_id, lesson_id, prerequisite_lesson_id);
INDEX idx_cctlp_template
    (customer_id, template_id);
INDEX idx_cctlp_lesson
    (customer_id, template_id, lesson_id, sort_order);
INDEX idx_cctlp_prerequisite
    (customer_id, template_id, prerequisite_lesson_id);
```

Foreign keys use `RESTRICT`. Application validation additionally enforces
same-tenant and same-Template compatibility because independent foreign keys
cannot enforce the complete ownership tuple.

# Delete Rules

A Lesson cannot be deleted while another Lesson depends on it unless the
authoring operation explicitly removes or replaces the dependent edges inside
the same locked transaction. Tenant or Template deletion follows the existing
Course aggregate deletion policy and must not create orphan rows.

# Migration Rollback

The relationship table may be removed only after a lossless preflight:

* a dependent Lesson must use `selected_lessons_completed`, match `all`, and
  have exactly one edge;
* that edge is restored to `unlock_after_lesson_id` and the rule is restored
  to `previous_lesson_completed`;
* any other new rule, edge cardinality or incompatible edge refuses rollback
  before data or schema changes.

# Tenant Isolation

Every read and write includes
`customer_id = TenantContext::customerId()`. A Lesson ID alone is never a
sufficient lookup boundary.

---

End of Document
