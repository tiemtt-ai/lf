# AI Embedding + Qdrant — Architecture Review (Bước 5)

Version: 1.0

Document Status: Review

Implementation Status: Implemented

Last Updated: 2026-09-14

Document Path: quality/LF-AI-Embedding-Qdrant-Architecture-Review.md

---

> **HỒ SƠ ĐÃ ĐÓNG — 2026-09-14.** Lượt review này **bị ngắt giữa chừng** (giới hạn
> phiên API, rồi treo quá 600 giây) và không bao giờ hoàn tất. Verdict **FAIL** bên
> dưới chỉ áp cho snapshot `b5da390`, bản đã bị thay thế bởi các bản vá sau đó.
> Owner đã **miễn trừ** điều kiện `Architecture Review passed` cho Bước 5 ngày
> 2026-09-14 — miễn trừ, không phải PASS. Cách xử lý từng finding nằm ở
> LF-AI-Embedding-Qdrant-Implementation-Review § "Step 5 closure". Nội dung dưới
> đây là của reviewer và được giữ nguyên; chỉ biển báo này và mục Đóng hồ sơ ở cuối
> do tác nhân điều phối thêm vào.


# 1. Neo review

| Mục | Giá trị |
| --- | --- |
| Commit snapshot | `b5da3901c843c6cd49388d290bd792696a38b73e` (ref `refs/lf-review/step5-2026-09-13`, tree `90b7a1e4cf15a216ecbe86249c8e4bec1affaa71`, cha `a7e66b4`) — đã xác minh bằng `git cat-file` / `rev-parse` |
| Ngày review | 2026-09-13 |
| Worktree | `/tmp/lf-step5-review` (detached tại snapshot) |
| MariaDB server (CI đại diện) | `SELECT VERSION()` = **11.4.12-MariaDB** — instance cô lập, datadir `/tmp/lf-step5-mdb114`, socket riêng, `@@skip_networking=1`, `@@port=0` |
| MariaDB server (engine XAMPP) | `SELECT VERSION()` = **10.4.21-MariaDB** — dựng từ `/Applications/XAMPP/xamppfiles/sbin/mysqld --no-defaults`, datadir `/tmp/lf-step5-mdb104`, socket riêng, `@@skip_networking=1` |
| SQLite | 3.53.4 (in-memory, theo `phpunit.xml`) |
| Qdrant | **Không có phiên bản thật nào được chạy.** Xem § 6 |
| PHP | 8.3.33, PHPUnit 11.5.55 |
| Reviewer | Tác nhân review độc lập; không viết hay sửa code/tài liệu Bước 5 |

Không có kết nối nào tới MariaDB XAMPP 3306 / `learnforge_db`. Không gọi embedding
provider thật, không dùng API key. Mọi test/kịch bản của reviewer chạy với
`HTTP_PROXY`/`HTTPS_PROXY` trỏ vào `127.0.0.1:9` (cổng từ chối) để request HTTP
thật nào qua Guzzle/cURL cũng thất bại thay vì đi ra mạng.

## Ghi chú môi trường ảnh hưởng tới tính hợp lệ của bằng chứng

1. **Bẫy autoload khi symlink `vendor`.** Lệnh dựng worktree trong hướng dẫn
   (`ln -s …/LF/vendor /tmp/lf-step5-review/vendor`) làm Composer tính `$baseDir`
   từ đường dẫn thật của `vendor/composer`, nên mọi class `App\` và `Tests\` được
   nạp từ **working tree của repo chính**, không phải từ snapshot. Đã xác minh:
   `ReflectionClass(App\Services\AiEmbeddingService)->getFileName()` trả
   `/Applications/XAMPP/xamppfiles/htdocs/LF/app/...`. Các lượt chạy ban đầu vì
   thế bị huỷ bỏ. Đã sửa bằng cách thay symlink bằng thư mục `vendor` cục bộ
   (symlink từng package, **copy** `vendor/composer`, `vendor/autoload.php` và
   `vendor/phpunit/phpunit`). Sau đó probe trong chính một test PHPUnit cho
   `app=/private/tmp/lf-step5-review/app/...`, `support=/private/tmp/lf-step5-review/tests/Support/...`,
   `base=/private/tmp/lf-step5-review`. **Mọi con số trong tài liệu này lấy từ
   các lượt chạy sau khi sửa.** (Tại thời điểm review, file tracked của
   working tree chính trùng snapshot, nên kết quả trước và sau sửa không lệch;
   nhưng đó là may mắn, không phải bảo đảm.)
2. `.env` copy từ repo chính trỏ `DB_CONNECTION=mysql`, `127.0.0.1:3306`,
   `learnforge_db` và chứa secret khác. Trong worktree nó đã được thay bằng một
   `.env` tối thiểu, không secret (`DB_CONNECTION=sqlite`, `DB_HOST=invalid.invalid`).
   Kết nối MariaDB chỉ đi qua biến môi trường trỏ socket cô lập; đã xác minh
   bằng `DB::selectOne('select @@socket')`.
3. Khác với mô tả trong prompt: **mysqld XAMPP đang chạy** (PID 5078, port 3306,
   datadir `/Applications/XAMPP/xamppfiles/var/mysql`). Reviewer không kết nối,
   không dừng, không đọc datadir của nó.
4. Trong lúc review có một tiến trình `phpunit` **không phải của reviewer** chạy
   trong repo chính (`--configuration=/Applications/XAMPP/xamppfiles/htdocs/LF/phpunit.xml
   --filter AiEmbeddingServiceTest|…`). Reviewer không đụng tới nó; các lượt chạy
   của reviewer dùng worktree và database riêng nên không bị ảnh hưởng.

---

# 2. Verdict tổng: **FAIL**

Lý do, theo đúng quy tắc "những gì KHÔNG được tính là PASS" của brief:

* **P1-1** — lỗi triển khai trong phạm vi, đã tái lập trên SQLite, MariaDB 10.4.21
  và 11.4.12: nếu `authorize()` lần hai bên trong `gate->execute()` bị chặn, các row
  `pending` vừa claim bị kẹt vĩnh viễn, còn worker báo thành công (`error_code=null`).
* **Q4 FAIL** — có một chỗ ghi `ai_embeddings.status` nằm ngoài lifecycle hiện
  hành (`pending → stale` trong ingestion).
* **Q8 FAIL** — sáu điều kiện post-validation có code nhưng bỏ đi thì **không**
  test nào đỏ.
* **Q5** vừa FAIL (P1-1) vừa có một OWNER DECISION (run `running` chết).
* **Qdrant thật KHÔNG KIỂM CHỨNG ĐƯỢC** (§ 6). Theo brief, test tích hợp bị skip
  không phải PASS, và chưa xác nhận được `customer_id` là payload index. Kể cả
  khi mọi finding khác đã được vá, phần bằng chứng bắt buộc này vẫn thiếu, nên
  verdict tổng vẫn không thể là PASS.

Hệ quả governance: điều kiện `Architecture Review passed` (AGENTS.md § Database
Rule) cho migration `2026_09_13_000100_add_ai_embedding_generation.php` **chưa
đạt**. Riêng migration (Q11) đã được kiểm chứng đúng trên cả hai engine. Tuy vậy
brief gắn điều kiện đó với verdict tổng của review này. Bước 5 không đóng được
nếu không vá các finding P1/P2 và bổ sung bằng chứng Qdrant thật, hoặc nếu không
có quyết định miễn trừ riêng, tường minh của Owner.

---

# 3. Bảng Q1–Q13

| # | Verdict | Bằng chứng (tại snapshot) |
| --- | --- | --- |
| Q1 | **PASS** | `grep -i "openai\|cohere\|voyage\|anthropic\|gemini\|mistral\|jina\|qdrant\|ollama"` trên `AiEmbeddingService`, `AiKnowledgeRetrievalService`, `AiProviderExecutionGate`, `AiModelRunRecorder`, `EmbeddingProviderAdapter`, `MediaDerivedRetrievalAudit`, `DatabaseUsageQuotaReserver`: không có tên vendor nào (`qdrant` chỉ xuất hiện qua `config('ai.vector_store.driver')`). Gate ghim cả hai trước khi gọi: `AiProviderExecutionGate.php:160-163` (`provider() !== $request->provider \|\| ! supportsModel(model)` → `AI_ADAPTER_MISMATCH`), đứng trước `markExecuting` và `adapter->execute` (dòng 169-171). Adapter chuyển tiếp nguyên hai giá trị (`EmbeddingProviderAdapter.php:38-46`). Kịch bản reviewer: bind `UnavailableEmbeddingProvider` (`supportsModel=false`) → `error_code=AI_ADAPTER_MISMATCH`, run `failed`, hold `released`, 0 provider call. Đổi provider/model chỉ đổi các cột provenance `provider`, `model`, `embedding_hash`, `vector_index`/`vector_key` (định danh mới), không sửa row cũ. Lỗ hổng test: xem P3-4. |
| Q2 | **PASS** | `embedding_hash` = `sha256:` + sha256 của `content_hash \| identity_fingerprint \| identity_version \| provider \| model \| dimensions` (`AiEmbeddingService.php:738-748`; bản SQL `:426` khớp từng thành phần). `identity_fingerprint = COALESCE(RTRIM(source_fingerprint), content_hash, '')`, `identity_version = COALESCE(processing_version, source_version, '')` (cột STORED). Key: gen 1 = `stableUuid("ai-embedding\|{customer}\|{chunk}\|{hash}")` (`:478`); gen ≥ 2 thêm `\|generation:{N}` (`:579-581`); retry dùng lại key của row (`:578`). Kịch bản reviewer (SQLite, 10.4.21, 11.4.12): key gen1 = `stableUuid(base)`, gen2 = `stableUuid(base\|generation:2)`, gen3 khác nhau đôi một (`6e59c5d9…`, `3a84fd7a…`, `9ed56593…`); purge gen1+gen2 xoá đúng hai point đó, point gen3 vẫn còn; retry `failed` giữ nguyên `vector_key`, vẫn 1 row. Lượt worker thứ hai: `embedded=0 runs_delta=0 rows_delta=0 reserve_delta=0 provider_delta=0`. |
| Q3 | **PASS** | Hai tiến trình PHP thật + một connection giữ khoá trên MariaDB 11.4.12 (DB `lf_step5_q3`). Tiền điều kiện: 3 chunk có gen1 `stale`. Connection thứ ba `SELECT … FROM ai_knowledge_sources FOR UPDATE; SLEEP(8)`. `information_schema.PROCESSLIST` cho thấy cả hai worker đang chờ `select * from ai_knowledge_sources … for update` và mỗi worker đã có run `queued` riêng (id 14, 15). Sau khi nhả khoá: worker thắng `embedded=3`, gen2 `ready` ×3 cho run 15; worker thua `embedded=0`, **không** provider call, run 14 `completed`. `saas_usage_reservations` (với **`DatabaseUsageQuotaReserver` thật**): run 15 `committed 3`, run 14 `reconciled_released`; không row mồ côi, không hold treo, không run `queued`. Người phân xử là khoá source→chunk→embedding (`:541-568`), unique key là lớp chặn thứ hai. Unique key được chứng minh vật lý bằng hai connection: A `INSERT gen2` rồi `SLEEP(4)`, B cùng định danh chờ tới khi A commit rồi nhận `ERROR 1062 … for key 'uk_aem_chunk_model'`. Ghi chú: nếu `claim()` ném lỗi (1062/hết retry deadlock), exception thoát ra ngoài `try` (`:130`), để lại run `queued`. `releaseAbandonedRuns` (30 phút) và lease của hold sẽ thu dọn, nên có đường phục hồi. |
| Q4 | **FAIL** | Mọi chỗ ghi `ai_embeddings.status` trong worker đều khớp lifecycle: insert `pending` (`:583-598`); `failed→pending` / `pending→pending` đổi run (`:599-608`); `pending→ready` (`:679-684`, `:330-333`); `pending→failed` (`:339-346`, `:722-725`); `pending→deletion_pending` (`:710-718`); `ready\|failed→stale` (`:176-177`); `pending\|ready\|failed\|stale→deletion_pending` (`:200-205`); `deletion_pending→deleted` (`:268-275`). Không có `processing` (CHECK `chk_aem_status` trên cả hai engine). Ba hằng: `REPLACEABLE_STATUSES=['stale','deleted']` không chứa `deletion_pending`; mutation thêm nó làm `test_deleted_generation_and_its_vector_key_are_never_reused` đỏ (SQLite). `LIVE_RUN_STATUSES`/`ABANDONED_RUN_STATUSES` liệt kê tên đúng ngữ nghĩa, và `reclaimable()` kiểm cả hai nên fail-closed. Tạo gen+1 không sửa row cũ (`test_superseded_identity…` + kịch bản Q2). **Nhưng** `AiKnowledgeIngestionService.php:157-160` ghi `->whereIn('status', ['pending','ready','failed'])->update(['status'=>'stale'])`, tức `pending → stale`, **không** có trong lifecycle hiện hành. Đã tái lập: embedding `pending` thuộc run `running`, ingest revision mới → status `stale` (SQLite + MariaDB 11.4). Xem P2-1. Ngoài ra `requestSourceDeletion` (`:203`) ghi `deletion_pending→deletion_pending` và ghi đè `deletion_requested_at` (P3-6). |
| Q5 | **FAIL + OWNER DECISION** | Điểm chết và đường phục hồi: (a) chết sau `record(queued)` / trong `claim()` → rollback, run `queued` → `releaseAbandonedRuns` (`:366-379`) đưa về `cancelled` sau 30 phút, hold hết lease; (b) chết sau khi `claim()` commit, trước `claimForExecution` → row `pending` dưới run `queued` → `cancelled` → được claim lại (`test_abandoned_queued_runs…`); (c) **chết sau khi run `running`** (trước/sau provider call, giữa các upsert, sau upsert trước `completed`) → **không có đường phục hồi**. Kịch bản reviewer: run `running` 30 ngày, `embedPending`/`reconcilePending` không làm gì, `requestSourceDeletion` + `purgeDeletionPending` → `retained 3`, `finalizeSourceDeletion` → `embedding_delete_barrier` mãi mãi → **OD-1**; (d) chết sau run `completed`, trước `markReady` → `reconcilePending` dùng `exists()` (`:294-351`): có → `ready`, không → `failed LF_EMBEDDING_POINT_MISSING`, không biết → giữ `pending` (có test); (e) chết trong `settleFailure` → row `pending` dưới run `failed` → claim lại, upsert ghi đè cùng key. **(f) Lỗi P1-1:** `authorize()` lồng trong `gate->execute()` (`AiProviderExecutionGate.php:132-136`) trả decision `blocked` **không ném lỗi**; `embedPending` bỏ qua giá trị trả về (`AiEmbeddingService.php:138`). Kết quả tái lập (SQLite, 10.4.21, 11.4.12): `{"embedded":0,"error_code":null}`, run `blocked AI_QUOTA_EXCEEDED`, 3 row `pending`; sau 2 ngày, khi đã khôi phục entitlement: `embedPending` ×2, `reconcilePending`, `releaseAbandonedRuns` đều không tiến triển, 3 row vẫn `pending`. |
| Q6 | **PASS** | `deleted` chỉ được ghi khi `delete()` trả `true` (`:244-276`); lỗi hoặc `false` chỉ tăng `deletion_attempts` + `last_error_code`. Kịch bản với `UnavailableVectorStore` thật: purge `{"deleted":0,"retained":1}`, `LF_VECTOR_DELETE_UNACKNOWLEDGED`; reconcile `undetermined:1`, row giữ `pending` với `LF_VECTOR_STORE_UNAVAILABLE`. Barrier purge giữ khi run không thuộc `['completed','failed','cancelled','blocked']` (`:238`), nên `queued`/`running`/giá trị lạ đều bị giữ (`test_purge_waits_until_a_live_writer…`). Barrier xoá source của ingestion chỉ mở khi không còn embedding `<> 'deleted'` dưới khoá (`AiKnowledgeIngestionService.php:235-240`; `test_purge_is_what_releases_the_source_delete_barrier`). Ghi chú P3-1, P3-2. |
| Q7 | **PASS** (mức wire) | Bắt request thật qua `Http::fake` + `Http::preventStrayRequests()` với `QdrantVectorStore` thật chạy xuyên `embedPending`/`reconcilePending`/`purgeDeletionPending`/`retrieve` (SQLite, 10.4.21, 11.4.12). `PUT /collections/lf_text_approved_model/points`: payload có đúng các khoá `customer_id` (chuỗi `"1"`), `is_tenant`, `knowledge_chunk_id`, `knowledge_source_id`, `source_fingerprint`, `processing_version`; không chứa nội dung chunk, không PII/URL. `POST …/points/scroll`: `filter.must=[has_id, customer_id="1"]`. `POST …/points/delete`: `filter.must=[has_id, customer_id="1"]`. `POST …/points/search`: `filter.must=[customer_id="1"]`, `with_payload=false`. Upsert không nhận filter; tenant nằm trong payload và key có `customer_id`. Việc Qdrant thật lập chỉ mục `customer_id` **chưa kiểm chứng** (§ 6). |
| Q8 | **FAIL** | Mutation từng điều kiện, `AiKnowledgeRetrievalServiceTest` (+ `QdrantVectorStoreTest` cho filter store), SQLite, hoàn tác sau mỗi lượt. **Đỏ:** (1) `e.status='ready'` (`:91`) → `test_stale_and_deletion_pending…`; (2a) `c.status` (`:94`) → `test_archived_chunk_is_dropped`; (2d) `s.deletion_requested_at` (`:97`) → `test_a_source_pending_deletion_is_dropped`; (3a) hash nội tại (`:124`) → `test_a_revision_that_moved_on…`; (3c) `media_file_id` (`:142`) → `test_replaced_file…`; (4a) `e.customer_id` (`:84`) → `test_hits_belonging_to_another_tenant…` (**chỉ đỏ ngẫu nhiên**: `RuntimeException LF_RETRIEVAL_AUDIT_MEDIA_UNAVAILABLE`, không phải assertion); (4b) filter tenant của search (`QdrantVectorStore.php:90`) → `test_search_preserves_rank_and_tenant_filter`; (5) owner context (`MediaReadService.php:81`) → 2 test; usage active (`:92`) → `test_detached…`; đúng một usage (`:97`) → `test_ambiguous…`; Media bị xoá (`:107`) → `test_tombstoned…`. **Xanh (không test nào đỏ):** (2b) `s.status='active'` (`:95`); (2c) `c.deletion_requested_at` (`:96`); (3b) `count($revisions)===1` (`:140`); (3d) `locale` (`:143`); (3e) chỉ bỏ `source_fingerprint` (`:144`); (3f) chỉ bỏ `processing_version` (`:145`). Các điều kiện này đạt được về mặt vật lý: CHECK cho phép chunk `active` có `deletion_requested_at`. Kịch bản reviewer xác nhận code hiện tại **có** loại chúng (chunk có `deletion_requested_at` → hits `[2,3]`; source `archived` → `[]`), nhưng theo brief "có code mà chưa có test đỏ" không tính PASS. Bypass toàn bộ quyết định Media (`if ($authorization[$ownerKey] !== null)` → `if (false)`) làm 11 test đỏ (implementer báo 6/6 với một mutation khác). Xem P2-2. |
| Q9 | **PASS** | `read()` và `currentRevision()` cùng gọi `readResolved()` (`MediaReadService.php:36-38`, `:56-57`). Khi `revisionOnly`, bộ chọn chỉ lấy revision `ready` mới nhất (`:210-216`, không bao giờ nhánh `processingVersion` ghim `archived`), select `distinct source_fingerprint, processing_version, locale` (`:256-257`), trả về trước khi dựng unit/structure/crop (`:329-336`), và catch ném lại không audit (`:479-481`). Query log thật của `currentRevision` (SQLite): 8 SELECT, không cột `text`, không ký URL. Kịch bản chỉ còn output `archived` → `MediaReadException('archived')`, retrieval 0 hit, audit `denied archived`. Guard `status='deleted' → missing` (`:107-109`) nằm trong selector dùng chung. `MediaService::deleteMediaInternal` từ chối khi còn usage `active` (`MediaService.php:564-575`), và gắn usage vào Media đã xoá bị `abort 404` (`:335`). |
| Q10 | **PASS** | Một bản ghi cho mỗi quyết định: 3 hit allowed → 3 log (`test_authorized_hits…`); 3 candidate bị từ chối → đúng 3 log (`assertMediaDenied`), tức Media Read không ghi thêm. Kịch bản reviewer: `currentRevision` thành công → 0 log; bị từ chối `detached` → 0 log. Lỗi insert audit → `QueryException`, không trả nội dung (`test_audit_failure_prevents_returning_retrieved_content`, trigger thật trên MariaDB). Mã thật được giữ: `detached`, `missing`, `ambiguous_source`, `revision_mismatch`, `unauthorized`, `archived` (các test + mutation Q8). `allowed` chỉ ghi sau `array_slice` (`AiKnowledgeRetrievalService.php:183-188`); kịch bản `limit=1` trên 3 hit hợp lệ → 1 hit, 1 log `allowed`. Metadata chỉ gồm `operation, retrieval_uuid, owner_type, owner_id, knowledge_source_id, knowledge_chunk_id, chunk_uuid, usage_type, content_type, processing_version, source_fingerprint, locator, decision, error_code`; không nội dung chunk, không giá trị query vector `0.123457`. Ghi chú P3-7. |
| Q11 | **PASS** | Probe `information_schema`, chạy trên **cả hai** engine: `chk_aem_generation` = `` `generation` >= 1 ``; `uk_aem_chunk_model` = `customer_id, knowledge_chunk_id, provider, model, embedding_hash, generation`; cột `int(10) unsigned NOT NULL DEFAULT 1`. Chuỗi 98 migration chạy sạch từ DB rỗng trên 10.4.21 và 11.4.12. Script rollback (có dữ liệu, gồm một row gen2): insert gen0 → **4025**, gen1 trùng → **1062** (cả hai engine); `down()` → `LF_EMBEDDING_GENERATION_ROLLBACK_REFUSED`; query log trong lúc từ chối chỉ có `select exists(... generation > 1)`; SHA của `SHOW CREATE TABLE` trước/sau từ chối giống hệt. Xoá gen2 → `down()` thành công (không cột, không CHECK, unique cũ, dữ liệu giữ nguyên) → `up()` với dữ liệu sẵn có → `generation=1`, SHA `SHOW CREATE TABLE` trùng bản ban đầu (10.4.21 `41688e7e5b1a`, 11.4.12 `3a2ff33cc5f3`). 65 test Bước 5 cũng xanh trên 10.4.21 (36+22 test DB, 0 skip). `schema:drift --connection=mysql` PASS trên cả hai instance. Giới hạn: instance 10.4.21 chạy `--no-defaults`, không áp `my.cnf` XAMPP (`collation-server=utf8mb4_general_ci`…), và không phải dữ liệu thật của `learnforge_db` (§ 6). |
| Q12 | **PASS** | Đo trên tenant mà mọi chunk đã `ready`, `chunk_batch=50`, một lượt `embedPending`. **MariaDB 11.4.12:** luôn **2 truy vấn** (1 `update ai_model_runs` của `releaseAbandonedRuns` + 1 SELECT candidate anti-join), 0 query định danh theo batch, 0 provider call; thời gian DB tăng tuyến tính: 2 000 chunk 107 ms, 20 000 chunk 976 ms, 50 000 chunk 2 506 ms (sau `ANALYZE TABLE`). EXPLAIN: `DEPENDENT SUBQUERY` trên `held` (`idx_aem_chunk`) và `newer` (`uk_aem_chunk_model`), thêm `Using temporary; Using filesort`. **SQLite (fallback):** `2·⌈N/batch⌉ + 2` truy vấn, ví dụ 100 chunk → 6, 2 000 chunk → 82 (1 update + 41 SELECT chunk có `content` + 40 SELECT định danh), và nạp nội dung mọi chunk mỗi lượt. Test query-budget `test_mysql_all_ready_pass_uses_one_candidate_query…` **đã chạy, không skip** trên cả 11.4.12 và 10.4.21, và nằm trong danh sách file của job CI `integration-mysql`. Implementation Review ghi rõ "SQL prefilter removes client-side per-batch query amplification, not the database's O(N) candidate evaluation", tức khớp với số đo. |
| Q13 | **PASS** | Không có trường credential trong `config/ai.php` mục `embedding`/`vector_store`; adapter Qdrant không gửi header xác thực. Lỗi HTTP chỉ mang mã trạng thái: kịch bản 400 với body chứa `SECRET-TENANT-TEXT` và vector → cả `upsert/search/exists/delete` ném `RuntimeException: LF_VECTOR_STORE_REQUEST_FAILED_400`, không có exception trước đó (`QdrantVectorStore.php:142-146`). Gate không ném lại/chain exception gốc (`AiProviderExecutionGate.php:189-195`); worker thu gọn lỗi store về `LF_*` hoặc `LF_VECTOR_STORE_ERROR` (`:763-770`; `test_store_error_during_purge…` với host trong message). Metadata run chỉ có `quota_quantity/chunk_count/indexed_count/dimensions`. Mặc định bind `UnavailableEmbeddingProvider`; `VectorStore` là `UnavailableVectorStore` khi `host` rỗng (`AppServiceProvider.php:54-62`; `test_shipped_bindings_refuse…`). Mạng trong test: `QdrantVectorStoreTest` dùng `preventStrayRequests`; test embedding/retrieval dùng `FakeVectorStore`/`FakeEmbeddingProvider`; test tích hợp skip khi thiếu `LF_QDRANT_TEST_URL` và chỉ nhận host `127.0.0.1`/`localhost`. Toàn bộ test Bước 5 + kịch bản reviewer xanh khi proxy bị bịt. Ghi chú P3-4 (thiếu test body lỗi). |

---

# 4. Finding

Reviewer không vá. Tất cả finding giao lại như cột "Giao cho".

## P1

### P1-1 — Chặn ở lượt authorize lồng trong `execute()` làm row `pending` kẹt vĩnh viễn và bị báo là thành công

* **Hiện tượng.** `AiEmbeddingService::embedPending()` gọi `gate->authorize()`
  (`:120`), claim row `pending` gắn với run đó (`:130`), rồi gọi
  `gate->execute()` (`:138`). `AiProviderExecutionGate::execute()` lại gọi
  `authorize()` (`:132`). Nếu lượt này bị chặn (approval bị thu hồi, entitlement
  hết hạn, hold hết lease → `CLOSED_UNUSED`, safety policy đổi), gate ghi run
  `queued → blocked` và **trả** `ProviderGateDecision::blocked`, không ném lỗi
  (`:133-136`). Worker bỏ qua giá trị trả về, không vào nhánh `catch`, gọi
  `markReady([])` và trả `{"embedded":0,"error_code":null}`. Các row `pending`
  thuộc run `blocked`: `reclaimable()` trả `false` (`blocked` không thuộc
  LIVE lẫn ABANDONED), prefilter SQL coi chúng là đang bị giữ, `reconcilePending`
  chỉ xét `completed`, `releaseAbandonedRuns` chỉ xét `queued`. Chunk sẽ không bao
  giờ được embed lại.
* **Tái lập.** Kịch bản `test_q5_reauthorization_block_strands_pending_rows`:
  `FakeUsageQuotaReserver::onReserve` thu hồi entitlement ngay sau lượt reserve
  đầu. Kết quả trên SQLite, MariaDB 10.4.21 và 11.4.12 giống nhau: outcome
  `{"embedded":0,"error_code":null,"stranded":0}`, run `blocked
  AI_QUOTA_EXCEEDED`, 3 row `pending`, 0 provider call. Khôi phục entitlement,
  tua 2 ngày: `embedPending` ×2 → 0, `reconcilePending` → 0/0/0,
  `releaseAbandonedRuns` → 0, vẫn 3 row `pending`.
* **Tác động.** Mất dữ liệu sẵn sàng cho AI một cách âm thầm và vĩnh viễn: đúng
  trạng thái "stranded" mà trường `stranded` được ghi là đã bị loại bỏ. Worker
  báo không lỗi nên vận hành không phát hiện được. Không lộ dữ liệu. Purge vẫn
  hoạt động vì `blocked` nằm trong danh sách terminal ở `:238`. Implementation
  Review F-2 đánh giá việc authorize hai lần là "Không sai", nhưng chưa xét nhánh này.
* **Giao cho.** Implementer Bước 5 (`AiEmbeddingService`). Vocabulary đã đủ,
  không cần mã mới: decision `blocked` mang sẵn `AI_APPROVAL_REQUIRED` /
  `AI_QUOTA_EXCEEDED` / `AI_SAFETY_BLOCKED`, và `pending → failed` là transition
  canonical. Nếu implementer muốn coi run `blocked` là "đã kết thúc" để claim
  lại, việc đó đổi câu "Retry chỉ hợp lệ khi run … `failed` hoặc `cancelled`"
  trong `ai_embeddings.md` và cần Owner duyệt (OD-3). Cần kèm test hồi quy trên
  MariaDB.

## P2

### P2-1 — Ingestion ghi `ai_embeddings` `pending → stale`, ngoài lifecycle canonical

* **Hiện tượng.** `AiKnowledgeIngestionService.php:157-160`, khi một revision mới
  thay revision cũ, cập nhật embedding của chunk cũ với
  `whereIn('status', ['pending','ready','failed'])` → `stale`. Lifecycle hiện hành
  chỉ có `ready|failed → stale`. Chính `AiEmbeddingService::markStale()`
  (`:172-176`) ghi rõ không được stale một row `pending` vì run có thể đang chạy.
  `AiKnowledgeIngestionServiceTest::test_new_revision_creates…` chỉ kiểm
  `ready → stale`. Commit đưa dòng này vào: `7959d0f` (2026-09-09).
* **Tái lập.** `tests/Review/IngestionStaleReviewTest::test_review_q4_pending_embedding_under_live_run_is_rewritten_to_stale`
  (bản sao fixture ingestion): embedding `pending` thuộc run `running`, ingest
  revision mới → `stale`. Xanh trên SQLite và MariaDB 11.4.12, tức hành vi có thật.
* **Tác động.** Code và tài liệu mâu thuẫn (brief: không tính PASS). Một
  writer đang chạy sau đó ghi point nhưng `markReady` khớp 0 row. Point còn trong
  index dưới row `stale` và không được dọn tự động, cho tới khi có
  `requestDeletion`. Không lộ qua retrieval (retrieval đòi `ready` + chunk `active`).
* **Giao cho.** Implementer ingestion (Bước 3), sau khi Owner chọn phương án ở
  OD-2 (sửa code, hoặc amendment thêm `pending → stale`).

### P2-2 — Sáu điều kiện post-validation có code nhưng không có test đỏ khi bỏ đi

* **Hiện tượng.** Mutation từng dòng (Q8) để nguyên 22/22 test xanh với: `s.status='active'`
  (`AiKnowledgeRetrievalService.php:95`), `c.deletion_requested_at IS NULL`
  (`:96`), `count($revisions) === 1` (`:140`), so `locale` (`:143`), chỉ so
  `source_fingerprint` (`:144`), chỉ so `processing_version` (`:145`). Trường hợp
  tenant quan hệ (`:84`) đỏ chỉ vì exception audit phụ, không phải vì assertion
  về tenant.
* **Tái lập.** `scratchpad/mutate.py` (thay chuỗi một lần, chạy
  `AiKnowledgeRetrievalServiceTest`, `git checkout` file). Có thể làm lại thủ
  công bằng cách xoá đúng dòng đã nêu và chạy file test.
* **Tác động.** Code hiện tại đúng (kịch bản reviewer xác nhận chunk có
  `deletion_requested_at` và source `archived` đều bị loại). Nhưng một hồi quy
  sau này ở các điều kiện đó sẽ không bị bắt, trong khi đây là rào tenant/revision
  của dữ liệu được trích dẫn.
* **Giao cho.** Implementer Bước 5 (test retrieval). Mỗi điều kiện cần một test
  đỏ riêng; test tenant quan hệ cần assertion không phụ thuộc exception audit.

### P2-3 — Run `running` có tiến trình đã chết: không có đường phục hồi

Xem OD-1. Được tái lập ở Q5(c). Giao cho Owner quyết định, sau đó implementer
Bước 4/5 triển khai.

## P3

* **P3-1 — `blocked` được coi là terminal trong barrier purge.**
  `AiEmbeddingService.php:238` nhận `blocked`, trong khi `AiModelRunRecorder`
  ghi `blocked` "không terminal" (`blocked → queued`) và `ai_embeddings.md` nói
  "Only terminal runs permit remote deletion". Thực tế an toàn vì run `blocked`
  chưa qua provider boundary và worker không bao giờ dùng lại `run_uuid` (mỗi
  lượt một correlation mới), nhưng tài liệu cần nói chính xác. *Giao:*
  implementer Bước 5 (tài liệu).
* **P3-2 — Ack xoá của Qdrant là ack của request, không phải của sự tồn tại.**
  Delete theo filter trả `completed` cả khi không khớp point nào (chính test tích
  hợp ghi "Wrong tenant deletion may acknowledge a no-op"). Point có payload
  `customer_id` kiểu số từ thời thử nghiệm sẽ không khớp filter chuỗi; row sẽ
  thành `deleted` còn vector vẫn nằm trong index. Tài liệu đã yêu cầu rebuild
  trước activation. Nên thêm bước xác minh (`exists` sau delete, hoặc đếm) hoặc
  ghi rõ giới hạn này. *Giao:* implementer Bước 5 / vận hành.
* **P3-3 — Provider cấu hình sai tạo vòng run + hold mỗi lượt.** Với
  `supportsModel=false`, mỗi lượt đưa row `failed → pending`, mint run mới,
  reserve và release hold, rồi `AI_ADAPTER_MISMATCH`. Đây đúng là "worker nói
  chuyện với chính nó" mà comment `:89-93` muốn tránh. *Giao:* implementer Bước 5
  (backoff hoặc chặn trước gate khi `supportsModel` sai).
* **P3-4 — Lỗ hổng test không làm sai hành vi hiện tại.** (a) Mutation
  `EmbeddingProviderAdapter::supportsModel()` → `true` hoặc `provider()` → hằng:
  36/36 test embedding vẫn xanh (gate được test ở Bước 4 bằng `SpyProviderAdapter`,
  không phải adapter embedding). (b) Không test nào kiểm body lỗi non-2xx của
  Qdrant không bị echo (hành vi đã được reviewer xác minh là đúng). (c) Suite
  không assert số log `allowed` khi `limit` cắt kết quả (reviewer xác minh là đúng).
  *Giao:* implementer Bước 5.
* **P3-5 — `FakeUsageQuotaReserver` không idempotent theo `run_uuid`.** Vì gate
  authorize hai lần, fake tạo hai hold và để một hold `reserved` mãi mãi (thấy rõ
  trong kịch bản Q3 với fake), khác `DatabaseUsageQuotaReserver` thật (một hold,
  settle đúng). Test dùng fake không thể phát hiện hold treo. *Giao:* implementer
  Bước 4/5 (test double).
* **P3-6 — `requestSourceDeletion` ghi đè `deletion_requested_at` của row đã
  `deletion_pending`** (`AiKnowledgeIngestionService.php:203`, `status <> 'deleted'`),
  trái với tính idempotent của `AiEmbeddingService::requestDeletion()`. *Giao:*
  implementer ingestion. (Ngoài phạm vi, không ảnh hưởng verdict.)
* **P3-7 — Nguy cơ tiềm ẩn: hit từ source không phải Media làm hỏng cả lượt
  retrieval.** Schema cho phép source có `content_type`/`media_file_id` NULL
  (`chk_aks_media_binding`). Với source đó, `currentRevision` ném
  `unsupported_source`, rồi audit từ chối ném `LF_RETRIEVAL_AUDIT_MEDIA_UNAVAILABLE`
  (`MediaDerivedRetrievalAudit.php:18-21`), làm hỏng toàn bộ lượt retrieval, kể cả
  hit hợp lệ. Hiện chưa chạm được, vì chỉ `AiKnowledgeIngestionService` tạo
  source và luôn gắn Media. *Giao:* implementer Bước 5.
* **P3-8 — `docs/platform/LF-AI.md` ghi "Bước 5 backend hoàn tất"** trong khi
  điều kiện Architecture Review chưa đạt. *Giao:* implementer Bước 5, cập nhật
  sau quyết định của Owner về review này.
* **P3-9 — Hướng dẫn dựng worktree bằng symlink `vendor` âm thầm nạp code của
  repo chính** (§ 1). Brief/quy trình review sau cần dùng autoloader cục bộ, hoặc
  kiểm `ReflectionClass::getFileName()` trước khi tin kết quả. *Giao:* Owner /
  người soạn brief.

## Phụ lục — ngoài phạm vi (không ảnh hưởng verdict)

* `AiKnowledgeIngestionService.php:396-401`: chunk bị loại khi đối soát
  (`reconcileChunks`) chuyển `stale`, nhưng embedding của chúng giữ `ready` và
  point vẫn còn. Retrieval loại chúng nhờ `c.status='active'`, nhưng không có
  đường dọn point tự động. *Giao:* implementer ingestion.

---

# 5. OWNER DECISION

### OD-1 — Phục hồi run `running` mà tiến trình đã chết

Hiện tượng: Q5(c) / P2-3. Contract hiện có không cho worker quyết định an toàn:
run `running` có thể đã gọi provider (usage có thể đã phát sinh), nên không được
reap về `cancelled`. `failed` lại bắt buộc mã lỗi, và không có mã nào đã duyệt
nói "tiến trình biến mất". Cả `ai_embeddings.md` lẫn config đều đẩy việc này sang
"provider-aware reconciliation", một thứ chưa tồn tại. Hậu quả đã tái lập: chunk
không bao giờ được embed lại, `embedding_delete_barrier` không bao giờ mở, tức
xoá Knowledge Source bị chặn vĩnh viễn. Cần Owner chọn, ví dụ: (a) duyệt một mã
lỗi và một đường timeout `running → failed` kèm đối soát Commercial cho hold
`executing`; (b) cho phép reconcile row theo `exists()` với run `running` quá
hạn; (c) chấp nhận rủi ro và yêu cầu thao tác vận hành thủ công có tài liệu.
Reviewer không tự đặt mã.

### OD-2 — `pending → stale` do ingestion

P2-1. Chọn: sửa ingestion để chỉ stale `ready|failed` (và xử lý `pending` bằng
cách khác), **hoặc** amendment `ai_embeddings.md` thêm `pending → stale`, đồng
thời định nghĩa quan hệ với run đang sống và với `markReady`.

### OD-3 — (tuỳ phương án vá P1-1) Run `blocked` có được coi là "đã kết thúc" để claim lại không

Chỉ cần nếu implementer chọn vá P1-1 bằng cách thêm `blocked` vào điều kiện
retry. Đường vá không cần quyết định là settle row `pending → failed` ngay trong
tiến trình, dùng mã lỗi của decision.

---

# 6. Không kiểm chứng được trong môi trường này

| Mục | Lý do | Cần gì để kiểm được | Ảnh hưởng verdict |
| --- | --- | --- | --- |
| Test tích hợp Qdrant thật v1.11.5 (`tests/Integration/QdrantVectorStoreIntegrationTest.php`) | Không có Docker. Sau khi tác nhân điều phối chuyển thông báo cho phép chạy một binary Qdrant 1.11.5 đã tải sẵn trong scratchpad, **permission system (auto mode classifier) đã chặn** ngay thao tác đầu tiên liên quan tới binary đó (kiểm hash / kiểm cổng). Reviewer không tìm đường vòng. Lượt chạy thực tế: `1 skipped (0 assertions)` — "Dedicated local Qdrant test endpoint required." | Người dùng cấp quyền trực tiếp cho lệnh chạy binary local (ghim `127.0.0.1`), hoặc cung cấp log một lượt job CI `integration-qdrant` tại đúng SHA này | Bằng chứng bắt buộc bị thiếu → không thể PASS phần Qdrant; góp phần vào **FAIL** tổng |
| `customer_id` là payload index (`keyword`, `is_tenant=true`, `points` = số point) của collection | Như trên | Như trên (`GET /collections/{c}` → `result.payload_schema.customer_id`) | Như trên |
| Job GitHub Actions `integration-qdrant`, `integration-mysql` | Không trigger CI trong review | Chạy workflow tại SHA | Không có; bằng chứng local tương đương đã có cho phần MariaDB |
| Migration trên **dữ liệu thật** và cấu hình thật của `learnforge_db` (XAMPP) | Bị cấm kết nối. Engine 10.4.21 được kiểm trên instance cô lập `--no-defaults`; không áp `my.cnf` XAMPP (`collation-server=utf8mb4_general_ci`, buffer…) và không có dữ liệu thật | Bản sao dữ liệu (dump) phục hồi vào instance cô lập 10.4.21 dùng đúng `my.cnf`, rồi chạy migrate + probe | Không chặn Q11 (brief hỏi về engine), nhưng người apply phải biết giới hạn này |
| Baseline test "lưu trữ" của implementer (`lf-step5-closure-suite.xml`, 16 failed) | Không tìm thấy file nào trên máy | — | Reviewer tự dựng baseline: toàn suite tại `a9da018` (commit ngay trước khi `AiEmbeddingService` được thêm ở `96600cf`) → 7 failed; tại snapshot → 7 failed, 24 skipped, 1123 passed, 10 331 assertions. So tên qua JUnit: introduced `[]`, resolved `[]`. 7 test đỏ: `MediaRevisionLifecycleTest` ×5 (1 error, 4 failure), `VideoTranscriptCaptionLocalReviewTest::test_a_corrupt_video_fails_extraction…`, `AudioProcessingLocalReviewTest::test_a_new_processing_version_archives…`. Con số 16 của implementer không tái lập được; nhiều khả năng do môi trường (`.env` trỏ MariaDB thật) |

---

# 7. Lệnh và kết quả tái lập chính

| Lệnh (worktree snapshot, sau khi sửa autoload) | Kết quả |
| --- | --- |
| `php artisan test --filter "AiEmbeddingServiceTest\|AiKnowledgeRetrievalServiceTest\|QdrantVectorStoreTest"` — SQLite | 63 passed, 2 skipped (MariaDB-only), 244 assertions |
| Cùng lệnh — MariaDB 11.4.12 cô lập (JUnit) | 65 tests, 250 assertions, 0 failure, 0 error, 0 skip |
| `phpunit` ba file tương ứng — MariaDB 10.4.21 cô lập (JUnit) | 36 + 22 test DB (150 + 84 assertions) + 7 test adapter HTTP, 0 failure/error/skip |
| `php artisan test tests/Integration/QdrantVectorStoreIntegrationTest.php` | 1 skipped — **không phải PASS** |
| `php artisan test` (toàn suite, SQLite) tại snapshot | 7 failed, 24 skipped, 1123 passed (10 331 assertions); tên lỗi trùng baseline `a9da018` |
| `php artisan docs:lint` | passed |
| `php artisan schema:drift --docs-only` | passed (98 migration files) |
| `php artisan schema:drift --connection=mysql` trên 11.4.12 và 10.4.21 cô lập | passed / passed |
| Kịch bản reviewer (`tests/Review/*`, chỉ trong worktree tạm, đã xoá cùng worktree) | Q2, Q5 (P1-1, run chết), Q7 trên SQLite + 10.4.21 + 11.4.12; Q1, Q6, Q13, Q4 (ingestion), Q9, Q10, Q8-hành-vi trên SQLite + 11.4.12; Q3 bằng hai tiến trình trên 11.4.12; Q11 script trên cả hai engine; Q12 script đo chi phí |

`pgrep -fl phpunit` được kiểm trước mỗi lượt đo toàn suite. Lượt toàn suite đầu
tiên (trước khi phát hiện bẫy autoload) đã bị dừng và loại bỏ.

---

# 8. Dọn dẹp

Xem mục "Dọn dẹp" ở cuối, được cập nhật sau khi thực hiện.

---

# Đóng hồ sơ — 2026-09-14 (tác nhân điều phối, không phải reviewer)

Mục 8 ở trên trỏ tới "mục Dọn dẹp ở cuối, được cập nhật sau khi thực hiện". Mục đó
**chưa từng được viết**: reviewer bị ngắt trước khi tới bước dọn dẹp. Mục này ghi
lại trạng thái thực tế, do tác nhân điều phối kiểm chứng, để hồ sơ không trỏ vào
khoảng trống.

| Hạng mục | Trạng thái kiểm chứng 2026-09-14 |
| --- | --- |
| Tiến trình MariaDB 11.4 (`/tmp/lf-step5-mdb114`), MariaDB 10.4 cô lập (`/tmp/lf-step5-mdb104`), Qdrant | Không còn tiến trình nào của reviewer |
| Worktree `/tmp/lf-step5-review` | Thư mục không còn; metadata git ở trạng thái prunable đã được `git worktree prune` |
| File tạm `/tmp/lf-step5-*` | Không còn |
| Ref `refs/lf-review/step5-2026-09-13` | **Giữ lại** — verdict FAIL ở trên neo vào nó |
| `learnforge_db` | Không bị động tới: `migrate:status` (chỉ đọc) báo hai migration chưa commit đều `Pending` |
| Server XAMPP chính (cổng 3306) | Đang chạy dưới `root`; không phải do reviewer khởi động (mọi tiến trình reviewer thuộc `amin`, không có sudo) |

Hai sai lệch trong chỉ dẫn do tác nhân điều phối gửi reviewer, cần ghi lại vì chúng
ảnh hưởng tới cách đọc hồ sơ:

1. **Symlink `vendor`** (finding AR-P3-9) — chính lệnh trong prompt điều phối. Reviewer
   đã tự phát hiện, huỷ các lượt chạy sai và dựng `vendor` cục bộ (§ Ghi chú môi
   trường). Cùng lỗi phương pháp đó làm vô hiệu một baseline mà tác nhân điều phối
   từng tự dựng; bốn chỗ tài liệu dựa vào nó đã được đính chính.
2. **Quyền chạy Qdrant** — tác nhân điều phối đã gửi quyền chạy binary Qdrant đã xác
   minh, nhưng hệ thống phân quyền của phiên reviewer chặn thao tác đó (§ 6). Bằng
   chứng Qdrant thật về sau do implementer bổ sung, không phải do reviewer.
