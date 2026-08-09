# Table: track_feature_snapshots

Document Path: database/track/track_feature_snapshots.md

## Purpose

Historical snapshots của AI-ready Track features để phân tích trend theo thời
gian.

## Relationships

Snapshot có thể tham chiếu current `track_ai_features`; luôn thuộc
Customer/User và optional Enrollment/Product context.

## Business Rules

* Append-only khi có thể; không update snapshot để viết lại trend history.
* Snapshot không thay thế current `track_ai_features`.
* Feature Snapshot là derived historical read model, không phải business state.
* `feature_key` và semantics phải khớp approved feature contract.
* `confidence_score` nếu có nằm trong 0–1.
* Snapshot date/window phải nhất quán với calculation timezone policy.
* AI/Analytics chỉ đọc snapshot trong tenant scope.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| ai_feature_id | BIGINT UNSIGNED NULL | Optional current feature lineage. |
| user_id | BIGINT UNSIGNED NOT NULL | User subject. |
| enrollment_id | BIGINT UNSIGNED NULL | Optional learning cycle. |
| product_id | BIGINT UNSIGNED NULL | Optional Product. |
| feature_scope | VARCHAR(50) NOT NULL | Feature scope. |
| feature_key | VARCHAR(100) NOT NULL | Stable feature identifier. |
| feature_value | VARCHAR(255) NOT NULL | Scalar snapshot value. |
| feature_value_json | JSON NULL | Complex snapshot value. |
| confidence_score | DECIMAL(5,4) NULL | Confidence from 0 to 1. |
| snapshot_date | DATE NOT NULL | Historical snapshot date. |
| source_window_start | TIMESTAMP NULL | Event window start. |
| source_window_end | TIMESTAMP NULL | Event window end. |
| metadata | JSON NULL | Formula/model/version provenance. |
| created_at | TIMESTAMP NULL | Append time. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, user_id, snapshot_date);
INDEX (customer_id, enrollment_id, snapshot_date);
INDEX (customer_id, product_id, snapshot_date);
INDEX (customer_id, feature_scope, feature_key, snapshot_date);
UNIQUE (customer_id, user_id, COALESCE(enrollment_id, 0), COALESCE(product_id, 0), feature_scope, feature_key, snapshot_date);
```

## Sample Data

`id=7001, customer_id=1, ai_feature_id=5001, user_id=100, enrollment_id=501, product_id=10, feature_scope=enrollment, feature_key=study_consistency, feature_value=0.82, confidence_score=0.9100, snapshot_date=2026-06-27, source_window_start=2026-06-20T00:00:00Z, source_window_end=2026-06-27T00:00:00Z`

## Design Notes

Snapshot cadence và retention vẫn cần owner policy. Nullable scopes được
normalize bằng reserved `0` trong logical index expression. A missing current
feature must not invalidate historical snapshots.
