# ADR-0012 — Course Template Published Version Snapshot Architecture

Version: 1.4

Status: Approved

Implementation Status: Unknown

Last Updated: 2026-08-09

Decision Date: 2026-07-03

Scope Amendment Date: 2026-07-10
Nested Section Amendment Date: 2026-07-10
Information Model Amendment Date: 2026-07-12
Lifecycle Clarification Date: 2026-07-17
Activity Learning Availability Amendment Date: 2026-07-25

Document Path: adr/ADR-0012-Course-Template-Published-Version-Snapshot.md

Extends:
[ADR-0001 — Course Foundation](ADR-0001-Course-Foundation.md)

---

# Context

LearnForge Course authoring uses mutable working tables:

```text
core_course_templates
core_course_template_sections
core_course_template_lessons
core_course_template_activities
```

Teachers and administrators must be able to keep editing that working
definition. Published Course consumers, historical audit and future Enrollment
cannot safely read those mutable rows because later edits would silently change
the learning structure they observe.

ADR-0001 already approves the Template → immutable Version architecture. This
ADR freezes the field-level persistence boundary and publish behavior for the
four published snapshot tables.

# Decision

## Activity Learning Availability Amendment (2026-07-25)

Each Activity snapshots four explicit availability booleans:
`available_anytime`, `available_before_session`,
`available_during_session`, and `available_after_session`. `anytime` is
exclusive; otherwise one or more Session-relative phases may be selected.
Existing data defaults to `anytime`.

The snapshot contains no Working Live Class Activity anchor. A future Cohort
Session mapped to the Version Lesson supplies the real runtime time anchor.
These fields do not change ordering, completion, unlock or Progress and do not
implement Session scheduling or automatic activation by themselves.

## Version Activity Uploaded Media Amendment (2026-07-13)

Uploaded video, audio and document Activities snapshot `media_file_id` and an
active immutable Media Usage owned by `course_version_activity`. The physical
Media object is reused, not copied. Draft usage changes never mutate Version
usage, and active Version usage prevents physical deletion.

## Publish Integrity Boundary Amendment (2026-07-12)

Publish validates the locked working Template, whose lifecycle status must be
`active`, together with its Section, Lesson and Activity graph before creating
Version rows. Invalid ownership, hierarchy, prerequisite,
ordering, duration aggregate, activity configuration or referenced Media fails
closed and rolls back the complete transaction. A successful publish updates
`last_version_published_at` with the exact `published_at` timestamp of the new
Version in that same transaction.

The canonical field-level rules for the working Content graph are maintained
in `LF-Core-Course.md`, section **Course Template Publish — Content
Readiness**. This ADR defines the immutable snapshot and transaction boundary;
it does not maintain a competing validation list. In particular, Quiz
Activities remain blocked until the Assessment immutable binding is
implemented.

## Activity Estimated Duration Amendment (2026-07-12)

Publish freezes `estimated_duration_seconds` as
`estimated_duration_seconds_snapshot`; ordering continues to use `sort_order`.

## Lesson Role Amendment (2026-07-12)

Publish copies the working Lesson `lesson_type` to the immutable Version Lesson.
Allowed codes are `regular`, `review`, `midterm_exam`, `final_exam`, and
`other_exam`; existing data defaults to `regular`. The field is semantic only
and does not alter scheduling, grading, completion, unlock or Assessment rules.

## Information Model Amendment (2026-07-12)

Course Template is an internal authoring aggregate identified by tenant-scoped
ID and no longer has a slug. Course Product remains the public/catalog/SEO
aggregate and retains all existing Product slug behavior. Consequently publish
does not copy `slug_snapshot`.

The former representative `cover_type = image|video` decision is superseded.
The working Template and every Version support three independent optional
introduction items: image, video and document. Image, video and document may be
present simultaneously. Video alone has one discriminator and one active
source:

| Source | Uploaded Media ID | Embed URL/provider |
| --- | --- | --- |
| `NULL` | `NULL` | `NULL` / `NULL` |
| `upload` | required | `NULL` / `NULL` |
| `embed` | `NULL` | required / required |

Canonical Version fields are:

```text
intro_image_media_file_id_snapshot
intro_video_source_snapshot
intro_video_media_file_id_snapshot
intro_video_embed_url_snapshot
intro_video_provider_snapshot
intro_document_media_file_id_snapshot
estimated_minutes_per_lesson_snapshot
estimated_lesson_count_snapshot
```

Only normalized HTTPS YouTube or Vimeo URLs are accepted for embedded video.
Raw iframe/HTML is never stored; rendering derives a trusted embed URL from
the normalized provider and URL. Remote embeds do not create Media records or
usages.

`estimated_duration_minutes` previously meant total Course duration and
`max_lessons` meant a hard authoring maximum, so label-only changes are not
valid. They are superseded by nullable `estimated_minutes_per_lesson` and
`estimated_lesson_count`. Difficulty remains Template learning-content
metadata and is not renamed to Product level.

Each successful publish creates a new, tenant-owned, immutable Course Template
Version and a complete structural snapshot in one transaction.

```text
Editable Template

↓ publish transaction

Template Version
├── Version Lesson
│   └── Version Activity
└── Version Section
    ├── Version Section
    │   └── Version Lesson
    │       └── Version Activity
    └── Version Lesson
        └── Version Activity
```

The snapshot copies the Course information and every supported Section, Lesson
and Activity field required to reproduce the published structure. Direct
Lessons remain direct; Sectioned Lessons map to their corresponding simple
Version Section. Source IDs are retained only for lineage and reporting.

Course Template Sections are optional Lesson containers and may be nested
without a fixed depth limit. A Section has no status, visibility, completion
rule, unlock rule, media, duration, metadata or cached Lesson-count semantics.
Version Section snapshots therefore preserve supported Section business
fields:

```text
parent_version_section_id
allows_lessons
title_snapshot
description_snapshot
display_order
```

plus the required technical identity, tenant, Version, lineage and audit
fields documented in `core_course_template_version_sections`.

`version_number` starts at `1` for each Template and increments by one. The new
published Version becomes current, and the previous current Version is unset in
the same locked transaction. Older Versions remain intact.

The publish workflow may use `draft_snapshot` while assembling and validating
records inside the transaction. A successful committed Version is
`published`. Snapshot content is immutable thereafter.

# Tables Introduced

1. `core_course_template_versions`
   — published Version identity, Template information snapshot, publication
   attribution, lifecycle and current designation.
2. `core_course_template_version_sections`
   — immutable nested Section grouping and display order.
3. `core_course_template_version_lessons`
   — immutable direct or Sectioned Lesson structure.
4. `core_course_template_version_activities`
   — immutable Activity content, completion and reference context.

Canonical field definitions, constraints and delete rules are in:

* [core_course_template_versions](../database/course/core_course_template_versions.md)
* [core_course_template_version_sections](../database/course/core_course_template_version_sections.md)
* [core_course_template_version_lessons](../database/course/core_course_template_version_lessons.md)
* [core_course_template_version_activities](../database/course/core_course_template_version_activities.md)

# Why A Snapshot Is Required

* Working authoring rows are intentionally mutable.
* Published learning must remain reproducible after later edits.
* Section/Lesson/Activity grouping and ordering must be preserved as one
  coherent historical graph.
* Future Product, Enrollment, Progress, Certificate, Tracking and AI consumers
  require a stable published identity.
* Copying only the Template ID would make historical behavior depend on mutable
  source rows and violate the Snapshot Principle.

# Why Versions Are Immutable

An in-place update would silently alter the Course experience for every
consumer of that Version and destroy historical auditability. Corrections and
improvements therefore occur on the working Template and are published as a
new Version.

Immutability applies to the snapshot payload. The Version envelope may change
only for approved lifecycle metadata:

* `is_current` may move to a newer Version.
* `status` may transition `published` → `deprecated` → `archived`.

Neither operation permits editing published Course content.

# Why Version Management Is Inside Course Edit

Publishing and Version history are lifecycle operations of one Course Template,
not an independent business module. Keeping Publish and History inside Course
Edit:

* preserves the user’s current Course context;
* avoids a duplicate top-level navigation or sidebar;
* makes draft-to-published lineage explicit;
* follows the LF rule that child workflow data is managed inside its parent
  business screen when practical.

The Course Edit tabs remain:

```text
Information
Content
Teachers
Publish
History
```

# Transaction And Concurrency Boundary

A publish operation must:

1. Resolve `customer_id` from `TenantContext::customerId()`.
2. Lock the tenant-owned Template and its Version sequence.
3. Allocate `MAX(version_number) + 1`, or `1` when none exists.
4. Create the Version envelope.
5. Copy Sections, their `allows_lessons` capability and mapped parents.
6. Copy Lessons, preserving direct/Sectioned location at any Section level and
   mapping prerequisites.
7. Copy Activities, their learning availability and map Activity prerequisites.
8. Validate tenant ownership, ordering and references.
9. Unset the previous current Version and mark the new Version current.
10. Finalize `status = published`, `published_at` and publication audit fields.
11. Commit all records together.

Snapshot validation must enforce the video-source matrix before finalization.
Nullable introduction items are copied as nullable values. Version-owned Media
usages are attached inside the publish transaction so detaching later working
Template usages cannot invalidate an immutable Version.

# Media Usage Contract

Canonical usages use the existing separate owner and usage columns:

| `owner_type` | `usage_type` |
| --- | --- |
| `course_template` | `intro_image` |
| `course_template` | `intro_video` |
| `course_template` | `intro_document` |
| `course_template_version` | `intro_image` |
| `course_template_version` | `intro_video` |
| `course_template_version` | `intro_document` |

Attach validates same-tenant Media ownership and authorized admin/teacher
access. Replace attaches the new usage before detaching the previous working
usage. Remove detaches only the relevant working usage. Publishing attaches
separate Version usages which remain for the immutable Version lifetime.
Duplicate-to-draft validates surviving same-tenant references, attaches new
working usages, and never transfers or removes Version usages. Embedded URLs
have no Media usage.

# Future Migration Policy

The implementation migration must be forward-only and preserve historical
references:

* drop Template slug index/unique constraint and column; drop
  `slug_snapshot`;
* rename/map `cover_image_media_file_id` to `intro_image_media_file_id` and its
  Version snapshot equivalent when legacy `cover_type = image`;
* retain `intro_video_media_file_id`, set source `upload`, and map its snapshot
  when legacy `cover_type = video`;
* no legacy row becomes an embed; embed URL/provider start `NULL`;
* document fields start `NULL`;
* remove `cover_type` and `cover_type_snapshot` after successful backfill and
  invariant validation;
* copy legacy duration and maximum Lesson values unchanged into the new
  estimate fields as initial estimates because reliable semantic conversion
  is unavailable; do not invent derived values;
* keep Version IDs unchanged, preserve Version Media usability and do not
  alter Product Items, Enrollments, Progress or current-Version bindings.

Rollback may restore legacy columns only when it can do so without discarding
embed/document data; otherwise rollback must fail explicitly rather than lose
information.

Any failure rolls back the entire publish. No partial published graph is
allowed.

# Tenant And Reference Decision

Every snapshot table includes `customer_id`. Parent and child relationships
must share the same tenant. Queries and writes are always tenant-scoped.

Source Section/Lesson/Activity IDs are logical lineage, not cascade
relationships. Published child records use `RESTRICT` references to their
Version parents. No working-record delete or edit may cascade into published
history.

Version Section rows include `parent_version_section_id` to preserve the
approved hierarchy and required `allows_lessons` to preserve whether each
Section accepts Lessons. Other removed Section concepts remain outside the
snapshot boundary.

# Consequences

## Positive

* Published Course content is historically stable and auditable.
* Authoring can continue without changing existing Versions.
* Flat and Sectioned Courses use one coherent model.
* Future consumers receive stable Version Lesson and Activity identities.
* Tenant ownership is explicit at every snapshot level.

## Costs And Trade-offs

* Publication duplicates Course definition data by design.
* Publish requires transactional mapping of Section grouping and prerequisites.
* Storage grows with each Version.
* `is_current` is a service-enforced single-current invariant because a simple
  boolean unique key would incorrectly limit non-current Versions.
* Cross-domain Activity references require separate immutable/versioned
  contracts before they can guarantee frozen external content.

# Future Considerations

The following are explicitly deferred:

* rollback or restoring a previous Version;
* duplicating a Version or Template;
* binding Student Enrollment to a Version;
* Product selection and published Course consumption;
* Version comparison/diff;
* retention policy;
* finalized immutable reference contracts for Media, Assessment and LiveClass.

Future work must preserve existing Version records and require its own approved
documentation or ADR when it changes architecture.

# Applied Principles And Patterns

* Domain Responsibility Principle
* Source Of Truth Principle
* Immutable Principle
* Snapshot Principle
* Versioning Principle
* Tenant Isolation Principle
* Backward Compatibility Principle
* ADR Principle
* Simplicity Principle
* Template → Version Pattern
* Versioned Authoring Pattern
* Immutable Publishing Pattern
* Tenant Boundary Pattern

# Decision Result

```text
Approved

Database Documentation Ready

Section Snapshot Scope Simplified

Implementation Requires Separate Migration And Code Task
```

---

End of ADR
