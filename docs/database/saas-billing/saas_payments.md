# Table: saas_payments

Document Path: database/saas-billing/saas_payments.md

## Purpose

Stores Customer Payment transactions and reconciliation lifecycle.

## Relationships

Payment belongs to one Invoice and Customer; it may use one Customer Payment
Method. One Invoice may have multiple Payments.

## Business Rules

* Every Payment belongs to one `customer_id` matching its Invoice.
* Payment Method, when present, belongs to the same Customer.
* Allowed `payment_status`: `pending`, `authorized`, `succeeded`, `failed`,
  `cancelled`, `refunded`.
* `(payment_provider, provider_transaction_id)` is unique.
* Provider transaction identity is immutable.
* Provider history must not be rewritten.
* Payment currency must match Invoice currency.
* Payment amount is positive and follows currency rounding policy.
* A succeeded Payment contributes to Invoice settlement.
* Refunded status requires an applied Credit Note/refund policy.
* Payment does not update Subscription or Entitlement directly.
* Metadata cannot contain provider secret or raw payment credential.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| invoice_id | BIGINT UNSIGNED NOT NULL | Parent Invoice. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| payment_method_id | BIGINT UNSIGNED NULL | Optional Customer Payment Method. |
| payment_provider | VARCHAR(100) NOT NULL | Provider code. |
| provider_transaction_id | VARCHAR(191) NOT NULL | Immutable provider transaction ID. |
| currency | CHAR(3) NOT NULL | ISO 4217 currency code. |
| amount | DECIMAL(20,4) NOT NULL | Payment amount. |
| payment_status | VARCHAR(50) NOT NULL DEFAULT 'pending' | Payment lifecycle. |
| paid_at | TIMESTAMP NULL | Successful settlement time. |
| metadata | JSON NULL | Safe provider/Billing context. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Controlled lifecycle update time. |

## Indexes

```sql
PRIMARY KEY (id);
INDEX (invoice_id);
INDEX (customer_id);
UNIQUE (payment_provider, provider_transaction_id);
INDEX (payment_status);
INDEX (customer_id, payment_status);
```

## Sample Data

`id=52001, invoice_id=50001, customer_id=1, payment_method_id=53001, payment_provider=stripe, provider_transaction_id=pi_3LF0001, currency=USD, amount=121.0000, payment_status=succeeded, paid_at=2026-07-02T03:20:00Z`

## Design Notes

Webhook idempotency, authorization/capture, partial allocation, overpayment and
immutable provider-event audit need owner approval. One Payment row alone
cannot be treated as the complete provider event history.

Financial Immutability Principle: successful Payment and provider history are
Financial Evidence. Do not rewrite amount, provider identity or original
success history. Refund/reconciliation must use a Credit Note and preserve the
Financial Audit Trail.
