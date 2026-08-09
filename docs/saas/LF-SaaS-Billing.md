# LF-SaaS-Billing.md

Version: 1.0

Status: Foundation Approved and Frozen

Last Updated: 2026-06

Document Path: saas/LF-SaaS-Billing.md

---

# LF SaaS Billing Architecture

SaaS Billing là Domain cuối cùng trong commercial chain của LearnForge.
Billing xác định và ghi nhận nghĩa vụ thanh toán của Customer.

```text
Commercial → Can Use

Usage → Used

Billing → Pay
```

Billing chỉ đọc Customer, Subscription, Entitlement và Usage Summary. Billing
không update Source Of Truth của các Domain đó.

---

# Domain Responsibility

Billing sở hữu:

* Invoice.
* Invoice Line Item.
* Payment.
* Payment Method reference.
* Credit Note/Refund.

Billing không sở hữu:

* Customer.
* Plan, Subscription hoặc Entitlement.
* Usage measurement, Counter hoặc Summary.
* Course hoặc Assessment.
* Media, Track hoặc AI state.

Pricing/tax/discount calculation chỉ là business process để tạo Billing-owned
Invoice; nó không chuyển Plan, Subscription, Entitlement hoặc Usage ownership
sang Billing.

---

# Source Of Truth

| Business State | Source Of Truth |
| --- | --- |
| Invoice obligation and lifecycle | `saas_invoices` |
| Official Invoice line snapshot | `saas_invoice_items` |
| Payment transaction and lifecycle | `saas_payments` |
| Customer payment-method reference | `saas_payment_methods` |
| Credit adjustment/refund document | `saas_credit_notes` |
| Customer identity | Tenant Domain |
| Plan/Subscription/Entitlement — “Can Use” | Commercial Domain |
| Usage measurement — “Used” | Usage Domain |

Invoice, Payment và Credit Note là Billing Sources Of Truth. Invoice Item là
immutable detail của Invoice sau issuance.

---

# Architecture

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

↓ adjust/refund when required

Credit Note
```

Billing input không trở thành Billing-owned copy của source business state.
Invoice Item chỉ snapshot billing description, quantity và price cần để tái
hiện Invoice.

---

# Invoice Architecture

`saas_invoices` là official payment obligation của Customer.

Allowed status:

```text
draft

issued

partially_paid

paid

overdue

cancelled

void
```

Draft Invoice có thể chỉnh trước issuance. Sau issuance:

* Financial content và Invoice Items immutable.
* Chỉ controlled lifecycle, payment totals và settlement timestamps được cập
  nhật.
* Correction không rewrite financial content; dùng Credit Note hoặc replacement
  document theo policy.

Invoice không quyết định Entitlement và không update Subscription.

---

# Invoice Item Architecture

`saas_invoice_items` là immutable line snapshot của Invoice.

Examples:

* Monthly Subscription.
* AI Usage.
* Storage.
* Add-on.
* Manual Charge.

Invoice Item có thể giữ generic source reference tới approved Commercial hoặc
Usage record, ví dụ Subscription Item hoặc Usage Summary.

Source reference chỉ cung cấp provenance. Invoice Item không trở thành Source
Of Truth của Usage hoặc Subscription.

---

# Payment Architecture

`saas_payments` ghi payment transaction và provider reconciliation state.

Allowed status:

```text
pending

authorized

succeeded

failed

cancelled

refunded
```

Một Invoice có thể có nhiều Payment. Provider transaction identity phải
idempotent trong provider scope.

Payment success cập nhật settlement fields của Invoice theo Billing policy,
nhưng không update Commercial Entitlement trực tiếp.

---

# Payment Method Architecture

`saas_payment_methods` chỉ lưu provider reference và safe display metadata.

Billing không lưu:

* Full card number/PAN.
* CVV/CVC.
* Raw bank credential.
* Provider secret.

Một Customer có thể có nhiều Payment Methods nhưng chỉ một active default tại
một thời điểm.

---

# Credit Note And Refund

`saas_credit_notes` là official independent document để điều chỉnh hoặc refund
một Invoice.

Allowed status:

```text
draft

issued

applied

cancelled
```

Rules:

* Không sửa Invoice gốc.
* Credit Note tham chiếu Invoice và optional Payment.
* Refund luôn có Credit Note.
* Credit amount không vượt quá refundable balance.
* Applied Credit Note cập nhật Billing settlement totals theo policy.
* Refund không tạo Usage correction hoặc thay Entitlement.

---

# Relationship With Tenant

Tenant sở hữu Customer identity và TenantContext.

Mọi Invoice, Invoice Item, Payment, Payment Method và Credit Note đều
tenant-scoped bằng `customer_id`.

Billing đọc Customer context nhưng không update Customer lifecycle, domain,
settings hoặc membership.

---

# Relationship With Commercial

Commercial sở hữu Plan, Subscription và Entitlement — “Can Use”.

Billing có thể đọc:

* Subscription.
* Subscription Item.
* Effective Entitlement.

Billing không activate/cancel Subscription và không create/revoke Entitlement.

Nếu delinquency hoặc Payment outcome cần ảnh hưởng access:

```text
Billing Event / Request

↓

Commercial

↓ own decision

Subscription / Entitlement
```

---

# Relationship With Usage

Usage sở hữu Usage Event, Counter và Summary — “Used”.

Billing đọc approved Usage Summary để tạo Invoice Items. Billing không update:

* Usage Event.
* Usage Counter.
* Usage Summary.

```text
Usage Summary

↓ read and snapshot

Invoice Item
```

Invoice Item không phải Usage Source Of Truth.

---

# Relationship With AI

AI sở hữu Model Run và Model Provenance. Billing không đọc AI Model Run như
Billing Source Of Truth.

AI resource consumption phải đi qua approved Usage Measurement Contract:

```text
AI Model Run

↓ approved measurement

Usage

↓ Usage Summary

Billing
```

AI estimated cost không tự trở thành charge hoặc Invoice Item.

---

# Relationship With Learning And Platform Domains

Course, Assessment, Media, LiveClass và Track không được Billing update.

Billing chỉ dùng approved Commercial/Usage inputs. Invoice, Payment hoặc Refund
không quyết định Course Progress, Assessment Result, Media Processing, Track
behavior hoặc AI output.

---

# Domain Distinctions

```text
Invoice

≠

Usage
```

```text
Payment

≠

Entitlement
```

```text
Refund

≠

Usage Correction
```

Mỗi record giữ Source Of Truth trong Domain của nó.

---

# Financial Immutability Principle

Issued Invoice, Invoice Item, successful Payment history và issued Credit Note
là Financial Evidence.

Sau khi được phát hành hoặc ghi nhận thành công, Financial Evidence không được
sửa đổi trực tiếp.

Mọi điều chỉnh tài chính phải thực hiện thông qua:

* Credit Note.
* Refund được quản lý bằng Credit Note.
* Financial Document mới.
* Billing reconciliation được phê duyệt.

Credit Note là adjustment document duy nhất của Foundation. Refund không tồn
tại ngoài Credit Note.

Không sửa đổi lịch sử tài chính. Approved reconciliation không được rewrite
amount, provider identity hoặc historical evidence. Billing phải luôn bảo toàn
Financial Audit Trail.

Lifecycle transition cần actor/time/reason hoặc provider provenance theo
approved audit policy. Metadata không thay thế canonical amount, status,
provider identity hoặc cross-domain source reference.

---

# Currency And Amount Principle

* Currency dùng ISO 4217 uppercase code.
* Mọi amount trong cùng Invoice phải dùng một currency.
* Amount lưu decimal, không dùng floating point.
* Rounding policy phải ổn định theo currency.
* `subtotal - discount + tax = total`.
* Payment và Credit Note application phải cập nhật `amount_paid` và
  `amount_due` theo một reconciliation policy có thể audit.

---

# Tenant Isolation

* Mọi Billing table có `customer_id`.
* Invoice Item và Credit Note phải giữ `customer_id` khớp parent Invoice.
* Payment phải giữ `customer_id` khớp Invoice và Payment Method.
* Generic source reference phải thuộc cùng tenant.
* Provider callback không được bypass Tenant validation.
* Invoice/payment lookup không được cross tenant dù number hoặc provider ID là
  globally unique.

---

# Database Namespace

```text
saas_*
```

Foundation tables:

```text
saas_invoices
saas_invoice_items
saas_payments
saas_payment_methods
saas_credit_notes
```

Table documentation:
[docs/database/saas-billing](../database/saas-billing/).

---

# Principles Applied

Canonical reference:
[LF-Architecture-Principles](../governance/LF-Architecture-Principles.md).

* Domain Responsibility Principle.
* Source Of Truth Principle.
* Immutable Principle.
* Evidence Principle.
* Generic Reference Principle.
* Tenant Isolation Principle.
* Append Only Principle for provider transaction history.
* Backward Compatibility Principle.
* Simplicity Principle.
* ADR Principle.

---

# Foundation Constraints

* Không thêm Customer, Plan, Subscription, Entitlement hoặc Usage state vào
  Billing tables.
* Không cho Billing update Commercial hoặc Usage.
* Không dùng Invoice Item như Usage Source Of Truth.
* Không dùng Payment như Entitlement state.
* Không dùng Refund như Usage correction.
* Không lưu raw payment credential.
* Không tạo migration trước khi Database Docs, ADR-0010 và Architecture Review
  được approved.

---

# Architecture Decision

[ADR-0010 — SaaS Billing Foundation](../adr/ADR-0010-SaaS-Billing-Foundation.md)
approves and freezes this Foundation at Version 1.0.

Changes to Domain Boundary, ownership, Source Of Truth, Financial Immutability
Principle or Foundation tables require an approved ADR Amendment or a new ADR.

---

# Future Extensions

* Invoice numbering scope, sequence and concurrency.
* Pricing/tax/discount snapshot and rounding policy.
* Issued Invoice lifecycle transition matrix.
* Partial payment allocation and overpayment policy.
* Provider webhook idempotency and immutable event audit.
* Default Payment Method uniqueness enforcement.
* Credit Note application, refund provider reconciliation and refundable
  balance.
* Cross-domain Invoice Item source taxonomy.
* Currency conversion and multi-currency policy.
* Retention, legal hold and financial compliance.

---

# Final Statement

SaaS Billing sở hữu payment obligation, settlement và adjustment của “Pay”.
Commercial giữ “Can Use”; Usage giữ “Used”.

```text
Foundation Approved and Frozen

Version 1.0

Ready for implementation: YES
```
