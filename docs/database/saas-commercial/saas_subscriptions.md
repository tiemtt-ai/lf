# Table: saas_subscriptions

## Purpose

Records which Commercial Plan a Customer uses and preserves Subscription
lifecycle history.

## Relationships

Subscription belongs to one Customer from Tenant Domain and one global Plan;
`Subscription 1 → N Subscription Items`.

## Business Rules

* Every Subscription belongs to exactly one `customer_id`.
* Customer and Plan must exist and be allowed for the subscription flow.
* Allowed `status`: `trial`, `active`, `suspended`, `expired`, `cancelled`.
* A Customer may have many historical Subscriptions.
* Only one Subscription may be `active` for a Customer at a time.
* `starts_at` must precede `ends_at` and `renews_at` when those values exist.
* `cancelled_at` is required when status becomes `cancelled`.
* Subscription does not create Invoice, Payment or Usage.
* Subscription is an input to Entitlement resolution; it is not itself the
  final “Can Use?” decision.
* Metadata cannot store price, current Usage or canonical Entitlement.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| plan_id | BIGINT UNSIGNED NOT NULL | Selected global Plan. |
| status | VARCHAR(50) NOT NULL | Subscription lifecycle. |
| starts_at | TIMESTAMP NOT NULL | Effective lifecycle start. |
| ends_at | TIMESTAMP NULL | Scheduled/actual end. |
| renews_at | TIMESTAMP NULL | Next renewal boundary when applicable. |
| cancelled_at | TIMESTAMP NULL | Cancellation time. |
| metadata | JSON NULL | Non-canonical lifecycle context. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Lifecycle update time. |

## Indexes

```sql
PRIMARY KEY (id);
INDEX (customer_id);
INDEX (plan_id);
INDEX (customer_id, status);
INDEX (customer_id, starts_at);
INDEX (renews_at);
```

## Sample Data

`id=101, customer_id=1, plan_id=3, status=active, starts_at=2026-06-28T00:00:00Z, renews_at=2026-07-28T00:00:00Z`

## Design Notes

Portable MySQL partial uniqueness for one active row is not assumed. The
enforcement strategy—transactional lock or generated active slot—must be
approved before migration. Billing cycle pricing remains Billing-owned.
