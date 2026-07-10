# Table: core_course_template_sections

Version: 3.0

Status: Approved

Last Updated: 2026-07-10

Related ADR:
[ADR-0001 — Course Foundation](../../adr/ADR-0001-Course-Foundation.md)

[ADR-0012 — Course Template Published Version Snapshot Architecture](../../adr/ADR-0012-Course-Template-Published-Version-Snapshot.md)

[ADR-0013 — Course Template Version Duplicate to Draft](../../adr/ADR-0013-Course-Template-Version-Duplicate-to-Draft.md)

---

# Purpose

Stores an optional, editable Lesson container for a Course Template draft.

A Course Template may be flat, with Lessons directly under the Template, or
sectioned, with Lessons grouped by Sections. Sections may be nested without a
fixed depth limit. A Section groups child Sections and, only when explicitly
allowed, Lessons. It also provides a title, optional description and display
order.

```text
Course Template
├── Template Lesson
│   └── Template Activity
└── Template Section
    ├── Template Section
    │   └── Template Lesson
    │       └── Template Activity
    └── Template Lesson
        └── Template Activity
```

Section is not a content unit, completion rule, media owner, unlock rule,
status lifecycle or metadata holder.

---

# Relationships

```text
saas_customers 1 → N core_course_template_sections
core_course_templates 1 → N core_course_template_sections
core_course_template_sections 0..1 → N core_course_template_sections
core_course_template_sections 1 → N core_course_template_lessons
```

---

# Business Rules

* Every Section belongs to one `customer_id`.
* Every Section belongs to one Course Template.
* Section `customer_id` must match the owning Course Template.
* Section queries must always be tenant-scoped.
* A Course Template may have zero or more Sections.
* A Section is optional and groups child Sections and Lessons.
* Sections support unlimited nesting through `parent_section_id`.
* `allows_lessons` is required and must never be inferred from hierarchy depth.
* Lessons may attach to a Section only when `allows_lessons = TRUE`.
* Root Sections have `parent_section_id = NULL`.
* Child Sections must belong to the same `customer_id` and `template_id` as
  their parent Section.
* Section hierarchy must not contain cycles.
* A Lesson may belong directly to the Template or to one Section.
* Lessons may attach to any Section level.
* If a Lesson belongs to a Section, the Lesson, Section and Template must
  share the same `customer_id` and `template_id`.
* Flat Courses create no hidden/default Section and no automatic `Section 1`.
* Sections do not contain Activities directly.
* Sections do not contain Media directly.
* Sections do not define status, visibility, required/completion rules, unlock
  rules, duration, metadata or cached Lesson counts.
* Section display order is managed by `display_order`.
* Publishing snapshots supported Section business fields into
  `core_course_template_version_sections`.
* Students and Progress never reference working Template Sections.
* In the authoring tree, Section is a grouping container and does not display
  Status. When `allows_lessons = FALSE`, its entire Lesson block is omitted,
  while nested child Sections remain visible.

---

# Final Field Set

```text
id
customer_id
template_id
parent_section_id
allows_lessons
title
description
display_order
created_at
updated_at
```

Do not add replacement fields for removed non-structural Section concepts.

---

# Fields

## id

```text
BIGINT UNSIGNED
PRIMARY KEY
AUTO_INCREMENT
```

Section identity.

---

## customer_id

```text
BIGINT UNSIGNED
NOT NULL
```

Tenant owner.

Foreign key:

```text
saas_customers.id
```

---

## template_id

```text
BIGINT UNSIGNED
NOT NULL
```

Owning Course Template.

Foreign key:

```text
core_course_templates.id
```

---

## parent_section_id

```text
BIGINT UNSIGNED
NULL
```

Parent Section in the same Template. `NULL` means root Section.

Foreign key:

```text
core_course_template_sections.id
```

Application validation must enforce same tenant, same Template and no circular
parenting.

---

## allows_lessons

```text
BOOLEAN
NOT NULL
```

Explicitly controls whether Lessons may be attached to this Section. The UI
exposes this field and the backend rejects Lesson attachment when it is false.

---

## title

```text
VARCHAR(255)
NOT NULL
```

Section title shown to authors and learners after publish.

---

## description

```text
TEXT
NULL
```

Optional Section description.

---

## display_order

```text
INT UNSIGNED
NOT NULL
DEFAULT 1
```

Display order inside the owning Template.

---

## created_at

```text
TIMESTAMP NULL
```

---

## updated_at

```text
TIMESTAMP NULL
```

---

# Removed Fields

The simplified Section foundation removes these previously documented concepts:

```text
code
short_title
thumbnail_file_id
sort_order
is_required
unlock_rule
estimated_duration_minutes
total_lessons
status
metadata
```

`parent_section_id` is restored as the approved structural hierarchy field and
is not a removed field. The remaining removed fields must not be replaced by
new equivalent fields in the Section foundation.

---

# Indexes

```sql
INDEX idx_ccts_customer (customer_id);
INDEX idx_ccts_template (customer_id, template_id);
INDEX idx_ccts_parent (customer_id, template_id, parent_section_id);
INDEX idx_ccts_display_order
    (customer_id, template_id, parent_section_id, display_order);
```

---

# Unique Constraints

No unique business constraint is required for Section titles or display order
in the foundation.

Application logic orders siblings by `display_order`, then `id`, to keep
rendering stable when two Sections share the same display order.

---

# Delete And Reference Rules

* `customer_id`: foreign key with `RESTRICT`.
* `template_id`: foreign key with `RESTRICT`.
* `parent_section_id`: self-referencing foreign key with `RESTRICT`.
* A Section referenced by child Sections must not be deleted unless those
  children are moved or deleted first.
* A Section referenced by working Lessons must not be deleted unless those
  Lessons are moved or deleted first.
* Working Section changes never cascade into published Version Section rows.

---

# Tenant Isolation

Every query includes:

```text
customer_id = TenantContext::customerId()
```

Section lookup, creation, update and deletion must verify that the parent
Template belongs to the same tenant.

---

# Sample Data

```text
id = 1
customer_id = 1
template_id = 1
parent_section_id = NULL
title = Hangul Fundamentals
description = Introductory lessons for Hangul basics.
display_order = 1
```

```text
id = 2
customer_id = 1
template_id = 1
title = Basic Grammar
description = NULL
display_order = 2
```

---

# Sample Structure

```text
TOPIK Beginner
├── Lesson: Course Orientation
├── Section: Hangul Fundamentals
│   ├── Lesson: Vowels
│   └── Lesson: Consonants
└── Section: Basic Grammar
    └── Lesson: Sentence Order
```

---

# Final Statement

Course Template Section is a simple optional Lesson container.

It exists only to group Lessons in an editable Course Template and to preserve
that grouping when a Course Template Version is published.

---

End of Document
