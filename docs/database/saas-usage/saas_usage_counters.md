# Table: saas_usage_counters

## Purpose

Current accumulated Usage projection by Customer, feature, period and unit.

## Relationships

Counter belongs to one Customer and is derived exclusively from
`saas_usage_events`.

## Business Rules

* Every Counter belongs to one `customer_id`.
* Allowed `period_type`: `daily`, `monthly`, `yearly`, `lifetime`.
* `(customer_id, feature_key, period_type, period_key, unit)` is unique.
* Counter is derived and rebuildable from Usage Events.
* Counter is not Source Of Truth.
* Business/source Domain and Billing cannot update Counter directly.
* Counter stores consumed quantity, not allowed Entitlement value.
* `period_key` must follow the approved timezone/format contract.
* `usage_quantity` must be recalculated when an included late/correction event
  arrives.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| feature_key | VARCHAR(100) NOT NULL | Aggregated feature identifier. |
| period_type | VARCHAR(50) NOT NULL | Daily, monthly, yearly or lifetime period. |
| period_key | VARCHAR(50) NOT NULL | Canonical period identifier. |
| usage_quantity | DECIMAL(20,6) NOT NULL DEFAULT 0 | Accumulated consumed quantity. |
| unit | VARCHAR(50) NOT NULL | Unit matching source metric contract. |
| updated_at | TIMESTAMP NOT NULL | Last projection update/rebuild time. |

## Indexes

```sql
PRIMARY KEY (id);
UNIQUE (customer_id, feature_key, period_type, period_key, unit);
INDEX (customer_id, period_type, period_key);
INDEX (customer_id, feature_key);
INDEX (updated_at);
```

## Sample Data

`id=20001, customer_id=1, feature_key=ai_tutor, period_type=monthly, period_key=2026-06, usage_quantity=2500000, unit=token, updated_at=2026-06-28T08:20:00Z`

## Design Notes

Counter update requires concurrency-safe projection logic. Timezone,
late-arrival window and full/incremental rebuild strategy require owner
approval. A cache may accelerate reads but cannot become another Source Of
Truth.
