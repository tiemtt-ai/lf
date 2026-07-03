# Table: core_course_template_version_sections

Version: 2.0

Status: Approved

Last Updated: 2026-07-03

Related ADR:
[ADR-0012 — Course Template Published Version Snapshot Architecture](../../adr/ADR-0012-Course-Template-Published-Version-Snapshot.md)

---

# Purpose

Stores the immutable published snapshot of an optional Course Template Section.
It preserves hierarchy, display order, completion semantics and presentation
data exactly as they existed at publish time.

# Relationships

```text
saas_customers 1 → N version_sections
core_course_template_versions 1 → N version_sections
version_sections 0..1 → N child version_sections
version_sections 1 → N version_lessons
core_course_template_sections 1 → N version_sections (logical lineage)
```

# Business Rules

* Every Version Section belongs to one tenant and one Template Version.
* `source_template_section_id` is lineage only and never a learning source.
* A working Section is copied at most once into a given Template Version.
* `parent_version_section_id` maps the source parent into the same Version.
* Root and child order are preserved with `sort_order`.
* Flat Courses create no hidden or default Version Section.
* `total_lessons` is the frozen count of published Version Lessons in the
  Section; it is not recalculated after publish.
* Version Sections become immutable when the parent Version is published.

# Fields

| Field | Type | Null / Default | Meaning |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | PK, required | Version Section identity. |
| `customer_id` | BIGINT UNSIGNED | required | Tenant owner. |
| `template_version_id` | BIGINT UNSIGNED | required | Parent published Version. |
| `source_template_section_id` | BIGINT UNSIGNED | required | Logical lineage to the working Section copied by publish. |
| `parent_version_section_id` | BIGINT UNSIGNED | nullable | Parent Section inside the same Version. |
| `code_snapshot` | VARCHAR(100) | nullable | Snapshot of internal Section code. |
| `title_snapshot` | VARCHAR(255) | required | Snapshot of Section title. |
| `short_title_snapshot` | VARCHAR(100) | nullable | Snapshot of short title. |
| `description_snapshot` | TEXT | nullable | Snapshot of description. |
| `thumbnail_file_id_snapshot` | BIGINT UNSIGNED | nullable | Media lineage/reference captured at publish time. |
| `sort_order` | INT UNSIGNED | required, default 1 | Published order among siblings. |
| `is_required` | TINYINT(1) | required, default 1 | Published required/optional rule. |
| `unlock_rule_snapshot` | VARCHAR(50) | required, default `immediate` | Snapshot rule: `immediate`, `after_previous_section`, or `manual`. |
| `estimated_duration_minutes` | INT UNSIGNED | nullable | Published estimated duration. |
| `total_lessons` | INT UNSIGNED | required, default 0 | Frozen Version Lesson count. |
| `status_snapshot` | VARCHAR(50) | required | Source Section status at publish: `active`, `inactive`, or `archived`. |
| `metadata_snapshot` | JSON | nullable | Snapshot of Section presentation metadata. |
| `created_at` | TIMESTAMP | nullable | Snapshot creation time. |
| `updated_at` | TIMESTAMP | nullable | Creation/finalization audit timestamp only. |

# Suggested Indexes

```sql
INDEX idx_cctvs_customer (customer_id);
INDEX idx_cctvs_version (customer_id, template_version_id);
INDEX idx_cctvs_source (customer_id, source_template_section_id);
INDEX idx_cctvs_parent (customer_id, template_version_id, parent_version_section_id);
INDEX idx_cctvs_sort
    (customer_id, template_version_id, parent_version_section_id, sort_order);
UNIQUE uk_cctvs_source
    (customer_id, template_version_id, source_template_section_id);
UNIQUE uk_cctvs_sort
    (customer_id, template_version_id, parent_version_section_id, sort_order);
```

MySQL permits multiple `NULL` values in a unique key. The publish service must
also validate unique root `sort_order` values where
`parent_version_section_id IS NULL`.

# Delete And Reference Rules

* `customer_id` and `template_version_id`: foreign keys with `RESTRICT`.
* `parent_version_section_id`: self-reference with `RESTRICT`.
* `source_template_section_id`: logical lineage, not a cascading foreign key.
  Working Sections may continue their authoring lifecycle without affecting
  historical snapshots.
* `thumbnail_file_id_snapshot` cannot cascade Media changes or deletion.
* Published Version Sections cannot be deleted independently.

# Immutability Rules

All content, hierarchy, ordering and metadata fields are read-only after the
parent Version is published. A Section correction requires editing the working
Template and publishing a new Version.

# Tenant Isolation

Every query includes `customer_id = TenantContext::customerId()`. The parent
Version, parent Version Section and source Section must have the same
`customer_id` during snapshot creation.

# Sample Data

```text
id = 101
customer_id = 1
template_version_id = 30
source_template_section_id = 7
parent_version_section_id = NULL
code_snapshot = M01
title_snapshot = Hangul Fundamentals
short_title_snapshot = Hangul
sort_order = 1
is_required = 1
unlock_rule_snapshot = immediate
estimated_duration_minutes = 240
total_lessons = 8
status_snapshot = active
metadata_snapshot = {"color":"#0EA5E9","icon":"book-open"}
```

---

End of Document
