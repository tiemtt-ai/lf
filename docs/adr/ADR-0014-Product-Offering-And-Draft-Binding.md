# ADR-0014 — Product Offering and Draft Content Binding

Version: 1.1

Status: Approved

Decision: Accepted

Date: 2026-07-15

Last Updated: 2026-08-06

Document Path: adr/ADR-0014-Product-Offering-And-Draft-Binding.md

---

# Context

The frozen Product contract uses `core_course_products.product_type` for package
composition: `single_course` or `bundle`. A new requirement introduces a
different dimension describing the offering delivered to the learner:
`self_paced_course`, `live_online_course`, `blended_course`, `assessment`, or
`learning_material`.

The first implementation slice must also retain a required Course Template
selection while a Product is Draft, including when that Template has no
published Version. The current Product Item contract stores only a required
published `version_id`, so it cannot represent that Draft selection.

# Decision

## Separate package and offering dimensions

`product_type` remains the package type with the frozen values
`single_course` and `bundle`. A new nullable transitional column,
`offering_type VARCHAR(50)`, represents the offering dimension.

The first implementation slice always writes `product_type = single_course`.
The Package type control is not exposed in the phase-one UI. The server assigns
`single_course`. `bundle` remains valid and is not removed.

## Product Item is the only content-binding aggregate child

No `template_id` is added to `core_course_products`.

`core_course_product_items` is amended with:

* `template_id BIGINT UNSIGNED NOT NULL`;
* `version_id BIGINT UNSIGNED NULL` during Draft preparation.

The item must share `customer_id` with Product, Template, and Version. When
`version_id` is present, that Version must belong to `template_id`. An active
Product requires exactly one active Item in the phase-one `single_course`
slice, and that Item must reference a `published` immutable Version.

This use of `template_id` is not runtime learning authority. It records the
authoring selection for Draft preparation and filtering. `version_id` remains
the runtime content authority. Enrollment continues to freeze `version_id`.

## Draft binding policy

For a Draft Product, the Item records the selected Template. If that Template
has a current published Version, the service may bind that published Version.
If none exists, `version_id` remains null and the Product cannot activate.
Later Template Draft edits do not change a bound Version. Activation performs
an explicit transactionally locked resolution and validation; it never binds a
mutable Draft as runtime content.

## Typed phase-one configuration

The existing nullable Product columns `access_duration_days` and
`review_duration_days` are reused. They are active only when
`offering_type = self_paced_course`. JSON configuration is rejected because
the phase-one contract is small, stable, and relationally validated.

Changing away from `self_paced_course` clears both columns in the same Product
transaction. Persisting the values does not enforce Enrollment expiry. Runtime
access-expiry enforcement is deferred.

## Enrollment duration policy amendment

This approved amendment makes Product offering type authoritative for
Enrollment duration projection:

* `access_duration_days` and `review_duration_days` apply only to
  `self_paced_course`;
* a self-paced Product requires an integer `access_duration_days >= 1` and an
  Enrollment freezes the Product durations and calculated access/review
  timestamps;
* a `live_online_course` stores both Product durations as `NULL`; a new
  Enrollment also stores both duration snapshots and all four duration-derived
  timestamps as `NULL`;
* live enrollment creation must not be rejected because
  `access_duration_days` is `NULL` and must not be automatically expired by a
  missing duration window;
* live duration must not be inferred from Template Lessons, Live Class
  Activities, expected Session count, Schedule, or Cohort dates.

Live runtime authority remains independent: Enrollment must be active, and
applicable Cohort membership, Cohort lifecycle, Session and LiveClass
authorization continue to apply. Completion, cancellation, or any future live
access-ending policy belongs to its explicit workflow.

Every current or future Enrollment creation source, including admin single,
bulk, self-registration, purchase, import and API, must use the same shared
offering-aware backend policy. A source may not implement its own duration
interpretation.

# Alternatives rejected

* Replacing `product_type` with offering values: breaks the package dimension
  and existing data.
* Adding `template_id` directly to Product: duplicates Product Item ownership.
* Reading a mutable Template Draft as active content: violates immutable
  publication.
* Schemaless typed configuration: weakens field-level validation and indexing.
* Inferring `offering_type` from Activities: is not reliable or approved.

# Compatibility and migration

Existing `single_course` and `bundle` values, codes, slugs, routes, Items,
Relations, and Enrollment Version locks remain unchanged.

The future additive migration must:

1. add nullable `offering_type` and other documented additive Product columns;
2. add nullable `template_id` first to Product Items;
3. backfill `template_id` by joining each existing Item Version to its Template;
4. verify tenant and Template/Version consistency;
5. make `template_id` non-null and add its foreign key/index;
6. make `version_id` nullable for Draft Items;
7. retain the existing Product/Version uniqueness protection and add the
   phase-one uniqueness described in the proposed database documentation.

Existing Products receive `offering_type = NULL`. No value is guessed. Existing
active Products are grandfathered for read/runtime compatibility, but any
subsequent activation transition or offering-sensitive edit must assign and
validate an offering type.

# Consequences

Product activation becomes a domain transition rather than a status-only
update. Product, Item, category, pricing, relations, media usages, and status
mutations require one transaction. Product Item documentation and its approved
review require revision before implementation.

# Approval

Approved by the Business/Architecture Owner on 2026-07-15 for Product v2 phase
one. In LF's ADR vocabulary, `Approved` is the canonical status corresponding
to the requested `Accepted` decision.

This approval is limited to the associated frozen Product v2 and Product Item
v2 database contracts. Deferred scope remains outside implementation authority.
