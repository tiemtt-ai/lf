# AI Knowledge Sync Contract

Version: 1.2

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-09-26

Document Path: platform/LF-AI-Knowledge-Sync-Contract.md

## Mục đích và phạm vi

Knowledge Source/Chunk là xương sống dữ liệu của AI: Media (phần 1) tạo dữ liệu
dẫn xuất có revision; Knowledge (phần 2) giữ bản chuẩn bị sẵn, có provenance,
cho đề xuất (phần 3) và cho trợ giảng AI sau này. Hợp đồng này đặt quy tắc để
Knowledge **tự đồng bộ** với Media theo ba chiều: tạo → cũ (`stale`/`archived`)
→ xoá.

Trong phạm vi: xác định corpus, authority đọc Media khi không có người dùng,
lệnh đối soát, event sau commit, quy tắc archive/xoá, retry và test. Không gọi
model, không tạo embedding, không truy cập Qdrant ngoài đường purge đã có.
**Không cần migration**: mọi cột và trạng thái dùng tới đã có trong schema
Foundation (`created_by` nullable, `archived` nằm trong CHECK status).

Ngoài phạm vi: `reading_order` trong retrieval DTO (F2), đồng bộ tài liệu trạng
thái (F3), UI đề xuất, provider activation và trợ giảng frontend.

## Quyết định Owner — 2026-09-26

Owner duyệt "theo đề xuất" bốn câu hỏi của bước 1:

| # | Câu hỏi | Quyết định |
| --- | --- | --- |
| Q1 | Corpus tự động | Chỉ `course_version_activity` của Course Version đã publish. Bản nháp (`course_activity`) không tự ingest; vẫn dùng Media Read trực tiếp hoặc `ai:knowledge-prepare` |
| Q2 | Content type | Document → `region` (+ `table`); audio → `transcript`; video → `transcript` + `video_frame_text`; mỗi locale có revision `ready` là một source |
| Q3 | Authority | Đường đọc hệ thống do Media sở hữu, consumer `ai_knowledge_sync`, audit `user_id = NULL`. Chuẩn bị dữ liệu không phải đọc dữ liệu; quyền người đọc vẫn kiểm ở retrieval |
| Q4 | Gỡ Media khỏi activity | Source chuyển `archived`, giữ provenance; nội dung chỉ bị xoá khi Media bị xoá |

Owner duyệt tiếp D1–D6 "theo đề xuất" và yêu cầu "triển khai bước 2" cùng ngày
(xem § Quyết định D1–D6). Q2 được thay bằng D1 cho document.

## Triển khai và kiểm chứng — 2026-09-26

Backend đã triển khai theo hợp đồng này; không migration, không provider.

| Thành phần | Kết quả |
| --- | --- |
| Media | `MediaReadService`: bốn method A1 (xem Media Read Contract § Knowledge sync); `ProcessMediaProcessingJob` phát `MediaRevisionReady` (A2) |
| AI | `AiKnowledgeSyncService`; `AiKnowledgeIngestionService::ingestForSync()`/`archiveSource()`; listener `EraseKnowledgeOfDeletedMedia`, `SyncKnowledgeOfReadyMedia`; lệnh `ai:knowledge-sync` mỗi 10 phút; `config/ai.php` khối `knowledge_sync` |
| Course | Port đọc `CourseVersionActivityTitleService` cho tên source |

Bằng chứng implementer (không phải review độc lập):

* `AiKnowledgeSyncServiceTest` 22 test (SQLite) và `AiKnowledgeSyncMariaDbTest`
  3 test; cả 25 chạy xanh trên MariaDB 11.4.12 schema dựng mới, cùng các suite
  Knowledge/Embedding/Retrieval/Vision/Authoring-erasure liền kề. Test A2 nằm
  trong `AudioProcessingLocalReviewTest`. Cả hai file được thêm vào `integration-mysql`.
* Toàn suite mặc định xanh sau thay đổi; Pint, `docs:lint`, `schema:drift --docs-only` PASS.
* 12 mutation trên bản sao riêng đều bị bắt: lọc tenant ở danh sách owner, lọc
  Version status, bỏ bước xoá, finalize bỏ barrier, archive ghi sai trạng thái,
  listener không after-commit, bỏ kiểm owner type, bỏ D1, bỏ backoff, system
  principal bỏ eligibility, bỏ dispatch A2, dispatch cho mọi job type. Lọc tenant
  ở danh sách owner lúc đầu **sống sót** vì lớp kiểm eligibility phía sau vẫn chặn;
  đã thêm assertion trực tiếp rồi chạy lại mới bị bắt.

Chi tiết bằng chứng và đối chiếu với báo cáo đánh giá lại:
[Knowledge backbone record](../quality/LF-AI-Knowledge-Backbone-Implementation-Record.md).

**Review độc lập 2026-09-26, ba lượt:** lượt 1 CHANGES REQUIRED (F1–F5), lượt 2
CHANGES REQUIRED (F6, dry-run lặp vô hạn), **lượt 3 PASS WITH DOCUMENTED RISKS**
(F1–F6 CLOSED), xem [báo cáo](../quality/LF-AI-Knowledge-Backbone-Independent-Review.md).
F7 (LOW, coverage của dataset lô trộn) đã xử lý sau lượt 3, chưa re-review.

**Owner chốt đóng phần chuẩn bị Source/Chunk ngày 2026-09-26** với các rủi ro đã ghi;
chi tiết phạm vi đóng, rủi ro chấp nhận và phần còn riêng ở
[Knowledge backbone record](../quality/LF-AI-Knowledge-Backbone-Implementation-Record.md)
§ Owner chốt đóng Source/Chunk. Không bao gồm apply `learnforge_db`.

Chưa kiểm chứng: Redis worker và scheduler thật; hai tiến trình sync chạy song
song thật (reviewer đã chạy một race hai tiến trình PHP; implementer chỉ mô phỏng);
MariaDB 10.4; tải tenant lớn; apply `learnforge_db`. Review lại xương sống thuộc
bước 3 của lộ trình.

## Hiện trạng trước triển khai — lịch sử, 2026-09-26

Bảng dưới mô tả code **trước** khi hợp đồng này được triển khai; giữ lại làm
lý do thiết kế. Trạng thái hiện hành nằm ở § Triển khai và kiểm chứng.

| Chiều | Hiện trạng |
| --- | --- |
| Tạo | `AiKnowledgeIngestionService::ingestMedia()` chỉ được gọi từ `ai:knowledge-prepare`, cần `--actor` và ID tường minh |
| Cũ | `stale` chỉ xảy ra khi ingest lại cùng logical key; không có kênh Media báo revision mới |
| Xoá | `requestSourceDeletion()`/`finalizeSourceDeletion()` không có caller; `MediaFileDeleted` chỉ có consumer Vision và Authoring (finding F1, O-6) |
| Purge vector | `AiEmbeddingService::purgeDeletionPending()` không được lên lịch |
| Archive | Không đường nào tạo `archived` cho source/chunk |

Các bảo đảm đã có và được giữ nguyên: ingestion tất định, idempotent theo
revision, khoá logical key trong transaction, `generation` cho đăng ký lại,
barrier xoá hai pha, retrieval chỉ nhận source/chunk `active` và tái kiểm quyền
của người đọc qua Media Read.

## Corpus

Một cặp `(owner, usage)` **đủ điều kiện** khi tất cả đều đúng:

* `owner_type = course_version_activity`, activity thuộc Course Version cùng tenant;
* Course Version có `status IN ('published','deprecated')` (D2);
* `media_file_usages.status = active`, `usage_type IN ('document','audio','video')`,
  đúng một usage active cho owner/usage đó;
* `media_files.status <> 'deleted'`.

Content type theo usage:

| Usage | Content type | Ghi chú |
| --- | --- | --- |
| document | `region` | Text region bao gồm text trong bbox của region `role = table` (media_extracted_regions § text), kèm page/bbox/`reading_order` |
| document | `table` | **Chỉ khi** revision không có region (spreadsheet: bảng neo `locator_type = sheet`). Xem D1 |
| audio | `transcript` | |
| video | `transcript`, `video_frame_text` | Hai source riêng |

Mỗi revision `ready` theo locale/language profile của Media là một logical key
`(owner, usage, content_type, locale)`. Tên source lấy từ `title_snapshot` của
Version Activity.

## Authority — Amendment A1 cho Media Read Contract

Media bổ sung các method **nội bộ**, không có route, controller hay command
công khai nào gọi được ngoài AI Knowledge sync. Khi triển khai, eligibility
owner cũng do Media trả lời (`knowledgeSyncOwners`, `knowledgeOwnerHoldsMedia`)
để AI không đọc bảng Media/Course; hai method chính:

1. `revisionsForKnowledgeSync(ownerType, ownerId, usageType)` — trả danh sách
   revision hiện hành `ready`: `content_type`, `locale`, `language_profile`,
   `media_file_id`, `source_fingerprint`, `processing_version`. Chỉ metadata,
   không text, không URL; **không ghi audit** vì không đọc nội dung.
2. `readForKnowledgeSync(ownerType, ownerId, usageType, contentType, locale, languageProfile)`
   — cùng selector và validation với `read()`, trả đúng revision hiện hành.

Ràng buộc bắt buộc của cả hai:

* tenant lấy từ `TenantContext`; không nhận `customer_id` qua tham số;
* chỉ `owner_type = course_version_activity` và Version `published|deprecated`;
  mọi owner khác trả `unauthorized`;
* usage active, Media không `deleted`, revision `ready`; không pin revision cũ;
* không crop, không signed URL, không `variant`/`caption_asset`;
* method 2 ghi `media_access_logs`: `user_id = NULL`, `action = read_derived`,
  `source_type = ai_knowledge_sync`, metadata như `read()`.
  `media_access_logs.user_id` đã nullable cho system policy hợp lệ.

Đường đọc theo actor (`read()`, `currentRevision()`) không đổi. Retrieval tiếp
tục kiểm quyền của **người đọc**; dữ liệu do sync chuẩn bị không mở thêm quyền
cho ai.

## Media event — Amendment A2

Media phát `MediaRevisionReady(customerId, mediaFileId)` sau commit khi một
processing job tạo revision đọc được (`ocr`, `structured_extraction`,
`speech_to_text`, `frame_ocr`, `caption`) ghi `ready`; `virus_scan` và job thất
bại không phát. Lỗi dispatch chỉ ghi log, không làm hỏng job.
Giống `MediaFileDeleted`: chỉ mang identifier, consumer-agnostic, listener phải
tự tái kiểm. Event chỉ để tăng tốc; thiếu event thì lệnh đối soát vẫn đúng.

## Lifecycle — Amendment A3 cho database docs Knowledge

Thêm transition "owner không còn đủ điều kiện" vào `ai_knowledge_sources` và
`ai_knowledge_chunks`:

* source/chunk `pending|active|failed|stale → archived`;
* embedding của chunk đó: `ready|failed → stale`, `pending → deletion_pending`
  (đúng quy tắc rebuild hiện hành; không thêm `pending → stale`);
* `archived` không bao giờ quay lại `active`; gắn lại usage thì ingest tạo
  `generation` kế tiếp như quy tắc hiện có;
* nội dung chunk `archived` được giữ cho provenance, chỉ bị erase qua đường xoá.

Không đổi schema: `archived` đã có trong CHECK, không có trigger transition.

## Thuật toán đối soát

`AiKnowledgeSyncService::reconcileTenant(limit)` chạy trong `TenantContext` của
một tenant, theo thứ tự ưu tiên xoá trước:

1. **Xoá.** Mọi source (mọi `source_type`, kể cả source nháp do lệnh tay tạo)
   có `media_file_id` trỏ tới Media `deleted` và status khác `deleted|deletion_pending`
   → `requestSourceDeletion()`. Tập Media được nạp theo tenant rồi chia lô 500;
   chưa đo tải ở tenant rất lớn.
2. **Purge vector.** `purgeDeletionPending(limit)` khi có embedding
   `deletion_pending`. Store lỗi thì row giữ nguyên, lượt sau thử lại.
3. **Finalize.** Mọi source `deletion_pending` → `finalizeSourceDeletion()`;
   `embedding_delete_barrier` là kết quả bình thường, lượt sau thử lại.
4. **Archive.** Source `pending|active|failed|stale` mà owner không còn đủ điều
   kiện (usage detached, Version `archived`, activity biến mất) → `archived` (A3).
   Áp cho cả source nháp khi usage `course_activity` bị gỡ.
5. **Ingest.** Với mỗi owner đủ điều kiện: lấy danh sách revision qua method 1;
   revision nào chưa có source `pending|active|failed` cùng fingerprint/version
   → `ingestMedia` qua method 2 (`created_by = NULL`). Ingestion hiện có tự
   chuyển revision cũ cùng logical key sang `stale` trong cùng transaction.

So sánh bằng metadata trước, nên một lượt không có thay đổi **không đọc nội
dung và không ghi audit Media**. Ingest và archive dùng khoá logical key hiện
có; xoá dùng khoá source hiện có. Mọi bước idempotent: chạy lại, event lặp, hay
listener và lệnh chạy chồng đều không tạo row trùng hoặc đổi thời điểm yêu cầu xoá.

## Trigger, lịch và tải

| Trigger | Hành động |
| --- | --- |
| `ai:knowledge-sync {--customer=} {--dry-run}` mỗi 10 phút, `withoutOverlapping` | Nguồn đúng: chạy đủ 5 bước cho từng tenant; một tenant lỗi không chặn tenant khác. `--dry-run` chỉ báo cáo: không ghi DB, không đẩy cursor hay đặt backoff, không đọc nội dung Media, không audit; nó duyệt mọi owner/source thay vì một lô |
| `MediaFileDeleted` → `EraseKnowledgeOfDeletedMedia` (`ShouldQueueAfterCommit`) | Bước 1–3 cho đúng Media đó |
| `MediaRevisionReady` → `SyncKnowledgeOfReadyMedia` (`ShouldQueueAfterCommit`) | Bước 5 cho các owner đủ điều kiện dùng Media đó |

Publish Version, gắn/gỡ usage và đổi status Version không có event riêng; chúng
được xử lý khi vòng quét của lệnh tới owner đó. Mỗi lượt giới hạn số owner mỗi
tenant (mặc định 200, cấu hình `ai.knowledge_sync.owner_limit`), duyệt theo id với
con trỏ trong cache. Vì vậy độ trễ là **một vòng quét**, tức số lượt cần để phủ
hết owner của tenant nhân chu kỳ 10 phút, cộng thời gian backoff nếu revision đang
bị hoãn; mất cache thì vòng quét bắt đầu lại từ đầu. Không có cam kết một chu kỳ. Listener khôi phục `TenantContext` trước
đó khi kết thúc, như listener Vision.

## Lỗi và retry

* Lỗi của một logical key không chặn key khác. Mã lỗi dùng lại
  `AiKnowledgeIngestionException`/`MediaReadException` hiện có; không thêm mã mới.
* Lỗi vĩnh viễn (`empty_revision`, `mixed_revision`, `invalid_revision`,
  `revision_identity_conflict`, `unsupported_source`) được ghi backoff trong
  cache theo `(tenant, logical key, fingerprint, version)`: 1 giờ, tăng tới 24
  giờ. Revision mới tự xoá backoff vì khoá khác. Cache mất thì chỉ tốn thêm một
  lượt đọc, không sai dữ liệu (D6).
* Log chỉ gồm tenant, owner, content type, mã lỗi; không text Media, không
  exception message có thể chứa dữ liệu.
* Candidate `ready` mà Media Read không resolve được được trả về dưới dạng
  `candidate_errors` (mã ổn định), đếm vào `failed` ở mọi lượt và log một lần mỗi
  cửa sổ backoff. Mã của revision vừa đổi giữa lúc liệt kê và resolve (`pending`,
  `processing`, `failed`, `archived`, `detached`, `missing`) được coi là đang ổn
  định lại và bỏ qua; lượt sau xử lý.
* Finalize chỉ chọn source không còn embedding chưa `deleted` **trước** khi áp
  giới hạn lô, nên source bị barrier giữ lại không chiếm chỗ của source đã đủ điều
  kiện; barrier vẫn được kiểm lại dưới khoá.
* Lệnh in số source đã tạo/stale/archive/request xoá/finalize và số row còn kẹt
  ở `deletion_pending` quá 24 giờ, để vận hành thấy việc xoá bị treo.

## Bất biến

* Không trộn tenant: mọi truy vấn lọc `customer_id` từ `TenantContext`; event
  của tenant không tồn tại bị bỏ qua.
* Không trộn revision: một source đúng một `(fingerprint, processing_version)`.
* Xoá Media luôn dẫn tới erase nội dung chunk, trừ khi embedding còn chưa
  `deleted` — khi đó barrier giữ lại cho tới lúc purge xong.
* Media không ghi bảng AI; AI không ghi bảng Media.
* Sync không mở quyền: retrieval vẫn trả rỗng cho người không có quyền trên owner.

## Thành phần

| Miền | File | Thay đổi |
| --- | --- | --- |
| Media | `MediaReadService` | Bốn method A1 (hai method chính + hai method eligibility) |
| Media | `App\Events\MediaRevisionReady`, `ProcessMediaProcessingJob` | Event A2 sau commit |
| AI | `AiKnowledgeSyncService` (mới) | Thuật toán đối soát |
| AI | `AiKnowledgeIngestionService` | `ingestForSync()` dùng method 2; `archiveSource()` (A3) |
| AI | `EraseKnowledgeOfDeletedMedia`, `SyncKnowledgeOfReadyMedia` (mới) | Listener `ShouldQueueAfterCommit` |
| AI | `ai:knowledge-sync` (mới), `routes/console.php`, `config/ai.php` | Lệnh, lịch, giới hạn |
| Course | `CourseVersionActivityTitleService` (mới) | Port đọc `title_snapshot` cho tên source |

`ai:knowledge-prepare` giữ nguyên cho bản nháp.

## Kế hoạch test

Feature (SQLite) cho luồng, Integration MariaDB 11.4 cho khoá và chạy đồng thời:

1. Version publish có revision `ready` → source/chunk `active`, `created_by` NULL,
   audit `ai_knowledge_sync` với `user_id` NULL; chạy lại không tạo row và không
   thêm audit.
2. Activity nháp, Version `draft_snapshot` → không ingest.
3. Revision mới → source mới, source cũ `stale`, embedding cũ `stale`/`deletion_pending`.
4. Gỡ usage, Version `archived` → `archived`; gắn lại → `generation` kế tiếp.
5. Xoá Media không có embedding → `deleted`, `content` NULL, provenance còn.
6. Xoá Media có embedding: store lỗi → vẫn `deletion_pending`, content còn;
   lượt sau store thành công → embedding `deleted` → chunk erase.
7. Source nháp tạo bằng lệnh tay cũng bị xoá khi Media bị xoá.
8. Hai tenant: sync tenant A không đọc/ghi tenant B; event mang tenant sai bị bỏ qua.
9. Event lặp, listener và lệnh chạy chồng (MariaDB, hai connection) → không trùng row.
10. Rollback transaction xoá Media → không có job; job chỉ chạy sau commit ngoài cùng.
11. Method A1 từ chối `course_activity`, Version nháp/archived, usage detached,
    Media deleted; không route nào trỏ tới chúng.
12. Teacher không được phân công vẫn không retrieve được nội dung đã sync.
13. Lỗi vĩnh viễn không bị đọc lại mỗi lượt; revision mới vẫn được ingest ngay.

Mutation phải bị bắt (chạy trên bản sao riêng): bỏ lọc tenant; bỏ điều kiện
Version published; bỏ bước xoá trong lệnh; finalize bỏ qua barrier; archive
dùng `active`; bỏ `afterCommit`; method A1 bỏ kiểm owner type.

## Quyết định D1–D6 — Owner duyệt 2026-09-26

| # | Nội dung | Đề xuất |
| --- | --- | --- |
| D1 | Q2 đã duyệt "region + table", nhưng region `role = table` đã chứa text của bảng PDF/DOCX; lấy cả hai sẽ trùng nội dung | `table` chỉ cho revision không có region (spreadsheet) |
| D2 | Version `deprecated` vẫn phục vụ học viên đang học | `published` + `deprecated` đủ điều kiện; `archived` → archive source |
| D3 | Amendment A1 + A2 thay đổi ranh giới Media | Duyệt với tư cách owner miền Media |
| D4 | Method 1 (chỉ metadata) không ghi audit | Duyệt; nội dung vẫn audit qua method 2 |
| D5 | Amendment A3 cho database docs Knowledge | Duyệt; không migration |
| D6 | Backoff lỗi vĩnh viễn trong cache thay vì bảng mới | Duyệt; tránh thay đổi schema |

Đã thực hiện: Media Read Contract v1.25 (A1, A2), `ai_knowledge_sources` và
`ai_knowledge_chunks` v1.2 (A3). Review lại xương sống thuộc bước 3 của lộ trình.
