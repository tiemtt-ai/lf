# Table: core_course_template_versions

Version: 2.0

Status: Approved

Last Updated: 2026-07-03

Related ADR:
[ADR-0012 — Course Template Published Version Snapshot Architecture](../../adr/ADR-0012-Course-Template-Published-Version-Snapshot.md)

---

# Purpose

Stores the immutable, tenant-owned published identity and Course Template
information snapshot for one publish event.

`core_course_templates` remains the editable source. This table becomes the
historical source of truth for the published Course definition. A later edit to
the working Template never changes an existing Version.

# Relationships

```text
saas_customers 1 → N core_course_template_versions
core_course_templates 1 → N core_course_template_versions
users 1 → N core_course_template_versions (published_by)
core_course_template_versions 1 → N version_sections
core_course_template_versions 1 → N version_lessons
core_course_template_versions 1 → N version_activities
```

Future Product and Enrollment references are acknowledged by Course
Foundation, but their implementation is outside this snapshot batch.

# Business Rules

* Every record has the same `customer_id` as its source Template.
* `version_number` starts at `1` and increases by one per Template.
* A publish operation snapshots the Template, Sections, Lessons and Activities
  in one database transaction.
* The publish workflow may use `draft_snapshot` inside the transaction, but a
  committed publish must have `status = published`, `published_at` and
  `published_by`.
* The newly published Version receives `is_current = 1`; the previous current
  Version receives `is_current = 0` in the same transaction.
* At most one current Version is allowed per tenant and Template. The
  publishing service must lock the Template/Version sequence while allocating
  the next number and changing the current marker.
* `is_current` is a designation, not content. Changing it does not mutate the
  frozen snapshot.
* Snapshot payload columns cannot be edited after publication.
* Lifecycle metadata may transition from `published` to `deprecated` or
  `archived`; content columns remain immutable.
* Old Versions are never deleted when a new Version is published.
* Publishing does not modify any editable Template, Section, Lesson or Activity
  content. Updating the Template read model `last_version_published_at` is
  allowed after a successful publish.

# Fields

| Field | Type | Null / Default | Meaning |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | PK, required | Version identity. |
| `customer_id` | BIGINT UNSIGNED | required | Tenant owner. |
| `template_id` | BIGINT UNSIGNED | required | Editable source Template. |
| `version_number` | INT UNSIGNED | required | Sequential number within the Template, starting at 1. |
| `version_code` | VARCHAR(100) | required | Stable tenant-unique Version code, for example `TOPIK-BEGINNER-V3`. |
| `is_current` | TINYINT(1) | required, default 0 | Whether this is the current published Version for the Template. |
| `source_category_id` | BIGINT UNSIGNED | nullable | Category lineage at publish time; not a published-content foreign-key authority. |
| `category_name_snapshot` | VARCHAR(255) | nullable | Category display name at publish time. |
| `title_snapshot` | VARCHAR(255) | required | Snapshot of Template title. |
| `slug_snapshot` | VARCHAR(255) | required | Snapshot of Template slug. |
| `short_description_snapshot` | VARCHAR(500) | nullable | Snapshot of short description. |
| `description_snapshot` | LONGTEXT | nullable | Snapshot of detailed description. |
| `publisher_name_snapshot` | VARCHAR(255) | nullable | Snapshot of content publisher name. |
| `thumbnail_type_snapshot` | VARCHAR(50) | required | Snapshot value: `image` or `video`. |
| `thumbnail_image_snapshot` | VARCHAR(500) | nullable | Snapshot image location. |
| `thumbnail_video_source_snapshot` | VARCHAR(50) | nullable | Snapshot video source such as `youtube` or `aws`. |
| `thumbnail_video_url_snapshot` | VARCHAR(1000) | nullable | Snapshot video URL. |
| `thumbnail_video_media_id_snapshot` | BIGINT UNSIGNED | nullable | Media lineage/reference captured at publish time. |
| `difficulty_level_snapshot` | VARCHAR(50) | nullable | Snapshot difficulty: `beginner`, `intermediate`, or `advanced`. |
| `language_snapshot` | VARCHAR(20) | nullable | Snapshot content language. |
| `estimated_duration_minutes_snapshot` | INT UNSIGNED | required, default 0 | Published duration aggregate. |
| `max_lessons_snapshot` | INT UNSIGNED | nullable | Snapshot authoring limit for audit. |
| `lesson_count_snapshot` | INT UNSIGNED | required, default 0 | Published Lesson count. |
| `meta_title_snapshot` | VARCHAR(255) | nullable | Snapshot SEO title. |
| `meta_description_snapshot` | VARCHAR(500) | nullable | Snapshot SEO description. |
| `meta_keywords_snapshot` | VARCHAR(500) | nullable | Snapshot SEO keywords. |
| `source_working_revision` | INT UNSIGNED | required | Working revision that produced this Version. |
| `status` | VARCHAR(50) | required, default `draft_snapshot` | Lifecycle: `draft_snapshot`, `published`, `deprecated`, `archived`. |
| `published_at` | TIMESTAMP | nullable before publish; required when published | Time the snapshot became published and immutable. |
| `published_by` | BIGINT UNSIGNED | required | User who initiated the publish. |
| `source_template_updated_at` | TIMESTAMP | required | Source Template `updated_at` captured for audit. |
| `metadata` | JSON | nullable | Non-canonical publish-pipeline metadata only. |
| `created_at` | TIMESTAMP | nullable | Record creation time. |
| `updated_at` | TIMESTAMP | nullable | Envelope metadata update time; not permission to edit snapshot payload. |

# Suggested Indexes

```sql
INDEX idx_cctv_customer (customer_id);
INDEX idx_cctv_template (customer_id, template_id);
INDEX idx_cctv_current (customer_id, template_id, is_current);
INDEX idx_cctv_status (customer_id, status);
INDEX idx_cctv_published (customer_id, template_id, published_at);
UNIQUE uk_cctv_number (customer_id, template_id, version_number);
UNIQUE uk_cctv_code (customer_id, version_code);
```

The service-level single-current invariant is required because a normal unique
index on `(customer_id, template_id, is_current)` would also prohibit multiple
non-current Versions.

# Delete And Reference Rules

* `customer_id` → `saas_customers.id`: `RESTRICT`.
* `template_id` → `core_course_templates.id`: `RESTRICT`. A Template with
  published Versions cannot be deleted.
* `published_by` → `users.id`: `RESTRICT` so publication attribution survives.
* Category and Media snapshot identifiers are lineage/reference values. They
  must not cascade changes or deletion into the Version.
* Published Versions cannot be hard-deleted through application behavior.
* No cascade from working authoring records is permitted.

# Immutability Rules

After `status = published`, all `*_snapshot`, source revision and published
structure records are read-only. Only `is_current`, lifecycle `status`, and
their audit timestamp may change through an approved lifecycle operation.
Rollback, restore, duplicate and in-place content correction are not part of
this design.

# Tenant Isolation

All reads and writes require `customer_id = TenantContext::customerId()`.
Template, publisher, child snapshot records and any resolved source record must
belong to the same tenant. A Version ID alone is never sufficient lookup scope.

# Sample Data

```text
id = 30
customer_id = 1
template_id = 10
version_number = 3
version_code = TOPIK-BEGINNER-V3
is_current = 1
source_category_id = 2
category_name_snapshot = Korean
title_snapshot = TOPIK Beginner
slug_snapshot = topik-beginner
thumbnail_type_snapshot = image
estimated_duration_minutes_snapshot = 2400
lesson_count_snapshot = 32
source_working_revision = 12
status = published
published_at = 2026-07-03 09:00:00
published_by = 5
source_template_updated_at = 2026-07-03 08:45:00
```

---

End of Document
