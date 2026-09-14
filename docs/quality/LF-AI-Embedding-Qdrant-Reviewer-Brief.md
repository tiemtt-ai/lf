# AI Embedding + Qdrant — Reviewer Brief

Version: 1.0

Document Status: Review

Implementation Status: Implemented

Last Updated: 2026-09-14

Document Path: quality/LF-AI-Embedding-Qdrant-Reviewer-Brief.md

---

# Vì sao có brief này

## Phạm vi nghiệm thu — Owner xác nhận 2026-09-13

Mục tiêu là dữ liệu sẵn sàng cho AI thật sử dụng sau này, không phải chứng minh
AI thật đã sử dụng dữ liệu. Reviewer đánh giá backend, schema, contract và
bằng chứng kiểm thử trong phạm vi Bước 5; không thêm provider activation, gọi
model thật, frontend/chat hay người dùng thật thành điều kiện PASS/đóng bước.

Các phép thử MariaDB/Qdrant cô lập dưới đây kiểm chứng infrastructure adapter,
constraint và concurrency, không phải yêu cầu mở AI thật. Provider giả dùng
trong test không thay thế quyền activation; activation vẫn là quyết định riêng
theo ADR-0018. Xác nhận này không miễn các kiểm chứng kỹ thuật trong brief và
không phải miễn trừ điều kiện Architecture Review của migration `generation`.

## Tình trạng hồ sơ review

Bước 5 (Embedding + Qdrant) đã có runtime, migration và kiểm thử local, nhưng
**chưa có review độc lập nào**. Mọi đánh giá thiết kế hiện có đều do implementer
tự ghi — chính amendment `generation` cũng ghi *"Design review (implementer, not
independent)"*.

Điều này có hệ quả governance cụ thể. `AGENTS.md` § Database Rule: *"Không tạo
migration trước khi: Database Docs approved / ADR approved nếu thay đổi là
Foundation / Architecture Review passed."* Migration
`2026_09_13_000100_add_ai_embedding_generation.php` đã tồn tại.

| Điều kiện | Trạng thái 2026-09-13 | Trạng thái 2026-09-14 |
| --- | --- | --- |
| Database Docs approved | **Đạt** — `ai_embeddings.md` v1.2 Approved, amendment `generation` Owner approved 2026-09-13 | Đạt |
| ADR approved (Foundation) | **Đạt** — ADR-0006 | Đạt |
| Architecture Review passed | **CHƯA ĐẠT, CHƯA ĐƯỢC MIỄN TRỪ** | **ĐƯỢC MIỄN TRỪ bởi Owner** — không phải đã PASS |

Miễn trừ của Owner ngày 2026-09-12 **không** phủ bước này: nó tự giới hạn ở
*"packet bốn bảng SaaS và Bước 4; không sửa quy trình toàn repository"*. Một PASS
từ brief này đáp ứng điều kiện thứ ba; nếu không, cần một quyết định miễn trừ
riêng, tường minh của Owner cho Bước 5.

## Owner waiver — Bước 5, 2026-09-14

Owner quyết định: *"miễn trừ review Bước 5"*. Ghi nhận là **Owner waiver**, không
phải review PASS, và không đảo ngược verdict nào đã có.

* **Điều kiện được miễn:** `Architecture Review passed` của `AGENTS.md` § Database
  Rule, cho migration `2026_09_13_000100_add_ai_embedding_generation.php` và phần
  runtime Bước 5 (Embedding + Qdrant + retrieval).
* **Phạm vi:** chỉ Bước 5. Không sửa quy trình toàn repository và không phủ bước
  nào khác. Miễn trừ của Bước 4 (2026-09-12) giữ nguyên phạm vi riêng của nó.
* **Không đảo ngược:** lượt review độc lập trên `b5da390` đã ghi verdict **FAIL**
  trước khi bị ngắt. Verdict đó vẫn là hồ sơ lịch sử của `b5da390`. Các finding
  của nó được xử lý hoặc ghi hoãn trong LF-AI-Embedding-Qdrant-Implementation-Review
  § "Step 5 closure"; miễn trừ không biến chúng thành "đã PASS".
* **Không miễn:** các kiểm chứng kỹ thuật (MariaDB, toàn suite, test đỏ-trước-xanh-
  sau), tính đúng của dữ liệu, bảo vệ tenant, và các điều kiện phải xử lý trước khi
  kích hoạt provider theo ADR-0018.
* **Thẩm quyền:** chỉ Owner có quyền miễn trừ này. Implementer không miễn trừ được
  `AGENTS.md`, và không có quyết định nào của implementer nằm sau miễn trừ.

Brief này giữ nguyên để dùng lại nếu Owner muốn khôi phục điều kiện review, trên
một snapshot có kèm các bản vá sau `b5da390`.

## Bài học quy trình — worktree review (review AR-P3-9)

**Không symlink `vendor` vào worktree review.** Composer tính
`$baseDir = dirname($vendorDir)` từ đường dẫn thật, nên mọi class `App\` và
`Tests\` sẽ được nạp từ **repo chính** chứ không phải từ snapshot, và test vẫn chạy
xanh như thể đang kiểm snapshot. Dùng `vendor` cục bộ (copy `vendor/composer`,
`vendor/autoload.php`; symlink từng package là đủ), rồi xác nhận trước khi tin bất
kỳ con số nào:

```php
(new ReflectionClass(App\Services\AiEmbeddingService::class))->getFileName();
```

Kết quả phải nằm trong worktree. Không copy `.env` của repo chính vào worktree:
nó trỏ `learnforge_db` và chứa secret; dùng `.env` tối thiểu với
`DB_CONNECTION=sqlite`.

Reviewer **không** vá lỗi mình tìm ra; mọi finding giao lại cho implementer.

---

# Ràng buộc độc lập

* Reviewer **không được** là người đã viết hoặc sửa code/tài liệu Bước 5. Cụ thể,
  **không đủ tư cách**:
  * tác nhân đã viết bản đầu của `AiEmbeddingService`,
    `AiKnowledgeRetrievalService`, `QdrantVectorStore`,
    `EmbeddingProviderAdapter` và artifact
    `LF-AI-Embedding-Qdrant-Implementation-Review.md`;
  * tác nhân đã thực hiện các bản sửa `generation`, retrieval audit,
    revalidation qua Media Read và hoàn thiện Qdrant.
* Tác nhân viết bản đầu cũng đã từng đọc lại code và nêu finding P1/P3. Việc đó
  **không** biến nó thành reviewer độc lập: nó đọc lại chính thiết kế của mình.
* Không dựa vào verdict, bảng bằng chứng hay báo cáo trước. Mọi số liệu hiện có
  do implementer tạo ra (xem § Bằng chứng hiện có) và phải được tái lập.
* Trong lượt review: **không sửa** ADR, contract, database docs, schema,
  migration hay code của workspace. Chỉ tạo/cập nhật review artifact trong
  `docs/quality/`. Thử nghiệm đột biến (mutation) phải chạy trong worktree hoặc
  bản sao riêng, không chạm workspace.
* Không ghi artifact vào `docs/platform/`, `docs/database/`,
  `docs/governance/` hoặc `docs/core/`.
* **Không** gọi embedding provider thật, không dùng API key, không gửi dữ liệu
  tenant ra mạng. Qdrant chỉ được chạy trên **instance riêng, dùng một lần, tại
  máy local**; không bao giờ trỏ test vào Qdrant dùng chung hay production — test
  tích hợp ghi và xoá point.

---

# Neo review

**Commit SHA:** `b5da3901c843c6cd49388d290bd792696a38b73e`
**Ref:** `refs/lf-review/step5-2026-09-13` · **Tree:** `90b7a1e4cf15a216ecbe86249c8e4bec1affaa71`

Owner chọn neo bằng **snapshot không nằm trên nhánh** ngày 2026-09-13. Commit
này được tạo từ đúng trạng thái trên đĩa, gồm cả file untracked, qua một index
tạm; `HEAD`, `main`, index thật và working tree **không đổi** (đã kiểm trước và
sau). Cha của nó là `a7e66b4`. Nó không phải commit của Owner lên `main`.

Bản brief **bên trong** snapshot vẫn để trống ô này — một commit không thể chứa
SHA của chính nó. Đó là điều tất yếu, không phải sai lệch.

Khi Owner commit thật, chứng minh cùng nội dung bằng cách so tree:

```bash
git diff --stat b5da390 <commit-của-Owner>
```

Bắt buộc neo vào SHA, không vào working tree. Tại thời điểm soạn brief, `HEAD`
là `a7e66b4` và cây làm việc có 20 file thay đổi, 3 file untracked — trong đó có
chính migration `generation` và `MediaDerivedRetrievalAudit`.

**Bẫy riêng của bước này:** Bước 5 trải qua nhiều commit — một phần đã nằm trong
các commit trước, phần còn lại chưa commit. Vì vậy `git show --stat <SHA>` chỉ
hiện **delta của commit cuối**, không phải toàn bộ Bước 5. Reviewer phải đọc
**file tại SHA** theo danh sách phạm vi dưới đây, không đọc diff:

```bash
git worktree add /tmp/lf-step5-review <SHA>
```

---

# Phạm vi

## TRONG phạm vi

Code:

| File | Vai trò |
| --- | --- |
| `app/Contracts/Ai/EmbeddingProvider.php` | Port provider |
| `app/Contracts/Ai/VectorStore.php` | Port vector store |
| `app/Support/Ai/VectorPoint.php` | Payload point |
| `app/Support/Ai/EmbeddingWorkItem.php` | Đơn vị công việc |
| `app/Exceptions/AiEmbeddingException.php` | Mã lỗi embedding |
| `app/Services/Ai/EmbeddingProviderAdapter.php` | Nối provider + store vào gate |
| `app/Services/Ai/QdrantVectorStore.php` | Adapter Qdrant |
| `app/Services/Ai/Unavailable{EmbeddingProvider,VectorStore}.php` | Mặc định fail-closed |
| `app/Services/AiEmbeddingService.php` | Worker vòng đời `ai_embeddings` |
| `app/Services/AiKnowledgeRetrievalService.php` | Retrieval + post-validation |
| `app/Services/MediaDerivedRetrievalAudit.php` | Audit truy hồi thuộc Media |
| `app/Services/MediaReadService.php` | **Chỉ** phần `currentRevision()`, `readResolved()` và guard `status = deleted` |
| `config/ai.php` | **Chỉ** mục `embedding` và `vector_store` |
| `app/Providers/AppServiceProvider.php` | **Chỉ** binding `EmbeddingProvider` và `VectorStore` |

Migration: `database/migrations/2026_09_13_000100_add_ai_embedding_generation.php`

Test:

* `tests/Feature/AiEmbeddingServiceTest.php`
* `tests/Feature/AiKnowledgeRetrievalServiceTest.php`
* `tests/Feature/QdrantVectorStoreTest.php`
* `tests/Integration/QdrantVectorStoreIntegrationTest.php`
* `tests/Support/Ai/{FakeEmbeddingProvider,FakeVectorStore}.php`

CI: job `integration-qdrant` trong `.github/workflows/application-tests.yml`.

Tài liệu:

* `docs/database/ai/ai_embeddings.md` v1.2 — lifecycle, amendment `generation`
* `docs/database/media/media_access_logs.md` v1.4 — amendment retrieval audit
* `docs/platform/LF-AI.md` — mục trạng thái các bước
* `docs/quality/LF-AI-Embedding-Qdrant-Implementation-Review.md` — ghi nhận
  implementer

ADR nền: `ADR-0006-AI-Foundation.md` (v1.0.2 vector store, v1.0.3
`media_file_id` là provenance), `ADR-0018-Media-PII-And-External-Processing-Boundary.md`.

## NGOÀI phạm vi

* Packet bốn bảng SaaS và gate Bước 4 — đã đóng dưới miễn trừ riêng.
* `AiKnowledgeIngestionService` và `app/Console/Commands/AiKnowledgePrepare.php`
  — thuộc ingestion. Chỉ được đọc để hiểu barrier xoá và thứ tự lock.
* Kích hoạt embedding provider thật, chat, frontend — không phải tiêu chí đóng
  Bước 5.

Lỗi thấy ở ngoài phạm vi → ghi phụ lục, không ảnh hưởng verdict.

---

# Quyết định đã chốt — kiểm việc triển khai, đừng lật lại

| Quyết định | Nguồn |
| --- | --- |
| Lifecycle canonical có `failed → pending` (retry) | OD-1, `ai_embeddings.md` Retry amendment 2026-09-10 |
| Retry chỉ hợp lệ khi run giữ `pending` đã kết thúc (`failed`/`cancelled`) | cùng amendment |
| Tái đăng ký tạo row `generation + 1`, giữ nguyên lịch sử; **không** thêm transition | OD-3, Generation amendment 2026-09-13 |
| Truy hồi là sự kiện truy cập Media, ghi qua service thuộc Media | OD-2, `media_access_logs.md` Retrieval audit amendment 2026-09-13 |
| Audit không nguyên khối trong một retrieval: có thể nói quá, không bao giờ thiếu | P3-2, cùng amendment |
| Không có status `processing` | `ai_embeddings.md` |
| Qdrant self-hosted là derived index; relational DB là Source Of Truth | ADR-0006 v1.0.2 |
| Payload point không chứa raw text, PII hay signed URL | ADR-0006 v1.0.2 |
| `media_file_id` là provenance, không phải authorization | ADR-0006 v1.0.3 |
| Kích hoạt provider là quyết định riêng | ADR-0018 |

**Lifecycle hiện hành** (dùng bản này, **không** dùng bản trước amendment):

```
pending → ready | failed
failed  → pending                    (retry — Amendment 2026-09-10)
ready | failed → stale
pending | ready | failed | stale → deletion_pending → deleted
deleted terminal
```

Một bản lifecycle thiếu `failed → pending` từng được dùng làm spec trong lúc
làm việc. Nếu reviewer đối chiếu với bản đó, retry sẽ bị đánh FAIL oan.

Chưa có mã lỗi contract nào dành riêng cho embedding. Nếu contract chưa đủ cho
một tình huống → báo OWNER DECISION, không tự đặt mã.

---

# Câu hỏi bắt buộc trả lời

Mỗi câu cần verdict **PASS / FAIL / OWNER DECISION** kèm bằng chứng tái lập được
(số dòng tại SHA, output lệnh, hoặc kịch bản). "Đọc thấy hợp lý" không tính.

**Q1 — Adapter độc lập với provider.** Worker, retrieval hay ledger có tham chiếu
tên vendor nào không? Gate có ghim cả `provider()` **và** `supportsModel()` trước
khi gọi không? Đổi provider có chạm tới `ai_embeddings` hay `ai_model_runs`
ngoài các cột provenance không?

**Q2 — Revision identity và idempotency.** Liệt kê chính xác các thành phần của
`embedding_hash`. Sơ đồ `vector_key` tại thời điểm soạn brief là:

* generation 1: `stableUuid("ai-embedding|{customer}|{chunk}|{hash}")` — **cố ý
  không** có hậu tố generation, để giữ nguyên key đã ghi trước amendment;
* generation ≥ 2: cùng chuỗi đó thêm `|generation:{N}`;
* retry `failed → pending`: **tái dùng** key của chính row đó.

Bất biến cần chứng minh **không** phải "key chứa generation", mà là: key của hai
generation bất kỳ trên cùng một định danh **khác nhau**, nên xoá point thế hệ cũ
không thể xoá point thay thế. Kiểm cả biên gen 1 ↔ gen 2, nơi hai sơ đồ gặp nhau.
Chạy worker hai lần liên tiếp: lần hai có tạo row, run, hold quota hay provider
call nào không?

**Q3 — Tranh chấp generation.** Hai worker cùng đọc được generation cao nhất là
`stale` rồi cùng chèn `generation + 1`. Unique key
`(customer_id, knowledge_chunk_id, provider, model, embedding_hash, generation)`
có làm trọng tài không, và bên thua có kết thúc sạch (không row mồ côi, không
hold quota treo, không run `queued` bị bỏ lại) không? Chứng minh bằng hai
connection thật trên MariaDB, không bằng lập luận.

**Q4 — Lifecycle được thực thi đúng.** Liệt kê **mọi** chỗ code ghi
`ai_embeddings.status` và ánh xạ từng chỗ vào lifecycle hiện hành. Có ghi nào
nằm ngoài danh sách không? Có `processing` không? Cảnh báo: trong dự án này
**việc gộp nhóm status thay vì liệt kê từng cái đã phá thiết kế nhiều lần** —
kiểm ba hằng `LIVE_RUN_STATUSES = ['queued','running']`,
`ABANDONED_RUN_STATUSES = ['failed','cancelled']` và
`REPLACEABLE_STATUSES = ['stale','deleted']` có đúng ngữ nghĩa với lifecycle và
với amendment `generation` không — đặc biệt vì amendment ghi `deletion_pending`
**chặn** thay thế, nên nó phải vắng mặt khỏi `REPLACEABLE_STATUSES`. Xác nhận việc
tạo `generation + 1` **không** sửa row cũ.

**Q5 — Retry và phục hồi sau crash.** Xác định **mọi** điểm tiến trình có thể
chết trên đường authorize → claim → provider call → upsert Qdrant → đánh dấu
`ready`, và nêu đường phục hồi cho từng điểm. Đặc biệt:
* run `queued` bị bỏ lại → có được đưa về `cancelled` an toàn không?
* run `completed` nhưng row còn `pending` → reconcile dựa trên `exists()` ra sao?
* **run `running` mà tiến trình đã chết** — có đường phục hồi nào không, hay row
  `pending` và run treo vĩnh viễn? Nếu treo và contract không đủ để xử lý mà không
  đặt mã lỗi mới → OWNER DECISION.

**Q6 — Máy trạng thái xoá và reconcile.** `deleted` có **chỉ** được ghi sau
acknowledgment của store không? `UnavailableVectorStore::delete()` trả `false`
(không tiến triển) và `exists()` ném lỗi ("không biết" ≠ "không có") — cả hai có
được worker tôn trọng không? Barrier purge có giữ nguyên khi run tham chiếu còn
`queued`/`running`/không xác định không? Barrier xoá source của ingestion có chỉ
mở khi mọi embedding đã `deleted` không?

**Q7 — Source Of Truth và payload.** `VectorPoint::payload()` tại thời điểm soạn
brief trả **đúng sáu** trường: `customer_id`, `is_tenant`, `knowledge_chunk_id`,
`knowledge_source_id`, `source_fingerprint`, `processing_version`. Xác nhận
payload **thực tế** tới Qdrant — bắt request, đừng chỉ đọc hàm — không có trường
nào khác, không raw text, PII hay signed URL. **Mọi** thao tác Qdrant
— upsert, delete, exists, search — có mang filter tenant không, kể cả delete?

**Q8 — Retrieval post-validation, từng điều kiện một.** Với **mỗi** điều kiện dưới
đây, chỉ ra dòng code thực thi nó **và** một test sẽ đỏ nếu bỏ nó đi:
1. embedding `ready`;
2. source và chunk `active`, không có `deletion_requested_at`;
3. đúng revision — cả nhất quán nội tại (hash) **lẫn** revision `ready` mới nhất
   của Media;
4. đúng tenant — cả filter payload **lẫn** scope quan hệ, độc lập nhau;
5. Media authorization còn hiệu lực — owner context, usage `active`, đúng một
   usage, usage trỏ đúng `media_file_id` của source, Media không bị xoá.

Implementer báo mutation 6/6 test đỏ. Reviewer **tự** làm lại trong worktree
riêng, không thừa nhận con số đó.

**Q9 — Không đi vòng qua Media Read.** `currentRevision()` có thật sự dùng
chung `readResolved()` với `read()` không? Ở chế độ `revisionOnly` nó có bao giờ
ghim revision `archived`, nạp nội dung, dựng cấu trúc hay ký URL không? Guard
`status = deleted → missing` áp cho mọi consumer ở mức code: xác nhận nó không
chạm được qua luồng chuẩn (`MediaService` từ chối xoá khi còn usage active).

**Q10 — Audit truy hồi.** Mỗi quyết định có đúng **một** bản ghi không (Media Read
không ghi thêm ở chế độ `revisionOnly`, cả nhánh thành công lẫn từ chối)? Lỗi
insert audit có làm retrieval **không** trả nội dung không? Lý do từ chối có giữ
mã thật (`detached`, `missing`, `ambiguous_source`, `revision_mismatch`) không?
`allowed` có chỉ ghi cho hit thực sự được trả (sau khi cắt `limit`) không? Metadata
có lọt raw text, query hay vector không?

**Q11 — Migration `generation`.** Rollback có từ chối khi còn `generation > 1`
**trước** mọi DDL không? CHECK `chk_aem_generation` và unique key mới có thật sự
được tạo không — probe `information_schema`, đừng tin migration. Migration có
chạy đúng trên **engine** XAMPP MariaDB 10.4.21 — nơi `learnforge_db` sống — hay
chỉ đã kiểm trên 11.4? Hai engine từng xử lý khác nhau cùng một câu DDL trong dự
án này.

**Q12 — Chi phí ở quy mô lớn.** Khi mọi chunk đã `ready`, một lượt worker tốn bao
nhiêu truy vấn trên MariaDB (đường anti-join SQL) và trên SQLite (đường fallback)?
Test query-budget có chạy trên đường MariaDB thật không? Chi phí quét toàn tenant
còn lại có được ghi nhận trung thực không?

**Q13 — Credential và mạng.** Credential có thể lọt vào log, exception, run
metadata, audit hay test output không? Adapter Qdrant có echo body lỗi (vốn có
thể chứa vector tenant) không? Có provider nào được bind mặc định không? Test
nào, ngoài test tích hợp Qdrant local, tạo kết nối mạng ra ngoài không?

---

# Lệnh kiểm chứng

## MariaDB 11.4 — bắt buộc, không phải SQLite

**SQLite bỏ qua mọi CHECK constraint** và chấp nhận chuỗi dài hơn cột. Trong
chính Bước 5, một chuỗi 45 ký tự ghi vào `CHAR(36)` từng qua sạch 34/34 test
SQLite nhưng làm đỏ 30/34 test trên MariaDB. Chỉ MariaDB 11.4 Homebrew đại diện
được CI (`mariadb:11.4.3`).

```bash
DD=/tmp/lf-step5-review-$(date +%s)
mkdir -p "$DD"
/usr/local/bin/mariadb-install-db --datadir="$DD/data" --auth-root-authentication-method=normal
/usr/local/bin/mariadbd --datadir="$DD/data" --socket="$DD/mysql.sock" --skip-networking &
```

Ghi phiên bản **server** thật, không phải client:

```sql
SELECT VERSION();
```

Probe constraint thật:

```sql
SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE TABLE_NAME = 'ai_embeddings';
```

```sql
SHOW INDEX FROM ai_embeddings WHERE Key_name = 'uk_aem_chunk_model';
```

## Test Bước 5

```bash
php artisan test --filter "AiEmbeddingServiceTest|AiKnowledgeRetrievalServiceTest|QdrantVectorStoreTest"
```

Chạy lại cùng lệnh trên với biến môi trường MariaDB trỏ vào socket ở trên.

## Qdrant thật — instance riêng, dùng một lần

CI dùng `qdrant/qdrant:v1.11.5`. Chạy cùng phiên bản trên cổng riêng và **xoá sau
khi xong**:

```bash
docker run --rm -d --name lf-qdrant-review -p 6333:6333 -e QDRANT__TELEMETRY_DISABLED=true qdrant/qdrant:v1.11.5
```

```bash
LF_QDRANT_TEST_URL=http://127.0.0.1:6333 php artisan test tests/Integration/QdrantVectorStoreIntegrationTest.php
```

```bash
docker stop lf-qdrant-review
```

Test tự `markTestSkipped` khi thiếu `LF_QDRANT_TEST_URL` — **một lượt skip không
phải PASS**. Ngoài kết quả test, xác nhận `customer_id` là **payload index** của
collection: thiếu index, filter tenant vẫn ra kết quả đúng nhưng quét toàn
collection.

## Toàn suite và đối chiếu baseline

```bash
php artisan test
```

So khớp **tên** test đỏ qua JUnit với một baseline lưu trữ, không suy ra từ tổng
số bằng nhau. Hai lượt đo trước trên cùng một cây đã từng cho 16 và 7 lỗi mà
chưa rõ nguyên nhân; nếu gặp lại chênh lệch, ghi đầy đủ danh sách `⨯`.

```bash
pgrep -fl phpunit
```

`pkill -f "artisan test"` **không** khớp tiến trình con `phpunit` — kiểm trước
khi tin một con số lệch.

## Tài liệu

```bash
php artisan docs:lint
```

```bash
php artisan schema:drift --docs-only
```

---

# Bằng chứng hiện có, và ai đã chạy

**Toàn bộ do implementer tạo ra. Không con số nào đã được kiểm chứng độc lập.**

| Hạng mục | Kết quả báo cáo | Ai chạy |
| --- | --- | --- |
| Test embedding + retrieval + Qdrant HTTP adapter, MariaDB 11.4.12 | 65 tests, 250 assertions | implementer |
| Qdrant thật | 13 assertions | implementer |
| Mutation bỏ quyết định Media | 6/6 test đỏ | implementer |
| Test liên quan Media Read/ingestion/embedding/retrieval, SQLite | 193 passed, 5 skipped, 871 assertions | implementer |
| Toàn suite | 1132 passed, 16 failed trùng baseline | implementer |
| `docs:lint`, `schema:drift --docs-only`, Pint, build | PASS | implementer |

Các finding đã được implementer báo đóng — reviewer phải tự xác nhận, không thừa
nhận:

| Finding | Mô tả | Trạng thái báo cáo |
| --- | --- | --- |
| P1 starvation | `LIMIT` áp trước bộ lọc, chunk sau batch đầu không bao giờ được embed | Đã vá |
| P1 Media authorization | Retrieval chỉ kiểm owner context, không kiểm usage active/đúng file | Đã vá |
| P2 revision | Chỉ kiểm nhất quán nội tại, không kiểm revision `ready` mới nhất của Media | Đã vá |
| P3 `$stranded` | Luôn bằng 0 | Giữ như trường deprecated |
| P3-1 | Guard `deleted` trong selector dùng chung | Đã ghi tài liệu |
| P3-2 | Audit không nguyên khối | Chấp nhận, đã ghi tài liệu |

---

# Những gì KHÔNG được tính là PASS

* Test SQLite xanh. Nó không chạm CHECK, không kiểm độ rộng cột, không kiểm lock.
* Test tích hợp Qdrant bị **skip**.
* Qdrant cho kết quả đúng mà chưa xác nhận `customer_id` là payload index.
* Một điều kiện post-validation "có code" mà chưa có test đỏ khi bỏ nó đi.
* Migration chạy trên MariaDB 11.4 được coi là đã chứng minh cho `learnforge_db`
  trên engine 10.4.21.
* Lập luận về tranh chấp generation (Q3) mà không có hai connection thật.
* Code đúng mà tài liệu nói khác — và ngược lại.

---

# Định dạng kết quả

Tạo `docs/quality/LF-AI-Embedding-Qdrant-Architecture-Review.md` gồm:

1. Neo: SHA đã review, ngày, phiên bản MariaDB **server** (`SELECT VERSION()`),
   phiên bản Qdrant thật.
2. Verdict tổng: **PASS / FAIL**.
3. Bảng 13 câu hỏi với verdict và bằng chứng từng câu.
4. Finding theo mức P0/P1/P2/P3, mỗi finding kèm: hiện tượng, cách tái lập, tác
   động, và **giao cho ai vá** — reviewer không tự vá.
5. Mục OWNER DECISION riêng cho những chỗ contract chưa đủ.

PASS đáp ứng điều kiện `Architecture Review passed` của `AGENTS.md` cho migration
`generation`. FAIL thì điều kiện đó vẫn chưa đạt, và Bước 5 không đóng được nếu
không có quyết định miễn trừ riêng của Owner.

*(2026-09-14: Owner đã miễn trừ điều kiện này cho Bước 5 — xem § Owner waiver.)*
