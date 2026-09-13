# Table: ai_embeddings

Version: 1.1

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-09-10

Document Path: database/ai/ai_embeddings.md

## Retry amendment — Approved by Owner 2026-09-10

Thêm `failed → pending` vào canonical transitions.

Lý do: `uk_aem_chunk_model` `(customer_id, knowledge_chunk_id, provider, model,
embedding_hash)` ghim định danh của một embedding, và một tombstone `deleted`
ghim nó y hệt. Nếu `failed` là ngõ cụt thì một lỗi provider thoáng qua sẽ khiến
chunk đó **không bao giờ** được index lại — không bằng row cũ, cũng không bằng
row mới. Đó là lỗ hổng vĩnh viễn sinh ra từ một lỗi tạm thời.

Việc tái dùng row không làm mất bằng chứng: mỗi lần thử là một row riêng, bất
biến trong `ai_model_runs` (`status`, `error_code`, `completed_at`), và
`ai_embeddings.model_run_id` trỏ vào lần thử hiện hành. Row embedding vì thế là
bản ghi **trạng thái hiện tại**, không phải sổ lịch sử attempt — sổ đó là
`ai_model_runs`.

Phạm vi hẹp có chủ ý: chỉ `failed → pending`. `stale → pending` **không** được
thêm; một định danh đã bị supersede không được quay lại vòng đời truy hồi bằng
đường này. Nguồn:
[LF-AI-Embedding-Qdrant-Implementation-Review](../../quality/LF-AI-Embedding-Qdrant-Implementation-Review.md)
OD-1.

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
* Canonical transitions: `pending → ready|failed`; `failed → pending` (retry,
  Amendment 2026-09-10); `ready|failed → stale`;
  `pending|ready|failed|stale → deletion_pending → deleted`. `deleted` terminal.
  Application service enforces transitions under row lock.
* Retry chỉ hợp lệ khi `ai_model_runs` row đang giữ `pending` đó đã kết thúc
  (`failed` hoặc `cancelled`). Một `pending` thuộc run `queued`/`running` là
  đang có chủ; thuộc run `completed` là việc của reconciliation, không phải của
  một lần gọi provider thứ hai.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| knowledge_chunk_id | BIGINT UNSIGNED NOT NULL | Embedded chunk. |
| model_run_id | BIGINT UNSIGNED NOT NULL | Provider execution provenance. |
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
FOREIGN KEY (model_run_id, customer_id)
    REFERENCES ai_model_runs (id, customer_id) RESTRICT;

CHECK (status IN ('pending','ready','failed','stale','deletion_pending','deleted'));
CHECK (dimensions >= 1);
CHECK (vector_store = 'qdrant');
CHECK (deletion_attempts >= 0);
CHECK (status <> 'deletion_pending' OR deletion_requested_at IS NOT NULL);
CHECK (status <> 'deleted' OR deleted_at IS NOT NULL);
CHECK (status <> 'ready' OR embedded_at IS NOT NULL);
```

## Sample Data

`id=20, customer_id=1, knowledge_chunk_id=10, provider=approved-provider, model=approved-model, dimensions=1536, vector_store=qdrant, vector_index=lf_text_approved_model_v1, vector_key=01910000-0000-7000-8000-000000000020, embedding_hash=sha256:789abc, status=ready`

## Design Notes

Deleted rows remain minimal audit tombstones and are not hard-deleted during
parent cleanup. Rollback migration fails closed while any row exists.

Qdrant là derived index, không phải Source Of Truth. MariaDB state quyết định
candidate có còn eligible; vector result không tự cấp quyền. Reconciliation là
bắt buộc vì transaction không thể atomic xuyên MariaDB và Qdrant.
