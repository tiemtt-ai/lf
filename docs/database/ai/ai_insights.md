# Table: ai_insights

Document Path: database/ai/ai_insights.md

## Purpose

Explainable AI observation/insight for learner, teacher or dashboard decision
support.

## Relationships

Insight belongs to optional User and generic `scope_type + scope_id`; generated
by Model Run and may receive Feedback.

## Business Rules

* AI Insight is source of truth only for the AI-generated statement.
* Insight does not replace Track Summary, Assessment Result or Course state.
* Allowed `insight_type`: `learner`, `teacher`, `course`, `risk`,
  `dashboard_explanation`.
* Allowed `status`: `generated`, `published`, `acknowledged`, `expired`,
  `archived`.
* Severity/confidence are advisory.
* Evidence snapshot and model provenance required.
* Publication/acknowledgement does not execute a business action.
* **Proposed, chưa có hiệu lực** (xem ADR-0006 Amendment Version 1.1 — cần
  Owner Approval và Learning Phase 4): khi input dùng Learning Mastery
  Profile, `evidence_snapshot` phải chứa tối thiểu `customer_id`, `user_id`,
  `node_definition_id`, `basis_framework_version_id`,
  `current_calculation_id`, `projected_at`. Không thêm cột mới cho quan hệ
  này.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| insight_uuid | CHAR(36) NOT NULL | Stable output identity. |
| user_id | BIGINT UNSIGNED NULL | Optional insight subject. |
| model_run_id | BIGINT UNSIGNED NOT NULL | Generating Model Run. |
| scope_type | VARCHAR(100) NOT NULL | Generic insight scope. |
| scope_id | BIGINT UNSIGNED NOT NULL | Generic scope record ID. |
| insight_type | VARCHAR(100) NOT NULL | Insight category. |
| title | VARCHAR(255) NOT NULL | Display title. |
| summary | TEXT NOT NULL | Explainable insight. |
| severity | VARCHAR(30) NULL | Advisory severity. |
| confidence_score | DECIMAL(5,4) NULL | Advisory confidence 0–1. |
| evidence_snapshot | JSON NOT NULL | Inputs/citations/provenance. |
| status | VARCHAR(50) NOT NULL DEFAULT 'generated' | Insight lifecycle. |
| observed_at | TIMESTAMP NOT NULL | Observation time. |
| expires_at | TIMESTAMP NULL | Expiration time. |
| metadata | JSON NULL | Presentation/analysis metadata. |
| created_at | TIMESTAMP NULL | Generated time. |
| updated_at | TIMESTAMP NULL | Lifecycle update time. |

## Indexes

```sql
UNIQUE (customer_id, insight_uuid);
INDEX (customer_id, user_id, status);
INDEX (customer_id, model_run_id);
INDEX (customer_id, scope_type, scope_id);
INDEX (customer_id, insight_type, observed_at);
INDEX (customer_id, expires_at);
```

## Sample Data

`id=400, customer_id=1, insight_uuid=0191-insight-0400, user_id=100, model_run_id=501, scope_type=enrollment, scope_id=501, insight_type=learner, title=Nhịp học chưa đều, summary=Hoạt động tập trung vào cuối tuần, severity=medium, confidence_score=0.7900, status=published, observed_at=2026-06-28T03:00:00Z`

## Design Notes

Risk insight must not silently become intervention or eligibility decision.
Human escalation and expiry policy require owner review.
