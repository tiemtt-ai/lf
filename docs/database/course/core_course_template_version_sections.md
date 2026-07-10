# Table: core_course_template_version_sections

Version: 3.0

Status: Approved

Last Updated: 2026-07-10

Related ADR:
[ADR-0012 — Course Template Published Version Snapshot Architecture](../../adr/ADR-0012-Course-Template-Published-Version-Snapshot.md)

[ADR-0013 — Course Template Version Duplicate to Draft](../../adr/ADR-0013-Course-Template-Version-Duplicate-to-Draft.md)

---

# Purpose

Stores the immutable published snapshot of an optional Course Template Section.

Version Sections preserve the simple Lesson grouping that existed at publish
time. They keep required technical identity, tenant ownership, Version
ownership, source lineage and audit timestamps, while snapshotting only the
supported Section business fields.

Section snapshots do not preserve unsupported working Section concepts such as
hierarchy, code, short title, media, completion semantics, unlock rules,
duration, status, metadata or cached Lesson counts.

---

# Relationships

```text
saas_customers 1 → N core_course_template_version_sections
core_course_template_versions 1 → N core_course_template_version_sections
core_course_template_version_sections 1 → N core_course_template_version_lessons
core_course_template_sections 1 → N core_course_template_version_sections (logical lineage)
```

---

# Business Rules

* Every Version Section belongs to one tenant and one Template Version.
* `source_template_section_id` is lineage only and never a learning source.
* A working Section is copied at most once into a given Template Version.
* Flat Courses create no hidden/default Version Section.
* Version Sections are not nested.
* Version Sections only group Version Lessons.
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
parent_version_section_id
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

No replacement fields are introduced for these removed concepts.

---

# Suggested Indexes

```sql
INDEX idx_cctvs_customer (customer_id);
INDEX idx_cctvs_version (customer_id, template_version_id);
INDEX idx_cctvs_source (customer_id, source_template_section_id);
INDEX idx_cctvs_display_order
    (customer_id, template_version_id, display_order);
UNIQUE uk_cctvs_source
    (customer_id, template_version_id, source_template_section_id);
```

No unique display-order constraint is required in the foundation. Consumers may
order by `display_order`, then `id`, to keep rendering stable when two Version
Sections share the same display order.

---

# Delete And Reference Rules

* `customer_id` and `template_version_id`: foreign keys with `RESTRICT`.
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
title_snapshot = Hangul Fundamentals
description_snapshot = Introductory lessons for Hangul basics.
display_order = 1
```

---

End of Document
