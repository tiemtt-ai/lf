# AI Knowledge Backbone — Reviewer Brief

Version: 1.0

Document Status: Review

Implementation Status: Implemented

Last Updated: 2026-09-26

Document Path: quality/LF-AI-Knowledge-Backbone-Reviewer-Brief.md

---

# Vì sao có brief này

Lộ trình Owner ngày 2026-09-26 đặt bước 3 là **review lại xương sống** sau khi
triển khai ba việc: làm xanh revision identity, đồng bộ Media → Knowledge (tạo →
stale/archived → xoá), `reading_order` và đồng bộ tài liệu. Toàn bộ bằng chứng
hiện có là của implementer, ghi trong
[Knowledge backbone record](LF-AI-Knowledge-Backbone-Implementation-Record.md).
**Chưa có review độc lập nào cho phần này và không có miễn trừ nào.**

Mục tiêu: xác nhận Knowledge Source/Chunk là xương sống dữ liệu đúng hợp đồng —
đúng corpus, đúng authority, không trộn tenant/revision, xoá được chứng minh qua
barrier — để Owner quyết định đóng phần chuẩn bị Source/Chunk. Theo Owner, UI đề
xuất, activation provider và trợ giảng frontend **không** phải điều kiện đóng.

Verdict yêu cầu: `PASS`, `PASS WITH DOCUMENTED RISKS`, `CHANGES REQUIRED` hoặc
`BLOCKED`, kèm lý do. Không ký PASS cho điều chưa tự kiểm chứng.

---

# Ràng buộc độc lập

* **Không đủ tư cách:** tác nhân đã viết code hoặc tài liệu của bước 1–3 ngày
  2026-09-26 (session implementer của Knowledge Sync, cũng là tác giả phần lớn
  code Bước 6/7 và của record implementer nêu trên).
* **Đủ tư cách nếu chưa sửa gì:** reviewer của báo cáo
  `AI-Packet-Reassessment-2026-09-26.md` (working directory) — người đó phát hiện
  F1–F3 nhưng không viết bản vá. Reviewer khác cũng được.
* Không dựa vào verdict, bảng số hay kết luận của record implementer; mọi con số
  phải tái lập.
* Reviewer **không vá** lỗi mình tìm ra; finding giao lại cho implementer.

# Ràng buộc an toàn

* Chỉ đọc. Không sửa code, migration, test hay tài liệu canonical. Báo cáo đặt ở
  `docs/quality/` hoặc working directory (AGENTS.md § Documentation Rule).
* **Không kết nối `learnforge_db`, không chạm MariaDB XAMPP ở cổng 3306.** Dùng
  instance MariaDB 11.4 dùng một lần (§ Kiểm chứng).
* Không gọi provider thật, không thêm API key/secret, không gửi dữ liệu ra mạng.
* Mutation chỉ trên **bản sao riêng**; ghi SHA-256 trước, khôi phục và đối chiếu
  sau. Một lần mutation trên cây thật đã từng để lại guard bị vô hiệu hoá
  (hồ sơ Bước 7).
* **Không symlink `vendor`** vào worktree/bản sao: Composer sẽ nạp `App\`/`Tests\`
  từ repo chính và test xanh sai snapshot. Xác nhận bằng
  `(new ReflectionClass(App\Services\AiKnowledgeSyncService::class))->getFileName()`.
* Không chạy song song các suite dùng chung fixture/storage/database.

# Snapshot

Phần cần review nằm trong **working tree chưa commit** trên HEAD `01cce9e`. Nếu
Owner commit trước khi review, dùng commit đó. Nếu không, ghi SHA-256 các file
dưới đây lúc bắt đầu và đối chiếu lại lúc kết thúc; lệch thì dừng và báo.

```bash
shasum -a 256 app/Services/AiKnowledgeSyncService.php app/Services/AiKnowledgeIngestionService.php app/Services/MediaReadService.php app/Services/AiKnowledgeRetrievalService.php app/Jobs/ProcessMediaProcessingJob.php
```

Working tree còn hai thay đổi **ngoài phạm vi**: bản vá trùng mapping ở
`CourseTemplateLearningMappingIntentService` (+ test HTTP) và `composer.lock` chỉ
đổi khoảng trắng.

---

# TRONG phạm vi

## Code

| Miền | File | Nội dung |
| --- | --- | --- |
| Media | `app/Services/MediaReadService.php` | System principal A1: `knowledgeSyncOwners`, `knowledgeOwnerHoldsMedia`, `revisionsForKnowledgeSync`, `readForKnowledgeSync`, nhánh `actorId = null` trong `readResolved`, audit `user_id = NULL` |
| Media | `app/Events/MediaRevisionReady.php`, `app/Jobs/ProcessMediaProcessingJob.php` | Event A2 sau commit, chỉ job tạo revision đọc được |
| AI | `app/Services/AiKnowledgeSyncService.php` | Đối soát 5 bước, D1, backoff, cursor |
| AI | `app/Services/AiKnowledgeIngestionService.php` | `register()` tách chung, `ingestForSync()`, `archiveSource()` (A3) |
| AI | `app/Listeners/EraseKnowledgeOfDeletedMedia.php`, `app/Listeners/SyncKnowledgeOfReadyMedia.php` | `ShouldQueueAfterCommit` |
| AI | `app/Console/Commands/AiKnowledgeSync.php`, `routes/console.php`, `config/ai.php` | Lệnh, lịch 10 phút, giới hạn |
| AI | `app/Services/AiKnowledgeRetrievalService.php` | `reading_order`, `source_text_quality`; ghi chú hoãn modifier |
| Course | `app/Services/CourseVersionActivityTitleService.php` | Port đọc `title_snapshot` |
| Test | `tests/Feature/AiKnowledgeSyncServiceTest.php`, `tests/Integration/AiKnowledgeSyncMariaDbTest.php`, `tests/Support/Ai/KnowledgeSyncFixture.php` | 22 + 3 test |
| Test | `tests/Feature/MediaRevisionLifecycleTest.php`, `AudioProcessingLocalReviewTest.php`, `VideoTranscriptCaptionLocalReviewTest.php` | Sửa kỳ vọng VAD; test A2 |
| Test | `tests/Feature/AiKnowledgeRetrievalServiceTest.php` | Test reading order |
| CI | `.github/workflows/application-tests.yml` | Thêm hai file sync vào `integration-mysql` |

## Tài liệu và quyết định

* [Knowledge Sync Contract](../platform/LF-AI-Knowledge-Sync-Contract.md) v1.0 — Q1–Q4, D1–D6.
* [Media Read Contract](../platform/LF-Media-Read-Contract.md) v1.25 § Knowledge sync (A1, A2).
* [`ai_knowledge_sources`](../database/ai/ai_knowledge_sources.md), [`ai_knowledge_chunks`](../database/ai/ai_knowledge_chunks.md) v1.2 (A3).
* [Media Processing Contract](../platform/LF-Media-Processing-Contract.md) v2.47 § length compaction; VAD correction 2026-09-07.
* [LF-AI](../platform/LF-AI.md) § Media evidence retrieval policy — modifier hoãn tới consumer đầu tiên (Owner 2026-09-26).
* Trạng thái tài liệu: `database/ai/README.md`, LF-INDEX, ADR-0006, ADR-0017; DOC-CONFLICT-0038/0039.

# NGOÀI phạm vi

* UI đề xuất, activation provider, trợ giảng frontend (Owner: không phải điều kiện đóng).
* Modifier xếp hạng và context expansion của retrieval (Owner hoãn; chỉ kiểm việc
  hoãn được ghi đúng và không có consumer nào đang dùng retrieval).
* Backend + HTTP Bước 7 (đã nghiệm thu dưới miễn trừ), trừ chỗ tương tác với
  `MediaFileDeleted` và system principal.
* Re-review DDL Foundation trước apply — gate riêng; reviewer có thể ghi nhận nhưng
  không thay nó.

---

# Câu hỏi reviewer phải trả lời

## A. Revision identity (bước 1)

1. Các test Media được sửa là **khôi phục đúng kỳ vọng theo amendment VAD** hay làm
   yếu test? Đối chiếu từng assertion với Processing Contract § VAD correction.
2. Kiểm `test_a_new_processing_version_archives_the_previous_audio_revision`: câu
   `revision_mismatch` giờ có thật sự kiểm fingerprint trên revision tồn tại không?
3. Assertion video mới (identity ghép tường minh + quy tắc nén) có chặt hơn kiểm
   chuỗi con cũ không? Bảng nén trong contract có khớp đủ 5 nhánh của
   `MediaProcessingOrchestrator::versionFor` không?

## B. System principal (A1)

1. Có đường nào tới `readResolved(actorId: null, …)` mà không qua bốn method A1,
   hoặc từ HTTP/route/command công khai không?
2. Nhánh `actorId = null` có bị ràng buộc đủ: consumer đúng, không crop, eligibility
   Version `published|deprecated`, cùng tenant? Có thể đọc `course_activity`, bản nháp,
   usage detached, Media deleted, `caption_asset`/`variant` không?
3. `revisionsForKnowledgeSync` không audit và **bỏ qua im lặng** candidate lỗi
   (`catch MediaReadException → continue`). Có che mất lỗi cần thấy không?
4. Media đọc bảng Course (`core_course_template_versions`/`_version_activities`)
   để quyết định eligibility — nhất quán với tiền lệ `CourseMediaOwnerContextAuthorizer`
   hay vi phạm ownership? AI có còn đọc trực tiếp bảng Media/Course nào không?
5. Audit `media_access_logs` với `user_id = NULL` có đúng schema doc
   (`user_id` nullable cho system policy) và đủ để truy vết không?

## C. Event A2

1. Dispatch có nằm sau commit và ngoài `catch` purge crop/caption không? Lỗi dispatch
   có thể làm hỏng job hoặc xoá asset của revision đã commit không?
2. Danh sách job type có đúng "revision đọc được qua Media Read"? `virus_scan` và job
   thất bại có chắc không phát?

## D. Thuật toán đồng bộ

1. Thứ tự xoá → purge → finalize → archive → ingest có đúng ưu tiên xoá? Tenant
   không active: vẫn xoá/archive, không ingest — có đúng ý Owner không?
2. Idempotency: lệnh chạy lại, event lặp, listener chạy chồng lệnh. Test MariaDB chỉ
   **mô phỏng** worker thắng giữa lượt bằng query listener, không có hai tiến trình
   thật — đánh giá rủi ro còn lại, nếu được thì thử hai tiến trình trên instance tạm.
3. Cursor owner/archive nằm trong cache (mất cache → quét lại từ đầu). Có thể bỏ
   sót owner vĩnh viễn không? `requestDeletionForDeletedMedia` không giới hạn số
   source mỗi lượt — có rủi ro tải không?
4. Danh sách lỗi vĩnh viễn và backoff (D6): lỗi nào đáng lẽ là tạm thời? Revision mới
   có luôn thoát backoff cũ?
5. Log có chắc không chứa text Media, message exception hay dữ liệu nhạy cảm?

## E. Lifecycle A3 và xoá

1. `archiveSource`: transition đúng A3? Embedding `pending → deletion_pending`,
   `ready|failed → stale`, không có `pending → stale`?
2. `archived` không bao giờ quay lại `active`; gắn lại tạo `generation` kế tiếp?
3. Xoá Media: mọi source của file (mọi `source_type`, gồm `stale`/`archived` và
   source nháp tạo tay) vào `deletion_pending`; nội dung chỉ erase khi mọi embedding
   `deleted`; vector store lỗi thì giữ nội dung và retry?
4. Probe xoá của báo cáo 2026-09-26 giờ đỏ ở dòng khẳng định nội dung **còn** sau
   khi xoá. Tự xác nhận đây là do lỗi đã được sửa, không phải lý do khác.

## F. Retrieval và tài liệu

1. DTO có đủ `media_file_id`, `source_fingerprint`, `processing_version`, locator,
   `reading_order` như LF-AI yêu cầu? NULL có giữ nguyên nghĩa?
2. Xác nhận `AiKnowledgeRetrievalService` chưa có consumer sản phẩm và điều kiện
   hoãn modifier được ghi ở LF-AI, record và docblock.
3. Còn phát biểu trạng thái nào mâu thuẫn với code (README, INDEX, ADR, LF-AI,
   manifest)? DOC-CONFLICT-0038/0039 có đúng phân loại, không mở lại 0012/0036?

## G. Điều kiện đóng của lộ trình (phần Source/Chunk)

Đánh giá từng điều: docs/schema/migration/implementation không drift; ingestion,
rebuild và xoá idempotent; không trộn revision/tenant; delete barrier chứng minh
bằng test; retrieval luôn tái kiểm quyền; mutation bảo vệ tenant, provenance,
revision, stale lifecycle và delete barrier.

---

# Kiểm chứng

## MariaDB 11.4 — bắt buộc

SQLite bỏ qua CHECK; trong chính lượt triển khai, MariaDB bắt ba lỗi fixture mà
SQLite cho qua. Socket phải ngắn (đường dẫn trên ~103 ký tự sẽ lỗi).

```bash
DD=$(mktemp -d /tmp/lfbb.XXXXXX)
/usr/local/opt/mariadb@11.4/bin/mariadb-install-db --datadir="$DD/data" --auth-root-authentication-method=normal
/usr/local/opt/mariadb@11.4/bin/mariadbd --datadir="$DD/data" --socket="$DD/s.sock" --skip-networking &
```

Xác nhận server trước khi chạy, rồi tạo database riêng:

```sql
SELECT VERSION(), @@datadir, @@explicit_defaults_for_timestamp;
```

Chạy với `APP_ENV=testing DB_CONNECTION=mysql DB_URL= DB_SOCKET=$DD/s.sock DB_DATABASE=<db tạm> DB_USERNAME=root DB_PASSWORD=`.

## Test

```bash
php artisan test tests/Feature/AiKnowledgeSyncServiceTest.php tests/Integration/AiKnowledgeSyncMariaDbTest.php tests/Feature/AiKnowledgeIngestionServiceTest.php tests/Feature/AiKnowledgeRetrievalServiceTest.php tests/Feature/AiEmbeddingServiceTest.php
```

```bash
php artisan test tests/Feature/MediaRevisionLifecycleTest.php tests/Feature/AudioProcessingLocalReviewTest.php tests/Feature/VideoTranscriptCaptionLocalReviewTest.php
```

Chạy cả SQLite và MariaDB. Sau đó toàn danh sách `integration-mysql` trong
workflow (33 file) trên schema dựng mới, rồi toàn suite mặc định; đối chiếu **tên**
test đỏ qua JUnit, không suy từ tổng số. Test Video thật cần `ffmpeg`/`say`; một
lượt skip không phải PASS.

## Mutation gợi ý (ngoài 12 mutation implementer đã chạy)

* `revisionsForKnowledgeSync` bỏ `withReadyDocumentJob`.
* `knowledgeOwnerHoldsMedia` bỏ điều kiện `media_file_id` khớp.
* `requestDeletionForDeletedMedia` bỏ `stale`/`archived` khỏi tập xoá.
* `alreadyActive` bỏ so fingerprint hoặc version.
* `archiveSource` chuyển embedding `pending → stale`.
* Retrieval bỏ `reading_order` khỏi DTO.

## Tài liệu

```bash
php artisan docs:lint
```

```bash
php artisan schema:drift --docs-only
```

---

# Bằng chứng hiện có (implementer — phải tái lập)

| Hạng mục | Số liệu implementer |
| --- | --- |
| Ba suite Media sau sửa VAD | 41/41; mutation bỏ VAD làm 7 test đỏ |
| Sync | 22/22 SQLite; 25/25 MariaDB 11.4.12 (22 + 3) |
| `integration-mysql` (33 file) | 537 passed, 1 skipped, 0 failed, schema dựng mới |
| Toàn suite mặc định | 1270 passed, 22 skipped, 0 failed |
| Mutation | 12/12 bị bắt; lọc tenant ở danh sách owner ban đầu sống sót, đã thêm assertion |

Chưa có: Redis worker/scheduler thật, hai tiến trình sync song song thật,
MariaDB 10.4, tải tenant lớn, GitHub CI, apply `learnforge_db`.

---

# Định dạng báo cáo

* Đặt tại `docs/quality/LF-AI-Knowledge-Backbone-Independent-Review.md` hoặc working
  directory; không sửa file khác.
* Header chuẩn; nêu reviewer, snapshot (commit hoặc SHA-256 file), ngày.
* Findings theo mức `BLOCKER | HIGH | MEDIUM | LOW`, mỗi finding có file:dòng, tình
  huống cụ thể dẫn tới sai, bằng chứng tái lập và đề xuất (không vá).
* Bảng lệnh đã chạy và kết quả **của chính reviewer**, tách khỏi số của implementer.
* Trả lời từng nhóm câu hỏi A–G; ghi rõ mục nào không kiểm được.
* Verdict cuối và điều kiện đóng phần Source/Chunk còn thiếu, nếu có.
