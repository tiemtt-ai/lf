# LF-SaaS-Commercial.md

Version: 1.0

Status: Foundation Approved and Frozen

Last Updated: 2026-06

Document Path: saas/LF-SaaS-Commercial.md

---

# LF SaaS Commercial Architecture

SaaS Commercial là Domain quyết định Customer đăng ký gói nào và được quyền
sử dụng capability nào của LearnForge.

Commercial chỉ trả lời:

```text
Can Use?
```

Usage trả lời:

```text
Used.
```

Billing trả lời:

```text
Pay.
```

Ba quyết định này có Source Of Truth riêng và không được ghi đè lẫn nhau.

---

# Domain Responsibility

Commercial sở hữu:

* Plan Catalog.
* Feature configuration theo Plan.
* Subscription lifecycle.
* Subscription Item.
* Effective Entitlement của Customer.

Commercial không sở hữu:

* Customer identity hoặc Tenant resolution.
* User identity hoặc membership.
* Usage event, measurement hoặc aggregation.
* Price calculation, Billing Summary, Invoice hoặc Payment.
* Course, Progress hoặc Completion.
* LiveClass, Assessment, Media, Track hoặc AI business state.

---

# Source Of Truth

| Business State | Source Of Truth |
| --- | --- |
| Plan catalog | `saas_plans` |
| Feature defaults of a Plan | `saas_plan_features` |
| Customer Subscription lifecycle | `saas_subscriptions` |
| Subscription add-on/package composition | `saas_subscription_items` |
| Effective Customer right — “Can Use?” | `saas_entitlements` |
| Customer identity | Tenant Domain |
| Resource consumption — “Used” | Usage Domain |
| Amount due, Invoice and Payment — “Pay” | Billing Domain |

Plan Feature mô tả quyền mặc định của Plan. Entitlement là quyền hiệu lực đã
được resolve cho một Customer và là Source Of Truth cuối cùng của “Can Use?”.

---

# Architecture

```text
Global Plan Catalog

↓ selected by

Customer Subscription

↓ composed with

Subscription Items

↓ resolve

Customer Entitlements

↓ read

Consumer Domain / Usage / Billing
```

Consumer chỉ đọc Entitlement qua contract được phê duyệt. Consumer không được
update Commercial tables.

---

# Plan Catalog

`saas_plans` là global catalog và không thuộc một Customer cụ thể.

Examples:

```text
Free

Starter

Professional

Enterprise
```

Plan không chứa Usage, Invoice, Payment hoặc Customer assignment. `billing_type`
chỉ phân loại commercial model; nó không lưu price hoặc tính amount due.

Plan code là stable identity. Plan đã active không được đổi nghĩa âm thầm.
Catalog change không được retroactively thay đổi Entitlement đang hiệu lực.

---

# Plan Features

`saas_plan_features` định nghĩa feature defaults của một Plan.

Examples:

```text
ai_tutor

liveclass

certificate

storage_gb

api_access
```

Feature default có thể:

* Enabled/disabled.
* Limited bằng một numeric value.
* Unlimited.

Plan Feature không lưu current Usage. `limit_value` mô tả quyền được cấp, không
mô tả lượng đã dùng.

---

# Subscription Lifecycle

`saas_subscriptions` liên kết Customer với Plan.

Allowed status:

```text
trial

active

suspended

expired

cancelled
```

Một Customer có thể có nhiều Subscription theo lịch sử nhưng chỉ có một
Subscription `active` tại một thời điểm.

Subscription:

* Không tạo Invoice.
* Không ghi Payment.
* Không ghi Usage.
* Không thay Customer lifecycle.
* Không tự cấp learning access nếu Entitlement chưa được resolve.

---

# Subscription Items

`saas_subscription_items` mô tả add-on hoặc package bổ sung thuộc Subscription.

Examples:

```text
ai_package

storage_add_on

extra_users
```

Item có quantity nhưng không lưu Usage. Item chỉ là một input để resolve
Entitlement; nó không phải Entitlement cuối cùng và không phải Billing line
item.

---

# Entitlement Architecture

`saas_entitlements` là Source Of Truth của effective Customer right.

```text
Plan Feature

+

Subscription Item

↓ resolve

Effective Entitlement
```

Examples:

```text
ai_tutor = enabled

max_users = 300

storage_gb = 200
```

Entitlement có effective window và lifecycle. Tại một thời điểm, mỗi
`customer_id + feature_key` chỉ có một effective entitlement.

Entitlement không lưu current consumption. So sánh entitlement limit với Usage
phải đọc hai Sources Of Truth riêng.

---

# Relationship With Tenant

Tenant sở hữu Customer identity và TenantContext. Commercial tham chiếu
`saas_customers.id` bằng `customer_id`.

```text
Tenant Customer

↓ subscribes

Commercial Plan

↓ receives

Entitlement
```

Commercial không update Customer status, domain, membership hoặc settings.

---

# Relationship With Usage

```text
Commercial Entitlement

↓ allowed limit

Usage Decision Support

+

Usage Measurement

↓

Used versus Allowed
```

Usage chỉ đọc Entitlement và tự sở hữu measurement. Usage không update Plan,
Subscription hoặc Entitlement.

---

# Relationship With Billing

Billing đọc:

* Active Subscription context.
* Subscription Items.
* Effective Entitlements khi calculation policy cần.
* Usage measurements.

Billing tự sở hữu price calculation, charge, Billing Summary, Invoice và
Payment state.

Commercial không tạo Invoice và không đánh dấu Payment.

---

# Relationship With AI And Learning Domains

AI, Course, LiveClass, Assessment, Media và các consumer khác có thể kiểm tra
Entitlement trước khi cho dùng một capability.

```text
Consumer Request

↓ read

Effective Entitlement

↓

Allow / Deny Capability
```

Consumer không update Entitlement. Entitlement chỉ quyết định capability có
được phép dùng; nó không quyết định learning Progress, Completion, Assessment
Result, Attendance, Media state hoặc AI output.

---

# Tenant Isolation

`saas_subscriptions`, `saas_subscription_items` và `saas_entitlements` phải
tenant-scoped bằng `customer_id`.

`saas_plans` và `saas_plan_features` là documented global catalog exception.
Global catalog visibility không cấp quyền sử dụng; chỉ effective
tenant-scoped Entitlement mới trả lời “Can Use?”.

Mọi relationship giữa Subscription, Item và Entitlement phải giữ cùng
`customer_id`.

---

# Global Catalog Exception

```text
saas_plans

saas_plan_features
```

là Global Catalog Exception duy nhất của SaaS Commercial Foundation.

* Catalog dùng chung cho toàn bộ LearnForge platform.
* Hai tables này không tenant-scoped và không có `customer_id`.
* Hai tables này không chứa Customer-specific business state.
* Exception không phải tiền lệ để Domain hoặc business table khác bỏ
  `customer_id`.
* Mọi Customer-specific state và mọi business table khác vẫn phải tuân thủ
  Tenant Isolation Principle.

Catalog visibility không cấp quyền sử dụng. Effective tenant-scoped
Entitlement mới là Source Of Truth của “Can Use?”.

---

# Database Namespace

```text
saas_*
```

Foundation tables:

```text
saas_plans
saas_plan_features
saas_subscriptions
saas_subscription_items
saas_entitlements
```

Table documentation:
[docs/database/saas-commercial](../database/saas-commercial/).

---

# Principles Applied

Canonical reference:
[LF-Architecture-Principles](../governance/LF-Architecture-Principles.md).

* Domain Responsibility Principle.
* Source Of Truth Principle.
* Tenant Isolation Principle.
* Generic Reference Principle.
* Read Model Principle.
* Backward Compatibility Principle.
* Simplicity Principle.
* ADR Principle.

---

# Foundation Constraints

* Không thêm price, Invoice, Payment hoặc Usage state vào 5 Foundation tables.
* Không thêm Customer identity fields vào Plan catalog.
* Không dùng Plan Feature như current Customer Entitlement.
* Không dùng Entitlement value như current Usage.
* Không cho consumer Domain update Commercial state trực tiếp.
* Không tạo migration trước khi Database Docs, ADR-0008 và Architecture Review
  được approved.

---

# Architecture Decision

[ADR-0008 — SaaS Commercial Foundation](../adr/ADR-0008-SaaS-Commercial-Foundation.md)
approves and freezes this Foundation at Version 1.0.

Changes to Domain Boundary, ownership, Source Of Truth, Global Catalog
Exception or Foundation tables require an approved ADR Amendment or a new ADR.

---

# Future Extensions

* Active Plan change and catalog rollout policy.
* Active Subscription uniqueness enforcement.
* Trial-to-active transition and grace period.
* One-time Plan lifecycle semantics.
* Subscription Item taxonomy and allowed quantity rules.
* Entitlement resolution precedence and rebuild policy.
* Whether manual overrides are allowed and, if so, their approved source,
  expiry and audit policy.
* Billing pricing contract; price remains outside Commercial Foundation.
* Cache/invalidation contract for high-volume entitlement checks.

---

# Final Statement

SaaS Commercial sở hữu Plan, Subscription và Entitlement. Commercial chỉ quyết
định Customer có thể dùng capability nào; Usage và Billing giữ Sources Of Truth
riêng.

```text
Foundation Approved and Frozen

Version 1.0

Ready for implementation: YES
```
