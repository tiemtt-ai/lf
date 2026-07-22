# ADR-0001

Course Foundation

---

## Status

Approved

---

## Date

2026-06-27

---

## Amendments

Course Blueprint V2 amendment: 2026-07-03 — Approved.

---

## 1. Architecture Summary

LearnForge Course Domain separates authoring, immutable publication, commerce,
learning cycles and progress tracking.

`core_course_templates` is the working Course Definition. Publishing creates an
immutable Template Version. A Product references that Version without cloning a
runtime Course. Enrollment freezes the Version for one learning cycle, and
Progress follows the Version Lesson and Version Activity within that cycle.

Runtime Course tables are not part of the architecture.

## 2. Final Course Architecture

```text
Category
↓
Course Template
↓
Template Version
↓
Course Product
↓
Enrollment
↓
Learning Progress
```

The learning content hierarchy is:

```text
Course Template
├── Template Lesson
│   └── Template Activity
└── Template Section
    └── Template Lesson
        └── Template Activity
```

Its published immutable hierarchy is:

```text
Template Version
├── Version Lesson
│   └── Version Activity
└── Version Section
    └── Version Lesson
        └── Version Activity
```

## 3. Versioning Strategy

```text
Working Template
↓
Publish
↓
Immutable Version
↓
Product
↓
Enrollment
```

Version lifecycle:

| Status | Meaning |
|--------|---------|
| `draft_snapshot` | Snapshot is being created and validated. |
| `published` | Version is immutable and available for Product use. |
| `deprecated` | No new sale; existing Enrollments continue learning. |
| `archived` | Storage/audit only; no new Product may use it. |

Published Versions are immutable. A content change requires a new Version.
Existing Enrollments always keep their locked Version.

## 4. Snapshot Strategy

```text
Working Template
↓
Publish New Version
↓
Version Snapshot
```

Snapshot occurs only when publishing a new Version.

Teacher edits update the working Template only. They do not create a snapshot,
mutate a published Version or silently change an existing Enrollment.

## 5. Foundation Decisions

1. Certificate verification always runs in tenant context.
   `core_certificate_verification_logs.customer_id` is `NOT NULL`, including
   failed lookups. Global verification is not supported.
2. Each Enrollment has one current active Cohort membership. A transfer updates
   the same record. Membership history and `is_current` are not used. The unique
   key is `(customer_id, enrollment_id)`.
3. Re-enrollment is allowed. Every Enrollment is one learning cycle. Progress,
   Completion and Product-based Certificate records always use `enrollment_id`;
   there is no permanent User/Student–Product unique constraint.
4. Course Blueprint V2 makes Section optional. A Lesson with no Section belongs
   directly to its Template; a Lesson with a Section must use a Section from the
   same Template and `customer_id`. Publishing supports both flat and sectioned
   structures and must not create a hidden/default Section or `Section 1`.
5. Only an `active` Enrollment can create or update Notes and Bookmarks.
   Preview, guest and anonymous Notes/Bookmarks are not supported.
6. Review uses `user_id`, not `student_id`, because Student is a role and the
   model may later support Teacher, QA or Internal Review.
7. Foundation supports one active Certificate mapping per Product. Multiple
   mappings are a future-phase option requiring explicit selection rules.
8. Certificate `minimum_score_percentage` is a normalized percentage, not an
   absolute score.
9. Template Version lifecycle is `draft_snapshot`, `published`, `deprecated`,
   `archived`. Published content is immutable; existing Enrollments retain their
   original Version through later lifecycle changes.

## 6. Open Items

Only future-phase items remain:

* Define immutable/versioned integration contracts for Media, Assessment and
  Live Class assets referenced by Version Activities.
* Decide whether a Product may have multiple active Certificate mappings and,
  if so, define issuance-selection rules.
* Define multi-item Product/bundle Version binding before supporting a single
  Enrollment across independently versioned learning items.
* Finalize schema-level enforcement for default Certificate Templates,
  Learning Path prerequisite cycles and Product Relations.
* Add Cohort multi-teacher and Review moderation-history models only when those
  use cases are approved.
* Plan migration/backfill separately when database implementation begins.

These items do not change the approved Course Foundation architecture.

## Applied Principles

See:

[LF-Architecture-Principles.md](../governance/LF-Architecture-Principles.md)

* Domain Responsibility Principle
* Source Of Truth Principle
* Immutable Principle
* Snapshot Principle
* Versioning Principle
* Evidence Principle
* Tenant Isolation Principle
* Read Model Principle
* Backward Compatibility Principle
* ADR Principle
* Simplicity Principle

## 7. Course Domain Readiness

```text
Foundation Ready

YES
```

Reasons:

* Authoring, publication, commerce, enrollment and progress responsibilities
  are separated.
* Published learning content is immutable and historically stable.
* Product and Enrollment bind a specific Template Version.
* Re-enrollment and downstream records are learning-cycle safe.
* Tenant ownership is explicit, including Certificate verification failures.
* Section, Cohort, Notes/Bookmarks, Review and Certificate rules are resolved.
* No Runtime Course table or cloning model is required.
