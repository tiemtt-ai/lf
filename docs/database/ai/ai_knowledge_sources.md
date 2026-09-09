# Table: ai_knowledge_sources

Version: 1.1

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-09-08

Document Path: database/ai/ai_knowledge_sources.md

## Deterministic ingestion runtime — Implemented 2026-09-08

`AiKnowledgeIngestionService` đăng ký Media source bằng đúng logical identity
`(customer_id, source_type, source_id, usage_type, content_type, locale,
source_fingerprint, processing_version, generation)`. Ordered language profile
được snapshot trong `metadata.language_profile`; nếu cùng database identity
nhưng profile khác, ingestion fail-closed bằng `revision_identity_conflict`
thay vì trộn provenance.

Retry cùng revision đang `pending|active|failed` dùng lại source. Revision mới
tạo source mới rồi, trong cùng transaction, chuyển source/chunk/embedding cũ
sang `stale`. Source đã `stale|archived|deleted` không được hồi sinh; nếu cùng
revision quay lại thì tạo `generation` kế tiếp. Ingestion chỉ gọi Media Read và
ghi relational source/chunk; không tạo Model Run, embedding hay vector point.

Deletion là tombstone hai pha: `requestSourceDeletion()` chuyển embedding,
chunk và source sang `deletion_pending`; `finalizeSourceDeletion()` chỉ erase
chunk content và ghi `deleted` sau khi mọi embedding con đã `deleted`.

## Media retrieval amendment — Approved 2026-09-05

Media Knowledge Source mở `content_type` thành toàn bộ read units có text phục
vụ retrieval: `extracted_text`, `transcript`, `region`, `table`, `formula`.
`caption_asset` và `variant` là file delivery không có text trực tiếp nên không
được đăng ký làm textual knowledge source; consumer muốn dùng phải qua một
processing/read contract tạo text riêng.

Một registration vẫn neo đúng owner context + revision, không theo Media File.
Ranking policy của ADR-0006 v1.0.1 không thay đổi identity hoặc authorization.

## Amendment — Approved 2026-08-25

Nguồn: [LF-AI-Foundation-Media-Consumer-Database-Architecture-Review](../../quality/LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md)
finding F-1 và F-2. **Approved by Owner 2026-08-25.** Thay đổi: đăng ký theo derived content unit thay vì theo Media File, thêm
`content_type`/`source_fingerprint`/`processing_version`, bỏ `media_file_id`, và
thêm tenant composite identity.

**Superseded ở phần `media_file_id`:** ADR-0006 Amendment v1.0.3 (Approved
2026-09-08) đưa `media_file_id` trở lại làm provenance có khóa ngoại
tenant-aware. Nó vẫn không phải đường authorization — đó mới là điều amendment
2026-08-25 muốn chặn. Xem § Business Rules và § Indexes hiện hành.

## Purpose

Đăng ký nguồn tri thức được AI phép sử dụng; không sao chép ownership của
Course, Assessment, Media, Track hoặc LiveClass.

## Relationships

`Knowledge Source 1 → N Knowledge Chunks`; optional Media provenance.
`source_type + source_id` là generic reference tới Owner Domain.

## Business Rules

* Mọi source tenant-scoped; source reference phải cùng tenant.
* Allowed `source_type`: `course_activity`, `course_version_activity`,
  `course_version`, `assessment_snapshot`, `track_summary`,
  `liveclass_transcript`, `other`.
* Với source do Media phục vụ, `source_type` là **owner context** của
  [LF-Media-Read-Contract](../../platform/LF-Media-Read-Contract.md) § 3, không
  phải một Media File. AI lưu `media_file_id` chỉ làm provenance; authorization
  luôn dùng owner context qua Media Read.
* Media source phải ghi `content_type`, `locale`, `source_fingerprint` và
  `processing_version` **của đúng unit đã đọc**. Đây là hợp đồng ở Read Contract
  § 7 và là dữ liệu duy nhất cho phép phát hiện stale mà không phải đoán.
* Chỉ registered/authorized content được chunk/retrieve.
* `content_hash`/`source_version` dành cho source ngoài Media, nơi không có
  fingerprint/version của Media. AI không update nguồn.
* Source thành `stale` khi Media có revision `ready` mới hơn cho cùng
  `(source_type, source_id, content_type, locale)`. Media báo trạng thái, AI
  quyết định rebuild; Media không tự rebuild và không xoá gì của AI.
* Allowed `status`: `pending`, `active`, `stale`, `archived`, `failed`,
  `deletion_pending`, `deleted`.
* `usage_type` phải khớp đúng cặp `(content_type, usage_type)` của Read
  Contract § 3. Một registration `formula` + `audio` là nguồn không đọc lại
  được, nên database từ chối thay vì để nó thành lỗi runtime.
* `media_file_id` có khóa ngoại kép tới `media_files (id, customer_id)`.
  Nó vẫn **không** cấp quyền — mọi lần đọc đi qua owner context — nhưng một
  citation trỏ tới file của tenant khác hoặc file không tồn tại thì không
  phải citation.
* `generation` là chu kỳ đăng ký. Row `deleted` là tombstone terminal và giữ
  identity của nó vĩnh viễn; khi owner gắn lại đúng Media revision cũ, AI
  đăng ký `generation` kế tiếp thay vì hồi sinh row cũ. Bản cũ giữ nguyên để
  một Proposal đã trích dẫn nó vẫn truy lại được.
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
| media_file_id | BIGINT UNSIGNED NULL | Media provenance có FK tenant-aware; không cấp quyền. |
| usage_type | VARCHAR(50) NOT NULL DEFAULT '' | Media usage; sentinel rỗng ngoài Media. |
| generation | INT UNSIGNED NOT NULL DEFAULT 1 | Chu kỳ đăng ký của cùng revision; tăng sau tombstone. |
| content_type | VARCHAR(50) NULL | Derived content unit type; NULL với source ngoài Media. |
| title | VARCHAR(255) NOT NULL | Display/audit title. |
| locale | VARCHAR(20) NULL | Source locale. |
| identity_content_type | VARCHAR(50) AS (COALESCE(content_type,'')) STORED | NULL-safe identity. |
| identity_locale | VARCHAR(20) AS (COALESCE(locale,'')) STORED | NULL-safe identity. |
| source_version | VARCHAR(100) NULL | Owner-provided immutable/version marker. |
| content_hash | VARCHAR(128) NULL | Fingerprint cho source ngoài Media. |
| source_fingerprint | CHAR(64) NULL | `source_fingerprint` của unit đã đọc. |
| processing_version | VARCHAR(100) NULL | `processing_version` của unit đã đọc. |
| identity_fingerprint | VARCHAR(128) AS (COALESCE(RTRIM(source_fingerprint),content_hash,'')) STORED | NULL-safe source revision identity. |
| identity_version | VARCHAR(100) AS (COALESCE(processing_version,source_version,'')) STORED | NULL-safe version identity. |
| status | VARCHAR(50) NOT NULL DEFAULT 'pending' | AI ingestion lifecycle. |
| last_synced_at | TIMESTAMP NULL | Last successful source sync. |
| deletion_requested_at | TIMESTAMP NULL | Tombstone requested. |
| deleted_at | TIMESTAMP NULL | Tombstone completed. |
| created_by | BIGINT UNSIGNED NULL | Registering User/system actor. |
| metadata | JSON NULL | Extraction/authorization context. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Lifecycle update time. |

## Indexes

```sql
UNIQUE (customer_id, source_uuid);
UNIQUE (id, customer_id);
UNIQUE (customer_id, source_type, source_id, usage_type,
        identity_content_type, identity_locale, identity_fingerprint,
        identity_version, generation);
INDEX  (customer_id, source_type, source_id);
INDEX  (customer_id, status);
INDEX  (customer_id, last_synced_at);
INDEX  (customer_id, source_fingerprint);

INDEX  (customer_id, media_file_id);

FOREIGN KEY (created_by, customer_id)
    REFERENCES users (id, customer_id) RESTRICT;
FOREIGN KEY (media_file_id, customer_id)
    REFERENCES media_files (id, customer_id) RESTRICT;

CHECK (status IN ('pending','active','stale','archived','failed',
                  'deletion_pending','deleted'));
CHECK (source_type IN ('course_activity','course_version_activity',
                       'course_version','assessment_snapshot','track_summary',
                       'liveclass_transcript','other'));
CHECK (content_type IS NULL
       OR content_type IN ('extracted_text','transcript','region','table',
                           'formula','video_frame_text'));
CHECK (content_type IS NULL
       OR (source_fingerprint IS NOT NULL AND processing_version IS NOT NULL));
CHECK ((content_type IS NULL AND media_file_id IS NULL AND usage_type = '')
       OR (content_type IS NOT NULL AND media_file_id IS NOT NULL
           AND source_type IN ('course_activity','course_version_activity')));
CHECK (content_type IS NULL OR (
    (content_type IN ('extracted_text','region','table','formula')
     AND usage_type = 'document')
 OR (content_type = 'transcript' AND usage_type IN ('audio','video'))
 OR (content_type = 'video_frame_text' AND usage_type = 'video')));
CHECK (generation >= 1);
CHECK (status <> 'deletion_pending' OR deletion_requested_at IS NOT NULL);
CHECK (status <> 'deleted' OR deleted_at IS NOT NULL);
```

`UNIQUE (id, customer_id)` là điều kiện để Chunk tham chiếu ngược bằng khóa ngoại
kép; không có nó thì một Chunk của tenant A trỏ được sang Source của tenant B và
database không chặn được.

`identity_fingerprint` bọc `RTRIM()` quanh `source_fingerprint`. Đây không phải
làm đẹp: `source_fingerprint` là `CHAR(64)`, mà giá trị CHAR phụ thuộc
`sql_mode` `PAD_CHAR_TO_FULL_LENGTH`, nên MariaDB 11.4 từ chối nó trong
`GENERATED ALWAYS AS` bằng lỗi 1901. MariaDB 10.4 chấp nhận, nên khác biệt
chỉ lộ ra trên server của CI. `RTRIM` làm biểu thức tất định và là no-op
trên một SHA-256 hex digest. Nguồn: gate CI `integration-mysql`, 2026-09-08.

Unique key gồm usage, fingerprint, version và `generation`; generated sentinels
tránh UNIQUE với NULL trên MariaDB. Thiếu `generation` thì một tombstone khóa
vĩnh viễn khả năng đăng ký lại cùng revision sau khi Media được gắn lại. Một revision mới của cùng owner/content
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

`deleted` là tombstone terminal, không hard-delete. Source chỉ hoàn tất sau khi
mọi Chunk con đã `deleted`. Rollback migration fail-closed khi bảng còn row.
