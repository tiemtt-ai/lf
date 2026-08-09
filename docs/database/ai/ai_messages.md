# Table: ai_messages

Document Path: database/ai/ai_messages.md

## Purpose

Ordered conversation messages for user, assistant, system and tool roles.

## Relationships

`Conversation 1 → N Messages`; optional parent Message and Model Run.

## Business Rules

* Message and Conversation must share tenant.
* Allowed `role`: `system`, `user`, `assistant`, `tool`.
* `sequence_no` unique within Conversation.
* Sent messages are immutable except approved redaction/privacy transformation.
* Assistant content is decision support, not final business decision.
* Tool output cannot bypass authorization or update another Domain directly.
* Allowed `status`: `created`, `completed`, `blocked`, `redacted`, `failed`.
* Safety state and provenance must be audit-safe.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| conversation_id | BIGINT UNSIGNED NOT NULL | Parent Conversation. |
| parent_message_id | BIGINT UNSIGNED NULL | Thread/response parent. |
| model_run_id | BIGINT UNSIGNED NULL | Generating Model Run. |
| role | VARCHAR(30) NOT NULL | Message actor role. |
| sequence_no | INT UNSIGNED NOT NULL | Conversation order. |
| content | LONGTEXT NOT NULL | Message content or redacted placeholder. |
| content_format | VARCHAR(30) NOT NULL DEFAULT 'text' | `text`, `markdown`, `json`. |
| status | VARCHAR(50) NOT NULL DEFAULT 'completed' | Message lifecycle. |
| safety_state | VARCHAR(50) NULL | Safety classification. |
| token_count | INT UNSIGNED NULL | Estimated/actual tokens. |
| metadata | JSON NULL | Citation/tool/provenance metadata. |
| created_at | TIMESTAMP NULL | Message time. |
| updated_at | TIMESTAMP NULL | Redaction/status update time. |

## Indexes

```sql
UNIQUE (customer_id, conversation_id, sequence_no);
INDEX (customer_id, conversation_id, created_at);
INDEX (customer_id, model_run_id);
INDEX (customer_id, role);
INDEX (customer_id, status);
```

## Sample Data

`id=101, customer_id=1, conversation_id=100, model_run_id=500, role=assistant, sequence_no=2, content=Thì hiện tại dùng..., content_format=markdown, status=completed, safety_state=passed, token_count=180`

## Design Notes

Retention/redaction must preserve audit linkage without requiring indefinite
storage of sensitive raw content.
