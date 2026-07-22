# Table: saas_credit_notes

## Purpose

Independent financial document for Invoice adjustment or refund.

## Relationships

Credit Note belongs to one Customer and Invoice; it may reference one Payment
when a provider refund is involved.

## Business Rules

* Every Credit Note belongs to one `customer_id` matching its Invoice.
* Payment, when present, belongs to the same Customer and Invoice.
* `credit_number` is globally unique and immutable after issuance.
* Allowed `credit_status`: `draft`, `issued`, `applied`, `cancelled`.
* Credit Note does not modify the original Invoice financial snapshot.
* Credit Note is an independent official Billing document.
* Refund always uses a Credit Note.
* `amount` is positive, uses Invoice currency implicitly and cannot exceed
  refundable balance.
* Applied Credit Note affects settlement totals through auditable
  reconciliation.
* Refund is not Usage correction and does not change Entitlement.
* Reason is required before issuance.
* Metadata cannot replace provider refund identity or audit fields when those
  become required by approved policy.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner matching Invoice. |
| invoice_id | BIGINT UNSIGNED NOT NULL | Original Invoice. |
| payment_id | BIGINT UNSIGNED NULL | Optional refunded Payment. |
| credit_number | VARCHAR(100) NOT NULL | Official immutable Credit Note number. |
| reason | TEXT NOT NULL | Adjustment/refund reason. |
| amount | DECIMAL(20,4) NOT NULL | Credit/refund amount in Invoice currency. |
| credit_status | VARCHAR(50) NOT NULL DEFAULT 'draft' | Credit Note lifecycle. |
| issued_at | TIMESTAMP NULL | Official issuance time. |
| metadata | JSON NULL | Non-canonical adjustment context. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Controlled lifecycle update time. |

## Indexes

```sql
PRIMARY KEY (id);
UNIQUE (credit_number);
INDEX (customer_id);
INDEX (invoice_id);
INDEX (payment_id);
INDEX (credit_status);
INDEX (customer_id, credit_status);
```

## Sample Data

`id=54001, customer_id=1, invoice_id=50001, payment_id=52001, credit_number=CN-2026-000001, reason=Approved service credit, amount=20.0000, credit_status=issued, issued_at=2026-07-03T04:00:00Z`

## Design Notes

Credit Note currency inherits Invoice because the owner-provided Foundation
field set has no separate currency field. Provider refund transaction identity,
application ordering, partial refund and financial audit fields require owner
approval before migration.

Financial Immutability Principle: an issued Credit Note is immutable Financial
Evidence and the Foundation's only adjustment document. Further correction
requires a new Financial Document or approved reconciliation; never rewrite
the original Credit Note or Invoice.
