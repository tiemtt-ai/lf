# Table: saas_payment_methods

Document Path: database/saas-billing/saas_payment_methods.md

## Purpose

Stores safe provider references for Customer payment methods.

## Relationships

Payment Method belongs to one Customer and may be referenced by many Payments.

## Business Rules

* Every Payment Method belongs to one `customer_id`.
* Store provider reference only; never store full PAN/card number, CVV/CVC,
  raw bank credential or provider secret.
* Allowed `method_type`: `card`, `bank_transfer`, `virtual_account`, `paypal`,
  `manual`.
* Allowed `status`: `active`, `inactive`, `expired`, `revoked`.
* Customer may have many Payment Methods.
* Only one active default Payment Method is allowed per Customer.
* `(customer_id, provider, provider_payment_method_id)` is unique.
* `display_name` must be masked/safe for display.
* `is_default` does not replace active-status validation.
* Metadata cannot contain raw credential.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| provider | VARCHAR(100) NOT NULL | Payment provider code. |
| provider_payment_method_id | VARCHAR(191) NOT NULL | Provider-side method reference. |
| method_type | VARCHAR(50) NOT NULL | Payment method type. |
| display_name | VARCHAR(255) NULL | Masked safe display label. |
| is_default | BOOLEAN NOT NULL DEFAULT false | Customer default flag. |
| status | VARCHAR(50) NOT NULL DEFAULT 'active' | Method lifecycle. |
| metadata | JSON NULL | Safe non-secret method context. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Lifecycle/default update time. |

## Indexes

```sql
PRIMARY KEY (id);
INDEX (customer_id);
INDEX (provider);
INDEX (is_default);
UNIQUE (customer_id, provider, provider_payment_method_id);
INDEX (customer_id, is_default);
INDEX (customer_id, status);
```

## Sample Data

`id=53001, customer_id=1, provider=stripe, provider_payment_method_id=pm_1LF0001, method_type=card, display_name=Visa ending 4242, is_default=true, status=active`

## Design Notes

Portable MySQL partial uniqueness for one active default is not assumed.
Generated default slot or transactional enforcement must be approved before
migration. Provider token ownership, deletion and expiry synchronization also
need policy.
