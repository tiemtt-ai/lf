# LF-SaaS-Billing.md

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

---

# LF SaaS Billing Architecture

Billing Domain quản lý pricing calculation, amount due, Invoice và Payment
state của LearnForge.

```text
Commercial

↓ Can Use?

Usage

↓ Used.

Billing

↓ Pay.
```

---

# Domain Responsibility

Billing sở hữu:

* Price and pricing-version contract.
* Billing calculation.
* Billing period summary.
* Invoice lifecycle.
* Payment lifecycle and provider reconciliation.
* Tax, discount and credit calculation khi được phê duyệt.

Billing không sở hữu:

* Plan Catalog.
* Plan Feature.
* Subscription lifecycle.
* Subscription Item.
* Entitlement.
* Customer identity.
* Usage measurement.
* Learning hoặc AI business state.

Plan, Subscription và Entitlement thuộc
[SaaS Commercial](LF-SaaS-Commercial.md).

---

# Source Of Truth

| State | Source Of Truth |
| --- | --- |
| Customer identity | Tenant Domain |
| Plan, Subscription and Entitlement | Commercial Domain |
| Usage measurement | Usage Domain |
| Pricing calculation and amount due | Billing Domain |
| Invoice lifecycle | Billing Domain |
| Payment/reconciliation state | Billing Domain |

Billing không được update Commercial hoặc Usage source records.

---

# Billing Inputs

Billing có thể consume:

* Customer identity/context từ Tenant.
* Subscription context và Items từ Commercial.
* Effective Entitlements từ Commercial.
* Usage summaries từ Usage.
* Approved pricing, tax, discount và contract policy thuộc Billing.

```text
Commercial Subscription Context

+

Usage Measurement

+

Billing Pricing Policy

↓

Amount Due

↓

Invoice

↓

Payment
```

---

# Billing Models

Billing có thể hỗ trợ:

```text
Fixed Recurring

Usage Based

Hybrid

One Time

Enterprise Contract
```

Commercial `billing_type` phân loại Plan nhưng không thay Billing pricing
policy hoặc pricing version.

---

# Usage Billing

Usage cung cấp approved measurements:

* Storage.
* Bandwidth.
* AI tokens/requests.
* Active users.
* Các measurement khác được contract hóa.

Billing tính included amount, overage, rate và charge theo pricing policy của
Billing. Usage không tự tính tiền.

---

# AI Billing

AI Model Run cung cấp execution provenance và estimated operational cost.
Usage sở hữu approved AI usage measurement. Billing quyết định charge.

BYOK có thể thay đổi pricing policy nhưng không làm mất Usage measurement hoặc
AI audit.

---

# Invoice Architecture

Invoice là Billing-owned record.

Allowed lifecycle examples:

```text
draft

issued

paid

overdue

cancelled
```

Invoice không thay đổi Subscription hoặc Entitlement trực tiếp. Khi Payment
hoặc delinquency cần ảnh hưởng quyền sử dụng:

```text
Billing Event / Request

↓

Commercial

↓ own decision

Subscription / Entitlement lifecycle
```

---

# Payment Architecture

Billing có thể tích hợp:

* Stripe.
* PayPal.
* VNPay.
* Momo.
* Bank Transfer.

Provider response phải idempotent, auditable và tenant-scoped. Payment state
không được lưu trong Commercial tables.

---

# Database Namespace

```text
saas_billing_*
```

Billing table foundation và provider integration cần review/ADR riêng. Các
table sau không thuộc Billing namespace:

```text
saas_plans
saas_plan_features
saas_subscriptions
saas_subscription_items
saas_entitlements
```

Chúng thuộc SaaS Commercial Foundation.

---

# Relationship With Commercial

Commercial trả lời “Can Use?” và Billing trả lời “Pay.”.

Billing reads Commercial state through an approved contract. Billing cannot:

* Activate/cancel Subscription directly.
* Create/revoke Entitlement directly.
* Rewrite Plan Feature.

Billing phát Event/Request khi commercial access cần được xem xét.

---

# Relationship With Usage

Usage supplies measurements. Billing applies pricing.

```text
Usage Summary

↓ read

Billing Calculation

↓

Invoice
```

Billing không rewrite Usage history hoặc aggregation.

---

# Relationship With Tenant

Billing records are tenant-scoped bằng `customer_id`. Tenant owns Customer
identity; Billing does not update Customer lifecycle or membership.

---

# Principles Applied

Canonical reference:
[LF-Architecture-Principles](../governance/LF-Architecture-Principles.md).

* Domain Responsibility Principle.
* Source Of Truth Principle.
* Evidence Principle.
* Tenant Isolation Principle.
* Append Only Principle for provider/payment events.
* Backward Compatibility Principle.
* Simplicity Principle.

---

# Future Decisions

* Pricing catalog and immutable pricing versions.
* Tax, discount, credit and currency policy.
* Invoice and payment table foundation.
* Provider idempotency and reconciliation.
* Refund, dispute and credit-note lifecycle.
* Overage and enterprise contract calculation.

---

# Final Statement

Billing owns price calculation, Invoice and Payment. It consumes Commercial and
Usage without taking ownership of their Source Of Truth.
