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
* `UNIQUE (customer_id, active_slot)` enforces the common half of that rule
  physically: at most one `active` row with an open end (`effective_to IS NULL`)
  per feature. Rows that are closed or inactive fall into the per-`id` branch of
  the generated column and never collide.
* It does **not** cover overlapping closed windows — MariaDB has no EXCLUDE
  constraint — so resolution must still close or revoke the previous row inside
  the same transaction. The guard removes the failure mode that matters most:
  two resolution jobs racing and both leaving an open-ended `active` row, which
  would give `saas_usage_reservations` two different rows to lock and therefore
  no serialization point at all.
* `effective_from` must precede `effective_to` when an end exists.
* Cả hai mốc là `DATETIME(6)`, **không** phải `TIMESTAMP`. `effective_from` sẽ
  là cột TIMESTAMP NOT NULL đầu tiên của bảng; MariaDB tự gắn `DEFAULT
  CURRENT_TIMESTAMP` **và** `ON UPDATE CURRENT_TIMESTAMP` cho cột như vậy —
  nhưng chỉ khi `explicit_defaults_for_timestamp` tắt. Đo ngày 2026-09-10:
  server deployment (MariaDB 10.4.21) đặt `explicit_defaults_for_timestamp =
  0`, còn một bản MariaDB 11.4.12 cài mặc định đặt `= 1`. Nghĩa là **cùng một
  migration sinh ra hai schema khác nhau** tùy nơi chạy, và `schema:drift
  --fresh` xanh trên chính server nó vừa dựng nên không nhìn thấy khác biệt đó.
  Khai kiểu/default tường minh làm schema độc lập với biến cấu hình này.
* Nếu bẫy đó kích hoạt, mọi `UPDATE` lên hàng — kể cả lần đóng entitlement bằng
  `status='expired'` — âm thầm ghi đè điểm bắt đầu hiệu lực, phá cửa sổ thời gian
  mà `INDEX (customer_id, feature_key, effective_from, effective_to)` dùng để
  resolve, và khiến một reservation đã cấp trông như được cấp trước khi
  entitlement có hiệu lực. Nó đã xảy ra thật trong repo trên các cột occurrence
  khác: xem
  `2026_08_09_050000_remove_implicit_timestamp_on_update_from_occurrence_columns`.
* `DATETIME(6)` còn bỏ trần 2038 của `TIMESTAMP`, vốn chặn entitlement dài hạn, và
  khớp precision với `saas_usage_reservations.period_start_at`, nơi hai giá trị
  được so sánh ở đúng biên period.
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
| effective_from | DATETIME(6) NOT NULL | Effective-window start. |
| effective_to | DATETIME(6) NULL | Effective-window end. |
| status | VARCHAR(50) NOT NULL DEFAULT 'active' | Entitlement lifecycle. |
| active_slot | VARCHAR(150) AS (CASE WHEN status='active' AND effective_to IS NULL THEN CONCAT(feature_key,':current') ELSE CONCAT(feature_key,':',id) END) STORED | Physical guard for one open-ended active Entitlement per feature. |
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
UNIQUE (customer_id, active_slot);
CHECK ((entitlement_type IN ('integer','decimal','unlimited') AND
        quota_unit IS NOT NULL AND quota_period_type IS NOT NULL AND
        quota_timezone IS NOT NULL) OR
       (entitlement_type IN ('boolean','string') AND quota_unit IS NULL AND
        quota_period_type IS NULL AND quota_timezone IS NULL));
```

## Sample Data

`id=5001, customer_id=1, feature_key=storage_gb, entitlement_type=integer, entitlement_value=200, source_type=plan_feature, source_id=31, effective_from=2026-06-28T00:00:00Z, status=active`

## Design Notes

Temporal uniqueness cannot be expressed **in full** by a unique index: overlapping
closed windows need range exclusion, which MariaDB lacks. The open-ended case is
expressible and is enforced by `UNIQUE (customer_id, active_slot)`. Entitlement
resolution must still close or revoke the previous effective row transactionally
before activating a replacement — the index narrows the window, it does not
replace the transaction. Cache is derived and must not become another Source
Of Truth.
