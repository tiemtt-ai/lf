# Table: ai_knowledge_chunks

Version: 1.0

Document Status: Approved

Implementation Status: Not Implemented

Last Updated: 2026-09-08

Document Path: database/ai/ai_knowledge_chunks.md

## Region text-quality snapshot — Approved 2026-09-08

Candidate schema bổ sung `source_text_quality VARCHAR(20) NULL`, snapshot trực
tiếp từ Media Read unit của đúng `source_fingerprint`/`processing_version`.
Allowed values là `normal|low`; NULL dành cho non-region hoặc revision cũ không
có signal. AI không được tính lại từ chunk text và không backfill NULL thành
`normal`.

```sql
CHECK (source_text_quality IS NULL
       OR source_text_quality IN ('normal','low'));
```

Đây là thay đổi database design cho AI Foundation chưa triển khai; không phải
Media migration và chưa authorize AI migration trước independent review.

## Media retrieval amendment — Approved 2026-09-05

Mỗi chunk của Media source chứa **đúng một Media Read unit**. Vì vậy
`locator_start = locator_end`; context expansion dùng nhiều chunk, mỗi chunk giữ
citation riêng, không nối nhiều region thành một locator giả. Locator vocabulary
mở đúng Media Read: `page`, `timespan`, `sheet`, `region`.

Chunk snapshot ba signal rerank quan sát được: `source_role`,
`source_quality_status` và `language_evidence`. Chúng được copy từ unit của đúng
`source_fingerprint`/`processing_version`, không phải AI classification.
`language_evidence` giữ array `{script, locale, char_count}`; NULL cho content
type không có evidence đa trị. Crop URL không được snapshot vì là signed delivery
tạm thời; khi cần kiểm chứng, consumer đọc lại Media bằng locator/revision.

## Amendment — Approved 2026-08-25

Nguồn: [LF-AI-Foundation-Media-Consumer-Database-Architecture-Review](../../quality/LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md)
finding F-2 và F-3. **Approved by Owner 2026-08-25.** Thay đổi: `source_locator` JSON
tự do được thay bằng hợp đồng locator đã freeze, và thêm tenant composite
identity.

## Purpose

Derived text chunks phục vụ tenant-scoped retrieval/RAG.

## Relationships

`Knowledge Source 1 → N Knowledge Chunks`; `Chunk 1 → N Embeddings`.

## Business Rules

* Chunk derived từ authorized Knowledge Source và có thể rebuild.
* Chunk không phải Source Of Truth của source content.
* `sequence_no` unique trong một Knowledge Source revision.
* `content_hash` xác định chunk content; không dùng title làm identity.
* Allowed `status`: `pending`, `active`, `stale`, `archived`, `failed`,
  `deletion_pending`, `deleted`.
* Chunk giữ locator theo **đúng** hợp đồng locator chung tại
  [LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md)
  § 4: `locator_type` là `page`, `timespan`, `sheet` hoặc `region`; giá trị luôn là text.
* Chunk ngoài Media có thể trải nhiều unit và dùng khoảng
  `locator_start`..`locator_end`. Chunk Media bắt buộc một unit nên hai giá trị
  bằng nhau.
* Content/metadata tuân tenant privacy and retention.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| knowledge_source_id | BIGINT UNSIGNED NOT NULL | Parent Knowledge Source. |
| chunk_uuid | CHAR(36) NOT NULL | Stable chunk identity. |
| sequence_no | INT UNSIGNED NOT NULL | Order within source version. |
| content | LONGTEXT NULL | Extracted chunk text; erased only after lifecycle reaches `deleted`. |
| content_hash | VARCHAR(128) NOT NULL | Chunk content fingerprint. |
| token_count | INT UNSIGNED NULL | Estimated tokens. |
| locale | VARCHAR(20) NULL | Chunk locale. |
| part_index | INT UNSIGNED NOT NULL DEFAULT 1 | Deterministic part trong source unit. |
| char_start | INT UNSIGNED NOT NULL DEFAULT 0 | Unicode start offset. |
| char_end | INT UNSIGNED NOT NULL | Exclusive Unicode end offset. |
| locator_type | VARCHAR(20) NOT NULL | `page`, `timespan`, `sheet` hoặc `region`, theo unit nguồn. |
| locator_start | VARCHAR(50) NOT NULL | Locator của unit đầu trong chunk. |
| locator_end | VARCHAR(50) NOT NULL | Locator của unit cuối trong chunk. |
| source_role | VARCHAR(20) NULL | Region role quan sát được; NULL ngoài region/formula. |
| source_quality_status | VARCHAR(20) NULL | Table `complete|incomplete|undetermined`; NULL ngoài table. |
| language_evidence | JSON NULL | Snapshot ordered `{script,locale,char_count}` từ Media unit. |
| reading_order | INT UNSIGNED NULL | Exact Media unit reading order. |
| source_text_quality | VARCHAR(20) NULL | Exact `normal|low` snapshot; NULL when unavailable. |
| bbox_x | DECIMAL(9,6) NULL | Normalized bbox x. |
| bbox_y | DECIMAL(9,6) NULL | Normalized bbox y. |
| bbox_width | DECIMAL(9,6) NULL | Normalized bbox width. |
| bbox_height | DECIMAL(9,6) NULL | Normalized bbox height. |
| frame_width | INT UNSIGNED NULL | Frame width for video frame text. |
| frame_height | INT UNSIGNED NULL | Frame height for video frame text. |
| status | VARCHAR(50) NOT NULL DEFAULT 'pending' | Derived chunk lifecycle. |
| deletion_requested_at | TIMESTAMP NULL | Tombstone requested. |
| deleted_at | TIMESTAMP NULL | Content erased and tombstone completed. |
| metadata | JSON NULL | Chunking strategy/version. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Rebuild/lifecycle update time. |

## Indexes

```sql
UNIQUE (customer_id, chunk_uuid);
UNIQUE (id, customer_id);
UNIQUE (customer_id, knowledge_source_id, sequence_no);
UNIQUE (customer_id, knowledge_source_id, locator_type, locator_start, part_index);
INDEX  (customer_id, knowledge_source_id);
INDEX  (customer_id, status);
INDEX  (customer_id, content_hash);

FOREIGN KEY (knowledge_source_id, customer_id)
    REFERENCES ai_knowledge_sources (id, customer_id) RESTRICT;

CHECK (status IN ('pending','active','stale','archived','failed',
                  'deletion_pending','deleted'));
CHECK (locator_type IN ('page','timespan','sheet','region'));
CHECK (sequence_no >= 1);
CHECK (part_index >= 1 AND char_end > char_start);
CHECK (source_role IS NULL OR source_role IN
       ('paragraph','heading','list','table','figure','caption','header','footer','other'));
CHECK (source_quality_status IS NULL
       OR source_quality_status IN ('complete','incomplete','undetermined'));
CHECK (source_text_quality IS NULL OR source_text_quality IN ('normal','low'));
CHECK (status <> 'deletion_pending' OR deletion_requested_at IS NOT NULL);
CHECK (status <> 'deleted' OR deleted_at IS NOT NULL);
CHECK (status = 'deleted' OR content IS NOT NULL);
```

Locator tách thành ba cột thay vì một JSON là để **join ngược được** về
`media_extracted_texts` / `media_transcripts`. Một citation không định vị lại
được nguồn thì không phải citation, và JSON tự do thì mỗi lần chunk lại sinh một
hình dạng khác.

Readiness invariant xuyên parent: chunk của Media source phải có
`locator_start = locator_end`; `source_role` chỉ có với `region|formula`;
`source_quality_status` chỉ có với `table`; `language_evidence` phải bằng unit
đã đọc. Vi phạm fail toàn ingestion revision, không publish chunk một phần.

All chunks begin `pending` in one transaction and become `active` only after the
complete revision validates. Oversized Media units split deterministically at
the last paragraph/sentence/whitespace boundary before a revisioned token cap,
falling back to an exact Unicode-character boundary. Parts use consecutive
`part_index`, non-overlapping `[char_start,char_end)`, zero overlap and the same
locator/revision/evidence snapshots.

## Sample Data

`id=10, customer_id=1, knowledge_source_id=1, chunk_uuid=0191-chunk-0010, sequence_no=1, content=Ôn tập về bất quy tắc của ㅂ..., content_hash=sha256:def456, token_count=18, locale=vi, locator_type=region, locator_start=15#1, locator_end=15#1, source_role=paragraph, source_quality_status=NULL, language_evidence=[{script:Latn,locale:vi,char_count:41},{script:Hang,locale:ko,char_count:3}], status=active`

## Design Notes

Chunk limit and tokenizer are recorded in the chunker version.
Rebuild marks prior chunks stale/archived according to retention policy.

Delete lifecycle is `pending|active|failed|stale|archived → deletion_pending →
deleted`; `deleted` is terminal. After all child Embeddings are deleted, content
is erased and minimal provenance retained. Migration makes `content` nullable
with a CHECK requiring it for non-deleted rows. Rollback fail-closed on any row.
