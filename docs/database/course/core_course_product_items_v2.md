# Product v2 Phase-One Contract: core_course_product_items

Version: 2.0

Status: Foundation Approved and Frozen

Last Updated: 2026-07-15

Parent: [core_course_product_items](core_course_product_items.md) v1.1

Document Path: database/course/core_course_product_items_v2.md

---

# Scope

This approved contract amends Product Items only enough to persist a selected
Template for Draft Product preparation while preserving immutable Version
runtime authority. Unchanged v1.1 rules remain authoritative.

# Approved schema amendment

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

# Approval and freeze

Approved and frozen by the Business/Architecture Owner on 2026-07-15 for the
Product v2 phase-one implementation authorized by ADR-0014 and the integrated
Product Architecture Review. Bundle workflow changes remain deferred.

# Implementation record

Implementation migration: `2026_07_15_000000_add_product_v2_phase_one_contract.php`.

The migration deterministically backfills Template through Version and logs
unresolved rows. Transitional nullability is retained until remediation proves
that tightening is safe; new Product v2 aggregate writes require Template.
