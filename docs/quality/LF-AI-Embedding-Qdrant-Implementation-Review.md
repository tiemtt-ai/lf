# AI Embedding + Qdrant — Implementation Review

Version: 1.5

Document Status: Review

Implementation Status: Implemented

Last Updated: 2026-09-14

Review Date: 2026-09-13

Document Path: quality/LF-AI-Embedding-Qdrant-Implementation-Review.md

---

# Scope

Owner xác nhận ngày 2026-09-13: mục tiêu là chuẩn bị dữ liệu để AI thật có thể
sử dụng sau này. Nghiệm thu Bước 5 kiểm backend/schema/tài liệu và các invariant
tenant, authorization, revision, retrieval, lifecycle; không yêu cầu provider
activation, gọi model thật, frontend/chat hoặc AI đã sử dụng dữ liệu. Việc chưa
bật AI thật không phải finding hoặc lý do giữ bước backend ở Partial.
Kiểm chứng adapter trên hạ tầng test thật vẫn thuộc phạm vi kỹ thuật; các
finding kỹ thuật và điều kiện governance phải được xử lý riêng, không được
coi xác nhận này là review PASS hay miễn trừ migration.

Bước 5 của lộ trình Phần 2 — Embedding + Qdrant. Biến `ai_knowledge_chunks`
thành vector point trong Qdrant, và biến vector hit ngược lại thành chunk có thể
trích dẫn, theo [ADR-0006](../adr/ADR-0006-AI-Foundation.md) v1.0.2 (vector
store) và v1.0.3 (`media_file_id` là provenance, không phải authorization), qua
gate của Bước 4.

Ngoài phạm vi: kích hoạt provider thật (quyết định riêng theo ADR-0018), Vision
Interpretation, AI Authoring Proposal, route và UI.

Bản gốc không cần migration mới. Amendment được Owner duyệt ngày 2026-09-13
thêm một migration additive cho `ai_embeddings.generation`; bốn bảng Bước 2
vẫn giữ nguyên ownership và Model Run provenance. `ai_embeddings` đã có
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

Nó cũng giữ cho một định danh đang có worker sở hữu không tốn kém: nếu không
lọc trước, mỗi lượt worker sẽ sinh một `ai_model_runs` row **và** một quota
hold, mãi mãi, cho công việc không thể tiến hành — một dấu vết audit ghi lại
việc worker tự nói chuyện với chính nó.

## D-9 — Retrieval kiểm lại, không tin index

Một hit chỉ sống sót nếu: embedding `ready`, chunk `active`, source `active`,
không có `deletion_requested_at`, `embedding_hash` tính lại **vẫn khớp** (bắt
trường hợp nội dung đã đổi mà sweep stale chưa kịp chạy), tenant khớp ở cả filter
payload lẫn scope quan hệ, và Media Read xác nhận actor, exact active usage,
file và current revision theo locale/profile của source. So khớp hash nội tại
AI không thay thế đối chiếu revision với Media. Người hỏi bây giờ không nhất
thiết là người đã ingest.

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
| 7 lỗi đó chạy lại trên `a9da018` (worktree riêng) | ~~đỏ y hệt → không phải hồi quy~~ **VÔ HIỆU về phương pháp** (đính chính 2026-09-14): worktree symlink `vendor`, Composer tính `$baseDir` từ đường dẫn thật nên code `App\`/`Tests\` được nạp từ repo chính — không phải baseline `a9da018`. Kết luận "7 lỗi có sẵn" nay dựa trên baseline reviewer độc lập dựng lại với `vendor` cục bộ (introduced `[]`, resolved `[]`), xem LF-AI-Embedding-Qdrant-Architecture-Review § 6 | chỉ implementer |

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

## OD-2 — CLOSED by Owner approval 2026-09-13 — Retrieval access audit

Owner approved access audit. `MediaDerivedRetrievalAudit` now owns appends to
the existing Media access log for returned chunks and authorization denials,
including retrieval UUID and exact locators/revisions. Insert failure aborts
disclosure. Zero hits read no Media and need no Media access row. Historical
discussion follows; it is no longer a pending decision. The owner-role-only
implementation described below was insufficient and is superseded by
`MediaReadService::currentRevision()` in the revalidation patch.

Retrieval gọi `CourseMediaOwnerContextAuthorizer::authorized()` — đúng hàm mà
`MediaReadService::read()` dùng — chứ không gọi `read()`. Lý do: chunk text đã
được ingest và nằm trong `ai_knowledge_chunks`; cái retrieval cần là *quyết định
authorization*, không phải trích xuất lại toàn bộ Media cho mỗi truy vấn.

Hệ quả cần Owner xác nhận: `read()` còn sinh audit record ("ai đọc trang nào,
lúc nào" — ADR-0018 § 8). Retrieval hiện **không** sinh record đó. Cần chốt
retrieval có phải là sự kiện phải audit hay không, và nếu có thì audit ở đâu.

## OD-3 — CLOSED by Owner approval 2026-09-13 — New generation, preserved history

Owner approved generation-based re-registration. The additive migration adds
generation to the registration key. Source/chunk locks serialize initial and
replacement registration; old rows and point keys remain unchanged. Latest
stale/deleted identity can create a distinct generation/key, but not while the
source/chunk is deleting. Old-generation purge cannot target the replacement.
Historical discussion follows; the previous stranded-only behavior is superseded.

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

# Historical remaining items — 2026-09-10

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

## Step 5 continuation — 2026-09-13

Current scope: continue the existing uncommitted implementation, not replace
it. Step 4 backend/migration/tests are now complete under the recorded Owner
waiver. Live embedding provider activation and chat/frontend remain outside
Step 5 completion criteria. The historical Step 4/provider/reviewer bullets
above are not current blockers and do not constitute an independent verdict.

Classification: Existing-Feature Change. Initial/final Audit Level: HIGH
(remote acknowledgment controls relational readiness and reconciliation).
No schema, lifecycle vocabulary, Media pipeline, tenant policy or provider
allow-list changes. No production database or live vector store was touched.

Changes:

* Qdrant upsert requires `result.status=completed`, not merely HTTP success.
  Missing or deferred acknowledgment throws before `indexedItems` is updated,
  so an unconfirmed point cannot become relational `ready`.
* Scroll/search require explicit, well-formed result lists. Missing response
  data is unknown/error, never evidence of absence. Transport/application errors
  are represented by stable codes without response bodies.
* Seven adapter contract tests cover positive/negative acknowledgment,
  malformed results, exact tenant/key delete filters and tenant search filter.
  Baseline embedding/retrieval: 36 tests, 129 assertions. After patch, all three
  files: 43 tests, 145 assertions on SQLite. The new tests first exposed three
  adapter defects; a separate test-double sequencing mistake was corrected.
* The three Step 5 test files are registered in the MariaDB CI selection.
  Registration is not a claim that that updated CI job has run.

Owner subsequently approved OD-2 and OD-3 in this task. Their current decisions
are recorded above and in the table documents before implementation. Existing
states/transitions remain unchanged; generation adds a row, not resurrection.
Initially no Docker or Qdrant executable was on PATH. A disposable official
Qdrant 1.11.5 macOS binary was subsequently downloaded to `/tmp`, bound to
localhost only with telemetry disabled. The real integration test provisions
its own collection/index, uses synthetic vectors, and deletes its collection
in finally. This supersedes the initial local-store evidence gap; it does not
activate the application's shipped endpoint/provider configuration.

### Amendment implementation scope

Additive generation migration; tenant-aware Media-owned retrieval audit;
source/chunk locking and hash recheck before registration; distinct keys for
new generations; rollback preflight protects generation history. No source text,
original Media processing result, historical point key or Model Run is rewritten.
Physical verification targets a fresh isolated MariaDB 11.4.12 socket instance,
not `learnforge_db`. Audit uses existing `read_derived` vocabulary and no new
audit table. Source/chunk deletion locks are acquired in the existing order.

Also corrected bounded-batch starvation: scans progress past completed rows to
find the next eligible batch. Reconciliation updates condition on still-pending
status, preventing concurrent deletion from being overwritten by remote results.

### Real Qdrant finding and correction

With the approved keyword index, numeric customer IDs filtered correctly but
`payload_schema.customer_id.points` was **0 for 2 inserted points**. The test
failed on real Qdrant 1.11.5. The adapter now serializes decimal strings for
both payload and tenant filters; relational IDs and internal value objects
remain numeric. This implements the already approved keyword-index design.
Reference: [Qdrant multitenancy](https://qdrant.tech/documentation/manage-data/multitenancy/).
The test also exercises upsert acknowledgment, exists, ranked search, a
wrong-tenant delete no-op and exact correct-tenant deletion. A dedicated CI
job now runs this contract against Qdrant 1.11.5 with synthetic vectors only.
CI configuration is not evidence that GitHub Actions has been triggered.

## Historical Step 5 backend closure — 2026-09-13

Superseded by the retrieval revalidation review below: the tests in this
section did not prove active Media usage/current Media revision authorization.

Final Audit Level: HIGH; escalation: none. Implementer verification, not an
independent reviewer signature. Owner approved both OD-2 and OD-3 in this task.
Live AI, chat/frontend and provider activation are outside closure scope.

| Requirement | Implementation / verification |
| --- | --- |
| Preserve re-registration history | generation migration + source/chunk locks; distinct point keys; stale/deleted rows retained |
| Physical identity and rollback | MariaDB rejects generation 0 and duplicate same generation; accepts generation 2; rollback refuses generation history, succeeds on empty table and reapplies |
| Audit derived access | Media-owned append with tenant/actor/revision/locator; denial recorded; insertion failure aborts disclosure; no raw query/text/vector |
| Retry/reconciliation | conditional status AND Model Run identity; cannot overwrite deletion or finish a newer attempt |
| Deletion barrier | live queued/running writers retain barrier; terminal-run exact tenant/key deletion only advances after acknowledgment |
| Response correctness | upsert acknowledgment; malformed response unknown, not absent; provider vectors all validated for finite numeric values before first write |
| Progress | completed first batch does not starve later chunks |
| Real vector store | Qdrant 1.11.5, localhost, telemetry disabled: keyword index contains both test points; wrong-tenant lookup/search/delete cannot cross tenant |

Verification snapshots:

* Full chain from an empty isolated MariaDB 11.4.12: **98 migrations PASS**.
* Schema contract harvested from that schema; `schema:drift --connection=mysql`
  **PASS**, no contract weakening or Media backfill.
* Final Step 5 MariaDB tests: **51 tests, 182 assertions, no skips**.
* Real Qdrant integration: **1 test, 13 assertions PASS**, after a red test
  demonstrating numeric tenant payloads were not actually indexed.
* Related SQLite scope: **114 passed, 2 skipped, 489 assertions**. Skips require
  MariaDB physical checks, independently covered above.
* Final full application suite: **1119 passed, 5 skipped, 16 failed**
  (10341 assertions). JUnit failure class/name sets are identical to the earlier
  1116-pass snapshot; that snapshot's 16 console labels also match the
  pre-amendment run in this session. No new failures in these comparisons.
  Those failures remain Media/runtime debt. Do not call the whole application
  suite green or substitute these counts for an independent run.
* Frontend build, targeted Pint, docs lint, docs-only schema drift and
  whitespace checks passed. GitHub Actions was not triggered in this task.

Operational handoff: no migration was applied to `learnforge_db`; no live
provider setting was enabled. Both temporary servers were shut down; disposable
MariaDB data and Qdrant binary/storage were removed. Test collections were
removed in finally. Deployment provisions the documented model collection/keyword tenant
index; the application does not silently create infrastructure. Previously
experimental numeric tenant payloads must be rebuilt before activation.
Existing source data and published snapshots are unchanged. Production rollout
and external-provider activation remain separate from implemented backend.

Final verdict: **PASS WITH DOCUMENTED RISKS** for the Step 5 backend; full
application runtime test debt and actual deployment remain explicitly separate.

## Retrieval revalidation correction — 2026-09-13 (current review)

Classification: Existing-Feature Change. Initial/Final Audit Level: HIGH;
escalation: none. The Owner requested this correction after the independent
finding. This section is implementer verification, not an independent signature.

The previous closure missed a real authorization gap: Course owner permission
does not prove that an active usage still binds that owner to the indexed file.
The previous revision hash only proved internal AI consistency. Both claims in
the historical closure are superseded here.

Source of truth remains Media Read. `read()` and the internal identity-only
`currentRevision()` share `readResolved()`: tenant/actor, exact usage slot,
ambiguity, locale/profile and current ready revision selection. The latter
never pins an archived version, materializes text/structures or signs URLs.
It returns distinct identity tuples only; AI compares Media ID, locale,
fingerprint and version with its source snapshot. Multiple identities fail
closed. Per-request caching is by source ID, not merely owner ID.

Retrieval records the final decision through `MediaDerivedRetrievalAudit`.
Denied candidates carry the actual named Media error; allowed is recorded only
after both Media and AI checks. Audit failure still aborts disclosure. A
tombstoned Media file is rejected even if a residual active usage remains.
Normal historical Media reads still support an explicitly pinned archived
revision; default knowledge search does not use that exception.

| Finding / invariant | Correction / regression evidence |
| --- | --- |
| Active Media authorization | Real Course/activity/usage fixtures; detach, replacement with identical fingerprint/version, ambiguous slot, deleted Media, teacher assignment and inactive actor tests |
| Current Media revision | New ready Media output invalidates an unchanged AI snapshot; a failed newer document job does not displace the last ready revision |
| Per-source isolation | Two slots on the same owner cannot share an authorization-cache result |
| Audio/video profile | Audio transcript, video transcript and frame OCR tests preserve the stored multilingual profile and reject a newer revision of that same profile |
| Worker query amplification | MariaDB/MySQL excludes the exact latest non-work generation in SQL before batching; an all-ready tenant makes one candidate SELECT and no identity batch SELECTs |
| Worker correctness | Existing lifecycle tests plus changed fingerprint and newer replaceable generation tests; PHP still rechecks candidates under source/chunk locks |
| Obsolete stranded counter | Removed from candidate collection; `stranded=0` retained explicitly as a deprecated compatibility field, not a backlog measurement |

Impact: retrieval, Media Read selector reuse, access audit and embedding work
selection. No new schema, migration, provider activation, route, UI, OCR/STT
provider changes, historical backfill or source deletion in this correction.
New repository files in this correction: None. Earlier uncommitted Step 5
files are preserved. Browser QA is not applicable; build is checked separately.

Verification:

* Before correction: embedding/retrieval SQLite **43 passed, 1 skipped,
  163 assertions**. The old tests did not cover active Media bindings.
* After correction, related Media Read/substrate/ingestion/embedding/retrieval
  SQLite: **193 passed, 5 skipped, 871 assertions**. Skips require MariaDB.
* Isolated-process mutation bypassing the Media decision: **6/6 tests fail**
  (detach, replacement, ambiguity, tombstone, new revision, wrong slot).
  The mutation never changed workspace code.
* Isolated **MariaDB server 11.4.12** (verified with `SELECT VERSION()`), fresh
  98-migration schema: **65 tests, 250 assertions, no skips, PASS** for embedding,
  retrieval and Qdrant HTTP-adapter tests. This includes the SQL query-budget
  assertion, not merely the SQLite fallback. The first run rejected incomplete
  test fixtures (`completed_at`/output provenance and OCR provider); fixtures
  were corrected to satisfy existing CHECKs, no constraint was weakened.
* Latest targeted SQLite: **56 passed, 2 skipped, 228 assertions**.
* Read-only schema drift on that isolated database: **PASS**. No new migration
  or contract change was needed for this correction.
* Build, targeted Pint, docs lint and docs-only schema drift: **PASS**.
* Final full-suite comparison is recorded below. No real Qdrant/provider call
  or GitHub Actions run was repeated for this Media authorization correction;
  the prior real Qdrant result is historical evidence, not a new measurement.

Remaining scale boundary: the SQL prefilter removes client-side per-batch
query amplification, not the database's O(N) candidate evaluation. SQLite
retains the portable scan because it has no built-in SHA2. The query-count
test is synthetic, not a million-row latency benchmark. A persistent dirty-work
queue/index would be a separate schema/design change if deployment metrics
require it. No new atomic concurrency guarantee for a detach racing a read is
claimed by these sequential authorization tests.

Final full suite on the corrected code: **1132 passed, 6 skipped, 16 failed,
10406 assertions**. JUnit class/name failure sets match the earlier saved
`lf-step5-closure-suite.xml` exactly: 16 before, 16 after, introduced `[]`,
resolved `[]`. This is a comparison to that saved local snapshot, not a newly
run clean-commit baseline. The full suite is not green. The two skipped
embedding checks are covered by the separate MariaDB run above; other existing
physical-only suites skipped by SQLite were not rerun on MariaDB in this
correction. Do not read the 65-test scope as the full CI MariaDB job.

Findings by severity: no remaining HIGH/BLOCKER from the reported usage and
revision gaps; database scan cost at large scale remains a documented MEDIUM
operational risk. Final verdict: **PASS WITH DOCUMENTED RISKS** for this
correction. Step 5 backend closure is reinstated on this evidence, not on the
superseded owner-role-only tests. No live AI/chat is a closure condition.

Handoff: the isolated MariaDB server was shut down and its synthetic datadir
removed; diagnostic logs were retained in the temporary working directory.
No `learnforge_db` migration, real provider activation, live evidence changes,
commit or push was performed in this correction.

## P3 documentation precision — 2026-09-13

Hai điểm P3 từ lượt đọc lại độc lập sau "Retrieval revalidation correction".
Chỉ sửa tài liệu; **không đổi code, schema hay test**. Cả hai được ghi thẳng vào
contract ở [media_access_logs § Retrieval audit amendment](../database/media/media_access_logs.md).

### P3-1 — Guard `deleted → missing` nằm trong code dùng chung

Amendment trước ghi *"Existing processing/read consumers are unchanged."* Câu đó
đúng về hành vi quan sát được nhưng không đúng từng chữ: guard
`status = deleted → missing` nằm trong `readResolved()`, selector mà cả `read()`
lẫn `currentRevision()` cùng dùng, nên ở mức code nó áp cho **mọi** consumer.

Đã kiểm guard có chạm được luồng chuẩn không: `MediaService` từ chối xoá Media
File khi còn usage `active` (`LF_media_file_delete_blocked_in_use`), và nhánh
guard chỉ tới được **sau** khi đã tìm thấy đúng một usage active. Vậy qua luồng
chuẩn, guard không bao giờ chạy. Nó là lớp phòng thủ trước ghi dữ liệu ngoài
luồng chuẩn, không phải thay đổi hành vi.

Cùng lý do, câu ở mục trên *"A tombstoned Media file is rejected even if a
residual active usage remains"* nên đọc là: một "residual active usage" trên
file đã tombstone **chỉ** phát sinh được từ ghi ngoài luồng chuẩn.

### P3-2 — Audit không nguyên khối trong một lần retrieval

"Audit failure still aborts disclosure" đúng, nhưng thiếu một hệ quả. Audit
`allowed` được ghi tuần tự cho từng hit sau `array_slice`. Nếu `append()` lỗi ở
hit thứ *k*, các bản ghi `allowed` của hit 1…*k*−1 **đã được lưu** trong khi
`retrieve()` ném lỗi và không trả nội dung nào. Các bản ghi `denied` ghi trong
vòng lặp trước đó cũng giữ lại — những bản đó chính xác, vì việc từ chối đã thực
sự xảy ra.

Hệ quả: với một retrieval UUID, bằng chứng có thể **nói quá** mức đã tiết lộ
nhưng **không bao giờ thiếu**. Đây là hướng an toàn và được chấp nhận; contract
nay ghi rõ một dòng `allowed` là *quyền được tiết lộ*, không phải *đã giao
thành công*. Nếu cần audit nguyên khối, bọc các lần `append()` trong một
transaction — việc đó là thay đổi code, nằm ngoài lượt này.

| Điểm | Loại | Trạng thái |
| --- | --- | --- |
| P3-1 guard trong selector dùng chung | Độ chính xác tài liệu | Đã ghi vào contract |
| P3-2 audit không nguyên khối | Hành vi chấp nhận được, nay có tài liệu | Đã ghi vào contract; transaction là tuỳ chọn tương lai |
| `$stranded` luôn bằng 0 (P3 trước đó) | Trường tương thích | Đã đóng ở mục trên: giữ rõ ràng như trường deprecated |

## P1/P2 remediation — 2026-09-13 (implementer evidence)

Nguồn: lượt review kỹ thuật Bước 5 với kết luận *CHANGES REQUIRED*. Người review
đó đã tham gia triển khai, nên đây **không** phải chữ ký độc lập. Hai finding
được tái lập bằng cách đọc code tại snapshot `b5da390` trước khi vá.
Phần này là bằng chứng của implementer, không phải verdict.

### P1 — Từ chối ở lần authorize thứ hai bị coi là thành công

**Hai tầng.**

1. `embedPending()` bỏ qua giá trị trả về của `gate->execute()`. `execute()`
   authorize lại trước khi claim run, và khi bị từ chối nó **trả về** decision
   chứ không ném exception. Nhánh `catch (AiProviderGateException)` không chạy;
   worker trả `error_code = null`.
2. `blocked` không nằm trong `LIVE_RUN_STATUSES` lẫn `ABANDONED_RUN_STATUSES`,
   nên `reclaimable()` luôn trả `false` và các row `pending` kẹt vĩnh viễn.

**Nguyên nhân gốc.** Việc phân loại run status được mã hoá ở ba nơi — hai hằng
và một mảng inline trong purge — và chúng **không thống nhất** về `blocked`:
purge coi nó đã dừng, đường claim thì không. Đây lại là kiểu lỗi "liệt kê thiếu
một status" đã phá thiết kế nhiều lần trong dự án.

**Bản vá.**

* `embedPending()` kiểm `$executed->allowed`. Bị từ chối thì trả đúng
  `errorCode` và `blocked_at`, đồng thời chuyển row `pending` của đúng run đó
  sang `failed` với mã từ chối, qua `settleFailure()` có guard `model_run_id`.
  Lượt sau, retry canonical `failed → pending` tự lo phần còn lại.
* Ba cách mã hoá được thay bằng **một** `RUN_STATUS_POLICY` phân loại đủ sáu
  status theo tên, với hai câu hỏi `writer_stopped` và `reclaimable`. Status
  không có trong map, kể cả run không tìm thấy, trả `false` cho cả hai.
* `blocked` được claim lại. An toàn chứng minh từ `AiModelRunRecorder`: không có
  transition `running → blocked`, nên run `blocked` chưa từng tới provider.

### P2 — Purge bị bỏ đói bởi các row đứng đầu

`purgeDeletionPending()` chạy `ORDER BY id LIMIT n` trước, rồi mới lọc run còn
sống trong PHP. Row thuộc run `running` chiếm hết batch. Đây là **cùng loại** lỗi
*LIMIT trước bộ lọc* đã từng vá trong `candidates()`; bản vá khi đó chỉ vá tại
chỗ. `reconcilePending()` không mắc, vì nó vốn lọc run status trong SQL trước
`limit` — chính là khuôn mẫu cho bản vá này.

**Bản vá.** Join `ai_model_runs` và lọc `writer_stopped` trong SQL **trước**
`limit`. Hàng rào giữ nguyên độ chặt: row thuộc run còn sống hoặc không tìm thấy
không bao giờ được chọn.

**Thay đổi hình dạng kết quả.** Trước đây `retained` gộp hai chuyện khác nhau:
*store từ chối xoá* và *đang chờ writer còn chạy* — người vận hành không phân biệt
được Qdrant đang sập với một run đang chạy. Nay tách:

| Khoá | Nghĩa |
| --- | --- |
| `deleted` | Store xác nhận xoá, row thành tombstone trong lượt này |
| `retained` | Store từ chối hoặc lỗi trong lượt này; `deletion_attempts` tăng |
| `held_by_writer_barrier` | Row toàn tenant đang chờ writer dừng; không gửi tới store, `deletion_attempts` không đổi |

Không tài liệu nào mô tả hình dạng kết quả purge, nên việc đổi khoá không chạm
contract. Ba test so nguyên mảng được cập nhật; đã xác nhận diff của chúng chỉ là
khoá mới, hành vi không đổi.

### Tài liệu

`ai_embeddings.md` được thêm *Blocked-run retry amendment*. Liệt kê cũ
`(failed hoặc cancelled)` nằm trong một tài liệu Approved, nên việc thêm
`blocked` phải khớp trong doc chứ không chỉ trong code. Amendment ghi nguồn phê
duyệt ban đầu là yêu cầu *"vá P1 và P2"* của Owner, đưa ra sau khi hướng vá — gồm
cả việc thêm `blocked` — đã được trình bày. Cách ghi nhận đó từng chờ xác nhận.
**Đã được Owner xác nhận trực tiếp ngày 2026-09-13** qua câu "về phía tôi tôi
đồng ý mục \"Cần anh xác nhận\"". Giữ amendment và đường claim lại dưới run
`blocked`; không còn quyết định treo đối với mục này. Xác nhận không phải
verdict review toàn packet hay phê duyệt các finding mới ngoài P1/P2.

Không thêm transition vào lifecycle `ai_embeddings`. Không đổi schema.

### Test

| Test | Kiểm |
| --- | --- |
| `test_a_refusal_on_the_second_authorization_fails_claimed_rows_for_retry` | Rút approval giữa hai lần authorize: trả `AI_APPROVAL_REQUIRED`/`tenant_approval`, 3 row `failed`, 0 provider call, 0 point; cấp lại approval → 3 row `ready` |
| `test_pending_rows_under_a_blocked_run_are_reclaimed` | Khoảng hở crash: row `pending` dưới run `blocked` được claim lại và `ready` |
| `test_purge_is_not_starved_by_rows_behind_a_live_writer` | `limit = 1`, row đầu thuộc run `running`: hai lượt xoá được hai row, hàng rào giữ row đầu và point của nó, `deletion_attempts` không đổi |
| `test_run_status_policy_covers_exactly_the_schema_vocabulary` | Khoá của `RUN_STATUS_POLICY` khớp đúng `chk_amr_status` đọc từ LF-SCHEMA-CONTRACT.json, và ngữ nghĩa từng status được ghim |

Helper test `modelRun()` được sửa để row thoả `chk_amr_completed`,
`chk_amr_failed` và `chk_amr_blocked` — trước đó nó chèn run `blocked`/`failed`
thiếu `error_code`, chỉ qua được trên SQLite. `FakeTenantSettings` có thêm
`revoke()`.

### Kiểm chứng

| Hạng mục | Kết quả | Ai chạy |
| --- | --- | --- |
| Bốn test mới trên code **trước** khi vá | **4/4 đỏ** | implementer |
| Cùng bốn test sau khi khôi phục bản vá (byte-identical) | 4/4 xanh, 31 assertions | implementer |
| Test embedding + retrieval + Qdrant adapter, SQLite | 67 passed, 2 skipped, 275 assertions | implementer |
| Cùng bộ test trên MariaDB **server 11.4.12** (`SELECT VERSION()`), instance cô lập | **69 passed, 0 skipped, 281 assertions** — hai test chỉ-MySQL cũng chạy; bốn test mới xanh, xác nhận helper thoả CHECK thật và phép join của purge | implementer |
| Pint (các file đã sửa), `docs:lint`, `git diff --check` | PASS | implementer |
| Toàn suite, SQLite, tên test đọc từ JUnit | 7 failed, 6 skipped, 1145 passed, 10575 assertions | implementer |

Bảy test đỏ trùng **theo tên** với bảy lỗi ~~đã ghi nhận có sẵn từ `a9da018`~~ *(đính chính 2026-09-14: danh sách "có sẵn" đó đến từ lượt baseline symlink `vendor` vô hiệu; đối chiếu hợp lệ là với baseline dựng lại của reviewer độc lập, xem mục đóng Bước 5)*:
`MediaRevisionLifecycleTest` (5), `VideoTranscriptCaptionLocalReviewTest` (1),
`AudioProcessingLocalReviewTest` (1). Không test nào thuộc embedding, retrieval
hay Qdrant. Lưu ý phạm vi của phép so: đây là đối chiếu tên với **danh sách đã
ghi**, không phải một lượt chạy baseline mới cùng lúc. Trong lúc chạy, reviewer
độc lập có một tiến trình `phpunit` riêng trong worktree `/tmp/lf-step5-review`
(đã kiểm `cwd`), với SQLite in-memory riêng — chỉ tranh CPU, không chung trạng
thái.

### Finding mới — CHƯA VÁ, ngoài phạm vi P1/P2

**P2 — Hold quota của lần authorize đầu không được trả khi lần hai bị chặn sớm.**
Trong `authorize()`, bước approval và entitlement chặn **trước**
`record(queued)` và `reserve()`. Vậy khi lần authorize thứ hai (bên trong
`execute()`) bị chặn ở một trong hai bước đó, hold đã giữ ở lần đầu — cùng
`run_uuid` — không được `release()`. Nó ở `reserved` cho tới khi lease hết hạn
rồi mới thành `expired`. Có giới hạn thời gian, không vĩnh viễn, và không có
provider call nào; nhưng quota bị giữ trong suốt lease. Lỗi nằm ở gate Bước 4,
bị kích hoạt bởi thiết kế authorize hai lần (F-2). Cần implementer của gate xử lý.

**Chưa kiểm — biến thể head-of-line.** Nếu `delete()` hoặc `exists()` lỗi *lâu
dài* với một số row cụ thể (ví dụ collection của model cũ không còn), chính các
row đó luôn đứng đầu `ORDER BY id` và vẫn có thể bỏ đói phần phía sau. Bản vá P2
không phủ trường hợp này.

### Snapshot và review độc lập

Các bản vá này **sau** snapshot `b5da390`. Verdict của reviewer độc lập đang chạy
chỉ áp cho `b5da390` — bản còn chứa P1 và P2. Cần một snapshot mới và một lượt
review lại phần delta; không được đọc verdict của `b5da390` như thể nó phủ luôn
code đã vá.

### Owner confirmation và kiểm tra tiếp — 2026-09-13

Owner đã xác nhận trực tiếp mục "Cần anh xác nhận" của bàn giao P1/P2;
amendment retry `blocked` được giữ nguyên. Lượt tiếp theo chỉ sửa tài liệu,
không sửa thêm runtime, migration hay test. Artifact Architecture Review được
đăng ký trong quality README, LF-INDEX và manifest; giữ nguyên verdict của
reviewer cho snapshot `b5da390`, không chuyển thành PASS cho working tree mới.

Kiểm tra chạy lại trong lượt xác nhận:

* Embedding + retrieval + Qdrant adapter trên SQLite: 67 passed, 2 skipped,
  275 assertions. Hai test chỉ-MySQL không được tính là đã chạy.
* `docs:lint`: PASS; ba lỗi đăng ký artifact đã hết.
* `schema:drift --docs-only`: PASS, 98 migration.
* `git diff --check`: PASS.

Không chạy lại full suite, MariaDB hay Qdrant thật trong lượt thuần tài liệu
này. Các số liệu trước đó vẫn là bằng chứng của lượt trước. Không apply
`learnforge_db`, không commit, không kích hoạt provider. Hai finding mới ngoài
P1/P2 nêu trên vẫn mở; xác nhận amendment không phải phê duyệt hoặc đóng chúng.

## Follow-up items 1–3 — 2026-09-13

Classification: Existing-Feature Change. Initial/Final Audit Level: HIGH
(embedding lifecycle, deletion barrier and retrieval safety). No escalation.
Scope is the three follow-up items requested by Owner, not provider activation,
frontend/chat, quota cleanup or Qdrant retry fairness.

### Item 1 — policy choice was pending at the items 2/3 handoff

No new automatic `running` recovery is implemented in this follow-up. Owner
has been asked to choose controlled operator recovery (with confirmation that
the old writer stopped, keeping unknown quota held) or automatic recovery with
lease/heartbeat and writer fencing. Elapsed time alone does not prove a writer
stopped; a vector's presence does not prove that provider settlement succeeded.
The previous approval for reclaiming `blocked` does not answer this question.

Superseded by the explicit controlled-recovery Owner decision below; the
paragraph above records the earlier handoff, not current pending approval.

### Item 2 — canonical supersession implemented

Ingestion now requests `pending → deletion_pending` before staling
`ready|failed`. Pending first ensures completion that won the race is caught by
the subsequent ready/failed sweep, while completion that lost cannot overwrite
the deletion request. Source/chunk remain stale as before. The source lock and
existing transaction are retained; neither Media evidence nor Commercial quota
is mutated. No new status or migration is introduced.

Regression test `test_new_revision_queues_pending_embeddings_for_delete_without_staling_live_work`
failed on the original code (`stale` instead of `deletion_pending`) and passes
after the fix. It checks retained run provenance, writer barrier with zero
remote deletes while running, and successful purge after the run completes.

### Item 3 — six independent regression guards implemented

Added tests for source archived, active chunk with deletion request, and four
independent Media identity mismatches (multiple revisions, locale only,
fingerprint only, version only). The latter isolate the consumer guard with a
MediaRead test double; existing real-selector tests remain unchanged.

Six mutations were executed in isolated PHP processes using an in-memory copy
of the retrieval class, without changing its file. Each removed exactly one
guard; all six produced one assertion failure in the corresponding test.
No mutation survived. Normal runtime retrieval code is unchanged.

### Verification and remaining scope

* Pre-change related baseline: 73 passed, 3 skipped, 334 assertions (SQLite).
* Final ingestion/embedding/retrieval/Qdrant-adapter scope: 87 passed,
  3 skipped, 400 assertions (SQLite). Includes seven new regression cases.
* Mutation: 6/6 caught; ingestion red-before-green-after confirmed.
* Targeted Pint, docs lint, docs-only schema drift (98 migrations), frontend
  build and diff check pass.
* No new migration, route, controller or UI. No production apply or commit.
* No new MariaDB or real Qdrant run in this follow-up; three driver-specific
  skips are not claimed as physical verification. Existing CI includes these
  test files. Full-suite result is recorded separately when complete.

Full suite completed: 1,165 tests, 10,487 assertions, 14 failures + 2 errors,
6 skipped (1,143 passed). All 16 failing cases are in AudioProcessingLocalReview,
DocumentProcessingLocalReview, MediaRevisionLifecycle or
VideoTranscriptCaptionLocalReview. No ingestion/embedding/retrieval case failed.
JUnit `/tmp/lf-step5-items123-full.xml` was compared by class+test name to the
available archived `full-baseline-a9da018.xml` and `full-snapshot.xml`: each
archive has 7 failures, all contained in the current 16. Nine additional
Media/runtime cases therefore cannot be called "same baseline" on this evidence.
No fresh identical-environment baseline was run; full-suite non-regression
remains unverified. This suite started before the final pending-first statement
ordering adjustment; the 90-test related scope above ran after that adjustment.

Overall items 1–3 are not declared done: item 1 awaits the recovery policy
decision and implementation; items 2/3 have the above runtime/test evidence.
This implementer follow-up does not replace the independent snapshot review.

Final Verdict for the complete items 1–3 request: BLOCKED pending item 1 policy
choice and the outstanding physical/full-suite verification. This does not
change the green targeted evidence for items 2/3 or imply a live-AI requirement.

## Item 1 — controlled recovery implementation, 2026-09-13

Owner explicitly chose "phục hồi có kiểm soát sau khi xác nhận worker cũ đã
dừng". This supersedes the policy-choice blocker above, not any independent
review verdict. Initial/Final Audit Level: HIGH (run lifecycle and deletion
barrier); no schema, Media pipeline, Commercial authority or route changes.

Implemented `ControlledEmbeddingRecovery` and operations CLI
`ai:embedding-recover`. Both confirmations are mandatory: old workers stopped
and outstanding vector-store writes drained. An active same-tenant admin and
exact embedding run UUID are required. The confirmations are trusted operator
attestations, not OS/process detection or automated fencing.

Within one database transaction, lock actor/run, allow only `running` embedding
runs, preserve immutable provenance/measurements, write `cancelled` plus
`metadata.controlled_recovery`, and queue nondeleted embeddings for cleanup.
Repeated recovery preserves the first audit. Failed writes roll back the run
and embedding changes together. No provider, vector or Commercial method is
called by recovery itself.

Uncertain points are never promoted to `ready`. Existing exact-key purge must
acknowledge deletion before the normal worker can create a new generation.
Unknown quota remains held for Commercial reconciliation. This intentionally
rebuilds uncertain work instead of claiming it completed; providers disabled
remain disabled. The operational procedure is documented in LF-AI.

Four regression tests cover negative confirmations, wrong role/tenant/run
state, CLI context restoration, rollback, repeated calls preserving audit,
quota unchanged, failed deletion retaining the retry barrier, and successful
purge followed by a distinct generation with old history retained.

SQLite related scope (gate + embedding + ingestion + retrieval + Qdrant adapter):
129 passed, 3 skipped, 647 assertions. Targeted Pint, docs lint, docs-only drift
(98 migrations), command help and frontend build pass. Full-suite and isolated
MariaDB evidence are recorded below when complete. No production command was
executed, no provider activated, no commit made.

Full suite for controlled recovery: 1,169 tests, 10,542 assertions, 1,147 passed,
6 skipped, 14 failures + 2 errors. Compare class+test name in
`/tmp/lf-controlled-recovery-full.xml` against the immediately preceding
`/tmp/lf-step5-items123-full.xml`: 16 failing cases in both, new = [], resolved =
[]. This is evidence of no added full-suite failures for item 1 relative to
that local pre-recovery run, not proof that the entire repository suite is green
or that the older archived seven-failure baseline is equivalent.

Physical verification: isolated MariaDB **11.4.12**, confirmed by
`SELECT VERSION(), @@socket, @@skip_networking` (socket
`/tmp/lf-controlled-recovery-db.euT5CD/mysql.sock`, networking disabled).
All 98 migrations applied to disposable `lf_recovery_test`; the five-file
related scope passed **132 tests, 656 assertions, zero skipped**. Includes the
four new controlled-recovery tests and the preceding items 2/3 tests. Migration
and test logs retained under that temporary directory; the server is shut down
after testing and its generated data is removed. No production database used.

Implementation status for item 1: implemented and verified on SQLite/MariaDB.
No automatic crash detection, live-provider activation, automatic quota refund,
or real-Qdrant recovery drill was performed. A simultaneous two-process recovery
race was not tested; serialization uses existing transactional row locks and
repeated-call idempotence is covered. Final Verdict for this controlled-recovery
change: PASS WITH DOCUMENTED RISKS (operator attestations required; existing
16 full-suite failures unchanged). This is not closure of all Step 5 findings.

## Items 4–5 implementation — 2026-09-13

The embedding worker now supplies its original AllowedExecution to execute.
Reauthorization checks the exact request/run/tenant and locks the queued run.
A returned refusal releases the prior reservation while persisting the blocked
audit in that transaction. A running or otherwise claimed run is refused before
cleanup, preserving the winner's quota. Commercial reserve remains idempotent
and its release method still refuses the provider boundary. No quota schema,
state vocabulary, provider allow-list or production data changed.

Maintenance keeps its bounded SQL limit and existing writer barrier. Purge
orders by deletion_attempts/id; completed-run reconciliation uses updated_at/id,
refreshing updated_at on unknown store results without treating them as absent.
This fixes repeated low-id point failures occupying every batch. Clock-tick
ties can temporarily repeat a reconcile row; this is not a persistent scheduler
cursor or a promise of throughput under unbounded incoming work. No extra
index was added, so sort cost remains a scaling consideration.

Regression coverage includes early second-pass refusal with the real database
quota store (allow-list, tenant approval, entitlement), refusal without refund
for an already claimed run, mismatched request protection, and point-specific
persistent deletion/lookup outages with limit one. Real external providers are
not used. MariaDB two-connection serialization and real-Qdrant outage testing
have not been rerun for these changes; prior MariaDB results above must not be
represented as evidence for this new code.

Verification for items 4–5: related SQLite scope **134 passed, 3 skipped, 686
assertions**. Full suite on the final files: **1,152 passed, 6 skipped, 16
failing cases, 10,581 assertions**. Class+test-name comparison of
`/tmp/lf-step5-items45-final.xml` with `/tmp/lf-controlled-recovery-full.xml`
has new = [] and resolved = []. The earlier `/tmp/lf-step5-items45-full.xml`
contained an additional incomplete-test-fixture error (missing entitlement),
fixed before the final rerun; it is not the verification result. Targeted Pint,
docs lint, docs-only drift (98 migrations) and diff check pass. No production
command, migration apply, provider activation or commit was performed.

## Items 4–5 physical verification follow-up — 2026-09-13

Classification: verification of the existing HIGH-risk quota/delete change;
no production code or policy changes in this follow-up. Source of truth,
tenant ownership, run lifecycle and provider activation remain unchanged.
New file: `tests/Support/Ai/gate_revalidation_worker.php`, a testing-only child
process with a dedicated-database guard. Updated tests and the existing Qdrant
CI job; no new migration, route, controller or UI. No architecture assumptions.

Dedicated MariaDB server reports **11.4.12-MariaDB**, socket
`/tmp/lf-items45-physical.Xdtlgm/mysql.sock`, skip_networking=1; all 98 migrations
applied to disposable `lf_items45_test`. Read-only schema drift on that database
passes. Qdrant **1.11.5**, commit `dee106d74625e891256ebe06c2c2e0515650e67e`,
listened only on 127.0.0.1:16333/16334 with telemetry disabled. Reused the
previously downloaded binary; tarball SHA-256 is
`90a7ba94888fd46847a771b9acda18b6ecd013aaba264456e1c864775c0f624f`.
This matches the previous local record, not a publisher signature verification.

Requirement-to-test evidence:

- Run serialization: two distinct physical connection IDs; the child gets
  MariaDB 1205 while the parent holds the run lock. After a running claim wins,
  revalidation returns AI_RUN_ALREADY_EXECUTED and the real hold stays executing.
  Conversely, blocked audit/refund remains locked against a second claim;
  after commit that claim returns false and the hold remains released.
  Standalone result: **1 passed, 23 assertions**.
- Real Qdrant: keyword tenant payload index, cross-tenant search/delete
  isolation, and a real HTTP 404 from one damaged collection locator. With
  limit one, later rows reconcile/purge successfully while the faulty row is
  retained. Restoring its locator allows successful reconciliation/deletion.
  No fake HTTP response or external AI call. SQLite + real Qdrant smoke:
  **2 passed, 30 assertions**. The maintenance case is now in the Qdrant CI job.
- MariaDB gate/quota/physical SaaS packet: **75 passed, 318 assertions**.
- MariaDB embedding/ingestion/retrieval/Qdrant adapter + real Qdrant integration:
  **98 passed, 506 assertions**, including the real maintenance test.
  These two non-overlapping groups total **173 passed, 824 assertions**, no skips.
- SQLite gate/embedding without real endpoints: **85 passed, 4 skipped,
  461 assertions**; skips are explicit physical-engine/endpoint requirements.
- Targeted Pint, npm build, docs lint, docs-only drift and diff check pass.

Logs are retained in `/tmp/lf-items45-physical.Xdtlgm/`. The new full-suite run
was stopped after over 30 minutes while in the real Document runtime scope;
it did not produce a complete result and must not be labelled green or baseline
equivalent. The previous complete run (1,152 passed / 16 existing failing cases)
above remains historical evidence for the unchanged production code, not a
fresh result for this follow-up. No fresh whole-Step-5 closure verdict is issued
here. Physical verification for items 4–5 passes; runtime-wide regression
completion remains unverified in this follow-up. This is implementer evidence,
not an independent review signature.

Cleanup: shut down only the isolated MariaDB and Qdrant instances, remove their
generated data and snapshots, retain logs/configuration and the borrowed binary.
No production database apply, production recovery or provider activation.

## Item 6 — real Qdrant payload-index evidence, 2026-09-13

Clarification: follow-up item 6 is the missing real-Qdrant verification, not
the remaining P3 fake-quota or repeated-deletion findings. Those are separate.

Reran `tests/Integration/QdrantVectorStoreIntegrationTest.php` against a fresh
isolated Qdrant **1.11.5** instance on 127.0.0.1:16333/16334, telemetry off:
**1 passed, 13 assertions, no skips**, 2.32 seconds. Logs and JUnit are
`/tmp/lf-items45-physical.Xdtlgm/item6-test.log` and `item6.xml`.

The test queries Qdrant's real collection API after two synthetic upserts and
asserts `payload_schema.customer_id.data_type = keyword`,
`payload_schema.customer_id.params.is_tenant = true`, and indexed `points = 2`.
Search for tenant 11 excludes tenant 22. Deleting tenant 11's key with tenant
22's filter acknowledges a no-op and leaves the point intact; deleting it with
the correct tenant removes it and preserves tenant 22's point. No HTTP fake or
live AI provider is involved.

Item 6 verification gap: **CLOSED at implementation-evidence level**. The test
explicitly provisions the documented index on its disposable collection; it
does not certify indexes on any existing production collection. This is not a
new independent-review signature and does not rewrite the reviewer's historical
snapshot verdict. The instance/data are removed after verification; the test's
finally block deletes its unique collection. No production database touched.

## Step 5 closure — 2026-09-14

### Quyết định của Owner

* **Miễn trừ review Bước 5.** Điều kiện `Architecture Review passed` của
  `AGENTS.md` § Database Rule được **Owner miễn trừ** cho migration
  `2026_09_13_000100_add_ai_embedding_generation.php` và runtime Bước 5. Đây là
  miễn trừ, **không phải** review PASS. Phạm vi và ràng buộc ghi ở
  [Reviewer Brief § Owner waiver](LF-AI-Embedding-Qdrant-Reviewer-Brief.md).
* **Vá và ghi hoãn** các finding còn lại theo bảng dưới.

Lượt review độc lập duy nhất (LF-AI-Embedding-Qdrant-Architecture-Review) ghi
verdict **FAIL** trên snapshot `b5da390` rồi bị ngắt. Verdict đó giữ nguyên là
hồ sơ lịch sử của `b5da390`; miễn trừ không đảo ngược nó.

**Quy ước tên.** Mục này dùng tiền tố `AR-` cho finding của Architecture Review.
Hai mục "P3-1/P3-2" ở phía trên tài liệu này là finding **của implementer** (guard
`deleted`, audit không nguyên khối) và chỉ trùng số với AR-P3-1/AR-P3-2.

### Cách xử lý mọi finding của review độc lập

| Finding | Nội dung | Xử lý | Nơi ghi / bằng chứng |
| --- | --- | --- | --- |
| AR-P1-1 | Từ chối ở authorize lồng làm row `pending` kẹt và báo thành công | **Đã vá** | § P1/P2 remediation; § Items 4–5 |
| AR-P2-1 / AR-OD-2 | Ingestion ghi `pending → stale` ngoài lifecycle | **Đã vá** | § Item 2 |
| AR-P2-2 | Post-validation thiếu test đỏ khi bỏ đi | **Đã vá** | § Item 3 (mutation 6/6) |
| AR-P2-3 / AR-OD-1 | Run `running` có tiến trình chết không có đường phục hồi | **Đã vá** — phục hồi có kiểm soát do vận hành xác nhận, không phải fencing tự động | § Item 1 |
| AR-OD-3 | Run `blocked` có được claim lại | **Đã chốt** | `ai_embeddings.md` Blocked-run retry amendment |
| AR § 6 Qdrant thật | Không kiểm chứng được trong phiên reviewer | **Đã bổ sung bằng chứng** (implementer, không phải reviewer) | § Item 6 |
| AR-P3-1 | `ai_embeddings.md` ghi "only terminal runs" nhưng purge cho `blocked` | **Đã vá** tài liệu | `ai_embeddings.md` đoạn generation amendment |
| AR-P3-2 | Ack xoá của Qdrant là ack request, không chứng minh point đã mất | **Hoãn có điều kiện** | Xem § Hoãn |
| AR-P3-3 | Provider cấu hình sai tạo run + hold mỗi lượt | **Hoãn có điều kiện** | Xem § Hoãn |
| AR-P3-4 | Ba lỗ hổng test | **Đã vá** | Bốn test mới, 4/4 đột biến bị bắt |
| AR-P3-5 | `FakeUsageQuotaReserver` không idempotent | **Đã vá** | Fake mô phỏng store thật; test rào chắn |
| AR-P3-6 | `requestSourceDeletion` ghi đè `deletion_requested_at` | **Đã vá** | Test đỏ trước, xanh sau |
| AR-P3-7 | Hit từ source không phải Media làm hỏng cả lượt retrieval | **Hoãn có điều kiện** | Xem § Hoãn |
| AR-P3-8 | `LF-AI.md` ghi "Bước 5 backend hoàn tất" khi điều kiện review chưa đạt | **Đã vá** tài liệu | `LF-AI.md` mục trạng thái |
| AR-P3-9 | Symlink `vendor` khiến worktree nạp code repo chính | **Đã vá** quy trình; bằng chứng bị ảnh hưởng đã đính chính | Brief § Bài học quy trình; bốn chỗ đính chính |

### Bản vá code

**AR-P3-6.** `AiKnowledgeIngestionService::requestSourceDeletion()` dùng `<> 'deleted'`
— một dạng gộp nhóm, vì vậy gồm cả `deletion_pending` và ghi đè mốc thời gian ở cả
source, chunk và embedding. Nay mỗi cấp chỉ chuyển từ các status mà lifecycle chuẩn
của nó cho phép, liệt kê theo tên: source/chunk `pending|active|failed|stale|archived`,
embedding `pending|ready|failed|stale` — khớp đúng `AiEmbeddingService::requestDeletion()`.
Yêu cầu lặp vẫn quét các row con chưa vào đường xoá, chỉ không đụng row đã
`deletion_pending`. Test `test_repeated_deletion_request_keeps_the_original_request_time`
**đỏ trên code trước khi vá** (ở `ai_knowledge_sources`), xanh sau khi vá.

**AR-P3-5.** `FakeUsageQuotaReserver::reserve()` nay idempotent theo định danh attempt
(customer, `run_uuid`, feature, usage type, unit), tra cứu **trước** capacity như store
thật, ném `LF_USAGE_RESERVATION_CONFLICT` khi sai run hoặc sai quantity, trả `null` cho
hold đã đóng mà chưa dùng. Tái lập trước vá: cùng attempt → 2 handle, balance 10 → 4;
sau vá: cùng handle, 1 reservation, còn 7. 140 test dùng fake vẫn xanh — không test nào
đang dựa vào việc trừ gấp đôi.

Test rào chắn: `test_one_embedding_pass_holds_and_settles_exactly_one_reservation`
**đỏ khi gỡ riêng khối idempotent**. Test đi kèm
`test_a_second_authorization_refusal_releases_the_single_hold` **không phải rào chắn cho
AR-P3-5**: nó vẫn xanh với fake cũ, vì lần authorize thứ hai bị chặn trước bước reserve
nên chỉ có một hold. Nó được giữ vì xác minh tuyên bố ở § Items 4–5 rằng từ chối lần hai
trả hold về.

**AR-P3-4.** Bốn test mới, mỗi test được kiểm bằng một đột biến riêng và file được khôi
phục nguyên từng byte sau đó:

| Test | Đột biến | Kết quả |
| --- | --- | --- |
| `test_an_adapter_whose_provider_does_not_support_the_approved_model_is_never_called` | `EmbeddingProviderAdapter::supportsModel()` → `true` | Đỏ |
| `test_an_adapter_for_a_different_provider_is_never_called` | `provider()` → hằng `'approved-provider'` | Đỏ |
| `test_a_non_success_response_body_is_never_echoed` (400/404/500/503) | Nối body lỗi vào exception | Đỏ |
| `test_allowed_access_is_audited_only_for_hits_actually_returned` | Audit trước khi cắt `limit` | Đỏ |

Trong lúc viết, test HTTP ban đầu đỏ vì **lỗi của chính test**: gọi `Http::fake()` lặp lại
sẽ cộng dồn stub, nên stub đầu tiên trả lời mọi vòng. Code sản phẩm đúng; test được sửa để
đăng ký một chuỗi phản hồi duy nhất.

### Bản vá tài liệu

* **AR-P3-1** — `ai_embeddings.md`: bỏ câu "only terminal runs permit remote deletion",
  thay bằng tập "writer đã dừng" (`completed|failed|cancelled|blocked`) kèm lý do `blocked`
  an toàn dù không terminal trong recorder. Cùng đoạn, sửa luôn câu "new generation keys
  include generation": key thế hệ 1 cố ý không có hậu tố generation.
* **AR-P3-8** — `LF-AI.md`: trạng thái Bước 5 viết lại theo đúng miễn trừ và các điều kiện
  hoãn.
* **AR-P3-9** — Brief: mục bài học quy trình worktree (không symlink `vendor`, xác minh
  `ReflectionClass::getFileName()`, không copy `.env`).
* **Đính chính bằng chứng** — bốn chỗ ghi "7 lỗi chạy lại trên `a9da018` → đỏ y hệt" được
  gạch bỏ và đánh dấu vô hiệu về phương pháp: artifact này (hai chỗ), artifact Bước 4,
  brief SaaS. Kết luận "7 lỗi Media có sẵn" nay dựa trên baseline reviewer dựng lại với
  `vendor` cục bộ.
* **Hồ sơ review bị ngắt** — Architecture Review có biển báo đóng hồ sơ ở đầu và mục Đóng
  hồ sơ ở cuối; `Document Status` giữ nguyên, vì chưa tài liệu nào dùng `Archived` và Owner
  không yêu cầu đổi trạng thái.

### Hoãn có điều kiện

Mỗi mục dưới đây **không** xảy ra được trong trạng thái hiện tại, và có một điều kiện rõ
ràng buộc phải xử lý trước khi nó có thể xảy ra.

| Finding | Vì sao chưa xảy ra được | Phải xử lý trước khi |
| --- | --- | --- |
| AR-P3-2 — Qdrant ack xoá cả khi không khớp point nào; point có payload `customer_id` sai kiểu sẽ không bị xoá mà row vẫn thành `deleted` | Chưa provider nào được bind; không có index production | **Kích hoạt provider** hoặc dựng index production: thêm xác minh tồn tại sau khi xoá, hoặc dựng lại index để không còn point sai payload |
| AR-P3-3 — `supportsModel = false` khiến mỗi lượt tạo run mới, reserve rồi release hold, trả `AI_ADAPTER_MISMATCH` | `UnavailableEmbeddingProvider` được bind; allow-list rỗng | **Kích hoạt provider**: kiểm `supportsModel` trước gate hoặc có backoff |
| AR-P3-7 — Source không gắn Media khiến `currentRevision` ném `unsupported_source`, audit từ chối ném theo, hỏng cả lượt | Chỉ `AiKnowledgeIngestionService` tạo source, và luôn gắn Media | **Thêm bất kỳ loại Knowledge Source nào không gắn Media**: từ chối riêng từng hit đó thay vì làm hỏng cả lượt |

### Kiểm chứng

| Hạng mục | Kết quả | Ai chạy |
| --- | --- | --- |
| Test bị ảnh hưởng (embedding, retrieval, ingestion, Qdrant adapter, gate, quota store), MariaDB **server 11.4.12** cô lập | **158 passed, 1 skipped, 803 assertions**, 0 lỗi | implementer |
| Xác nhận kết nối MariaDB trước khi chạy | `@@socket` = socket tạm, `database()` = `lf_close_review`, `skip_networking = 1` — không chạm XAMPP 3306 đang chạy | implementer |
| Test skip trên MariaDB | `test_real_qdrant_maintenance_passes_a_broken_collection_and_recovers_it` — cần Qdrant thật; lượt này không chạy Qdrant | implementer |
| Toàn suite SQLite **trước** các bản vá lượt này | 7 failed, 8 skipped, 1161 passed, 10719 assertions, 205 giây | implementer |
| Toàn suite SQLite **sau** các bản vá | **7 failed, 8 skipped, 1168 passed**, 10761 assertions, 272 giây | implementer |
| So tên test đỏ qua JUnit, trước ↔ sau, cùng môi trường | xuất hiện mới `[]`, hết đỏ `[]`; passed tăng đúng 7 = số test mới | implementer |
| Pint (các file sửa), `docs:lint`, `schema:drift --docs-only`, `git diff --check` | PASS | implementer |

Bảy test đỏ đều là Media/runtime: `MediaRevisionLifecycleTest` (5),
`AudioProcessingLocalReviewTest` (1), `VideoTranscriptCaptionLocalReviewTest` (1). So với
baseline reviewer dựng lại đúng cách, **6/7 trùng tên**; test Video khác tên trong cùng
class (lượt này: `test_real_video_pipeline…`; baseline reviewer:
`test_a_corrupt_video_fails_extraction…`). Cả hai là test dùng ffmpeg thật.

Chênh lệch với lượt hoàn chỉnh 16 lỗi trước đó **vẫn chưa rõ nguyên nhân**. Lượt này loại
trừ được một giả thuyết: 59/59 test `DocumentProcessingLocalReviewTest` chạy đủ, không skip,
đều xanh trong 62,5 giây, nên chênh lệch không do thiếu runtime Docling. Hai lượt tiếp tục
được ghi riêng.

### Vệ sinh

* `git worktree prune`: đã xoá metadata của `/tmp/lf-step5-review` (thư mục đã không còn).
* Ref `refs/lf-review/step5-2026-09-13` **giữ lại**: verdict FAIL neo vào nó.
* Không còn tiến trình MariaDB, Qdrant hay phpunit nào do review hoặc lượt này để lại.
* `learnforge_db`: hai migration chưa commit vẫn `Pending` (kiểm bằng `migrate:status`, chỉ
  đọc). Chưa apply.

### Trạng thái nghiệm thu

**Bước 5 backend: ĐÓNG ngày 2026-09-14, dưới miễn trừ review của Owner.**

* Đã triển khai và kiểm chứng: vòng đời embedding, generation, retry, phục hồi có kiểm
  soát, purge/reconcile, post-validation retrieval kèm Media authorization và revision
  hiện hành, audit truy hồi, index tenant trên Qdrant thật.
* Không phải điều kiện đóng: AI thật, provider activation, frontend/chat.
* **Không** được suy ra từ trạng thái này: review PASS, quyền apply migration lên
  `learnforge_db`, hay phê duyệt provider theo ADR-0018.
* Còn mở, có điều kiện kích hoạt: AR-P3-2, AR-P3-3 (trước khi kích hoạt provider),
  AR-P3-7 (trước khi có source không gắn Media).
