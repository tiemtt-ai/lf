# Table: core_course_template_version_activities

Version: 2.0

Status: Approved

Last Updated: 2026-07-03

Related ADR:
[ADR-0012 — Course Template Published Version Snapshot Architecture](../../adr/ADR-0012-Course-Template-Published-Version-Snapshot.md)

---

# Purpose

Stores immutable published Activity snapshots. Version Activity is the
smallest frozen Course learning-content unit and the future canonical reference
for Activity Progress, Tracking and authorized AI learning context.

# Relationships

```text
saas_customers 1 → N version_activities
core_course_template_versions 1 → N version_activities
version_lessons 1 → N version_activities
version_activities 0..1 → N dependent version_activities (unlock prerequisite)
core_course_template_activities 1 → N version_activities (logical lineage)
Media / Assessment / LiveClass → version_activities (approved immutable reference contract required)
```

# Business Rules

* Every Version Activity belongs to one tenant, one Template Version and one
  Version Lesson.
* Every working Activity is copied at most once into a given Version.
* Activity order within the Version Lesson is preserved.
* Completion, preview and unlock rules are frozen at publish time.
* `unlock_after_version_activity_id` maps the source prerequisite and must
  belong to the same Version Lesson and tenant.
* External URL and embed content are explicit snapshot fields, not hidden in
  generic metadata.
* `source_template_activity_id` is lineage only.
* Cross-domain references must point to an immutable/versioned asset or be
  accompanied by the immutable context required by that Domain contract.
  Final Media/Assessment/LiveClass contracts remain a future review item.
* Version Activities become immutable when the parent Version is published.

# Fields

| Field | Type | Null / Default | Meaning |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | PK, required | Version Activity identity. |
| `customer_id` | BIGINT UNSIGNED | required | Tenant owner. |
| `template_version_id` | BIGINT UNSIGNED | required | Parent published Version. |
| `version_lesson_id` | BIGINT UNSIGNED | required | Parent Version Lesson. |
| `source_template_activity_id` | BIGINT UNSIGNED | required | Logical lineage to the working Activity copied by publish. |
| `title_snapshot` | VARCHAR(255) | required | Snapshot Activity title. |
| `description_snapshot` | TEXT | nullable | Snapshot Activity description. |
| `sort_order` | INT | required, default 0 | Published order within the Lesson. |
| `activity_type` | VARCHAR(50) | required | `text`, `video`, `audio`, `document`, `quiz`, `assignment`, `liveclass`, or `external_link`. |
| `activity_ref_type_snapshot` | VARCHAR(100) | nullable | Snapshot of referenced Domain/entity type. |
| `activity_ref_id_snapshot` | BIGINT UNSIGNED | nullable | Immutable/versioned target identifier or approved lineage reference. |
| `external_url_snapshot` | VARCHAR(1000) | nullable | Frozen external URL. |
| `embed_code_snapshot` | LONGTEXT | nullable | Frozen embed configuration/content. |
| `duration_seconds` | INT UNSIGNED | required, default 0 | Published Activity duration. |
| `is_required` | TINYINT(1) | required, default 1 | Published completion requirement. |
| `completion_rule` | VARCHAR(50) | required, default `view` | `view`, `watch_percent`, `submit`, `pass`, `attend`, or `manual`. |
| `completion_threshold` | INT UNSIGNED | nullable | Frozen threshold, such as watch percentage or pass percentage. |
| `is_preview` | TINYINT(1) | required, default 0 | Published preview permission. |
| `unlock_rule_snapshot` | VARCHAR(50) | required, default `none` | `none`, `previous_activity_completed`, `previous_lesson_completed`, or `date_based`. |
| `unlock_after_version_activity_id` | BIGINT UNSIGNED | nullable | Published prerequisite Activity in the same Lesson. |
| `unlock_at_snapshot` | TIMESTAMP | nullable | Published date-based unlock time. |
| `status_snapshot` | VARCHAR(50) | required | Source Activity status at publish: `draft`, `active`, `inactive`, or `archived`. |
| `created_by_snapshot` | BIGINT UNSIGNED | nullable | Source author identifier captured for audit. |
| `metadata` | JSON | nullable | Non-canonical immutable integration context only; not a substitute for defined fields. |
| `created_at` | TIMESTAMP | nullable | Snapshot creation time. |
| `updated_at` | TIMESTAMP | nullable | Creation/finalization audit timestamp only. |

# Suggested Indexes

```sql
INDEX idx_cctva_customer (customer_id);
INDEX idx_cctva_version (customer_id, template_version_id);
INDEX idx_cctva_lesson (customer_id, template_version_id, version_lesson_id);
INDEX idx_cctva_source (customer_id, source_template_activity_id);
INDEX idx_cctva_type (customer_id, activity_type);
INDEX idx_cctva_reference
    (customer_id, activity_ref_type_snapshot, activity_ref_id_snapshot);
INDEX idx_cctva_unlock
    (customer_id, version_lesson_id, unlock_after_version_activity_id);
INDEX idx_cctva_sort (customer_id, version_lesson_id, sort_order);
UNIQUE uk_cctva_source
    (customer_id, template_version_id, source_template_activity_id);
```

The publish service validates unique `sort_order` within each Version Lesson.

# Delete And Reference Rules

* `customer_id`, `template_version_id`, `version_lesson_id`, and
  `unlock_after_version_activity_id`: foreign keys with `RESTRICT`.
* `source_template_activity_id` and `created_by_snapshot`: logical audit
  values; no cascade is permitted.
* Generic cross-domain reference fields do not use an unconditional physical
  foreign key. Publish must validate owner existence and tenant compatibility.
* Published Version Activities cannot be deleted independently.
* Deleting or editing working content never changes a Version Activity.

# Immutability Rules

All Activity content, reference context, order, duration, completion, preview
and unlock fields are read-only after publication. Corrections require a new
Template Version. Rollback and duplication are not part of this design.

# Tenant Isolation

Every query includes `customer_id = TenantContext::customerId()`. Parent
Version, Version Lesson, prerequisite, source Activity and any resolved
cross-domain reference must belong to the same tenant or follow an explicitly
approved shared-asset contract.

# Sample Data

Video Activity:

```text
id = 9001
customer_id = 1
template_version_id = 30
version_lesson_id = 501
source_template_activity_id = 20
title_snapshot = Hangul Introduction
sort_order = 1
activity_type = video
activity_ref_type_snapshot = media_files
activity_ref_id_snapshot = 700
duration_seconds = 900
is_required = 1
completion_rule = watch_percent
completion_threshold = 80
is_preview = 1
unlock_rule_snapshot = none
status_snapshot = active
created_by_snapshot = 5
```

External Activity:

```text
id = 9002
customer_id = 1
template_version_id = 30
version_lesson_id = 501
source_template_activity_id = 21
title_snapshot = Extra Practice
sort_order = 2
activity_type = external_link
external_url_snapshot = https://example.com/practice
duration_seconds = 300
is_required = 0
completion_rule = view
is_preview = 0
unlock_rule_snapshot = previous_activity_completed
unlock_after_version_activity_id = 9001
status_snapshot = active
```

---

End of Document
