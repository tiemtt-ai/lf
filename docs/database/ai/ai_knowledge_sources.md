# Table: ai_knowledge_sources

Document Path: database/ai/ai_knowledge_sources.md

## Amendment — Proposed 2026-08-25

Nguồn: [LF-AI-Foundation-Media-Consumer-Database-Architecture-Review](../../quality/LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md)
finding F-1 và F-2. **Chưa được Owner approve; chưa được viết migration theo bản
này.** Thay đổi: đăng ký theo derived content unit thay vì theo Media File, thêm
`content_type`/`source_fingerprint`/`processing_version`, bỏ `media_file_id`, và
thêm tenant composite identity.

## Purpose

Đăng ký nguồn tri thức được AI phép sử dụng; không sao chép ownership của
Course, Assessment, Media, Track hoặc LiveClass.

## Relationships

`Knowledge Source 1 → N Knowledge Chunks`; optional Media File reference.
`source_type + source_id` là generic reference tới Owner Domain.

## Business Rules

* Mọi source tenant-scoped; source reference phải cùng tenant.
* Allowed `source_type`: `course_activity`, `course_version_activity`,
  `course_version`, `assessment_snapshot`, `track_summary`,
  `liveclass_transcript`, `other`.
* Với source do Media phục vụ, `source_type` là **owner context** của
  [LF-Media-Read-Contract](../../platform/LF-Media-Read-Contract.md) § 3, không
  phải một Media File. AI không cầm `media_file_id`: cùng một file có thể phục vụ
  hai Activity với hai mức quyền khác nhau, nên quyền gắn với owner.
* Media source phải ghi `content_type`, `locale`, `source_fingerprint` và
  `processing_version` **của đúng unit đã đọc**. Đây là hợp đồng ở Read Contract
  § 7 và là dữ liệu duy nhất cho phép phát hiện stale mà không phải đoán.
* Chỉ registered/authorized content được chunk/retrieve.
* `content_hash`/`source_version` dành cho source ngoài Media, nơi không có
  fingerprint/version của Media. AI không update nguồn.
* Source thành `stale` khi Media có revision `ready` mới hơn cho cùng
  `(source_type, source_id, content_type, locale)`. Media báo trạng thái, AI
  quyết định rebuild; Media không tự rebuild và không xoá gì của AI.
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
| content_type | VARCHAR(50) NULL | Derived content unit type; NULL với source ngoài Media. |
| title | VARCHAR(255) NOT NULL | Display/audit title. |
| locale | VARCHAR(20) NULL | Source locale. |
| source_version | VARCHAR(100) NULL | Owner-provided immutable/version marker. |
| content_hash | VARCHAR(128) NULL | Fingerprint cho source ngoài Media. |
| source_fingerprint | CHAR(64) NULL | `source_fingerprint` của unit đã đọc. |
| processing_version | VARCHAR(100) NULL | `processing_version` của unit đã đọc. |
| status | VARCHAR(50) NOT NULL DEFAULT 'pending' | AI ingestion lifecycle. |
| last_synced_at | TIMESTAMP NULL | Last successful source sync. |
| created_by | BIGINT UNSIGNED NULL | Registering User/system actor. |
| metadata | JSON NULL | Extraction/authorization context. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Lifecycle update time. |

## Indexes

```sql
UNIQUE (customer_id, source_uuid);
UNIQUE (id, customer_id);
UNIQUE (customer_id, source_type, source_id, content_type, locale,
        processing_version);
INDEX  (customer_id, source_type, source_id);
INDEX  (customer_id, status);
INDEX  (customer_id, last_synced_at);
INDEX  (customer_id, source_fingerprint);

FOREIGN KEY (created_by, customer_id)
    REFERENCES users (id, customer_id) RESTRICT;

CHECK (status IN ('pending','active','stale','archived','failed'));
CHECK (source_type IN ('course_activity','course_version_activity',
                       'course_version','assessment_snapshot','track_summary',
                       'liveclass_transcript','other'));
CHECK (content_type IS NULL
       OR content_type IN ('extracted_text','transcript','caption_asset',
                           'variant'));
CHECK (content_type IS NULL
       OR (source_fingerprint IS NOT NULL AND processing_version IS NOT NULL));
```

`UNIQUE (id, customer_id)` là điều kiện để Chunk tham chiếu ngược bằng khóa ngoại
kép; không có nó thì một Chunk của tenant A trỏ được sang Source của tenant B và
database không chặn được.

Unique key gồm `processing_version`: một revision mới của cùng owner/content
type/locale là **một registration mới**, không ghi đè bản cũ. Bản cũ chuyển
`stale` rồi `archived`, giữ nguyên để một Proposal đã trích dẫn nó vẫn truy lại
được.

`CHECK` cuối buộc mọi Media source phải có đủ fingerprint và version. Một
registration Media thiếu hai giá trị đó không phát hiện được stale, và im lặng
phục vụ nội dung đã lỗi thời.

## Sample Data

`id=1, customer_id=1, source_uuid=0191-source-0001, source_type=course_activity, source_id=99, content_type=transcript, title=TOPIK Lesson 1 Transcript, locale=ko, source_fingerprint=9f2c…(64 hex), processing_version=whisper-large-v3+a1b2c3, status=active`

## Design Notes

Generic reference requires owner existence, tenant and authorization validation.
Deletion/retention follows both Owner Domain and AI derived-data policy.

Đăng ký **không** thay thế authorization: một registration `active` không cấp
quyền đọc. Mọi lần đọc vẫn đi qua Media Read Service với `actor_id` tường minh và
vẫn bị owner-context authorization chặn.
