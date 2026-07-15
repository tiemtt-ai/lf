# Course Product Integrated Architecture Review

Version: 2.0-draft

Status: Proposed Review — Owner Decision Required

Review Date: 2026-07-15

---

# Review basis

This proposal integrates Product common fields, phase-one binding, offering
classification, self-paced configuration, inheritance, media, promotion,
registration, ordering, and related Products. It does not revoke prior Product,
Item, or Relation approvals. If approved, it amends them only where ADR-0014
and the v2 database proposals explicitly state.

* [ADR-0014](../adr/ADR-0014-Product-Offering-And-Draft-Binding.md)
* [Product v2 proposal](../database/course/core_course_products_v2_proposal.md)
* [Product Item v2 proposal](../database/course/core_course_product_items_v2_proposal.md)
* [Product Relation foundation](../database/course/core_course_product_relations.md)

# Conflict decisions

- [x] Package composition and offering classification are separate fields.
- [x] `single_course` and `bundle` remain valid.
- [x] No direct Product-to-Template foreign key is introduced.
- [x] Draft Template selection requires the reviewed Product Item amendment.
- [x] Active runtime content always uses an immutable published Version.
- [x] Existing duration columns are reused; JSON configuration is rejected.
- [x] Target Levels are deferred because no suitable taxonomy exists.
- [x] Selling price is calculated, not persisted.
- [x] Override values are retained but ignored while inheritance is enabled.
- [x] Existing Products are not assigned a guessed offering type.

# Architecture checks

## Tenant, authorization, and transactions

- [x] Every lookup and mutation is scoped by `customer_id` and TenantContext.
- [x] Customer Admin remains the only approved Product manager.
- [x] Category, Template, Version, related Product, media file, and usage are
      independently authorized server-side.
- [x] Filtered controls are not authorization.
- [x] Create/update/activation uses one transaction for Product scalars, Item
      binding, `related` Relation sync, media usages, and final status.
- [x] Activation validates the final aggregate; dependent failure rolls back.

## Inheritance and media

- [x] Active inheritance reads only the bound immutable Version.
- [x] Draft preview prefers a bound Version; otherwise mutable Template data is
      visibly labelled preview-only and is never runtime output.
- [x] Later Template Draft edits do not alter active output.
- [x] Owner `course_product` and purposes `intro_image`, `intro_video`, and
      `intro_document` use generic Media usage architecture.
- [x] Slots coexist; uploaded/embed video states are mutually exclusive.
- [x] Ready tenant-owned files and exact active usages are required.
- [x] Storage URLs remain private and presentation remains purpose-scoped.

## Pricing, registration, relations, and status

- [x] Decimal server arithmetic is authoritative.
- [x] Promotion and registration use inclusive start/exclusive end.
- [x] UTC is stored and tenant timezone is used for parsing/display.
- [x] Status and registration availability remain separate.
- [x] Related Products use directional `related` records; reverse is separate.
- [x] Same-tenant, non-self, non-duplicate validation is mandatory.
- [x] Eligible relation targets are authorized, non-archived Products.
- [x] No Relation grants Enrollment or learning access; gift is excluded.
- [x] Canonical statuses remain `draft`, `active`, `inactive`, `archived`.

# Compatibility and migration review

Additive migration and staged validation are mandatory. Existing Item Version
bindings, Enrollment locks, Relations, codes, slugs, and routes remain
authoritative. Existing active Products with null `offering_type` are
grandfathered for unchanged reads. No Activity-based offering inference is
allowed. Remediation is required before a later activation transition.

Product Item `template_id` may be deterministically projected from its Version.
Product `category_id` may be projected only when all authoritative Items resolve
to one Category. Zero-Item or conflicting-category Products require an explicit
owner remediation decision; migration must abort instead of guessing.

Legacy promotion/media fields are not dropped in the first migration. Any
backfill and later cleanup require verified mapping and separate review.

# Implementation phases after approval

1. Additive schema and deterministic backfills.
2. Domain constants, validation, transaction service, activation policy, and
   centralized price/registration calculations.
3. Product Media lifecycle and authorization.
4. Admin form, JavaScript, localization, and accessibility.
5. Domain/feature/media/tenant tests, build, lint, and visual acceptance.

# Deferred scope

Future offering configurations; Workshop; Gift/attached Products; Bundle UI;
Target Levels; Cohort schedules; expected opening/capacity; refund policy;
learning outcomes; automatic access-expiry runtime; and commerce transaction
price snapshots.

# Open approval decisions

1. Accept `offering_type` as the final column name.
2. Accept nullable transitional offering state and grandfathering policy.
3. Accept the Product Item `template_id` amendment and nullable Draft Version.
4. Approve remediation for Products whose category cannot be backfilled.
5. Approve retaining inactive description/media overrides rather than clearing.
6. Approve deferral of Target Levels and runtime access expiry.

# Review result

```text
Decision: PENDING
Architecture freeze: NO
Implementation authorized: NO
```

Required gates: Architecture Owner approval of ADR-0014; Database Owner approval
of both v2 proposals; Architecture Owner approval of this integrated review.
