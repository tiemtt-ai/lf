# Table: ai_embeddings

Document Path: database/ai/ai_embeddings.md

## Vector-store and deletion amendment — Approved 2026-09-05

`vector_store` được freeze là `qdrant`; `vector_key` là UUID point id. Qdrant
self-hosted >=1.11 chạy trong LF-managed boundary. Point payload bắt buộc
`customer_id`, nhưng không chứa raw chunk text, PII hay signed URL.

Deletion dùng `deletion_pending` trước remote call. Retrieval chỉ dùng row
`ready` và post-validate tenant/source/chunk/Media revision. Worker delete exact
UUID kèm tenant filter và chỉ chuyển `deleted` sau acknowledgment; lỗi giữ row để
retry/reconcile. Parent chunk/source không hard-delete trước khi mọi child
embedding đã `deleted`.

## Amendment — Approved 2026-08-25

Nguồn: [LF-AI-Foundation-Media-Consumer-Database-Architecture-Review](../../quality/LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md)
finding F-2, F-6 và F-7. **Approved by Owner 2026-08-25.** Thay đổi: thêm tenant
composite identity; ghi nhận ràng buộc ADR-0018 và vector store là điều kiện
triển khai, không phải chi tiết bỏ ngỏ.

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
* Allowed `status`: `pending`, `ready`, `failed`, `stale`, `deletion_pending`, `deleted`.
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
| deletion_requested_at | TIMESTAMP NULL | Lúc row bị loại khỏi retrieval và chờ remote delete. |
| deletion_attempts | INT UNSIGNED NOT NULL DEFAULT 0 | Số lần worker thử delete. |
| deleted_at | TIMESTAMP NULL | Remote point deletion đã được xác nhận. |
| last_error_code | VARCHAR(100) NULL | Mã lỗi ổn định gần nhất; không chứa credential/payload. |
| metadata | JSON NULL | Provider/version metadata without secrets. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Lifecycle update time. |

## Indexes

```sql
UNIQUE (customer_id, vector_store, vector_index, vector_key);
UNIQUE (id, customer_id);
UNIQUE (customer_id, knowledge_chunk_id, provider, model, embedding_hash);
INDEX  (customer_id, knowledge_chunk_id);
INDEX  (customer_id, provider, model);
INDEX  (customer_id, status);

FOREIGN KEY (knowledge_chunk_id, customer_id)
    REFERENCES ai_knowledge_chunks (id, customer_id) RESTRICT;

CHECK (status IN ('pending','ready','failed','stale','deletion_pending','deleted'));
CHECK (dimensions >= 1);
CHECK (vector_store = 'qdrant');
CHECK (deletion_attempts >= 0);
CHECK (status <> 'deletion_pending' OR deletion_requested_at IS NOT NULL);
CHECK (status <> 'deleted' OR deleted_at IS NOT NULL);
```

## Sample Data

`id=20, customer_id=1, knowledge_chunk_id=10, provider=approved-provider, model=approved-model, dimensions=1536, vector_store=qdrant, vector_index=lf_text_approved_model_v1, vector_key=01910000-0000-7000-8000-000000000020, embedding_hash=sha256:789abc, status=ready`

## Design Notes

Qdrant là derived index, không phải Source Of Truth. MariaDB state quyết định
candidate có còn eligible; vector result không tự cấp quyền. Reconciliation là
bắt buộc vì transaction không thể atomic xuyên MariaDB và Qdrant.
