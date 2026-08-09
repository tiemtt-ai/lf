# ADR-0013 — Course Template Version Duplicate to Draft

Version: 1.3

Status: Approved

Decision Date: 2026-07-03

Scope Amendment Date: 2026-07-10
Nested Section Amendment Date: 2026-07-10
Information Model Amendment Date: 2026-07-12

Document Path: adr/ADR-0013-Course-Template-Version-Duplicate-to-Draft.md

Extends:

* [ADR-0001 — Course Foundation](ADR-0001-Course-Foundation.md)
* [ADR-0012 — Course Template Published Version Snapshot](ADR-0012-Course-Template-Published-Version-Snapshot.md)

---

# Context

LearnForge maintains one mutable Course Template working definition and many
immutable published Course Template Versions:

```text
One Course Template draft

↓ publishes

Many immutable Course Template Versions
```

An administrator may need to use the content of an earlier published Version
as the new starting point for authoring. Editing the Version would destroy
historical integrity. Creating another Template or another draft record would
also violate the Course Foundation source-of-truth model.

ADR-0012 intentionally deferred duplicate behavior. This ADR approves the
specific operation that copies one immutable Version back into the sole
editable draft of the same Template.

The 2026-07-10 nested Section amendment approves unlimited Section hierarchy.
Duplicate-to-draft must reconstruct only the supported Section fields
documented in `core_course_template_sections`, including `parent_section_id`.

# Decision

## Version Activity Uploaded Media Amendment (2026-07-13)

Duplicate-to-draft restores uploaded Activity Media only from the Version
Activity `media_file_id` and its active `course_version_activity` usage. It
reuses the Media object and creates a new `course_activity` usage owned by the
new draft Activity; source Version usage remains unchanged.

## Activity Estimated Duration Amendment (2026-07-12)

Duplicate restores Activity estimated duration and `sort_order`, then
recalculates each working Lesson duration from restored Activity estimates.

## Lesson Role Amendment (2026-07-12)

Duplicate-to-draft restores each Version Lesson `lesson_type` snapshot to the
new working Lesson. It does not change any unlock or cross-domain behavior.

`Duplicate Version to Draft` is an Authoring operation. It replaces the current
working content of the selected Version's own Course Template.

The operation:

* uses an existing immutable Version as a read-only source;
* updates the existing `core_course_templates` row;
* replaces its working Sections, Lessons and Activities;
* creates no new Template, draft row, Version or Product;
* changes no published Version record or current-Version designation;
* changes no Product, Enrollment, Progress or Completion data.

```text
Selected immutable Version

↓ duplicate in one transaction

Existing editable Template
├── direct Lessons
│   └── Activities
└── Sections
    ├── Sections
    │   └── Lessons
    │       └── Activities
    └── Lessons
        └── Activities
```

# Domain And UI Placement

The operation belongs to Course Template Authoring, not Course Product.

The action is exposed only in:

* Course Edit → History;
* readonly Course Template Version Detail.

No Product screen, top-level navigation or separate module is introduced.

# Eligibility And Authorization

* Only `customer_admin` may duplicate a Version to draft.
* The Template and Version must be resolved using
  `TenantContext::customerId()`.
* The selected Version must belong to the same tenant and the same Template.
* A Version ID alone is never sufficient lookup scope.
* Immutable lifecycle states `published`, `deprecated` and `archived` are
  eligible sources.
* An incomplete `draft_snapshot` is not eligible.
* A missing or tenant-mismatched Template or Version returns `404`.
* An authenticated unauthorized role returns `403`.

# Single Draft Decision

A Course Template has exactly one editable working definition:

```text
core_course_templates
core_course_template_sections
core_course_template_lessons
core_course_template_activities
```

Duplicate does not create a second draft, a draft table, a draft Version or a
new Template. It updates the existing Template and reconstructs its existing
working child graph.

Teacher assignments are not Version snapshot content and are not replaced.
Existing `core_course_template_teachers` rows remain unchanged.

# Template Field Mapping

The 2026-07-12 Information Model amendment removes Template slug restoration,
generation and conflict validation, and supersedes `cover_type` restoration.

The following Version snapshot fields map back to their editable Template
counterparts:

| Version snapshot | Editable Template |
| --- | --- |
| `source_category_id` | `category_id`, subject to fallback rules |
| `title_snapshot` | `title` |
| `short_description_snapshot` | `short_description` |
| `description_snapshot` | `description` |
| `publisher_name_snapshot` | `publisher_name` |
| `intro_image_media_file_id_snapshot` | `intro_image_media_file_id`, subject to fallback rules |
| `intro_video_source_snapshot` | `intro_video_source`, subject to the video matrix |
| `intro_video_media_file_id_snapshot` | `intro_video_media_file_id`, subject to fallback rules |
| `intro_video_embed_url_snapshot` | `intro_video_embed_url` when source is `embed` |
| `intro_video_provider_snapshot` | `intro_video_provider` when source is `embed` |
| `intro_document_media_file_id_snapshot` | `intro_document_media_file_id`, subject to fallback rules |
| `difficulty_level_snapshot` | `difficulty_level` |
| `estimated_minutes_per_lesson_snapshot` | `estimated_minutes_per_lesson` |
| `estimated_lesson_count_snapshot` | `estimated_lesson_count` |
| `lesson_count_snapshot` | `lesson_count` |
| `meta_title_snapshot` | `meta_title` |
| `meta_description_snapshot` | `meta_description` |
| `meta_keywords_snapshot` | `meta_keywords` |

Fields that represent the Version envelope, including `version_number`,
`version_code`, `is_current`, Version `status`, `published_at` and
`published_by`, never map into editable Template business state.

# Template Status Decision

After duplicate, `core_course_templates.status` is set to `draft`.

The resulting Template is an editable modified draft even if the source
Version is current, deprecated or archived. Version lifecycle status is not
copied into Template status.

# Working Revision Decision

No new draft or revision field is introduced.

`working_revision` remains the monotonic revision counter for the one working
draft and is incremented from its current value:

```text
new working_revision = current working_revision + 1
```

It is not reset to `source_working_revision` from the selected Version.

`updated_at` is set to the operation time. `created_by` and `created_at` remain
unchanged. `last_version_published_at` remains unchanged because duplicate is
not a publish event.

# Optional Reference Fallback

Optional mutable references can disappear after a Version was published.
Their absence must not mutate the Version and should not prevent draft
reconstruction when the editable relationship is optional.

## Category

Copy `source_category_id` to `category_id` only when that Category still exists
in the same tenant. Otherwise set `category_id = NULL`.

`category_name_snapshot` remains preserved on the immutable Version for
historical display. The editable Template has no category-name snapshot field,
so no new field is introduced.

## Media

Copy nullable Template Media identifiers only when the referenced asset still
exists under an approved same-tenant or shared-asset contract. Otherwise set
the nullable identifier to `NULL`.

The video source matrix is validated atomically. `upload` restores only a
surviving same-tenant Media reference; `embed` restores only the normalized
URL/provider; `NULL` clears both. Image and document restore independently and
may coexist with either video source. New working Media usages are attached;
immutable Version usages are never transferred or detached. Embed URLs create
no Media usage. The immutable Version retains all original snapshot values
regardless of fallback.

## Activity References

Activity reference type/ID pairs follow their owning Domain contract. A valid
immutable or same-tenant reference may be copied. If an optional target no
longer exists, its nullable reference pair is cleared while explicit snapshot
content such as external URL or embed content is preserved.

Missing required data, an invalid video-source combination, an unsupported
required reference or a tenant mismatch fails validation and rolls back the
whole operation. Template slug conflict validation no longer exists.

# Structural Replacement

Existing working child rows are removed in dependency order:

1. Template Activities.
2. Template Lessons.
3. Template Sections.

The selected Version graph is then recreated with new working row identities.
Snapshot source IDs are lineage values and are never reused as editable
primary keys.

Reconstruction must:

* preserve nested Section hierarchy and sibling display order;
* preserve direct Lessons with `template_section_id = NULL`;
* preserve Sectioned Lessons at any Section level through a Version Section →
  new Template Section map;
* preserve Lesson order within each direct or Section group;
* preserve Activities and their order within each Lesson;
* remap Lesson prerequisites to newly created Template Lesson IDs;
* remap Activity prerequisites to newly created Template Activity IDs;
* preserve documented editable Template, Section, Lesson and Activity fields;
* recreate Sections only with nullable `parent_section_id`, required
  `allows_lessons`, `title`, nullable `description` and `display_order`.

# Transaction And Concurrency Boundary

The complete operation runs in one database transaction.

The implementation must:

1. Resolve tenant and authorize `customer_admin`.
2. Lock the tenant-owned Template working row.
3. Resolve and validate the tenant-owned source Version and complete snapshot
   graph.
4. Preflight required constraints and reference fallback.
5. Delete existing working Activities, Lessons and Sections.
6. Update the existing Template snapshot-mapped fields and draft metadata.
7. Recreate Sections as nested Lesson containers.
8. Recreate direct and Sectioned Lessons and map prerequisites.
9. Recreate Activities and map prerequisites.
10. Record the append-only tenant audit event.
11. Commit.

Any failure rolls back all changes. The previous working draft must remain
intact after a failed operation. The Template lock prevents concurrent
authoring writes from interleaving with replacement.

# Audit Decision

No `duplicated_from_version_id`, `duplicated_by` or `duplicated_at` columns are
added to `core_course_templates`.

Those fields would describe only the latest duplicate and overwrite the
history of repeated operations. Provenance instead uses an append-only tenant
audit event:

```text
action = course_template_version_duplicated_to_draft
customer_id
template_id
source_template_version_id
actor_user_id
occurred_at
```

The event is audit context, not Course business state. It must not contain
snapshot content or sensitive payloads.

# Confirmation And Result

Before submission, the UI must display:

```text
This will replace the current draft content with this published version.
Published versions will not be changed. Continue?
```

The operation uses a state-changing, CSRF-protected request and must not be
performed by `GET`.

After success, redirect to Course Template Edit → Content and display:

```text
Published version duplicated to draft successfully.
```

# Immutability And Isolation Guarantees

Duplicate reads Version rows but never updates or deletes them.

It must not change:

* `version_number`;
* `is_current`;
* Version lifecycle `status`;
* any Version snapshot payload;
* the Version count;
* Product or Product Item references;
* Enrollment bindings;
* Progress or Completion.

All Version and working-table queries and writes are tenant-scoped.

# Consequences

## Positive

* Administrators can resume authoring from a historical published state.
* Published history remains immutable and auditable.
* The one-draft source-of-truth model remains intact.
* Direct and Sectioned Course structures use the same reconstruction workflow.
* No Product or learner state is affected.
* Section schema simplification is authorized by the amended documentation and
  must be implemented separately with new migrations only.

## Costs And Risks

* Current unpublished draft work is intentionally replaced.
* Reconstruction requires careful identity and prerequisite mapping.
* Optional external references may no longer resolve.
* A long-running replacement transaction may temporarily serialize edits for
  the selected Template.
* Audit recording must participate in the transaction so success is not
  reported without provenance.
* Optional Media may have disappeared; fallback must preserve the video-source
  invariant without changing the immutable Version.

# Explicitly Out Of Scope

* Editing a published Version.
* Rollback that changes the current published Version.
* Creating a new Template from a Version.
* Creating multiple drafts.
* Publishing automatically after duplicate.
* Product Version switching.
* Enrollment, Progress or Completion migration.
* Version comparison or merge.

# Applied Principles And Patterns

* Domain Responsibility Principle
* Source Of Truth Principle
* Immutable Principle
* Snapshot Principle
* Versioning Principle
* Tenant Isolation Principle
* Append Only Principle
* Backward Compatibility Principle
* ADR Principle
* Simplicity Principle
* Template → Version Pattern
* Tenant Boundary Pattern

# Decision Result

```text
Approved

Documentation Frozen

Implementation Authorized After Documentation Update

Section Schema Simplification Authorized By New Migration Only
```

Note: "Documentation Frozen" describes this ADR's content/text being locked
against further edits. It is not the ADR lifecycle Status defined at the top
of this document (`Status: Approved`, per the Draft → Review → Approved →
Frozen → Archived vocabulary in `adr/README.md`). The two uses of "Frozen"
refer to different things and are not in conflict.

---

End of ADR
