# Course Template Ordering Architecture Review

Version: 1.0

Document Status: Frozen

Implementation Status: Unknown

Last Updated: 2026-08-09

Review Date: 2026-07-18

Document Path: quality/LF-Course-Template-Ordering-Architecture-Review.md

## Review Information

| Field | Value |
| --- | --- |
| Domain | Course |
| Parent ADR | [ADR-0001 — Course Foundation](../adr/ADR-0001-Course-Foundation.md) |
| Database Doc | [core_course_templates](../database/course/core_course_templates.md) |
| Contract | Tenant- and Category-scoped mutable Template administration order |

## Approved Contract

- Add `core_course_templates.sort_order` as an unsigned, non-negative integer.
- Scope ordering to `customer_id + category_id`.
- Create always assigns `MAX(sort_order) + 1` on the server in the scoped
  destination Category and ignores a client-provided Create order; an empty
  Category starts at `1`.
- Duplicate values are allowed; ascending `id` is the stable tie-breaker.
- Moving Category without changing the submitted order places the Template at
  the end of the destination Category.
- Moving or editing one Template does not renumber any other Template.
- Existing rows are backfilled deterministically by ascending `id` within each
  tenant and Category.

## Boundary Review

- [x] `sort_order` remains mutable working-Template administration metadata.
- [x] It is not copied into immutable Template Version snapshots.
- [x] It does not change Product ordering or `core_course_products.sort_order`.
- [x] All default and update calculations are tenant- and Category-scoped.
- [x] Authorization remains the existing Course Template authorization.
- [x] No route, publish validator, media lifecycle or version lifecycle changes.

## Database Review

- [x] Additive migration only.
- [x] Non-negative integer validation.
- [x] Composite ordering index includes tenant, Category, order and ID.
- [x] No uniqueness constraint because duplicate order values are allowed.
- [x] Backfill is deterministic and does not modify other business fields.

## Decision

```text
Approved and Frozen — Course Template Ordering Implementation Authorized
```

Owner Approval:

```text
Role: LearnForge Architecture Owner
Date: 2026-07-18
Decision: Approved
```

End of Review
