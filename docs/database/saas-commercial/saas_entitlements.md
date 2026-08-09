# Table: saas_entitlements

Document Path: database/saas-commercial/saas_entitlements.md

## Purpose

Source Of Truth for a Customer's effective right to use a feature — “Can Use?”.

## Relationships

Entitlement belongs to one Customer. `source_type + source_id` identifies the
Commercial source that produced the effective right.

## Business Rules

* Every Entitlement belongs to one `customer_id`.
* `feature_key` is stable lowercase `snake_case`.
* Allowed `entitlement_type`: `boolean`, `integer`, `decimal`, `string`,
  `unlimited`.
* `entitlement_value` must conform to `entitlement_type`; it is NULL for
  `unlimited`.
* Allowed Foundation `source_type`: `plan_feature`, `subscription_item`.
* Allowed `status`: `active`, `inactive`, `expired`, `revoked`.
* At one instant, only one effective Entitlement may exist for each
  `customer_id + feature_key`.
* `effective_from` must precede `effective_to` when an end exists.
* Generic source reference must resolve to an approved Commercial source in
  the same Customer context, except global Plan Feature. Manual override is not
  an approved Foundation source.
* Usage and Billing may read Entitlement but cannot update it.
* AI, Course and other consumer Domains cannot update this table.
* Entitlement never stores current Usage, Invoice or Payment state.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| feature_key | VARCHAR(100) NOT NULL | Effective feature identifier. |
| entitlement_type | VARCHAR(50) NOT NULL | Value interpretation. |
| entitlement_value | TEXT NULL | Serialized effective value by type. |
| source_type | VARCHAR(50) NOT NULL | Commercial source classification. |
| source_id | BIGINT UNSIGNED NOT NULL | Source record ID. |
| effective_from | TIMESTAMP NOT NULL | Effective-window start. |
| effective_to | TIMESTAMP NULL | Effective-window end. |
| status | VARCHAR(50) NOT NULL DEFAULT 'active' | Entitlement lifecycle. |
| metadata | JSON NULL | Resolution provenance without foreign state. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Lifecycle/resolution update time. |

## Indexes

```sql
PRIMARY KEY (id);
INDEX (customer_id);
INDEX (customer_id, feature_key, status);
INDEX (customer_id, feature_key, effective_from, effective_to);
INDEX (source_type, source_id);
INDEX (effective_to);
```

## Sample Data

`id=5001, customer_id=1, feature_key=storage_gb, entitlement_type=integer, entitlement_value=200, source_type=plan_feature, source_id=31, effective_from=2026-06-28T00:00:00Z, status=active`

## Design Notes

Temporal uniqueness cannot be expressed by a basic unique index. Entitlement
resolution must close/revoke the previous effective row transactionally before
activating a replacement. Cache is derived and must not become another Source
Of Truth.
