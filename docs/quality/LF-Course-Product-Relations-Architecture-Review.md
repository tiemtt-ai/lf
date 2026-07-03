# Course Product Relations Architecture Review

Version: 1.0

Status: Approved Review

Review Date: 2026-07-03

---

# Review Information

| Field | Value |
| --- | --- |
| Domain | Course |
| Parent Review | [Course Product Architecture Review](LF-Course-Product-Architecture-Review.md) |
| Database Docs | [core_course_product_relations](../database/course/core_course_product_relations.md), [core_course_products](../database/course/core_course_products.md) |
| Review Version | 1.0 |

# Review Scope

This review verifies the architecture contract for implementing Product
Relations inside Course Product management.

The implementation phase authorized by this review is limited to:

```text
core_course_product_relations
```

Authorized behavior:

* attach a related Product to an existing source Product;
* list Product Relations under that source Product;
* remove a Product Relation link from that source Product;
* maintain documented Relation display, visibility and status metadata.

This review does not authorize implementation of:

* Enrollment;
* Catalog;
* Payment;
* Billing;
* Student Learning;
* Teacher assigned-product access;
* checkout behavior;
* gift fulfillment;
* recommendation engine behavior.

# A — Purpose

- [x] `core_course_product_relations` manages Product-to-Product commercial
      relationships.
- [x] Product Relations support gift, related, upsell, cross-sell and
      recommended Product configurations.
- [x] Product Relations are marketing and sales configuration records.
- [x] Product Relations do not store Course content.
- [x] Product Relations do not reference Course Template Versions directly.
- [x] Product Relations do not create Enrollment, Progress or learning access.

# B — Parent / Child Relationships

- [x] `core_course_products` has many Product Relations as source Product.
- [x] `core_course_products` has many Product Relations as related Product.
- [x] Every Product Relation belongs to exactly one source Product.
- [x] Every Product Relation references exactly one related Product.
- [x] Source Product, related Product and Product Relation must share the same
      `customer_id`.
- [x] Product Relations are managed as Product children, not as a separate
      top-level module.

# C — CRUD Complexity

- [x] Product Relations are workflow-child CRUD, not standalone Golden CRUD.
- [x] The approved workflow is attach, list and remove.
- [x] Removing a Product Relation removes only the relation row.
- [x] Product Relation implementation must not modify Product Item behavior.
- [x] Product Relation implementation must not modify Course Authoring, publish
      snapshots, Version detail, Duplicate-to-Draft or Product CRUD behavior
      beyond displaying and managing Product children.

# D — Tenant Isolation

- [x] Every Product Relation row requires `customer_id`.
- [x] All Product Relation reads and writes must use
      `TenantContext::customerId()`.
- [x] Source Product lookup must be tenant-scoped.
- [x] Related Product lookup must be tenant-scoped.
- [x] A Product Relation cannot attach a source Product from another tenant.
- [x] A Product Relation cannot attach a related Product from another tenant.
- [x] A Product Relation ID alone is never sufficient lookup scope.

# E — Validation Rules

- [x] `product_id` is required and must resolve to a source Product in the
      current tenant.
- [x] `related_product_id` is required and must resolve to a different Product
      in the current tenant.
- [x] `relation_type` is required and must use a documented value.
- [x] `title_override` is nullable and must follow the documented
      `VARCHAR(255)` limit.
- [x] `description_override` is nullable and must follow the documented
      `VARCHAR(500)` limit.
- [x] `sort_order` uses the documented integer ordering behavior.
- [x] `is_featured` uses the documented boolean behavior.
- [x] `starts_at` and `ends_at` are nullable timestamps.
- [x] `status` values are limited to `active` and `inactive`.

# F — Relation Type Rules

The approved Relation Type values are:

```text
gift
related
upsell
cross_sell
recommended
```

- [x] `gift` may be configured as a Product Relation record, but gift
      fulfillment at purchase or checkout is not implemented in this phase.
- [x] `related` may be configured for future Product Detail display.
- [x] `upsell` may be configured for future checkout or sales display.
- [x] `cross_sell` may be configured for future checkout or sales display.
- [x] `recommended` may be configured for future recommendation display.
- [x] No Relation Type in this phase grants learning access or creates
      Enrollment.

# G — Duplicate / Self-Relation Prevention

- [x] A Product must not relate to itself:

```text
product_id != related_product_id
```

- [x] Duplicate Product Relation links are rejected by:

```text
UNIQUE(customer_id, product_id, related_product_id, relation_type)
```

- [x] The application must validate duplicates before insert when practical.
- [x] The database constraint remains the final protection against duplicate
      same-tenant source/related/type links.
- [x] Reverse-direction relations are separate records unless a future approved
      policy defines symmetric Relation behavior.

# H — Attach / List / Remove Behavior

- [x] Attach creates a Product Relation for a source Product and related Product
      in the same tenant.
- [x] Attach may capture documented metadata such as relation type, override
      title, override description, sort order, featured marker, visibility
      window and status.
- [x] List displays Product Relations only for the tenant-scoped source Product.
- [x] List must preserve Product Relation ordering using documented
      `sort_order`.
- [x] Remove deletes only the Product Relation link.
- [x] Remove must not delete or archive the source Product.
- [x] Remove must not delete or archive the related Product.
- [x] Remove must not change Product Items, Course Template Versions,
      Enrollment, Catalog, Payment, Billing or Student Learning records.

# I — Delete / Reference Behavior

- [x] Product Relation references to source Product and related Product use
      restrictive reference behavior.
- [x] Product archive does not automatically delete Product Relations.
- [x] Hard delete of a Product remains governed by the Product documentation and
      must not be allowed while Product Relations reference the Product in
      either role unless those relations are removed in the same approved
      transaction.
- [x] No cascade may delete source Product, related Product, Product Items,
      Course Template Versions or learning records from Product Relation
      operations.

# J — Admin-Only Authorization

- [x] Customer Admin may manage Product Relations within the current tenant.
- [x] Product Relation routes must be registered only under the Admin Course
      Product area.
- [x] Teacher routes must not expose Product Relation management.
- [x] Student routes must not expose Product Relation management.
- [x] Guest access must be blocked.
- [x] Teacher assigned-product access remains unapproved and out of scope.

# K — UI Placement

- [x] Product Relation UI belongs inside Product management.
- [x] Product Relation UI must not create a separate left-sidebar menu item.
- [x] Product Relation UI must not create a Teacher management area.
- [x] Product Relation UI must not expose Enrollment, Catalog, Payment, Billing
      or Student Learning controls.
- [x] Responsive behavior must follow the existing Product and Course Authoring
      admin UI patterns.

# L — Golden CRUD Reuse

- [x] Golden CRUD patterns may be reused for validation, tables, forms,
      messages and authorization checks.
- [x] Golden CRUD must be adapted because Product Relations are nested workflow
      children, not standalone top-level CRUD.
- [x] Reuse must remain `DB::table()` based.
- [x] Reuse must remain tenant-scoped and Customer Admin-only.
- [x] Reuse must not introduce Eloquent models.
- [x] Reuse must not introduce Enrollment, Catalog, checkout, Payment, Billing
      or learning consumption.

# M — Manual Review Requirements

Manual review is required before implementation completion to verify:

* Product Relation routes exist only under Admin Product management;
* no Teacher, Student or Guest Product Relation routes are exposed;
* all source Product and related Product lookups are scoped by
  `TenantContext::customerId()`;
* the attach form uses `related_product_id` and `relation_type`;
* self-relations are rejected;
* duplicate same-tenant source/related/type links are rejected;
* removing a Product Relation does not delete either Product;
* `gift` does not create Enrollment, checkout behavior or payment behavior;
* Enrollment, Catalog, Payment, Billing, Student Learning and Teacher
  assigned-product access remain absent;
* responsive Product Relation list and attach/remove UI has no horizontal
  overflow.

# N — Explicit Exclusions

The following are explicitly excluded from this implementation decision:

* Enrollment;
* Catalog;
* Payment;
* Billing;
* Student Learning;
* Teacher assigned-product access;
* checkout behavior;
* gift fulfillment;
* public catalog display;
* recommendation engine behavior;
* public APIs outside Admin Product management.

# Review Result

Score:

```text
100 / 100
```

Decision:

```text
Approved — Product Relations Implementation Authorized For core_course_product_relations Only
```

Owner Approval:

```text
Role: LearnForge Architecture Owner
Date: 2026-07-03
Decision: Approved
```

# Final Implementation Decision

Implementation may proceed for `core_course_product_relations` after reading
this review and the Product Relation database documentation.

The next implementation must remain limited to Admin-only Product Relation
attach/list/remove behavior inside Product management. It must not implement
Enrollment, Catalog, Payment, Billing, Student Learning, Teacher
assigned-product access, checkout behavior, gift fulfillment or recommendation
engine behavior.

---

End of Review
