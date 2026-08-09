# Table: core_course_template_version_sections

Version: 4.0

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-08-09

Document Path: database/course/core_course_template_version_sections.md

Related ADR:
[ADR-0012 — Course Template Published Version Snapshot Architecture](../../adr/ADR-0012-Course-Template-Published-Version-Snapshot.md)

[ADR-0013 — Course Template Version Duplicate to Draft](../../adr/ADR-0013-Course-Template-Version-Duplicate-to-Draft.md)

---

# Purpose

Stores the immutable published snapshot of an optional Course Template Section.

Version Sections preserve the nested Section and Lesson grouping that existed
at publish time. They keep required technical identity, tenant ownership,
Version ownership, source lineage and audit timestamps, while snapshotting
only the supported Section business fields.

Section snapshots do not preserve unsupported working Section concepts such as
code, short title, media, completion semantics, unlock rules, duration,
status, metadata or cached Lesson counts. Hierarchy is preserved only through
`parent_version_section_id`.

---

# Relationships

```text
saas_customers 1 → N core_course_template_version_sections
core_course_template_versions 1 → N core_course_template_version_sections
core_course_template_version_sections 0..1 → N core_course_template_version_sections
core_course_template_version_sections 1 → N core_course_template_version_lessons
core_course_template_sections 1 → N core_course_template_version_sections (logical lineage)
```

---

# Business Rules

* Every Version Section belongs to one tenant and one Template Version.
* `source_template_section_id` is lineage only and never a learning source.
* A working Section is copied at most once into a given Template Version.
* Flat Courses create no hidden/default Version Section.
* Version Sections preserve unlimited Section nesting through
  `parent_version_section_id`.
* `allows_lessons` is the immutable snapshot of the working Section's required
  Lesson-container capability.
* A Version Lesson may reference a Version Section only when that Section's
  `allows_lessons` snapshot is `TRUE`.
* Root Version Sections have `parent_version_section_id = NULL`.
* Version Section hierarchy must not contain cycles.
* Version Sections group child Version Sections and Version Lessons.
* Version Lessons may attach to any Version Section level.
* Version Sections do not contain Activities directly.
* Version Sections do not contain Media directly.
* Version Sections do not define status, visibility, required/completion rules,
  unlock rules, duration, metadata or cached Lesson counts.
* Display order is preserved with `display_order`.
* Version Sections become immutable when the parent Version is published.

---

# Fields

| Field | Type | Null / Default | Meaning |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | PK, required | Version Section identity. |
| `customer_id` | BIGINT UNSIGNED | required | Tenant owner. |
| `template_version_id` | BIGINT UNSIGNED | required | Parent published Version. |
| `source_template_section_id` | BIGINT UNSIGNED | required | Logical lineage to the working Section copied by publish. |
| `parent_version_section_id` | BIGINT UNSIGNED | nullable | Parent Version Section in the same Version. |
| `allows_lessons` | BOOLEAN | required | Snapshot of whether the Section accepts Lessons. |
| `title_snapshot` | VARCHAR(255) | required | Snapshot of Section title. |
| `description_snapshot` | TEXT | nullable | Snapshot of Section description. |
| `display_order` | INT UNSIGNED | required, default 1 | Published display order inside the Version. |
| `created_at` | TIMESTAMP | nullable | Snapshot creation time. |
| `updated_at` | TIMESTAMP | nullable | Creation/finalization audit timestamp only. |

---

# Removed Snapshot Fields

The simplified Section foundation removes snapshot fields corresponding to
unsupported working Section business fields:

```text
code_snapshot
short_title_snapshot
thumbnail_file_id_snapshot
sort_order
is_required
unlock_rule_snapshot
estimated_duration_minutes
total_lessons
status_snapshot
metadata_snapshot
```

`parent_version_section_id` is restored as the approved structural hierarchy
snapshot field. No replacement fields are introduced for the remaining removed
concepts.

---

# Suggested Indexes

```sql
INDEX idx_cctvs_customer (customer_id);
INDEX idx_cctvs_version (customer_id, template_version_id);
INDEX idx_cctvs_source (customer_id, source_template_section_id);
INDEX idx_cctvs_parent
    (customer_id, template_version_id, parent_version_section_id);
INDEX idx_cctvs_display_order
    (customer_id, template_version_id, parent_version_section_id, display_order);
UNIQUE uk_cctvs_source
    (customer_id, template_version_id, source_template_section_id);
```

No unique display-order constraint is required in the foundation. Consumers may
order siblings by `display_order`, then `id`, to keep rendering stable when
two Version Sections share the same display order.

---

# Delete And Reference Rules

* `customer_id` and `template_version_id`: foreign keys with `RESTRICT`.
* `parent_version_section_id`: self-referencing foreign key with `RESTRICT`.
* `source_template_section_id`: logical lineage, not a cascading foreign key.
  Working Sections may continue their authoring lifecycle without affecting
  historical snapshots.
* Published Version Sections cannot be deleted independently.

---

# Immutability Rules

All supported Section snapshot fields are read-only after the parent Version is
published. A Section correction requires editing the working Template and
publishing a new Version.

ADR-0013 may read these rows to reconstruct new working Section rows. Existing
Version Section rows remain unchanged.

---

# Tenant Isolation

Every query includes:

```text
customer_id = TenantContext::customerId()
```

The parent Version and source Section must have the same `customer_id` during
snapshot creation.

---

# Sample Data

```text
id = 101
customer_id = 1
template_version_id = 30
source_template_section_id = 7
parent_version_section_id = NULL
title_snapshot = Hangul Fundamentals
description_snapshot = Introductory lessons for Hangul basics.
display_order = 1
```

---

End of Document
