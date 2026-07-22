# ADR-0010

SaaS Billing Foundation

---

## Status

Frozen

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
* [ADR-0008 — SaaS Commercial Foundation](ADR-0008-SaaS-Commercial-Foundation.md)
* [ADR-0009 — SaaS Usage Foundation](ADR-0009-SaaS-Usage-Foundation.md)

---

## Context

LearnForge cần Domain cuối cùng trong commercial chain để phát hành payment
obligation, ghi nhận settlement và xử lý adjustment/refund.

Nếu Billing không có boundary và financial-history rule rõ ràng:

* Invoice có thể bị dùng để thay đổi Entitlement.
* Invoice Item có thể bị dùng như Usage Source Of Truth.
* Payment có thể bị dùng như Subscription state.
* Refund có thể bị dùng để rewrite Usage.
* Issued financial documents có thể bị sửa trực tiếp.
* Provider payment history có thể mất auditability.
* Commercial và Usage ownership có thể bị Billing tiếp quản.

Foundation cần một Billing Domain độc lập trả lời “Pay” và bảo toàn Financial
Audit Trail.

---

## Decision

SaaS Billing được xác định là:

```text
SaaS Financial Domain

+

Payment Obligation and Settlement Authority
```

Boundary:

```text
Commercial → Can Use

Usage → Used

Billing → Pay
```

SaaS Billing Foundation Version 1.0 gồm 5 tables.

---

## Domain Responsibility

Billing sở hữu:

* Invoice.
* Invoice Item.
* Payment.
* Payment Method reference.
* Credit Note/Refund.

Billing không sở hữu:

* Customer.
* Plan, Subscription hoặc Entitlement.
* Usage Event, Counter hoặc Summary.
* Course hoặc Assessment.
* Media, Track hoặc AI state.

Pricing, tax, discount và reconciliation chỉ là Billing processes tạo hoặc
settle Billing-owned Financial Evidence. Chúng không chuyển ownership của
Commercial hoặc Usage state.

---

## Billing Boundary

```text
Tenant Customer

+

Commercial Subscription / Entitlement

+

Usage Summary

↓ Billing calculation

Draft Invoice + Items

↓ issue

Official Invoice

↓ settle

Payment

↓ adjust/refund

Credit Note
```

Billing chỉ đọc Tenant, Commercial và Usage inputs. Billing không update Source
Of Truth của các Domain đó.

```text
Invoice ≠ Usage

Payment ≠ Entitlement

Refund ≠ Usage Correction
```

---

## Financial Immutability Principle

Issued Invoice, Invoice Item, successful Payment history và issued Credit Note
là Financial Evidence.

Sau khi được phát hành hoặc ghi nhận thành công, Financial Evidence không được
sửa đổi trực tiếp.

Mọi điều chỉnh tài chính phải thực hiện thông qua:

* Credit Note.
* Refund được quản lý bằng Credit Note.
* Financial Document mới.
* Billing reconciliation được phê duyệt.

Credit Note là cơ chế adjustment document duy nhất của Foundation. Refund phải
có Credit Note; Financial Document mới không rewrite document cũ; approved
reconciliation không được rewrite amount, provider identity hoặc historical
evidence.

Không sửa đổi lịch sử tài chính. Billing phải luôn bảo toàn Financial Audit
Trail.

Rules:

* Invoice là Billing Source Of Truth của payment obligation.
* Invoice Item là immutable financial snapshot sau Invoice issuance.
* Payment provider history là immutable.
* Credit Note điều chỉnh mà không sửa Invoice gốc.
* Không sửa trực tiếp Financial Evidence.
* Billing chỉ đọc Commercial và Usage.
* Billing không quyết định “Can Use” hoặc “Used”.

Financial Immutability Principle là Billing Foundation rule áp dụng canonical
Immutable Principle; nó không tạo định nghĩa Governance cạnh tranh.

---

## Invoice Architecture

`saas_invoices` là Source Of Truth cho payment obligation và Invoice lifecycle.

`saas_invoice_items` là line-item financial snapshot.

Draft Invoice có thể chỉnh trước issuance. Sau issuance:

* Financial totals, currency và Items immutable.
* Chỉ approved lifecycle, settlement totals và timestamps được reconcile.
* Correction dùng Credit Note hoặc Financial Document mới.
* Invoice không update Subscription hoặc Entitlement.
* Invoice Item không thay Usage Summary Source Of Truth.

Invoice Item có thể giữ generic source reference tới approved Subscription Item
hoặc Usage Summary để bảo toàn provenance.

---

## Payment Architecture

`saas_payments` là Source Of Truth cho Payment transaction/lifecycle.

`saas_payment_methods` chỉ giữ safe provider reference; không lưu full card
number, CVV, raw bank credential hoặc provider secret.

Rules:

* Một Invoice có thể có nhiều Payments.
* Provider transaction ID là immutable và unique trong provider scope.
* Successful Payment evidence không được sửa trực tiếp.
* Refund/reconciliation giữ original success history và liên kết Credit Note.
* Payment không update Entitlement trực tiếp.
* Provider callbacks phải idempotent, auditable và tenant-scoped.

---

## Credit Note Architecture

`saas_credit_notes` là independent official financial document.

Credit Note:

* Tham chiếu original Invoice.
* Có thể tham chiếu Payment khi refund.
* Không sửa original Invoice.
* Là adjustment document duy nhất của Foundation.
* Bắt buộc cho Refund.
* Không tạo Usage Correction.
* Không thay đổi Entitlement.
* Trở thành immutable Financial Evidence sau issuance.

---

## Relationship With Tenant

Tenant sở hữu Customer identity và TenantContext.

Mọi Billing table có `customer_id`. Parent/child relationship, provider
callback, source reference và reconciliation phải giữ cùng tenant.

Billing không update Customer lifecycle, domain, settings hoặc membership.

---

## Relationship With Commercial

Commercial sở hữu Plan, Subscription và Entitlement — “Can Use”.

Billing đọc Subscription, Subscription Item và Entitlement. Billing không
activate/cancel Subscription hoặc create/revoke Entitlement.

Khi Billing outcome có thể ảnh hưởng access:

```text
Billing Event / Request

↓

Commercial

↓ own decision

Subscription / Entitlement
```

---

## Relationship With Usage

Usage sở hữu Usage Event, Counter và Summary — “Used”.

Billing đọc approved Usage Summary và có thể snapshot billing details vào
Invoice Item. Billing không update Usage Event, Counter hoặc Summary.

Invoice Item không phải Usage Source Of Truth. Refund không phải Usage
Correction.

---

## Relationship With AI

AI sở hữu Model Run và Model Provenance.

Billing chỉ nhận AI resource consumption qua approved Usage Measurement
Contract và Usage Summary.

AI estimated cost không tự trở thành charge, Invoice Item hoặc Payment
obligation.

---

## Database Namespace

```text
saas_*
```

---

## Foundation Tables

* `saas_invoices`.
* `saas_invoice_items`.
* `saas_payments`.
* `saas_payment_methods`.
* `saas_credit_notes`.

Canonical table documentation:
[docs/database/saas-billing](../database/saas-billing/).

---

## Applied Principles

Canonical reference:
[LF-Architecture-Principles](../governance/LF-Architecture-Principles.md).

Applied canonical principles:

* Domain Responsibility Principle.
* Source Of Truth Principle.
* Immutable Principle.
* Evidence Principle.
* Generic Reference Principle.
* Tenant Isolation Principle.
* Append Only Principle.
* Backward Compatibility Principle.
* Simplicity Principle.
* ADR Principle.

This ADR references canonical definitions and does not redefine them.

---

## Foundation Freeze

SaaS Billing Foundation Version 1.0 is Approved and Frozen by this ADR.

Changes to Domain Boundary, ownership, Source Of Truth, Financial Immutability
Principle or the 5-table Foundation require:

* Approved ADR Amendment; or
* New ADR.

Implementation must preserve Financial Evidence and Financial Audit Trail.

---

## Consequences

### Benefits

* “Can Use”, “Used” and “Pay” remain separate.
* Issued financial documents can be audited and reproduced.
* Payment provider identity and success history are preserved.
* Adjustments/refunds do not rewrite original Invoice or Usage.
* Commercial and Usage remain read-only inputs.
* Payment credentials are minimized to safe provider references.

### Trade-offs

* Invoice/Credit numbering needs concurrency-safe policy.
* Financial snapshot and rounding rules need explicit contracts.
* Partial payment and overpayment need allocation policy.
* Provider webhooks need idempotency and immutable audit.
* Credit/refund reconciliation needs a controlled workflow.
* Financial retention and legal hold increase operational obligations.

---

## Future Extensions

Future topics that do not change Foundation by default:

* Invoice/Credit numbering and sequence policy.
* Pricing, tax, discount and currency rounding snapshots.
* Issued Invoice lifecycle transition matrix.
* Partial payment, allocation and overpayment.
* Provider webhook/event audit and reconciliation.
* Default Payment Method enforcement.
* Credit Note application and provider refund workflow.
* Invoice Item source taxonomy.
* Multi-currency/conversion policy.
* Financial retention, legal hold and compliance.

Any extension that changes Domain Boundary, ownership, Source Of Truth,
Financial Immutability Principle or Foundation tables requires ADR Amendment
or a new ADR.

---

## Result

```text
SaaS Billing Foundation

Version 1.0

Status

Frozen

Ready for implementation

YES
```
