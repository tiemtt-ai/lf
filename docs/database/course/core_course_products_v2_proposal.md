# Proposed Revision: core_course_products

Version: 2.0-draft

Status: Proposed — Not Approved or Frozen

Last Updated: 2026-07-15

Parent: [core_course_products](core_course_products.md) v1.3

ADR: [ADR-0014](../../adr/ADR-0014-Product-Offering-And-Draft-Binding.md)

---

# Purpose and compatibility

This is an additive proposal. Until approval, v1.3 remains authoritative.
Product remains the tenant-owned commercial/display/access package; learning
content remains owned by Product Items and immutable Template Versions.

# Conflict matrix

| Concern | Frozen contract | Requested contract | Decision | Documentation change |
| --- | --- | --- | --- | --- |
| Classification | `product_type`: `single_course`, `bundle` | Five offering values | Keep separate dimensions | Add `offering_type`; retain `product_type` |
| Content selection | Item binds published Version | Template required even without Version | Keep association in Item | Item gets Draft `template_id`; no Product FK |
| Self-paced settings | Nullable duration columns | Conditional fields | Reuse columns | Type-bound validation and cleanup |
| Description | Direct Product text | Version inheritance/override | Text becomes override storage | Add override flag |
| Media | Legacy thumbnail fields | Three inherited/override slots | Use Media usages/pointers | Add override flag and intro columns |
| Pricing | `price`/`sale_price` | Discount configuration | Calculate selling price | Add promotion fields; derived price not stored |
| Target levels | No taxonomy | Multi-select | Existing difficulty is unsuitable | Defer; no phase-one schema |
| Relations | Directional typed table | Related selector | Reuse `related` relations | No Product columns; no gift behavior |
| Registration | Nullable start/end | Explicit boundaries | Preserve fields | Centralize `[start, end)` rule |
| Ordering | Non-null `sort_order` | Blank means automatic | Service resolves blank | Scope by tenant+category |
| Status | Four canonical statuses | Same plus display states | Preserve statuses | Document activation transition |

# Column classification

## Preserved and reinterpreted

`customer_id`, `product_code`, `product_type`, `title`, `slug`, `price`,
`currency`, `access_duration_days`, `review_duration_days`,
`registration_starts_at`, `registration_ends_at`, `is_featured`, `sort_order`,
`status`, and audit timestamps remain. `title` is labelled Product name.
`product_code` and `slug` are readonly and server-authoritative; existing values
and URLs remain stable on edit. `short_description` and `description` become
retained Product override storage.

## New additive columns

| Column | Type | Null/default | Rule |
| --- | --- | --- | --- |
| `category_id` | BIGINT UNSIGNED | staged nullable, then NOT NULL | Same-tenant Category |
| `offering_type` | VARCHAR(50) | NULL transitionally | Canonical offering |
| `uses_custom_description` | TINYINT(1) | DEFAULT 0 | False ignores stored overrides |
| `uses_custom_intro_media` | TINYINT(1) | DEFAULT 0 | False ignores Product media |
| `intro_image_media_file_id` | BIGINT UNSIGNED | NULL | Ready same-tenant image |
| `intro_video_source` | VARCHAR(50) | NULL | `upload` or `embed` |
| `intro_video_media_file_id` | BIGINT UNSIGNED | NULL | Ready uploaded video |
| `intro_video_embed_url` | VARCHAR(2048) | NULL | Normalized trusted URL |
| `intro_video_provider` | VARCHAR(50) | NULL | `youtube` or `vimeo` |
| `intro_document_media_file_id` | BIGINT UNSIGNED | NULL | Ready document |
| `promotion_enabled` | TINYINT(1) | DEFAULT 0 | Gates promotion fields |
| `discount_type` | VARCHAR(50) | NULL | `percentage`, `fixed_amount` |
| `discount_value` | DECIMAL(15,2) | NULL | Positive and bounded |

Legacy thumbnail columns and `sale_price` remain during compatibility work.
They are not new-form sources after verified backfill. Removal requires a later
approved, non-destructive cleanup.

# Package and offering rules

`product_type` remains `single_course` or `bundle`. Phase one assigns
`single_course` server-side and may hide Package type. Bundle remains valid but
its form/workflow is deferred.

`offering_type` values are `self_paced_course`, `live_online_course`,
`blended_course`, `assessment`, and `learning_material`. For self-paced,
`access_duration_days` is integer >= 1 and `review_duration_days` is null or
integer >= 0. For all other offerings both must be null. Changing type clears
irrelevant duration values transactionally. Storage does not enforce runtime
expiry; that behavior is deferred.

# Content binding and activation

Product has no direct Template FK. Category and the one phase-one Product Item
are required. The Item's Template must belong to Product category. Draft Item
may have null Version. Active Product requires exactly one active Item with a
Version belonging to that Template and having `status = published`.

Activation validates, under one transaction: tenant ownership and Customer
Admin authorization; identity/category/package/offering; Item/Template/Version;
self-paced configuration; price/currency/promotion; registration interval; and
every enabled custom media pointer plus its exact active usage. The four future
offerings need no type-specific configuration in phase one.

# Description inheritance

When custom description is off, retained Product override text is ignored by
runtime/public presentation. Draft admin preview uses a bound published Version
when available; without one it may show selected Template Draft text only when
explicitly labelled non-runtime preview. Active inheritance always reads the
bound immutable Version. Toggling inheritance back on retains but deactivates
overrides; hidden stale values never affect output.

# Introduction media

Media owner is `course_product`; purposes are `intro_image`, `intro_video`, and
`intro_document`. All three slots may coexist. When custom media is off,
retained Product pointers/usages are ignored. Active inheritance reads only
immutable Version media. Draft preview follows the description rule.

A valid replacement wins over removal; otherwise removal wins; otherwise the
current association remains. Invalid replacement changes nothing. Detach
archives usage without deleting a shared file. Upload/embed switching clears
inactive source fields. Scalar, pointer, and usage mutations are one
transaction. Ready status, tenant, owner, purpose, active usage, private storage,
trusted provider validation, and signed presentation remain mandatory.

# Pricing

`price` is list price, uses DECIMAL, and may be zero. `currency` comes from the
existing configured allowlist. When promotion is disabled, promotion fields are
null and ineffective. When enabled, type/value are required. Percentage is
`> 0` and `<= 100`; fixed amount is `> 0` and `<= price`.

Promotion dates are independently nullable. If both exist, end is after start.
Intervals use inclusive start/exclusive end and UTC storage with tenant-timezone
input/display.

Selling price is derived server-side with decimal arithmetic and approved
currency rounding; it is never submitted or persisted as authority and cannot
be negative. Checkout/order/payment snapshots belong to future commerce work.

# Registration availability

Both null means open while active; start only opens at start; end only is open
until end; both form `[start, end)`. Central rule:

```text
status = active
AND (registration_starts_at IS NULL OR now >= registration_starts_at)
AND (registration_ends_at IS NULL OR now < registration_ends_at)
```

Status remains distinct. Upcoming/open/closed are derived display states.

# Related Products

Use directional `core_course_product_relations` records with
`relation_type = related`. Reverse direction is separate. Target must be a
same-tenant, authorized, non-archived Product other than self. Existing unique
constraints prevent duplicates. No Relation creates Enrollment or access.
Gift/attached behavior is out of scope.

# Display order

Physical `sort_order` remains non-negative. Blank input is assigned one plus
the maximum in the same `customer_id` and `category_id`, including all statuses.
Explicit duplicates are allowed; no renumbering occurs. Allocation must use the
repository transaction/locking pattern, never an unprotected `MAX + 1`.

# Target-level audit

No normalized tenant-aware framework/level taxonomy exists. Template
`difficulty_level` is authoring metadata, not a reusable Product taxonomy and
cannot represent CEFR without mixing frameworks. Target Levels are deferred;
no table or pivot is proposed.

# Keys and indexes

Proposed additions use explicit short names: restrictive Category and media
file FKs; indexes `(customer_id, category_id, sort_order)`,
`(customer_id, offering_type)`, `(customer_id, status)`, and the two
registration timestamps. Existing tenant-scoped Product code and slug unique
keys remain. Cross-tenant equality and conditional activation remain domain
validation because current FK policy cannot express them safely.

# Backward-compatible migration plan

Use forward-only additive migrations after approval. Add new required fields as
nullable, backfill deterministically where evidence exists, validate, then
tighten nullability. Existing Products receive `offering_type = NULL`; never
infer it from Activities. Existing active rows remain readable/active but must
be remediated before a later activation transition.

Category backfill requires an explicit pre-migration audit through each
Product's Item Version -> Template -> Category. Products with zero Items or
conflicting multi-item categories require an owner-approved remediation list;
the migration must abort rather than guess. Existing Items, Version locks,
Relations, codes, slugs, and routes remain authoritative.

# Deferred scope

Future offering configurations; Workshop; Gift/attached Products; Bundle UI;
Target Levels; Cohort schedules; expected opening/capacity; refund policy;
learning outcomes; automatic access-expiry runtime; and commerce price
snapshots.

# Approval gate

Ready for review, not implementation. It becomes authoritative only after
ADR-0014, both database proposals, and the integrated review are approved.
