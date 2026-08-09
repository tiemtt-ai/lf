# Table: ai_embeddings

Document Path: database/ai/ai_embeddings.md

## Purpose

Lưu metadata/reference của vector embedding; không lưu binary/vector payload
bắt buộc trong relational database.

## Relationships

`Knowledge Chunk 1 → N Embeddings`; mỗi Embedding thuộc provider/model/vector
store reference.

## Business Rules

* Embedding là derived data và có thể regenerate.
* AI không thay đổi Knowledge Chunk khi embedding model thay đổi.
* `vector_key` là canonical locator trong configured vector store.
* Không lưu provider credential hoặc BYOK secret.
* Allowed `status`: `pending`, `ready`, `failed`, `stale`, `deleted`.
* Dimensions phải khớp model contract.
* Retrieval luôn tenant-scoped dù vector store dùng shared index.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| knowledge_chunk_id | BIGINT UNSIGNED NOT NULL | Embedded chunk. |
| provider | VARCHAR(50) NOT NULL | Embedding provider. |
| model | VARCHAR(100) NOT NULL | Embedding model. |
| dimensions | INT UNSIGNED NOT NULL | Vector dimensions. |
| vector_store | VARCHAR(50) NOT NULL | Store/adapter name. |
| vector_index | VARCHAR(255) NOT NULL | Tenant-safe logical index. |
| vector_key | VARCHAR(255) NOT NULL | Canonical vector locator. |
| embedding_hash | VARCHAR(128) NOT NULL | Input/model fingerprint. |
| status | VARCHAR(50) NOT NULL DEFAULT 'pending' | Embedding lifecycle. |
| embedded_at | TIMESTAMP NULL | Successful embedding time. |
| metadata | JSON NULL | Provider/version metadata without secrets. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Lifecycle update time. |

## Indexes

```sql
UNIQUE (customer_id, vector_store, vector_index, vector_key);
UNIQUE (customer_id, knowledge_chunk_id, provider, model, embedding_hash);
INDEX (customer_id, knowledge_chunk_id);
INDEX (customer_id, provider, model);
INDEX (customer_id, status);
```

## Sample Data

`id=20, customer_id=1, knowledge_chunk_id=10, provider=openai, model=text-embedding-3-small, dimensions=1536, vector_store=pgvector, vector_index=tenant_1_knowledge, vector_key=chunk_0191, embedding_hash=sha256:789abc, status=ready`

## Design Notes

Vector-store selection, data residency and deletion synchronization remain open
Foundation questions.
