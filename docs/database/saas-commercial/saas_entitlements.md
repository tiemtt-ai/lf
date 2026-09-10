# Table: saas_entitlements

Version: 1.1

Document Status: Review

Implementation Status: Not Implemented

Last Updated: 2026-09-10

Document Path: database/saas-commercial/saas_entitlements.md

## Owner Freeze — 2026-09-10 — held pending independent review

```text
Role: LearnForge Architecture Owner
Date: 2026-09-10
Decision: APPROVED AND FROZEN
Scope: saas_entitlements metered-entitlement schema in this version
```

**Held pending independent review — 2026-09-10.** Khối trên giữ nguyên làm lịch
sử quyết định của Owner. Nhưng `docs/README.md` xếp vòng đời `Draft → Review →
Approved → Frozen`, và AGENTS.md xếp `Review → Freeze → Migration`: chữ ký này
được ghi **trước** khi có Architecture Review độc lập PASS, nên nhãn `Frozen` sẽ
khiến người đọc sau tin rằng điều kiện "Database Docs approved" của AGENTS.md §
Database Rule đã đạt. Tài liệu vì thế trở lại `Review` cho tới khi có PASS thật,
rồi Owner phê duyệt lại.

Implementation remains `Not Implemented`. Freeze does not itself authorize or
apply a migration without the required Architecture Review PASS.

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
* A metered entitlement uses `integer`, `decimal` or `unlimited` and snapshots
  its unit, period type and IANA timezone. Boolean/string entitlements have no
  quota fields.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant owner. |
| feature_key | VARCHAR(100) NOT NULL | Effective feature identifier. |
| entitlement_type | VARCHAR(50) NOT NULL | Value interpretation. |
| entitlement_value | TEXT NULL | Serialized effective value by type. |
| quota_unit | VARCHAR(50) NULL | Approved metric unit for metered entitlement. |
| quota_period_type | VARCHAR(50) NULL | `daily`, `monthly`, `yearly`, `lifetime`. |
| quota_timezone | VARCHAR(64) NULL | IANA timezone used for period boundaries. |
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
UNIQUE (id, customer_id);
CHECK ((entitlement_type IN ('integer','decimal','unlimited') AND
        quota_unit IS NOT NULL AND quota_period_type IS NOT NULL AND
        quota_timezone IS NOT NULL) OR
       (entitlement_type IN ('boolean','string') AND quota_unit IS NULL AND
        quota_period_type IS NULL AND quota_timezone IS NULL));
```

## Sample Data

`id=5001, customer_id=1, feature_key=storage_gb, entitlement_type=integer, entitlement_value=200, source_type=plan_feature, source_id=31, effective_from=2026-06-28T00:00:00Z, status=active`

## Design Notes

Temporal uniqueness cannot be expressed by a basic unique index. Entitlement
resolution must close/revoke the previous effective row transactionally before
activating a replacement. Cache is derived and must not become another Source
Of Truth.
