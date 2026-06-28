# Table Name

`ai_feedback`

## Purpose

User/reviewer feedback on AI Message, Recommendation, Insight or Model Run.

## Relationships

Feedback belongs to User and generic `target_type + target_id`; optional Model
Run provenance.

## Business Rules

* Allowed `target_type`: `message`, `recommendation`, `insight`, `model_run`.
* Target must exist in same tenant.
* Allowed `feedback_type`: `rating`, `helpful`, `correction`, `safety`,
  `quality`.
* `rating` when present is 1–5.
* Feedback does not mutate historical AI output or source business state.
* Correction feedback is evaluation input, not automatic truth.
* Allowed `status`: `submitted`, `reviewed`, `resolved`, `dismissed`.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| user_id | BIGINT UNSIGNED NOT NULL | Feedback author. |
| target_type | VARCHAR(50) NOT NULL | AI target type. |
| target_id | BIGINT UNSIGNED NOT NULL | AI target record ID. |
| model_run_id | BIGINT UNSIGNED NULL | Optional related Model Run. |
| feedback_type | VARCHAR(50) NOT NULL | Feedback category. |
| rating | TINYINT UNSIGNED NULL | Rating 1–5. |
| label | VARCHAR(100) NULL | Stable feedback label. |
| comment | TEXT NULL | Reviewer/user explanation. |
| status | VARCHAR(50) NOT NULL DEFAULT 'submitted' | Review lifecycle. |
| metadata | JSON NULL | Evaluation context. |
| created_at | TIMESTAMP NULL | Submitted time. |
| updated_at | TIMESTAMP NULL | Review lifecycle update. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, user_id, created_at);
INDEX (customer_id, target_type, target_id);
INDEX (customer_id, model_run_id);
INDEX (customer_id, feedback_type, status);
```

## Sample Data

`id=600, customer_id=1, user_id=100, target_type=message, target_id=101, model_run_id=500, feedback_type=helpful, rating=5, label=clear_explanation, status=submitted`

## Design Notes

Deduplication, moderation and feedback-to-evaluation pipeline remain open.
Feedback is not training consent by default.
