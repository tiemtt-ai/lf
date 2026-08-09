# Course Product Architecture Review

Version: 1.0

Status: Approved Review

Review Date: 2026-07-03

Document Path: quality/LF-Course-Product-Architecture-Review.md

---

# Review Information

| Field | Value |
| --- | --- |
| Domain | Course |
| Domain Doc | [LF-Core-Course](../core/LF-Core-Course.md) |
| Parent ADR | [ADR-0001 — Course Foundation](../adr/ADR-0001-Course-Foundation.md) |
| Database Docs | [core_course_products](../database/course/core_course_products.md), [core_course_product_items](../database/course/core_course_product_items.md), [core_course_product_relations](../database/course/core_course_product_relations.md) |
| Review Version | 1.0 |

# Review Scope

This review verifies the architecture contract for Product-specific Course
documentation and the first Product implementation phase.

The implementation phase authorized by this review is limited to:

```text
core_course_products CRUD
```

This review does not authorize implementation of:

* `core_course_product_items`;
* `core_course_product_relations`;
* Enrollment;
* Purchase, Payment or Billing;
* Student Learning;
* Public catalog;
* APIs outside Product CRUD.

# A — Purpose

- [x] `core_course_products` is the commercial/display/access packaging layer.
- [x] Product is not Course content.
- [x] Product is not a Course Template Version.
- [x] Product will later contain published Versions through Product Items.
- [x] Product does not copy Version Sections, Lessons or Activities.

# B — Parent / Child Relationships

- [x] Product belongs to `customer_id`.
- [x] Product may later have many Product Items.
- [x] Product may later have many Product Relations.
- [x] Product may later have Certificate Product mappings.
- [x] Product Items reference immutable Course Template Versions.
- [x] Product Relations reference Products in source and related roles.
- [x] The first implementation phase creates Product independently and does not
      create child records.

# C — CRUD Complexity

- [x] `core_course_products` is Standard CRUD with lifecycle status.
- [x] Product Items are workflow children and are out of first-table scope.
- [x] Product Relations are workflow children and are out of first-table scope.
- [x] Product CRUD must not modify Course Authoring, publish snapshots,
      Version detail or Duplicate-to-Draft behavior.
- [x] Product CRUD must not introduce Product Items, Enrollment or purchasing.

# D — Tenant Isolation

- [x] All Product records require `customer_id`.
- [x] All Product queries must use `TenantContext::customerId()`.
- [x] `slug` is unique within tenant.
- [x] `product_code` is unique within tenant.
- [x] Cross-tenant Product access, update or deletion is forbidden.
- [x] Future Product Items and Relations must validate same-tenant parent and
      child records.

# E — Validation

- [x] Product status values are `draft`, `active`, `inactive`, `archived`.
- [x] Product uses `active` instead of `published` because Product is mutable
      commercial packaging, while published Course Template Versions remain
      immutable learning-content snapshots.
- [x] Product `active` does not mutate, republish or replace any Course Template
      Version.
- [x] Product type values are `single_course` and `bundle`.
- [x] Visibility values are `public`, `private` and `hidden`.
- [x] Slug and Product Code validation must be tenant-scoped.
- [x] Price, sale window, enrollment type, display metadata and status must
      follow the Product table documentation.

# F — Delete / Reference Behavior

- [x] Product does not use soft delete in the approved Foundation document.
- [x] Default removal behavior is lifecycle archive:

```text
status = archived
```

- [x] Hard delete is allowed only when no business or historical reference
      exists.
- [x] Product cannot be hard-deleted while referenced by Product Items,
      Product Relations, Certificate Product mappings or future Enrollment,
      Purchase, Payment, Progress, Completion or Certificate records.
- [x] Product Item references to Product and Template Version use restrictive
      reference behavior.
- [x] Product Relation references to source and related Product use restrictive
      reference behavior.
- [x] No cascade delete may remove published Course Template Version content
      from Product operations.

# G — Authorization Rules

- [x] Customer Admin may manage Products within the tenant.
- [x] Teacher Product management is not approved in Phase 3.
- [x] Teacher authorization remains through Course Template assignment only.
- [x] No assigned-product relationship exists yet.
- [x] Do not infer Product permissions from Template assignments, Product Items
      or Product Relations.
- [x] Future Teacher assigned-product access requires separate ADR/database
      documentation before implementation.

# H — Golden CRUD Reuse

- [x] Golden CRUD can be reused for basic Product list/create/edit/view/delete
      patterns.
- [x] Reuse must remain tenant-scoped and Customer Admin-only for this phase.
- [x] Golden CRUD must be adapted for tenant-scoped unique validation of slug
      and product code.
- [x] Golden CRUD must not create Eloquent models if the module uses
      `DB::table()`.
- [x] Golden CRUD must not introduce child Product Items or Relations.

# I — Manual Review Requirements

Manual review is required before implementation to verify:

* Product navigation appears only in the approved Admin Course area;
* Teacher navigation does not expose Product CRUD;
* Product forms do not include `version_id`, `template_id` or `course_id`;
* Product delete/archive UI matches the documented reference rules;
* Product status UI uses `active`, not `published`;
* responsive Product list/create/edit/detail pages have no horizontal overflow;
* implementation does not alter Course Authoring or Published Version flows.

# Review Result

Score:

```text
100 / 100
```

Decision:

```text
Approved — Product CRUD Implementation Authorized For core_course_products Only
```

Owner Approval:

```text
Role: LearnForge Architecture Owner
Date: 2026-07-03
Decision: Approved
```

# Final Implementation Decision

Implementation may proceed for `core_course_products` only after reading this
review and the Product database documentation.

The next implementation must not include Product Items, Product Relations,
Enrollment, Purchase, Payment, Billing, Student Learning, Public Catalog or
external APIs outside Product CRUD.

# Required Future Reviews

Separate review remains required before implementing:

* Product Items;
* Product Relations;
* Teacher assigned-product authorization;
* Product-to-Version consumption rules;
* Enrollment binding;
* purchase/payment/billing;
* public catalog;
* Student Learning consumption.

---

End of Review
