# Table: saas_subscription_items

## Purpose

Stores add-ons or packages composing a Customer Subscription.

## Relationships

Every Item belongs to one Subscription. `customer_id` must match the parent
Subscription owner.

## Business Rules

* Every Item is tenant-scoped by `customer_id`.
* Parent Subscription must belong to the same Customer.
* `item_type` and `item_key` use stable lowercase `snake_case`.
* `quantity` must be greater than zero.
* `(subscription_id, item_type, item_key)` is unique.
* Item can affect Entitlement resolution.
* Item does not store Usage.
* Item is not an Invoice line, charge or Payment record.
* Metadata cannot replace quantity or canonical item identity.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner, matching Subscription. |
| subscription_id | BIGINT UNSIGNED NOT NULL | Parent Subscription. |
| item_type | VARCHAR(50) NOT NULL | Item classification. |
| item_key | VARCHAR(100) NOT NULL | Stable add-on/package key. |
| quantity | DECIMAL(20,4) NOT NULL DEFAULT 1 | Commercial quantity. |
| metadata | JSON NULL | Non-canonical item context. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Last item update time. |

## Indexes

```sql
PRIMARY KEY (id);
INDEX (customer_id);
INDEX (subscription_id);
UNIQUE (subscription_id, item_type, item_key);
INDEX (customer_id, item_key);
```

## Sample Data

`id=1001, customer_id=1, subscription_id=101, item_type=add_on, item_key=extra_users, quantity=50`

## Design Notes

`customer_id` is deliberately stored to enforce direct tenant scoping and must
be validated against the parent Subscription. Item taxonomy and whether
quantity may be fractional require owner policy.
