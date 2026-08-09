# Course Product Items Architecture Review

Version: 1.0

Status: Approved Review

Review Date: 2026-07-03

Document Path: quality/LF-Course-Product-Items-Architecture-Review.md

---

> ⚠️ **SUPERSEDED** by
> [LF-Course-Product-Integrated-Architecture-Review.md](LF-Course-Product-Integrated-Architecture-Review.md)
> (v2.0). This document is retained for historical context only.

---

# Review Information

| Field | Value |
| --- | --- |
| Domain | Course |
| Parent Review | [Course Product Architecture Review](LF-Course-Product-Architecture-Review.md) |
| Database Docs | [core_course_product_items](../database/course/core_course_product_items.md), [core_course_products](../database/course/core_course_products.md), [core_course_template_versions](../database/course/core_course_template_versions.md) |
| Review Version | 1.0 |

# Review Scope

This review verifies the architecture contract for implementing Product Items
inside Course Product management.

The implementation phase authorized by this review is limited to:

```text
core_course_product_items
```

Authorized behavior:

* attach a published Course Template Version to an existing Product;
* list Product Items under that Product;
* remove a Product Item link from that Product;
* maintain documented Product Item display and link metadata.

This review does not authorize implementation of:

* `core_course_product_relations`;
* Enrollment;
* Catalog;
* Payment;
* Billing;
* Student Learning;
* Teacher assigned-product access;
* Product-to-Version learning consumption.

# A — Purpose

- [x] `core_course_product_items` links Product commerce packaging to immutable
      published Course Template Versions.
- [x] A Product Item is not Course authoring content.
- [x] A Product Item is not a Course Template draft.
- [x] A Product Item does not copy Version Sections, Lessons or Activities.
- [x] Product Items allow a Product to behave as a single-course Product or a
      bundle without changing Version snapshot architecture.

# B — Parent / Child Relationships

- [x] `core_course_products` has many `core_course_product_items`.
- [x] `core_course_template_versions` can be referenced by many Product Items.
- [x] Every Product Item belongs to exactly one Product.
- [x] Every Product Item references exactly one Course Template Version.
- [x] Product, Product Item and Course Template Version must share the same
      `customer_id`.
- [x] Product Items are managed as Product children, not as a separate
      top-level module.

# C — CRUD Complexity

- [x] Product Items are workflow-child CRUD, not standalone Golden CRUD.
- [x] The approved workflow is attach, list and remove.
- [x] Removing a Product Item removes only the link row.
- [x] Product Item implementation must not modify Course Product CRUD behavior
      beyond displaying and managing Product children.
- [x] Product Item implementation must not modify Course Authoring, publish
      snapshots, Version detail or Duplicate-to-Draft behavior.

# D — Tenant Isolation

- [x] Every Product Item row requires `customer_id`.
- [x] All Product Item reads and writes must use `TenantContext::customerId()`.
- [x] Parent Product lookup must be tenant-scoped.
- [x] Course Template Version lookup must be tenant-scoped.
- [x] A Product Item cannot attach a Product from another tenant.
- [x] A Product Item cannot attach a Course Template Version from another
      tenant.
- [x] A Product Item ID alone is never sufficient lookup scope.

# E — Validation Rules

- [x] `product_id` is required and must resolve to a Product in the current
      tenant.
- [x] `template_version_id` is required and must resolve to a Course Template
      Version in the current tenant.
- [x] `title_override` is nullable and must follow the documented
      `VARCHAR(255)` limit.
- [x] `short_description_override` is nullable and must follow the documented
      `VARCHAR(500)` limit.
- [x] `sort_order` uses the documented integer ordering behavior.
- [x] `is_required` uses the documented boolean behavior.
- [x] `status` values are limited to `active` and `inactive`.
- [x] Product Item implementation must not use removed legacy fields:

```text
item_type
item_id
template_id
```

# F — Published-Version-Only Rule

- [x] Product Items may reference only `core_course_template_versions`.
- [x] Product Items must not reference `core_course_templates` drafts.
- [x] Product Items must not reference editable Sections, Lessons or
      Activities.
- [x] New Product Item attachment must require Version `status = published`.
- [x] The Version does not need to be the current Version unless a later
      approved commercial policy adds that rule.
- [x] Deprecated, archived or draft snapshot Versions are not approved for new
      Product Item attachment in this phase.

# G — Duplicate Uniqueness Rule

- [x] Duplicate Product Item links are rejected by:

```text
UNIQUE(customer_id, product_id, template_version_id)
```

- [x] The application must validate duplicates before insert when practical.
- [x] The database constraint remains the final protection against duplicate
      same-tenant Product/Version links.

# H — Attach / List / Remove Behavior

- [x] Attach creates a Product Item for a Product and published Version in the
      same tenant.
- [x] Attach may capture documented Product Item metadata such as override
      title, override short description, sort order, required marker and link
      status.
- [x] List displays Product Items only for the tenant-scoped parent Product.
- [x] List must preserve Product Item ordering using documented `sort_order`.
- [x] Remove deletes only the Product Item link.
- [x] Remove must not delete or archive the Product.
- [x] Remove must not delete, mutate, archive or republish the Course Template
      Version.

# I — Delete / Reference Behavior

- [x] Product Item references to Product and Course Template Version use
      restrictive reference behavior.
- [x] Product archive does not automatically delete Product Items.
- [x] Hard delete of a Product remains governed by the Product documentation and
      is not expanded by this review.
- [x] Published Course Template Versions cannot be deleted or updated through
      Product Item operations.
- [x] No cascade may delete Product, Course Template Version, Version Section,
      Version Lesson or Version Activity data from Product Item operations.

# J — Admin-Only Authorization

- [x] Customer Admin may manage Product Items within the current tenant.
- [x] Product Item routes must be registered only under the Admin Course Product
      area.
- [x] Teacher routes must not expose Product Item management.
- [x] Student routes must not expose Product Item management.
- [x] Guest access must be blocked.
- [x] Teacher assigned-product access remains unapproved and out of scope.

# K — UI Placement

- [x] Product Item UI belongs inside Product management.
- [x] Product Item UI must not create a separate left-sidebar menu item.
- [x] Product Item UI must not create a Teacher management area.
- [x] Product Item UI must not expose Product Relations, Enrollment, Catalog,
      Payment, Billing or Student Learning controls.
- [x] Responsive behavior must follow the existing Product and Course Authoring
      admin UI patterns.

# L — Golden CRUD Reuse

- [x] Golden CRUD patterns may be reused for validation, tables, forms,
      messages and authorization checks.
- [x] Golden CRUD must be adapted because Product Items are nested workflow
      children, not standalone top-level CRUD.
- [x] Reuse must remain `DB::table()` based.
- [x] Reuse must remain tenant-scoped and Customer Admin-only.
- [x] Reuse must not introduce Eloquent models.
- [x] Reuse must not introduce Product Relations or learning consumption.

# M — Manual Review Requirements

Manual review is required before implementation completion to verify:

* Product Item routes exist only under Admin Product management;
* no Teacher, Student or Guest Product Item routes are exposed;
* all Product and Version lookups are scoped by `TenantContext::customerId()`;
* the attach form uses `template_version_id`, not `template_id`,
  `item_type` or `item_id`;
* only Course Template Versions with `status = published` can be attached;
* duplicate same-tenant Product/Version links are rejected;
* removing a Product Item does not delete Product or Course Template Version
  records;
* Product Relations, Enrollment, Catalog, Payment, Billing, Student Learning
  and Teacher assigned-product access remain absent;
* responsive Product Item list and attach/remove UI has no horizontal overflow.

# N — Explicit Exclusions

The following are explicitly excluded from this implementation decision:

* Product Relations;
* Enrollment;
* Catalog;
* Payment;
* Billing;
* Student Learning;
* Teacher assigned-product access;
* Product-to-Version consumption or learning access;
* Product purchase flow;
* public APIs outside Admin Product management.

# Review Result

Score:

```text
100 / 100
```

Decision:

```text
Approved — Product Items Implementation Authorized For core_course_product_items Only
```

Owner Approval:

```text
Role: LearnForge Architecture Owner
Date: 2026-07-03
Decision: Approved
```

# Final Implementation Decision

Implementation may proceed for `core_course_product_items` after reading this
review and the Product Item database documentation.

The next implementation must remain limited to Admin-only Product Item
attach/list/remove behavior inside Product management. It must not implement
Product Relations, Enrollment, Catalog, Payment, Billing, Student Learning,
Teacher assigned-product access or Product-to-Version learning consumption.

---

End of Review
