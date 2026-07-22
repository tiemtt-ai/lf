# Table: ai_knowledge_sources

## Purpose

Đăng ký nguồn tri thức được AI phép sử dụng; không sao chép ownership của
Course, Assessment, Media, Track hoặc LiveClass.

## Relationships

`Knowledge Source 1 → N Knowledge Chunks`; optional Media File reference.
`source_type + source_id` là generic reference tới Owner Domain.

## Business Rules

* Mọi source tenant-scoped; source reference phải cùng tenant.
* Allowed `source_type`: `course_version`, `version_activity`,
  `assessment_snapshot`, `media_file`, `media_transcript`, `track_summary`,
  `liveclass_transcript`, `other`.
* Chỉ registered/authorized content được chunk/retrieve.
* `content_hash`/`source_version` phát hiện source change; AI không update nguồn.
* Allowed `status`: `pending`, `active`, `stale`, `archived`, `failed`.
* Source stale kích hoạt rebuild policy cho chunks/embeddings.
* Metadata không chứa credential hoặc canonical source business state.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu registration. |
| source_uuid | CHAR(36) NOT NULL | Stable AI source identity. |
| source_type | VARCHAR(100) NOT NULL | Generic owner type. |
| source_id | BIGINT UNSIGNED NOT NULL | Generic owner record ID. |
| media_file_id | BIGINT UNSIGNED NULL | Optional canonical Media File. |
| title | VARCHAR(255) NOT NULL | Display/audit title. |
| locale | VARCHAR(20) NULL | Source locale. |
| source_version | VARCHAR(100) NULL | Owner-provided immutable/version marker. |
| content_hash | VARCHAR(128) NOT NULL | Source content fingerprint. |
| status | VARCHAR(50) NOT NULL DEFAULT 'pending' | AI ingestion lifecycle. |
| last_synced_at | TIMESTAMP NULL | Last successful source sync. |
| created_by | BIGINT UNSIGNED NULL | Registering User/system actor. |
| metadata | JSON NULL | Extraction/authorization context. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Lifecycle update time. |

## Indexes

```sql
UNIQUE (customer_id, source_uuid);
UNIQUE (customer_id, source_type, source_id, content_hash);
INDEX (customer_id, source_type, source_id);
INDEX (customer_id, media_file_id);
INDEX (customer_id, status);
INDEX (customer_id, last_synced_at);
```

## Sample Data

`id=1, customer_id=1, source_uuid=0191-source-0001, source_type=media_transcript, source_id=800, media_file_id=700, title=TOPIK Lesson 1 Transcript, locale=ko, source_version=v1, content_hash=sha256:abc123, status=active`

## Design Notes

Generic reference requires owner existence, tenant and authorization validation.
Deletion/retention follows both Owner Domain and AI derived-data policy.
