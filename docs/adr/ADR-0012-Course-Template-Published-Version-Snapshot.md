# ADR-0012 — Course Template Published Version Snapshot Architecture

Version: 1.0

Status: Approved

Decision Date: 2026-07-03

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

Each successful publish creates a new, tenant-owned, immutable Course Template
Version and a complete structural snapshot in one transaction.

```text
Editable Template

↓ publish transaction

Template Version
├── Version Lesson
│   └── Version Activity
└── Version Section
    └── Version Lesson
        └── Version Activity
```

The snapshot copies the Course information and every Section, Lesson and
Activity required to reproduce the published structure. Direct Lessons remain
direct; Sectioned Lessons map to their corresponding Version Section. Source
IDs are retained only for lineage and reporting.

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
   — immutable Section hierarchy and ordering.
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
* Section/Lesson/Activity ordering and unlock relationships must be preserved as
  one coherent historical graph.
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
5. Copy Sections and map parent Sections.
6. Copy Lessons, preserving direct/Sectioned location and mapping prerequisites.
7. Copy Activities and map Activity prerequisites.
8. Validate tenant ownership, counts, ordering and references.
9. Unset the previous current Version and mark the new Version current.
10. Finalize `status = published`, `published_at` and publication audit fields.
11. Commit all records together.

Any failure rolls back the entire publish. No partial published graph is
allowed.

# Tenant And Reference Decision

Every snapshot table includes `customer_id`. Parent and child relationships
must share the same tenant. Queries and writes are always tenant-scoped.

Source Section/Lesson/Activity IDs are logical lineage, not cascade
relationships. Published child records use `RESTRICT` references to their
Version parents. No working-record delete or edit may cascade into published
history.

# Consequences

## Positive

* Published Course content is historically stable and auditable.
* Authoring can continue without changing existing Versions.
* Flat and Sectioned Courses use one coherent model.
* Future consumers receive stable Version Lesson and Activity identities.
* Tenant ownership is explicit at every snapshot level.

## Costs And Trade-offs

* Publication duplicates Course definition data by design.
* Publish requires transactional mapping of hierarchy and prerequisites.
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

No Code Or Migration Authorized By This ADR Alone
```

---

End of ADR
