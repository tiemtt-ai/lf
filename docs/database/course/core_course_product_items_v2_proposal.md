# Proposed Revision: core_course_product_items

Version: 2.0-draft

Status: Proposed — Not Approved or Frozen

Last Updated: 2026-07-15

Parent: [core_course_product_items](core_course_product_items.md) v1.1

---

# Scope

This proposal amends Product Items only enough to persist a selected Template
for Draft Product preparation while preserving immutable Version runtime
authority. Until approved, v1.1 remains authoritative.

# Proposed schema amendment

Add `template_id BIGINT UNSIGNED`. It is introduced nullable for backfill, then
made NOT NULL. Change `version_id` from NOT NULL to NULL.

Add:

* FK `template_id -> core_course_templates.id`, restrict on delete;
* index `(customer_id, template_id)`;
* unique `(customer_id, product_id, template_id)` for the phase-one
  single-Template association.

Retain the existing unique `(customer_id, product_id, version_id)`. MySQL permits
multiple nulls, while the Product/Template key prevents duplicate Draft Items.

# Integrity rules

Product, Item, Template, and optional Version share `customer_id`. If
`version_id` is non-null, Version must belong to `template_id`. New bindings may
use only a `published` Version. A Draft Item may have null Version. An active
Product may not.

For phase-one `product_type = single_course`, exactly one active Item is
required. Bundle cardinality remains approved but its UI and mutation workflow
are deferred.

`template_id` is Draft selection provenance, not learning runtime authority.
Enrollment and runtime access continue to use the immutable `version_id`.

# Backfill

For each existing Item, derive `template_id` only from its referenced Version's
documented `template_id`; this is a deterministic foreign-key projection, not
an offering inference. Abort on missing, foreign-tenant, or inconsistent rows.
No Version, Product, Enrollment, or Relation is rewritten.

# Approval gate

This amendment requires ADR-0014 and a revised Product Item architecture review
before migration or application implementation.
