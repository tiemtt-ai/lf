# AI Embedding + Qdrant — Implementation Review

Version: 1.3

Document Status: Review

Implementation Status: Partial

Last Updated: 2026-09-10

Review Date: 2026-09-10

Document Path: quality/LF-AI-Embedding-Qdrant-Implementation-Review.md

---

# Scope

Bước 5 của lộ trình Phần 2 — Embedding + Qdrant. Biến `ai_knowledge_chunks`
thành vector point trong Qdrant, và biến vector hit ngược lại thành chunk có thể
trích dẫn, theo [ADR-0006](../adr/ADR-0006-AI-Architecture.md) v1.0.2 (vector
store) và v1.0.3 (`media_file_id` là provenance, không phải authorization), qua
gate của Bước 4.

Ngoài phạm vi: kích hoạt provider thật (quyết định riêng theo ADR-0018), Vision
Interpretation, AI Authoring Proposal, route, UI, migration mới.

Không có migration mới. Bốn bảng của Bước 2 đã đủ; `ai_embeddings` đã có
`vector_store`, `vector_index`, `vector_key`, `embedding_hash`, `dimensions`,
`deletion_attempts` và `last_error_code`.

---

# Đã triển khai

| Thành phần | Đường dẫn |
| --- | --- |
| Worker vòng đời embedding | `app/Services/AiEmbeddingService.php` |
| Retrieval + post-validation | `app/Services/AiKnowledgeRetrievalService.php` |
| Qdrant adapter | `app/Services/Ai/QdrantVectorStore.php` |
| Adapter nối provider + store vào gate | `app/Services/Ai/EmbeddingProviderAdapter.php` |
| Port | `app/Contracts/Ai/{EmbeddingProvider,VectorStore}.php` |
| Mặc định fail-closed | `app/Services/Ai/Unavailable{EmbeddingProvider,VectorStore}.php` |
| Value object | `app/Support/Ai/{VectorPoint,EmbeddingWorkItem}.php` |
| Config (host **rỗng**, provider **rỗng**) | `config/ai.php` |
| Binding | `app/Providers/AppServiceProvider.php` |
| Test | `tests/Feature/AiEmbeddingServiceTest.php`, `tests/Feature/AiKnowledgeRetrievalServiceTest.php` |

---

# Quyết định thiết kế và lý do

## D-1 — Thứ tự bị `model_run_id NOT NULL` quy định

`ai_embeddings.model_run_id` là NOT NULL, nên row embedding **không thể** tồn
tại trước khi gate tạo run. Thứ tự vì thế là bắt buộc, không phải lựa chọn:

```
authorize()  → tạo run `queued` + giữ quota
insert       → ai_embeddings `pending` trỏ vào run đó
execute()    → claim run, gọi provider, upsert Qdrant
markReady()  → `pending → ready`
```

Không có sắp xếp nào cho phép một row embedding tồn tại mà không có audit row
giải thích nó.

## D-2 — Không có status `processing`

Vocabulary của `ai_embeddings` không có `processing`, và việc thêm nó là thừa:
công việc đang chạy đã được biểu diễn bằng `pending` + một `ai_model_runs` row
còn sống. Audit row **chính là** lease. Hai nguồn sự thật sẽ có lúc nói khác
nhau.

Hệ quả: một `pending` row thuộc run `queued`/`running` là đang có chủ; thuộc run
`failed`/`cancelled` là tự do; thuộc run `completed` là việc của reconciliation.

## D-3 — Reap chỉ áp dụng cho run `queued`

`claimForExecution()` chuyển `queued → running` **trước khi** dựng adapter, nên
một run còn `queued` chứng minh được là chưa chạm provider. Chỉ những run đó mới
bị đưa về `cancelled`. Update có điều kiện trên `status = 'queued'`, nên một
worker claim đúng lúc đó hoặc thắng claim (và update này không khớp row nào),
hoặc thua claim (và không có provider call nào). An toàn ở cả hai nhánh.

Run `running` bị bỏ mặc: chỉ provider-aware reconciliation mới biết cuộc gọi có
xảy ra hay không. Dùng `cancelled` thay vì `failed` vì `failed` bắt buộc có
`error_code`, và không có mã nào đã duyệt mang nghĩa "tiến trình biến mất" —
không tự chế mã mới.

## D-4 — Collection được ghi vào row, không suy ra từ config

`vector_index` lưu tên collection tại thời điểm ghi. Nếu delete suy ra collection
từ `config('ai.embedding.model')` hiện tại thì việc đổi model sẽ làm **mọi point
đã ghi trở nên không thể xoá** — chúng vẫn phục vụ truy vấn trong khi phía quan
hệ tin là đã gỡ. Vì vậy `delete()`/`exists()`/`search()` đều nhận collection
tường minh.

## D-5 — Store và provider cùng nằm trong một boundary

Ghi vào index cũng là side effect ra ngoài như gọi provider. Tách đôi sẽ đẩy
thao tác ghi ra ngoài vùng mà quota, approval và safety bảo vệ. `isConfigured()`
được kiểm **trước** khi gọi provider: sinh vector mà không có chỗ chứa là tiêu
quota để lấy về không.

## D-6 — Chỉ acknowledgment mới được đổi trạng thái

`ready` chỉ sau khi store xác nhận ghi; `deleted` chỉ sau khi store xác nhận
xoá. `delete()` của `UnavailableVectorStore` trả **false** chứ không throw: một
purge worker phải *không tiến triển*, chứ không phải báo lỗi hàng loạt hay tệ
hơn là để row đạt `deleted` khi point còn đó.

Ngược lại `exists()` **throw**: "không biết" không phải là "không có". Một row
`pending` sau sự cố không được kết luận là thất bại chỉ vì store đang mất kết
nối.

## D-7 — Payload point chỉ mang định danh

`customer_id`, `is_tenant`, `knowledge_chunk_id`, `knowledge_source_id`,
`source_fingerprint`, `processing_version`. Không raw text, không PII, không
signed URL: index nằm ngoài cơ chế retention và deletion của phía quan hệ, nên
mọi thứ đặt vào đó đều thoát khỏi cả hai.

## D-8 — Việc không thể tiến hành bị loại **trước** gate

`candidates()` lọc trước (không lock) những định danh đã có row không thể
claim, và chỉ `claim()` mới quyết định bằng `lockForUpdate()`. Đây là đường đi
thường gặp nhất — một source đã index xong thì mọi lượt sau đều dừng ở đây.

Nó cũng là thứ giữ cho một định danh bị ghim (OD-3) không tốn kém: nếu không
lọc trước, mỗi lượt worker sẽ sinh một `ai_model_runs` row **và** một quota
hold, mãi mãi, cho công việc không thể tiến hành — một dấu vết audit ghi lại
việc worker tự nói chuyện với chính nó.

## D-9 — Retrieval kiểm lại, không tin index

Một hit chỉ sống sót nếu: embedding `ready`, chunk `active`, source `active`,
không có `deletion_requested_at`, `embedding_hash` tính lại **vẫn khớp** (bắt
trường hợp nội dung đã đổi mà sweep stale chưa kịp chạy), tenant khớp ở cả filter
payload lẫn scope quan hệ, và authorization Media được **vào lại** cho actor
đang hỏi — người hỏi bây giờ không nhất thiết là người đã ingest.

Over-fetch (`retrieval_overfetch`) tồn tại vì việc rơi bớt candidate là chuyện
bình thường; `limit` được áp **sau** khi lọc.

---

# Bằng chứng

Cột "Ai chạy" là bắt buộc: implementer và Owner không có cùng trọng lượng, và
một người review thứ ba cần biết con số nào đã được tái lập độc lập.

| Hạng mục | Kết quả | Ai chạy |
| --- | --- | --- |
| `php artisan test --filter "AiEmbeddingServiceTest\|AiKnowledgeRetrievalServiceTest"` (SQLite) | 36 passed, 129 assertions | implementer; **Owner tái lập 2026-09-10** |
| `php artisan docs:lint` | passed | implementer; **Owner tái lập 2026-09-10** |
| Cùng bộ test trên MariaDB 11.4.12 (Homebrew, `sql_mode` strict) | 36 passed, 129 assertions | **chỉ implementer — chưa được tái lập** |
| Toàn bộ suite (SQLite) | 7 failed, 4 skipped, 1098 passed | chỉ implementer |
| 7 lỗi đó chạy lại trên `a9da018` (worktree riêng) | đỏ y hệt → không phải hồi quy | chỉ implementer |

Lượt MariaDB có ý nghĩa vì cả 7 CHECK constraint của `ai_embeddings` đều đang
hoạt động trên instance đó — đã probe `information_schema.CHECK_CONSTRAINTS`
thay vì suy đoán. Nhưng nó **chưa được ai ngoài implementer chạy lại**, nên
người review thứ ba phải tự dựng lại instance chứ đừng thừa nhận bảng dưới đây:

```
chk_aem_status             status in ('pending','ready','failed','stale','deletion_pending','deleted')
chk_aem_dimensions         dimensions >= 1
chk_aem_vector_store       vector_store = 'qdrant'
chk_aem_deletion_attempts  deletion_attempts >= 0
chk_aem_deletion_pending   status <> 'deletion_pending' or deletion_requested_at is not null
chk_aem_deleted            status <> 'deleted' or deleted_at is not null
chk_aem_ready              status <> 'ready' or embedded_at is not null
```

Nên bộ test đã chứng minh — chứ không chỉ khẳng định — rằng `markReady()` luôn
kèm `embedded_at`, `requestDeletion()`/`settleFailure()` luôn kèm
`deletion_requested_at`, `purgeDeletionPending()` luôn kèm `deleted_at`, và
không có giá trị status nào ngoài vocabulary từng được ghi.

Toàn bộ suite: **7 failed, 4 skipped, 1098 passed (10369 assertions)**. Cả 7
lỗi đã được chứng minh là có sẵn từ trước, không phải hồi quy: chạy lại đúng
những test đó trên commit `a9da018` trong một `git worktree` riêng cũng đỏ y
hệt. Chúng nằm ở `MediaRevisionLifecycleTest` (5),
`VideoTranscriptCaptionLocalReviewTest` (1) và
`AudioProcessingLocalReviewTest` (1) — đều phụ thuộc ffmpeg/whisper thật, không
chạm vào bất kỳ file nào của Bước 5.

Test bao phủ những điểm dễ hỏng âm thầm: payload không chứa text; provider trả
sai số lượng vector (lệch vị trí sẽ gán vector chunk 1 cho định danh chunk 2);
vector sai chiều; store hỏng giữa batch; purge chỉ tombstone phần được xác nhận;
purge là thứ mở khoá `embedding_delete_barrier` của Bước 3; reconcile phân biệt
"không có" với "không biết"; tenant khác không đọc/xoá được gì.

---

# Finding tự phát hiện

## F-1 — `correlation_id` tràn cột — ĐÃ VÁ

`ai_model_runs.correlation_id` là `CHAR(36)` (một UUID). Bản đầu tôi ghi
`'ai-embed:'.uuid` = 45 ký tự. SQLite nhận, MariaDB strict mode từ chối
(`SQLSTATE[22001] 1406`), 30/34 test đỏ. Đã đổi về UUID trần; cột `purpose` đã
nói đây là run embedding.

Điều đáng ghi lại không phải là lỗi, mà là **nó chỉ lộ ra ở cổng MariaDB** — bộ
test SQLite mặc định xanh hoàn toàn với cùng đoạn code đó.

## F-2 — Mỗi lượt worker chạy gate hai lần — CHẤP NHẬN, có ghi nhận

`embedPending()` gọi `authorize()` để lấy `model_run_id` (bắt buộc, xem D-1),
rồi `gate->execute()` tự `authorize()` lại. Năm bước gate vì thế chạy hai lần
mỗi lượt. Không sai: `record()` idempotent theo `run_uuid` và `reserve()`
idempotent theo `source_uuid`, nên chỉ có **một** run và **một** hold. Nhưng
`reserveCalls` là 2 chứ không phải 1, và test đo *delta* thay vì con số tuyệt
đối để không khẳng định sai.

## F-3 — Gate không chặn `correlationId` quá dài — CHƯA VÁ, ngoài phạm vi

`ProviderGateRequest` nhận `correlationId` là string tự do và chỉ vỡ ở tầng DB.
Bất kỳ caller nào của gate cũng dính. Việc vá thuộc Bước 4
(`app/Support/Ai/ProviderGateRequest.php`), giao cho task khác.

---

# OWNER DECISIONS

## OD-1 — DECIDED 2026-09-10 — Phương án A: thêm `failed → pending`

**Trạng thái xác nhận:** Owner đã kiểm tra bản triển khai ngày 2026-09-10 và tái
lập được 36 passed / 129 assertions trên SQLite cùng `docs:lint` PASS, xác nhận
bốn điểm: `failed → pending` cho phép retry; `pending` thuộc run còn sống không
bị giành lại và run `completed` dành cho reconciliation; run thất bại cũ vẫn tồn
tại sau retry; `stale`/`deleted` được báo qua `stranded` chứ chưa được phục hồi.

Đây là **xác nhận riêng phần retry**, không phải review PASS cho toàn Bước 5.

Owner chốt phương án A. Đã vá:

* `docs/database/ai/ai_embeddings.md` v1.1 — thêm amendment "Retry amendment —
  Approved by Owner 2026-09-10" và đưa `failed → pending` vào canonical
  transitions, kèm điều kiện retry (run giữ `pending` phải đã kết thúc).
* `AiEmbeddingService::reclaimable()` — `failed` được claim lại trực tiếp.
* Không có migration: transition là prose, không CHECK nào ràng buộc chúng.
  `chk_aem_status` chỉ ràng buộc *giá trị*, và `pending` đã nằm trong đó.

Bằng chứng bổ sung:
`test_a_retry_keeps_the_failed_attempt_as_evidence` chứng minh lần thử thất bại
vẫn giữ nguyên `ai_model_runs` row bất biến của nó (`failed` +
`AI_PROVIDER_CALL_FAILED`), row embedding trỏ sang attempt thành công, và tổng
số run là 2 — tức tái dùng row **không** làm mất dấu vết nào.

Phần dưới đây giữ nguyên làm ghi chép quyết định.

### Bối cảnh (giữ nguyên)

`docs/database/ai/ai_embeddings.md` (Approved) liệt kê canonical transitions

`docs/database/ai/ai_embeddings.md` (Approved) liệt kê canonical transitions:
`pending → ready|failed`; `ready|failed → stale`;
`pending|ready|failed|stale → deletion_pending → deleted`.

**Không có `failed → pending`.** Đồng thời `uk_aem_chunk_model`
`(customer_id, knowledge_chunk_id, provider, model, embedding_hash)` ghim định
danh, và một tombstone `deleted` cũng ghim y hệt. Hệ quả: một lỗi provider thoáng
qua làm chunk đó **không bao giờ** được index lại — không phải bằng row cũ, cũng
không phải bằng row mới.

Bản đầu của tôi tự cho phép `failed → pending` mà không có tài liệu hậu thuẫn.
Tôi đã gỡ bỏ và báo cáo; Owner sau đó chốt chính hướng đó qua amendment. Thứ tự
này mới đúng: tài liệu duyệt trước, implementation theo sau.

Phương án đã cân nhắc:

* **A — thêm `failed → pending` vào canonical transitions.** ✅ **ĐÃ CHỌN.** Rẻ
  nhất, không cần migration. Bằng chứng thất bại nằm ở `ai_model_runs` (bất
  biến, mỗi attempt một row), nên row embedding là *trạng thái hiện tại* chứ
  không phải sổ attempt.
* **B — thêm cột `generation`/`attempt` vào `ai_embeddings` và đưa vào unique
  key**, đúng cách `ai_knowledge_sources` đã giải bài toán tombstone-ghim-định-
  danh ở Bước 1. Cần migration + doc + vòng review. Không chọn.
* **C — giữ nguyên.** Chấp nhận lỗ hổng index vĩnh viễn. Không khả thi.

## OD-2 — Retrieval vào lại authorization nhưng không ghi audit

Retrieval gọi `CourseMediaOwnerContextAuthorizer::authorized()` — đúng hàm mà
`MediaReadService::read()` dùng — chứ không gọi `read()`. Lý do: chunk text đã
được ingest và nằm trong `ai_knowledge_chunks`; cái retrieval cần là *quyết định
authorization*, không phải trích xuất lại toàn bộ Media cho mỗi truy vấn.

Hệ quả cần Owner xác nhận: `read()` còn sinh audit record ("ai đọc trang nào,
lúc nào" — ADR-0018 § 8). Retrieval hiện **không** sinh record đó. Cần chốt
retrieval có phải là sự kiện phải audit hay không, và nếu có thì audit ở đâu.

## OD-3 — VẪN MỞ — Định danh `stale`/`deleted` sống lại

Nếu một chunk đổi nội dung rồi quay về đúng `content_hash` cũ trong cùng source,
`embedding_hash` cũ tái xuất hiện trên một row đang `stale`. Tương tự với một
row `deleted` khi `requestDeletion()` được gọi thẳng trên chunk còn `active`.

**Amendment OD-1 không đóng trường hợp này** — nó cố ý chỉ thêm
`failed → pending`, không thêm `stale → pending`: một định danh đã bị supersede
không nên tự quay lại vòng truy hồi. Nhưng hệ quả là chunk đó không được index
lại, và đó vẫn là lỗ hổng.

Hiện tại nó **không im lặng nữa**: `candidates()` đếm những định danh bị ghim
bởi `stale` hoặc `deleted` và `embedPending()` trả về qua khoá `stranded`, có
test `test_a_superseded_identity_is_reported_as_blocked_not_retried` chứng minh.
Việc đóng hẳn cần phương án B (cột `generation` trong unique key) hoặc một
đường đi được duyệt riêng cho trường hợp này.

---

# Chưa đóng được

* **Qdrant thật chưa được chạm.** Phiên này không được gọi ra mạng, nên
  `QdrantVectorStore` mới chỉ đúng về hình dạng request. Cần một vòng kiểm với
  Qdrant >= 1.11 thật: hình dạng response của `points/delete`, `points/scroll`,
  `points/search`, và quan trọng nhất là **`customer_id` phải là payload index**
  — nếu không, filter tenant vẫn đúng kết quả nhưng sẽ quét toàn collection.
* **Chưa có embedding provider nào được bind.** `UnavailableEmbeddingProvider`
  throw. Kích hoạt provider là quyết định riêng theo ADR-0018.
* **Bước 4 vẫn Partial.** Gate mà Bước 5 đứng sau chưa được đóng, nên Bước 5
  không thể vượt qua trạng thái đó.
* **Chưa có reviewer độc lập.** Tôi viết toàn bộ code lẫn tài liệu này, nên
  không thể tự nhận PASS.
