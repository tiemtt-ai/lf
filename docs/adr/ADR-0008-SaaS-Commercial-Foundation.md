# ADR-0008

SaaS Commercial Foundation

---

## Status

Accepted

---

## Version

1.0

---

## Date

2026-06-28

---

## Related ADRs

* [ADR-0001 — Course Foundation](ADR-0001-Course-Foundation.md)
* [ADR-0002 — LiveClass Foundation](ADR-0002-LiveClass-Foundation.md)
* [ADR-0003 — Assessment Foundation](ADR-0003-Assessment-Foundation.md)
* [ADR-0004 — Media Foundation](ADR-0004-Media-Foundation.md)
* [ADR-0005 — Track Foundation](ADR-0005-Track-Foundation.md)
* [ADR-0006 — AI Foundation](ADR-0006-AI-Foundation.md)
* [ADR-0007 — SaaS Tenant Foundation](ADR-0007-SaaS-Tenant-Foundation.md)

---

## Context

LearnForge cần một Commercial boundary thống nhất để xác định Customer đăng ký
Plan nào và được quyền sử dụng capability nào.

Nếu Plan, Subscription, Entitlement, Usage và Billing không được tách rõ:

* Plan Feature có thể bị dùng nhầm như current Usage.
* Usage measurement có thể tự quyết định access.
* Billing có thể tiếp quản Subscription hoặc Entitlement lifecycle.
* Consumer Domain có thể tự update commercial access.
* Customer-specific commercial state có thể mất tenant isolation.
* Global Plan catalog có thể bị hiểu sai thành tiền lệ bỏ `customer_id` ở các
  business tables khác.

Foundation cần một Domain độc lập trả lời “Can Use?” mà không tiếp quản “Used”
hoặc “Pay”.

---

## Decision

SaaS Commercial được xác định là:

```text
SaaS Commercial Domain

+

Entitlement Authority
```

Commercial sở hữu Plan Catalog, Plan Feature defaults, Subscription,
Subscription Item và effective Entitlement.

Boundary:

```text
Commercial → Can Use?

Usage → Used.

Billing → Pay.
```

SaaS Commercial Foundation Version 1.0 gồm 5 tables.

---

## Domain Responsibility

Commercial sở hữu:

* Plan Catalog.
* Feature configuration theo Plan.
* Subscription lifecycle.
* Subscription Item.
* Effective Customer Entitlement.

Commercial không sở hữu:

* Customer identity, Tenant resolution hoặc membership.
* User identity.
* Usage event, measurement hoặc aggregation.
* Pricing calculation, Billing Summary, Invoice hoặc Payment.
* Course Progress hoặc Completion.
* LiveClass, Assessment, Media, Track hoặc AI business state.

---

## Commercial Boundary

```text
Global Plan Catalog

↓ selected by

Tenant-scoped Subscription

↓ composed with

Tenant-scoped Subscription Items

↓ resolve

Tenant-scoped Effective Entitlements

↓ read

Consumer Domain / Usage / Billing
```

Plan Feature là default catalog configuration. `saas_entitlements` là Source
Of Truth cuối cùng của effective Customer right.

Consumer có thể đọc Entitlement nhưng không được update Commercial state.

---

## Plan Catalog

`saas_plans` là global product-plan catalog.

`saas_plan_features` định nghĩa feature defaults và limits của từng Plan.

Plan Catalog:

* Không gắn với một Customer cụ thể.
* Không lưu Usage.
* Không lưu price calculation, Invoice hoặc Payment.
* Không tự cấp quyền cho Customer.
* Không retroactively thay đổi effective Entitlement.

`billing_type` chỉ phân loại commercial model; Billing vẫn sở hữu pricing và
amount-due calculation.

---

## Subscription Architecture

`saas_subscriptions` liên kết một Customer với Plan và giữ lifecycle:

```text
trial

active

suspended

expired

cancelled
```

Một Customer có thể có nhiều historical Subscriptions nhưng chỉ có một
Subscription `active` tại một thời điểm.

`saas_subscription_items` lưu add-on/package composition. Item có thể là input
cho Entitlement resolution nhưng không lưu Usage và không phải Invoice line.

Subscription và Item luôn tenant-scoped.

---

## Entitlement Architecture

```text
Plan Feature

+

Subscription Item

↓ resolve

Effective Entitlement

↓

Can Use?
```

`saas_entitlements` là Source Of Truth của effective Customer right.

Rules:

* Entitlement luôn tenant-scoped.
* Mỗi `customer_id + feature_key` chỉ có một effective Entitlement tại một
  thời điểm.
* Entitlement không lưu current Usage.
* Usage và Billing chỉ đọc.
* AI, Course và consumer Domain khác không update Entitlement.
* Derived cache không thay Entitlement Source Of Truth.

---

## Global Catalog Exception

Hai tables sau là Global Catalog Exception:

```text
saas_plans

saas_plan_features
```

Exception có nghĩa:

* Catalog dùng chung cho toàn bộ LearnForge platform.
* Hai tables này không tenant-scoped và không có `customer_id`.
* Hai tables này không chứa Customer-specific business state.
* Catalog visibility không cấp quyền sử dụng capability.
* Chỉ tenant-scoped `saas_entitlements` trả lời effective “Can Use?”.

Đây là exception duy nhất được SaaS Commercial Foundation cho phép.

Global Catalog Exception không phải tiền lệ cho Domain khác hoặc table khác bỏ
`customer_id`. Mọi Customer-specific business state và mọi business table khác
vẫn phải tuân thủ Tenant Isolation Principle.

---

## Relationship With Tenant

Tenant sở hữu Customer identity và TenantContext. Commercial tham chiếu
Customer bằng `customer_id` trên Subscription, Subscription Item và
Entitlement.

Commercial không update Customer lifecycle, domain, settings hoặc membership.

---

## Relationship With Usage

Usage sở hữu measurement — “Used.”.

Usage có thể đọc effective Entitlement để so sánh used versus allowed nhưng
không tự quyết định Entitlement và không update Commercial state.

---

## Relationship With Billing

Billing có thể consume Subscription context, Subscription Items,
Entitlements và Usage.

Billing tự sở hữu pricing, amount due, Invoice và Payment. Commercial không tạo
Invoice hoặc đánh dấu Payment.

Nếu Billing outcome cần ảnh hưởng access:

```text
Billing Event / Request

↓

Commercial

↓ own decision

Subscription / Entitlement lifecycle
```

---

## Relationship With AI

AI có thể đọc effective Entitlement trước khi dùng một AI capability.

AI không update Plan, Subscription hoặc Entitlement. AI Model Run không phải
Commercial state; Usage và Billing vẫn giữ boundary riêng.

---

## Database Namespace

```text
saas_*
```

---

## Foundation Tables

Global Catalog Exception:

* `saas_plans`.
* `saas_plan_features`.

Tenant-scoped Commercial tables:

* `saas_subscriptions`.
* `saas_subscription_items`.
* `saas_entitlements`.

Canonical table documentation:
[docs/database/saas-commercial](../database/saas-commercial/).

---

## Applied Principles

Canonical reference:
[LF-Architecture-Principles](../governance/LF-Architecture-Principles.md).

Applied canonical principles:

* Domain Responsibility Principle.
* Source Of Truth Principle.
* Tenant Isolation Principle, with the explicit Global Catalog Exception in
  this ADR.
* Generic Reference Principle.
* Read Model Principle.
* Backward Compatibility Principle.
* Simplicity Principle.
* ADR Principle.

This ADR references canonical definitions and does not redefine them.

---

## Foundation Freeze

SaaS Commercial Foundation Version 1.0 is Approved and Frozen by this ADR.

Changes to Domain Boundary, ownership, Source Of Truth, Global Catalog
Exception or the 5-table Foundation require:

* Approved ADR Amendment; or
* New ADR.

No additional global-catalog or tenant-isolation exception may be inferred
from this decision.

---

## Consequences

### Benefits

* “Can Use?”, “Used” and “Pay” have separate Sources Of Truth.
* Consumer Domains share one effective Entitlement authority.
* Subscription history and add-on composition remain auditable.
* Plan Catalog can be reused platform-wide.
* Global catalog scope is explicit and narrowly constrained.
* Customer-specific commercial state remains tenant-scoped.

### Trade-offs

* Catalog rollout and active Plan change need controlled policy.
* Active Subscription uniqueness needs an implementation strategy.
* Entitlement resolution and cache invalidation need operational contracts.
* Billing requires a separate pricing/version contract.
* Global catalog administration requires platform-level authorization.

---

## Future Extensions

Future topics that do not change Foundation by default:

* Active Plan rollout and replacement policy.
* Trial transition, renewal grace and suspension policy.
* One-time Plan lifecycle semantics.
* Subscription Item taxonomy and quantity rules.
* Entitlement resolution precedence and rebuild policy.
* Approved override source, expiry and audit policy.
* Entitlement cache/invalidation contract.
* Billing pricing/version integration.
* Marketplace offerings and enterprise contracts.

Any extension that changes Domain Boundary, ownership, Source Of Truth, Global
Catalog Exception or Foundation tables requires ADR Amendment or a new ADR.

---

## Result

```text
SaaS Commercial Foundation

Version 1.0

Status

Foundation Approved and Frozen

Ready for implementation

YES
```
