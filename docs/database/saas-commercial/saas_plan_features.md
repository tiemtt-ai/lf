# Table: saas_plan_features

Document Path: database/saas-commercial/saas_plan_features.md

## Purpose

Defines feature defaults and limits included in a Commercial Plan.

## Relationships

`Plan 1 → N Plan Features`; `plan_id` references `saas_plans.id`.

## Business Rules

* Plan Feature thuộc Global Catalog Exception, dùng chung toàn platform và
  không có `customer_id`.
* Plan Feature không chứa Customer-specific business state.
* Exception này không cho phép bất kỳ business table nào khác bỏ
  `customer_id`.
* Every Plan Feature belongs to one global Plan.
* `(plan_id, feature_key)` is unique.
* `feature_key` is stable lowercase `snake_case`.
* Allowed `limit_type`: `boolean`, `integer`, `decimal`, `unlimited`.
* `limit_value` is NULL for `unlimited`; it is required for limited types.
* Plan Feature stores allowed defaults, never current Usage.
* Plan Feature is not the final Customer Entitlement.
* Feature unit/meaning must be stable in the `feature_key` contract.
* Catalog change does not silently rewrite existing effective Entitlements.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| plan_id | BIGINT UNSIGNED NOT NULL | Parent global Plan. |
| feature_key | VARCHAR(100) NOT NULL | Stable feature identifier. |
| feature_name | VARCHAR(255) NOT NULL | Human-readable feature name. |
| limit_type | VARCHAR(50) NOT NULL | Value interpretation. |
| limit_value | DECIMAL(20,4) NULL | Included limit when applicable. |
| metadata | JSON NULL | Non-canonical feature presentation/configuration. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Last catalog update time. |

## Indexes

```sql
PRIMARY KEY (id);
UNIQUE (plan_id, feature_key);
INDEX (feature_key);
INDEX (plan_id);
```

## Sample Data

`id=31, plan_id=3, feature_key=storage_gb, feature_name=Storage, limit_type=integer, limit_value=200`

## Design Notes

This table and `saas_plans` are the only Global Catalog Exception approved by
SaaS Commercial Foundation. The exception is not precedent for other Domains
or tables. If a feature requires non-numeric configuration, that contract
needs approval rather than hiding canonical values in metadata.
