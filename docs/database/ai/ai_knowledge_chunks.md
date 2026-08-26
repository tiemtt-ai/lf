# Table: ai_knowledge_chunks

Document Path: database/ai/ai_knowledge_chunks.md

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
* Một chunk có thể trải nhiều unit, nên locator là một khoảng:
  `locator_start`..`locator_end`. Chunk gói đúng một unit thì hai giá trị bằng
  nhau. Không có hình dạng locator thứ ba.
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
| locator_type | VARCHAR(20) NOT NULL | `page` hoặc `timespan`, theo unit nguồn. |
| locator_start | VARCHAR(50) NOT NULL | Locator của unit đầu trong chunk. |
| locator_end | VARCHAR(50) NOT NULL | Locator của unit cuối trong chunk. |
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
CHECK (locator_type IN ('page','timespan'));
CHECK (sequence_no >= 1);
```

Locator tách thành ba cột thay vì một JSON là để **join ngược được** về
`media_extracted_texts` / `media_transcripts`. Một citation không định vị lại
được nguồn thì không phải citation, và JSON tự do thì mỗi lần chunk lại sinh một
hình dạng khác.

## Sample Data

`id=10, customer_id=1, knowledge_source_id=1, chunk_uuid=0191-chunk-0010, sequence_no=1, content=안녕하세요..., content_hash=sha256:def456, token_count=320, locale=ko, locator_type=timespan, locator_start=0-45000, locator_end=0-45000, status=active`

## Design Notes

Chunk overlap, size and versioning strategy remain owner-review decisions.
Rebuild marks prior chunks stale/archived according to retention policy.
