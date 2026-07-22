# Table: ai_knowledge_chunks

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
* Chunk phải giữ source locator đủ để citation/audit.
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
| source_locator | JSON NULL | Page/time/section citation locator. |
| status | VARCHAR(50) NOT NULL DEFAULT 'active' | Derived chunk lifecycle. |
| metadata | JSON NULL | Chunking strategy/version. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Rebuild/lifecycle update time. |

## Indexes

```sql
UNIQUE (customer_id, chunk_uuid);
UNIQUE (customer_id, knowledge_source_id, sequence_no, content_hash);
INDEX (customer_id, knowledge_source_id);
INDEX (customer_id, status);
INDEX (customer_id, content_hash);
```

## Sample Data

`id=10, customer_id=1, knowledge_source_id=1, chunk_uuid=0191-chunk-0010, sequence_no=1, content=안녕하세요..., content_hash=sha256:def456, token_count=320, locale=ko, source_locator={"start_second":0,"end_second":45}, status=active`

## Design Notes

Chunk overlap, size and versioning strategy remain owner-review decisions.
Rebuild marks prior chunks stale/archived according to retention policy.
