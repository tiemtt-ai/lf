# Table Name

`ai_conversations`

## Purpose

Conversation container giữa User và một AI role trong tenant/learning context.

## Relationships

`User 1 → N Conversations`; `Conversation 1 → N Messages / Assistant Sessions`;
optional Course Product/Enrollment/Version context.

## Business Rules

* Conversation tenant-scoped; user/context references phải cùng tenant.
* Allowed `conversation_type`: `tutor`, `teaching_assistant`,
  `dashboard_explanation`, `authoring_assistant`, `support`.
* Enrolled context dùng Version IDs, không working Template IDs.
* Allowed `status`: `active`, `closed`, `archived`.
* Conversation state không phải Course Progress hoặc business approval.
* Retention, export and redaction follow privacy policy.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| conversation_uuid | CHAR(36) NOT NULL | External/stable conversation ID. |
| user_id | BIGINT UNSIGNED NOT NULL | Conversation owner. |
| conversation_type | VARCHAR(50) NOT NULL | AI experience type. |
| title | VARCHAR(255) NULL | Display title. |
| product_id | BIGINT UNSIGNED NULL | Optional Product context. |
| enrollment_id | BIGINT UNSIGNED NULL | Optional learning cycle. |
| template_version_id | BIGINT UNSIGNED NULL | Optional published Version. |
| version_activity_id | BIGINT UNSIGNED NULL | Optional Version Activity. |
| status | VARCHAR(50) NOT NULL DEFAULT 'active' | Conversation lifecycle. |
| started_at | TIMESTAMP NOT NULL | Start time. |
| last_message_at | TIMESTAMP NULL | Latest message time. |
| closed_at | TIMESTAMP NULL | Close time. |
| metadata | JSON NULL | UI/context metadata without canonical state. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Lifecycle update time. |

## Indexes

```sql
UNIQUE (customer_id, conversation_uuid);
INDEX (customer_id, user_id, started_at);
INDEX (customer_id, enrollment_id);
INDEX (customer_id, status);
INDEX (customer_id, last_message_at);
```

## Sample Data

`id=100, customer_id=1, conversation_uuid=0191-conv-0100, user_id=100, conversation_type=tutor, product_id=10, enrollment_id=501, template_version_id=30, version_activity_id=9001, status=active, started_at=2026-06-28T02:00:00Z`

## Design Notes

Conversation title/history is AI-owned interaction data. It cannot be used as
canonical evidence of Course completion or Assessment result.
