# Course Product Integrated Architecture Review

Version: 2.0

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-08-09

Review Date: 2026-07-15

Document Path: quality/LF-Course-Product-Integrated-Architecture-Review.md

---

# Review basis

This approved review integrates Product common fields, phase-one binding,
offering classification, self-paced configuration, inheritance, media,
promotion, registration, ordering, and related Products. It does not revoke
prior Product, Item, or Relation approvals. It amends them only where ADR-0014
and the v2 database contracts explicitly state.

* [ADR-0014](../adr/ADR-0014-Product-Offering-And-Draft-Binding.md)
* [Product v2 contract](../database/course/core_course_products_v2.md)
* [Product Item v2 contract](../database/course/core_course_product_items_v2.md)
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

# Approved owner decisions

1. `offering_type` is the final offering-classification column name.
2. Transitional nullability and the documented grandfathering policy are
   approved; new Product v2 records require a value.
3. Product Item `template_id` and nullable Draft `version_id` are approved; an
   active Item requires a published immutable Version of that Template.
4. Missing, conflicting, or ambiguous Item/category mappings require explicit
   migration-time remediation and reporting; no value may be guessed.
5. Inactive description and media overrides are retained but ignored; explicit
   removal remains destructive at the documented association/usage level.
6. Target Levels and runtime access-expiry enforcement are explicitly deferred.
7. Package type is hidden in phase one and assigned `single_course` by the
   server; Bundle architecture remains valid and its workflow is deferred.
8. Selling price is calculated; promotion and registration intervals use
   inclusive start and exclusive end.

# Review result

```text
Decision: PASS
Architecture freeze: YES
Implementation authorized: YES
```

Owner approval date: 2026-07-15.

Authorization is limited to the frozen Product v2 phase-one contract. It does
not authorize any item listed in Deferred scope.
