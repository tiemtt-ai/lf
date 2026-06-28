# Table Name

`saas_invoice_items`

## Purpose

Immutable line-item snapshot describing each charge in an Invoice.

## Relationships

Item belongs to exactly one Invoice. Optional `source_type + source_id` may
reference an approved Usage Summary, Subscription Item or other Billing input.

## Business Rules

* Every Item belongs to one `customer_id` matching its Invoice.
* Invoice Item belongs to exactly one Invoice.
* Allowed foundation `item_type` examples: `subscription`, `usage`, `add_on`,
  `manual_charge`.
* `item_key` is stable lowercase `snake_case`.
* `quantity`, `unit_price` and `amount` use the Invoice currency.
* `amount` equals rounded `quantity × unit_price` under Invoice policy.
* Item is immutable after Invoice issuance.
* Source generic reference must resolve within the same tenant.
* Item may snapshot Usage Summary or Subscription Item input.
* Item is not Source Of Truth for Usage, Subscription or Entitlement.
* Metadata cannot hide canonical source identity or amount.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner matching Invoice. |
| invoice_id | BIGINT UNSIGNED NOT NULL | Parent Invoice. |
| item_type | VARCHAR(50) NOT NULL | Billing line classification. |
| item_key | VARCHAR(100) NOT NULL | Stable line-item key. |
| description | VARCHAR(500) NOT NULL | Human-readable line description snapshot. |
| quantity | DECIMAL(20,6) NOT NULL | Billed quantity. |
| unit_price | DECIMAL(20,6) NOT NULL | Price per unit. |
| amount | DECIMAL(20,4) NOT NULL | Rounded line total. |
| source_type | VARCHAR(100) NULL | Optional source type. |
| source_id | BIGINT UNSIGNED NULL | Optional source record ID. |
| metadata | JSON NULL | Non-canonical presentation/provenance context. |
| created_at | TIMESTAMP NULL | Created time. |

## Indexes

```sql
PRIMARY KEY (id);
INDEX (customer_id);
INDEX (invoice_id);
INDEX (item_type);
INDEX (item_key);
INDEX (customer_id, source_type, source_id);
```

## Sample Data

`id=51001, customer_id=1, invoice_id=50001, item_type=usage, item_key=ai_tokens, description=AI token usage June 2026, quantity=2500000, unit_price=0.000040, amount=100.0000, source_type=usage_summary, source_id=30001, created_at=2026-06-28T00:00:00Z`

## Design Notes

`customer_id` is explicit for tenant isolation. `source_type/source_id` are
included because the required Usage Summary/Subscription Item relationship
must not be hidden in metadata. Source taxonomy and snapshot evidence required
for financial audit need owner approval.

Financial Immutability Principle: an Item becomes immutable Financial Evidence
when its Invoice is issued. Correction must not rewrite the Item; use a Credit
Note, approved refund, new Financial Document or approved reconciliation that
preserves the original snapshot.
