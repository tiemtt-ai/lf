# Table Name

`saas_invoices`

## Purpose

Official Customer Invoice representing a Billing payment obligation.

## Relationships

Invoice belongs to one Customer and may reference one Commercial Subscription.
`Invoice 1 → N Invoice Items / Payments / Credit Notes`.

## Business Rules

* Every Invoice belongs to one `customer_id`.
* `subscription_id` is nullable and references Commercial read-only.
* `invoice_number` is globally unique and immutable after issuance.
* Allowed `invoice_status`: `draft`, `issued`, `partially_paid`, `paid`,
  `overdue`, `cancelled`, `void`.
* Invoice is Billing Source Of Truth for payment obligation and lifecycle.
* Draft financial content may change before issuance.
* After issuance, financial content and Invoice Items are immutable; only
  approved lifecycle/payment totals/timestamps may transition.
* `subtotal_amount - discount_amount + tax_amount = total_amount`.
* `amount_paid` and `amount_due` follow auditable Payment/Credit reconciliation.
* Currency is uppercase ISO 4217 and matches all Items/Payments.
* Invoice does not decide Entitlement or update Subscription.
* Invoice does not store Usage Event.
* Metadata cannot replace canonical amount, status or cross-domain reference.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| subscription_id | BIGINT UNSIGNED NULL | Optional Commercial Subscription reference. |
| invoice_number | VARCHAR(100) NOT NULL | Official immutable Invoice number. |
| currency | CHAR(3) NOT NULL | ISO 4217 currency code. |
| subtotal_amount | DECIMAL(20,4) NOT NULL DEFAULT 0 | Sum before discount/tax. |
| discount_amount | DECIMAL(20,4) NOT NULL DEFAULT 0 | Applied discount total. |
| tax_amount | DECIMAL(20,4) NOT NULL DEFAULT 0 | Applied tax total. |
| total_amount | DECIMAL(20,4) NOT NULL DEFAULT 0 | Official Invoice total. |
| amount_paid | DECIMAL(20,4) NOT NULL DEFAULT 0 | Reconciled settled amount. |
| amount_due | DECIMAL(20,4) NOT NULL DEFAULT 0 | Remaining payment obligation. |
| invoice_status | VARCHAR(50) NOT NULL DEFAULT 'draft' | Invoice lifecycle. |
| issued_at | TIMESTAMP NULL | Official issuance time. |
| due_at | TIMESTAMP NULL | Payment due time. |
| paid_at | TIMESTAMP NULL | Full settlement time. |
| metadata | JSON NULL | Non-canonical Billing context. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Controlled lifecycle update time. |

## Indexes

```sql
PRIMARY KEY (id);
UNIQUE (invoice_number);
INDEX (customer_id);
INDEX (subscription_id);
INDEX (invoice_status);
INDEX (due_at);
INDEX (customer_id, invoice_status);
```

## Sample Data

`id=50001, customer_id=1, subscription_id=101, invoice_number=LF-2026-000001, currency=USD, subtotal_amount=120.0000, discount_amount=10.0000, tax_amount=11.0000, total_amount=121.0000, amount_paid=0.0000, amount_due=121.0000, invoice_status=issued, issued_at=2026-06-28T00:00:00Z, due_at=2026-07-05T00:00:00Z`

## Design Notes

Invoice numbering concurrency, tenant/global display format, transition matrix,
rounding and payment/credit reconciliation require owner approval. `cancelled`
versus `void` semantics must be explicit before migration.

Financial Immutability Principle: an issued Invoice is Financial Evidence.
Do not directly edit issued financial content. Adjustment requires a Credit
Note, approved refund, new Financial Document or approved reconciliation that
preserves the original Financial Audit Trail.
