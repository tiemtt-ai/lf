# Table: track_ai_features

Document Path: database/track/track_ai_features.md

## Purpose

Current AI-ready feature record theo User và optional
Enrollment/Product/Template Version context.

## Relationships

Mỗi Feature thuộc Customer/User context; được derive từ Track Events/Summaries;
`AI Feature 1 → N Feature Snapshots`.

## Business Rules

* Derived read model, có thể recalculate.
* AI tiêu thụ bảng này; AI không sở hữu hoặc cập nhật canonical Track feature.
* Feature không phải business state và không tự tạo Recommendation.
* Allowed `feature_scope`: `user`, `enrollment`, `product`, `activity`,
  `tenant`.
* `feature_key` ổn định và lowercase `snake_case`.
* Simple value được normalize trong `feature_value`; complex value dùng
  `feature_value_json`.
* `confidence_score` nếu có nằm trong 0–1.
* Source window và calculation contract phải audit được.
* `projection_version` định danh feature formula/projection.
* Công thức mới tạo versioned feature row; không overwrite feature version cũ
  hoặc sửa event history.
* Versioned features cho phép rebuild, compare và rollback.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| user_id | BIGINT UNSIGNED NOT NULL | User subject. |
| enrollment_id | BIGINT UNSIGNED NULL | Optional learning cycle. |
| product_id | BIGINT UNSIGNED NULL | Optional Product. |
| template_version_id | BIGINT UNSIGNED NULL | Optional published Version. |
| feature_scope | VARCHAR(50) NOT NULL | Scope classification. |
| feature_key | VARCHAR(100) NOT NULL | Stable feature identifier. |
| feature_value | VARCHAR(255) NOT NULL | Normalized scalar value. |
| feature_value_json | JSON NULL | Complex structured value. |
| confidence_score | DECIMAL(5,4) NULL | Confidence from 0 to 1. |
| projection_version | VARCHAR(50) NOT NULL | Stable feature projection version. |
| calculated_at | TIMESTAMP NOT NULL | Calculation time. |
| source_window_start | TIMESTAMP NULL | Event window start. |
| source_window_end | TIMESTAMP NULL | Event window end. |
| metadata | JSON NULL | Formula/model/version provenance. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Current feature refresh time. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, user_id);
INDEX (customer_id, enrollment_id);
INDEX (customer_id, product_id);
INDEX (customer_id, feature_scope, feature_key);
INDEX (customer_id, calculated_at);
UNIQUE (customer_id, user_id, COALESCE(enrollment_id, 0), COALESCE(product_id, 0), feature_scope, feature_key, projection_version);
```

## Sample Data

`id=5001, customer_id=1, user_id=100, enrollment_id=501, product_id=10, template_version_id=30, feature_scope=enrollment, feature_key=study_consistency, feature_value=0.82, confidence_score=0.9100, projection_version=v1, calculated_at=2026-06-27T03:00:00Z, source_window_start=2026-06-20T00:00:00Z, source_window_end=2026-06-27T00:00:00Z`

## Design Notes

Examples: `attention_score`, `completion_velocity`, `replay_ratio`,
`quiz_retry_rate`, `preferred_study_time`, `preferred_playback_speed`.
Feature lifecycle vẫn cần owner policy. Nullable scopes được normalize bằng
reserved `0` trong logical index expression.
