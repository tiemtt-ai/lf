# Table: ai_recommendations

Document Path: database/ai/ai_recommendations.md

## Purpose

AI-owned decision-support recommendation with target, evidence and provenance.

## Relationships

Recommendation belongs to User and optional learning context/Model Run.
`target_type + target_id` generically identifies suggested target.

## Business Rules

* Recommendation never directly enrolls, completes, grades, issues or pays.
* Owner Domain/User decides whether and how to act.
* Allowed `status`: `generated`, `shown`, `accepted`, `dismissed`, `expired`.
* `accepted` records AI interaction only, not target business action.
* Confidence/score are advisory values from 0–1.
* Evidence snapshot must not become source business state.
* Expired recommendation cannot be treated as current.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| recommendation_uuid | CHAR(36) NOT NULL | Stable output identity. |
| user_id | BIGINT UNSIGNED NOT NULL | Recommendation subject. |
| model_run_id | BIGINT UNSIGNED NOT NULL | Generating Model Run. |
| enrollment_id | BIGINT UNSIGNED NULL | Optional learning cycle. |
| product_id | BIGINT UNSIGNED NULL | Optional Product. |
| version_activity_id | BIGINT UNSIGNED NULL | Optional Activity context. |
| recommendation_type | VARCHAR(100) NOT NULL | Recommendation category. |
| target_type | VARCHAR(100) NOT NULL | Generic target type. |
| target_id | BIGINT UNSIGNED NOT NULL | Generic target ID. |
| title | VARCHAR(255) NOT NULL | Display title. |
| rationale | TEXT NOT NULL | Explainable reason. |
| confidence_score | DECIMAL(5,4) NULL | Advisory confidence 0–1. |
| evidence_snapshot | JSON NOT NULL | Inputs/provenance snapshot. |
| status | VARCHAR(50) NOT NULL DEFAULT 'generated' | Output lifecycle. |
| valid_from | TIMESTAMP NULL | Validity start. |
| expires_at | TIMESTAMP NULL | Expiration time. |
| metadata | JSON NULL | Ranking/presentation metadata. |
| created_at | TIMESTAMP NULL | Generated time. |
| updated_at | TIMESTAMP NULL | Interaction lifecycle time. |

## Indexes

```sql
UNIQUE (customer_id, recommendation_uuid);
INDEX (customer_id, user_id, status, created_at);
INDEX (customer_id, model_run_id);
INDEX (customer_id, target_type, target_id);
INDEX (customer_id, recommendation_type);
INDEX (customer_id, expires_at);
```

## Sample Data

`id=300, customer_id=1, recommendation_uuid=0191-rec-0300, user_id=100, model_run_id=500, enrollment_id=501, recommendation_type=review_activity, target_type=version_activity, target_id=9001, title=Ôn lại bài nghe, rationale=Replay ratio cao và quiz retry tăng, confidence_score=0.8400, status=generated`

## Design Notes

Target validation/authorization belongs to caller/Owner Domain. Recommendation
expiration, ranking and action-request contract remain open.
