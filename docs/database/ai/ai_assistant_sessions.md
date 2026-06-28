# Table Name

`ai_assistant_sessions`

## Purpose

Runtime orchestration session cho một AI role; không phải login session hoặc
Track Learning Session.

## Relationships

Mỗi Assistant Session thuộc Conversation/User, optional Prompt Template and
learning context; `Assistant Session 1 → N Model Runs`.

## Business Rules

* Allowed `assistant_role`: `tutor`, `teaching_assistant`, `recommendation`,
  `insight_engine`, `dashboard_explanation`, `authoring_assistant`.
* Mỗi Assistant Session phải dùng approved Prompt Template.
* `context_snapshot` records allowed context at session start; not source state.
* Session cannot update Progress, Attendance, Result, Certificate or Payment.
* Allowed `status`: `active`, `completed`, `expired`, `cancelled`, `failed`.
* End time must follow start time.
* Context and Prompt Template must belong to allowed tenant/global scope.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| session_uuid | CHAR(36) NOT NULL | Stable assistant session ID. |
| conversation_id | BIGINT UNSIGNED NULL | Optional Conversation. |
| user_id | BIGINT UNSIGNED NOT NULL | Session actor. |
| prompt_template_id | BIGINT UNSIGNED NOT NULL | Approved prompt reference. |
| assistant_role | VARCHAR(50) NOT NULL | AI role. |
| product_id | BIGINT UNSIGNED NULL | Optional Product. |
| enrollment_id | BIGINT UNSIGNED NULL | Optional learning cycle. |
| template_version_id | BIGINT UNSIGNED NULL | Optional Version context. |
| version_activity_id | BIGINT UNSIGNED NULL | Optional Activity context. |
| context_snapshot | JSON NOT NULL | Authorized runtime context snapshot. |
| status | VARCHAR(50) NOT NULL DEFAULT 'active' | Session lifecycle. |
| started_at | TIMESTAMP NOT NULL | Start time. |
| ended_at | TIMESTAMP NULL | End time. |
| metadata | JSON NULL | Orchestration metadata. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Lifecycle update time. |

## Indexes

```sql
UNIQUE (customer_id, session_uuid);
INDEX (customer_id, user_id, started_at);
INDEX (customer_id, conversation_id);
INDEX (customer_id, assistant_role, status);
INDEX (customer_id, enrollment_id);
```

## Sample Data

`id=200, customer_id=1, session_uuid=0191-session-0200, conversation_id=100, user_id=100, prompt_template_id=900, assistant_role=tutor, enrollment_id=501, template_version_id=30, context_snapshot={"version_activity_id":9001}, status=active, started_at=2026-06-28T02:00:00Z`

## Design Notes

Session timeout, context refresh and tool permissions remain owner-review
questions.
