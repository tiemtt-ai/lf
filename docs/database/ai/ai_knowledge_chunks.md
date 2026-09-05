# Table: ai_knowledge_chunks

Document Path: database/ai/ai_knowledge_chunks.md

## Region text-quality snapshot — Proposed 2026-09-05

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
* `sequence_no` unique trong một source/content version.
* `content_hash` xác định chunk content; không dùng title làm identity.
* Allowed `status`: `active`, `stale`, `archived`, `failed`.
* Chunk giữ locator theo **đúng** hợp đồng locator chung tại
  [LF-Media-Processing-Contract](../../platform/LF-Media-Processing-Contract.md)
  § 4: `locator_type` là `page` hoặc `timespan`, giá trị luôn là text.
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
| content | LONGTEXT NOT NULL | Extracted chunk text. |
| content_hash | VARCHAR(128) NOT NULL | Chunk content fingerprint. |
| token_count | INT UNSIGNED NULL | Estimated tokens. |
| locale | VARCHAR(20) NULL | Chunk locale. |
| locator_type | VARCHAR(20) NOT NULL | `page`, `timespan`, `sheet` hoặc `region`, theo unit nguồn. |
| locator_start | VARCHAR(50) NOT NULL | Locator của unit đầu trong chunk. |
| locator_end | VARCHAR(50) NOT NULL | Locator của unit cuối trong chunk. |
| source_role | VARCHAR(20) NULL | Region role quan sát được; NULL ngoài region/formula. |
| source_quality_status | VARCHAR(20) NULL | Table `complete|incomplete|undetermined`; NULL ngoài table. |
| language_evidence | JSON NULL | Snapshot ordered `{script,locale,char_count}` từ Media unit. |
| status | VARCHAR(50) NOT NULL DEFAULT 'active' | Derived chunk lifecycle. |
| metadata | JSON NULL | Chunking strategy/version. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Rebuild/lifecycle update time. |

## Indexes

```sql
UNIQUE (customer_id, chunk_uuid);
UNIQUE (id, customer_id);
UNIQUE (customer_id, knowledge_source_id, sequence_no, content_hash);
INDEX  (customer_id, knowledge_source_id);
INDEX  (customer_id, status);
INDEX  (customer_id, content_hash);

FOREIGN KEY (knowledge_source_id, customer_id)
    REFERENCES ai_knowledge_sources (id, customer_id) RESTRICT;

CHECK (status IN ('active','stale','archived','failed'));
CHECK (locator_type IN ('page','timespan','sheet','region'));
CHECK (sequence_no >= 1);
CHECK (source_quality_status IS NULL
       OR source_quality_status IN ('complete','incomplete','undetermined'));
```

Locator tách thành ba cột thay vì một JSON là để **join ngược được** về
`media_extracted_texts` / `media_transcripts`. Một citation không định vị lại
được nguồn thì không phải citation, và JSON tự do thì mỗi lần chunk lại sinh một
hình dạng khác.

Readiness invariant xuyên parent: chunk của Media source phải có
`locator_start = locator_end`; `source_role` chỉ có với `region|formula`;
`source_quality_status` chỉ có với `table`; `language_evidence` phải bằng unit
đã đọc. Vi phạm fail toàn ingestion revision, không publish chunk một phần.

## Sample Data

`id=10, customer_id=1, knowledge_source_id=1, chunk_uuid=0191-chunk-0010, sequence_no=1, content=Ôn tập về bất quy tắc của ㅂ..., content_hash=sha256:def456, token_count=18, locale=vi, locator_type=region, locator_start=15#1, locator_end=15#1, source_role=paragraph, source_quality_status=NULL, language_evidence=[{script:Latn,locale:vi,char_count:41},{script:Hang,locale:ko,char_count:3}], status=active`

## Design Notes

Chunk overlap, size and versioning strategy remain owner-review decisions.
Rebuild marks prior chunks stale/archived according to retention policy.
