# Table: core_course_template_version_lessons

Version: 2.0

Status: Approved

Last Updated: 2026-07-03

Related ADR:
[ADR-0012 — Course Template Published Version Snapshot Architecture](../../adr/ADR-0012-Course-Template-Published-Version-Snapshot.md)

[ADR-0013 — Course Template Version Duplicate to Draft](../../adr/ADR-0013-Course-Template-Version-Duplicate-to-Draft.md)

---

# Purpose

Stores immutable published Lesson snapshots. A Version Lesson may belong
directly to the Template Version or to one Version Section, preserving both
flat and sectioned Course structures.

# Relationships

```text
saas_customers 1 → N version_lessons
core_course_template_versions 1 → N version_lessons
version_sections 0..1 → N version_lessons
version_lessons 0..1 → N dependent version_lessons (unlock prerequisite)
version_lessons 1 → N version_activities
core_course_template_lessons 1 → N version_lessons (logical lineage)
```

# Business Rules

* Every Version Lesson belongs to one tenant and one Template Version.
* `version_section_id = NULL` means the Lesson belongs directly to the Version.
* A non-null Version Section must belong to the same Version and tenant.
* Every working Lesson is copied at most once into a given Version.
* Direct and Section-relative `sort_order` values are preserved.
* `unlock_after_version_lesson_id` is mapped from the source prerequisite and
  must point to a Lesson in the same Template Version.
* Duration and Activity count are frozen published aggregates.
* Source IDs exist for lineage/reporting only.
* Version Lessons become immutable when the parent Version is published.
* Runtime evaluates `previous_lesson_completed` against the specifically
  mapped prerequisite, not display ordering. Completion must come from the
  same tenant, Enrollment, Product, Version and student learning cycle.

# Fields

| Field | Type | Null / Default | Meaning |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | PK, required | Version Lesson identity. |
| `customer_id` | BIGINT UNSIGNED | required | Tenant owner. |
| `template_version_id` | BIGINT UNSIGNED | required | Parent published Version. |
| `version_section_id` | BIGINT UNSIGNED | nullable | Optional containing Version Section. |
| `source_template_lesson_id` | BIGINT UNSIGNED | required | Logical lineage to the working Lesson copied by publish. |
| `title_snapshot` | VARCHAR(255) | required | Snapshot Lesson title. |
| `short_description_snapshot` | VARCHAR(500) | nullable | Snapshot short description. |
| `description_snapshot` | TEXT | nullable | Snapshot detailed description. |
| `sort_order` | INT | required, default 0 | Published order within the direct or Section group. |
| `is_preview` | TINYINT(1) | required, default 0 | Published preview permission. |
| `lesson_type` | VARCHAR(50) | required, default `regular` | Immutable semantic role snapshot: `regular`, `review`, `midterm_exam`, `final_exam`, `other_exam`. |
| `duration_seconds` | INT UNSIGNED | required, default 0 | Frozen published duration aggregate. |
| `activity_count` | INT UNSIGNED | required, default 0 | Frozen Version Activity count. |
| `unlock_rule_snapshot` | VARCHAR(50) | required, default `none` | Snapshot rule: `none`, `previous_lesson_completed`, or `date_based`. |
| `unlock_after_version_lesson_id` | BIGINT UNSIGNED | nullable | Published prerequisite Lesson in the same Version. |
| `unlock_at_snapshot` | TIMESTAMP | nullable | Published date-based unlock time. |
| `created_by_snapshot` | BIGINT UNSIGNED | nullable | Source author identifier captured for audit. |
| `created_at` | TIMESTAMP | nullable | Snapshot creation time. |
| `updated_at` | TIMESTAMP | nullable | Creation/finalization audit timestamp only. |

# Suggested Indexes

```sql
INDEX idx_cctvl_customer (customer_id);
INDEX idx_cctvl_version (customer_id, template_version_id);
INDEX idx_cctvl_section (customer_id, template_version_id, version_section_id);
INDEX idx_cctvl_source (customer_id, source_template_lesson_id);
INDEX idx_cctvl_unlock
    (customer_id, template_version_id, unlock_after_version_lesson_id);
INDEX idx_cctvl_sort
    (customer_id, template_version_id, version_section_id, sort_order);
UNIQUE uk_cctvl_source
    (customer_id, template_version_id, source_template_lesson_id);
```

The publish service validates unique `sort_order` values separately for direct
Lessons (`version_section_id IS NULL`) and for each Version Section.

# Delete And Reference Rules

* `customer_id`, `template_version_id`, `version_section_id`, and
  `unlock_after_version_lesson_id`: foreign keys with `RESTRICT`.
* `source_template_lesson_id` and `created_by_snapshot`: logical audit values;
  no cascade may mutate or delete the snapshot.
* Published Version Lessons cannot be deleted independently.
* A working Lesson may be edited or removed later without changing this row.

# Immutability Rules

All Lesson content, location, order, preview, duration and unlock fields are
read-only after publication. Reordering or moving a working Lesson affects only
a future Version. ADR-0013 may read these rows to create new direct or
Sectioned working Lessons and remap working prerequisites; Version Lesson rows
remain unchanged.

# Tenant Isolation

Every query includes `customer_id = TenantContext::customerId()`. Parent
Version, optional Section, prerequisite Version Lesson and source Lesson must
share the same tenant during publish.

# Sample Data

```text
id = 501
customer_id = 1
template_version_id = 30
version_section_id = 101
source_template_lesson_id = 15
title_snapshot = Korean Alphabet
sort_order = 1
is_preview = 1
duration_seconds = 1800
activity_count = 4
unlock_rule_snapshot = none
created_by_snapshot = 5
```

Direct Lesson example:

```text
id = 502
customer_id = 1
template_version_id = 30
version_section_id = NULL
source_template_lesson_id = 16
title_snapshot = Course Orientation
sort_order = 0
is_preview = 1
duration_seconds = 300
activity_count = 1
unlock_rule_snapshot = none
```

---

End of Document
