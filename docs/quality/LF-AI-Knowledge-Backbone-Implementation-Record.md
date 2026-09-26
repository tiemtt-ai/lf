# AI Knowledge Backbone — Implementation Record

Version: 1.4

Document Status: Review

Implementation Status: Implemented

Last Updated: 2026-09-26

Document Path: quality/LF-AI-Knowledge-Backbone-Implementation-Record.md

## Owner chốt đóng Source/Chunk — 2026-09-26

Owner quyết định: **"chốt đóng Source/Chunk"**, sau review độc lập ba lượt với verdict
cuối **PASS WITH DOCUMENTED RISKS** ([báo cáo](LF-AI-Knowledge-Backbone-Independent-Review.md)).
Đây là đóng dựa trên review PASS có rủi ro đã ghi, không phải miễn trừ.

| Mục | Nội dung |
| --- | --- |
| Được đóng | Phần chuẩn bị Knowledge Source/Chunk (Phần 2 xương sống): revision identity, đồng bộ Media → Knowledge tạo → stale/archived → xoá, system principal A1, event A2, lifecycle A3, `reading_order` trong retrieval, đồng bộ tài liệu |
| Rủi ro chấp nhận | Quét xoá chưa giới hạn bộ nhớ ở tenant rất lớn; mất cache kéo dài vòng quét; backoff có thể trì hoãn lỗi đã tự hết khi identity chưa đổi; `MediaRevisionReady` không tự `ShouldDispatchAfterCommit` (đảm bảo dựa vào đường dispatch sau transaction và listener after-commit); chưa kiểm Redis worker/scheduler thật, nhiều tiến trình, tải lớn, MariaDB 10.4, GitHub CI |
| Không đóng / phạm vi riêng | Apply `learnforge_db` (chờ [review bốn migration AI](LF-AI-Migrations-Pre-Apply-Reviewer-Brief.md)); provider activation và embedding thật; modifier xếp hạng retrieval (hoãn tới consumer đầu tiên); UI đề xuất; trợ giảng frontend; usage mồ côi khi thay nội dung nháp (task riêng Course × Media) |
| Ghi chú | F7 (LOW, coverage test) được xử lý sau lượt 3 và chưa được reviewer chạy lại; chỉ đổi test, không đổi runtime |

## Phạm vi và vị thế

Hồ sơ implementer cho ba bước Owner đặt ngày 2026-09-26 sau lượt đánh giá lại
packet AI (báo cáo `AI-Packet-Reassessment-2026-09-26.md` ở working directory,
verdict BLOCKED với F1 HIGH, F2 MEDIUM, F3 MEDIUM):

1. Chốt corpus/authority và làm xanh kiểm chứng revision identity.
2. Đồng bộ Media → Knowledge: tạo → stale/archived → xoá.
3. `reading_order` trong retrieval và đồng bộ tài liệu.

**Đây không phải review độc lập.** Người viết hồ sơ đồng thời viết code các bước
trên (và phần lớn code Bước 6/7). Bước 3 của lộ trình yêu cầu review lại xương
sống; reviewer phải là người khác. Không có miễn trừ nào được ghi ở đây.

Nguồn sự thật: [Knowledge Sync Contract](../platform/LF-AI-Knowledge-Sync-Contract.md),
[Media Read Contract](../platform/LF-Media-Read-Contract.md) § Knowledge sync,
[Media Processing Contract](../platform/LF-Media-Processing-Contract.md),
[`ai_knowledge_sources`](../database/ai/ai_knowledge_sources.md),
[`ai_knowledge_chunks`](../database/ai/ai_knowledge_chunks.md),
[LF-AI](../platform/LF-AI.md).

## Quyết định Owner — 2026-09-26

| Mục | Quyết định |
| --- | --- |
| Thứ tự | Corpus/authority + revision identity → đồng bộ → reading_order + tài liệu + review lại xương sống; UI đề xuất, activation, trợ giảng là phạm vi sau, không phải điều kiện đóng Source/Chunk |
| Q1–Q4 | Corpus = `course_version_activity` của Version đã publish; content type theo usage; system principal Media-owned; gỡ usage → `archived` |
| D1–D6 | `table` chỉ khi không có region; Version `deprecated` trong corpus; amendment A1/A2 Media, A3 Knowledge; listing metadata không audit; backoff trong cache |

## Bước 1 — revision identity

Bảy failure trước đây gọi là "baseline Media" là **test lỗi thời**, không phải
lỗi runtime: amendment VAD 2026-09-07 bắt buộc strategy tham gia
`processing_version` (commit `ac47b1b`), test viết trước đó.

* `MediaRevisionLifecycleTest`: helper `videoVersion()` ghép tường minh gồm VAD;
  docblock cũ nói sai là dùng hàm runtime.
* `AudioProcessingLocalReviewTest`: ba version tường minh thêm VAD; câu kiểm
  `revision_mismatch` trước đó trỏ tới version không tồn tại nên không kiểm được
  fingerprint.
* `VideoTranscriptCaptionLocalReviewTest`: với VAD, identity vượt 100 ký tự và bị
  nén; thay kiểm chuỗi con bằng so khớp identity ghép tường minh gồm quy tắc nén.
  Quy tắc nén được ghi vào Processing Contract v2.47 (DOC-CONFLICT-0039).

Kiểm chứng: 41/41 test ba suite; mutation bỏ VAD khỏi identity trên bản sao riêng
làm cả 7 test đỏ, khôi phục khớp SHA-256; toàn suite mặc định lần đầu 0 failure.

## Bước 2 — đồng bộ Media → Knowledge (đóng F1)

Triển khai theo Knowledge Sync Contract v1.0; không migration, không provider.
`MediaFileDeleted` có thêm consumer `EraseKnowledgeOfDeletedMedia`; `MediaRevisionReady`
mới có consumer `SyncKnowledgeOfReadyMedia`; lệnh `ai:knowledge-sync` chạy mỗi 10
phút là nguồn đúng. Xoá Media giờ đưa mọi Source của file (kể cả source nháp tạo
tay) vào `deletion_pending`, purge vector, rồi erase nội dung chunk khi barrier cho
phép.

| Kiểm chứng | Kết quả |
| --- | --- |
| `AiKnowledgeSyncServiceTest` (22) + `AiKnowledgeSyncMariaDbTest` (3) | 25/25 trên MariaDB 11.4.12 schema dựng mới; 22/22 SQLite |
| Test A2 trong `AudioProcessingLocalReviewTest` | Job thất bại và `virus_scan` không phát; revision `ready` phát đúng một lần |
| Toàn danh sách `integration-mysql` (33 file) | 537 passed, 1 skipped, 0 failed, MariaDB 11.4.12 schema dựng mới |
| Toàn suite mặc định | 1269 passed, 22 skipped, 0 failed |
| Mutation trên bản sao riêng | 12/12 bị bắt (xem contract); lọc tenant ở danh sách owner ban đầu sống sót nhờ lớp kiểm phía sau, đã thêm assertion trực tiếp |

MariaDB bắt ba lỗi fixture mà SQLite bỏ qua (`chk_mpj_ready`, `chk_mer_method`,
`chk_mtc_row`); đã sửa fixture, không nới constraint.

## Bước 3 — reading_order (F2) và tài liệu (F3)

`AiKnowledgeRetrievalService` trả thêm `reading_order` và `source_text_quality`,
đều là snapshot lúc ingest của đúng revision; NULL giữ nguyên nghĩa (transcript
không có reading order). Test mới trong `AiKnowledgeRetrievalServiceTest`.

Các modifier xếp hạng và context expansion của LF-AI § Media evidence retrieval
policy chưa triển khai. **Owner quyết định 2026-09-26: hoãn tới consumer đầu tiên**;
consumer đầu tiên phải triển khai đủ trong cùng thay đổi hoặc có quyết định mới.
Đây là hoãn có điều kiện, không phải nới policy.

Tài liệu (DOC-CONFLICT-0038): `database/ai/README.md`, LF-INDEX routing AI,
ADR-0006 và ADR-0017 (`Partial`; Context của ADR-0017 đánh dấu là lịch sử),
LF-AI (`Partial`, đoạn mở đầu và trạng thái retrieval), O-6 trong LF-AI ghi đã nối.

## Đối chiếu probe của reviewer

Hai probe trong `review-artifacts/ai-2026-09-26/`:

* Probe reading order: **xanh** không sửa gì.
* Probe xoá: giờ đỏ ở dòng khẳng định nội dung chunk **vẫn còn** sau khi xoá Media —
  đó là mô tả hành vi lỗi cũ. Một bản sao tạm thay dòng đó bằng `assertNull`
  chạy xanh qua `MediaService::deleteMedia()` thật, kể cả assertion cuối (source
  vào `deletion_pending|deleted`). Artifact gốc của reviewer không bị sửa.

## Review độc lập 2026-09-26 và remediation

[Báo cáo](LF-AI-Knowledge-Backbone-Independent-Review.md): **CHANGES REQUIRED**,
HIGH 1, MEDIUM 2, LOW 2. Reviewer độc lập với code; implementer vá, reviewer chưa
chạy lại.

| Finding | Remediation | Regression |
| --- | --- | --- |
| F1 HIGH — finalize bị source bị barrier giữ chiếm hết lô | Lọc source còn embedding chưa `deleted` trước `limit`; đếm riêng số bị giữ; barrier vẫn kiểm dưới khoá | `test_finalize_does_not_starve_sources_already_free_of_embeddings` (lô 1) |
| F2 MEDIUM — activity nháp bị xoá, usage còn sót, source vẫn active | `knowledgeOwnerHoldsMedia` kiểm owner nháp còn tồn tại trong tenant; fixture test nháp dùng activity thật thay owner giả | `test_a_removed_draft_activity_archives_its_hand_prepared_source` |
| F3 MEDIUM — lỗi candidate bị nuốt | Listing trả `candidate_errors` (trừ mã đang ổn định lại); sync đếm `failed` mỗi lượt, log một lần mỗi cửa sổ backoff, chỉ identifier + mã | `test_a_refused_ready_candidate_is_counted_and_logged_once_per_window` |
| F4 LOW — contract lỗi thời, `--dry-run` không tồn tại, cam kết độ trễ sai | Triển khai `--dry-run` chỉ đọc như contract đã duyệt; đánh dấu bảng hiện trạng là lịch sử; độ trễ diễn đạt theo vòng quét | `test_dry_run_reports_the_pass_and_writes_nothing` |
| F5 LOW — bảng nén thiếu nhánh OCR sau language profile | Processing Contract v2.48; DOC-CONFLICT-0039 cập nhật evidence | Tài liệu |

Reviewer cũng chỉ ra bốn mutation (M02–M05) chỉ bị probe của họ bắt; đã đưa thành
regression thường trực: `test_replacing_the_media_archives_the_exact_previous_file`,
`test_deleting_media_erases_stale_archived_and_current_sources`,
`test_fingerprint_alone_and_version_alone_each_trigger_a_rebuild`.

Bằng chứng implementer sau remediation (không phải kết quả reviewer):

* `AiKnowledgeSyncServiceTest` 29/29 SQLite; Sync/Ingestion/Retrieval/Embedding
  128 passed, 1 skipped trên MariaDB 11.4.12 schema dựng mới.
* Toàn danh sách `integration-mysql` (33 file): 545 passed, 1 skipped, 0 failed trên
  MariaDB 11.4.12 schema dựng mới (database tên `lf_test`, qua guard tiền tố `lf_`).
* Toàn suite mặc định: 1277 passed, 22 skipped, 0 failed.
* 9 mutation trên bản sao riêng đều bị bắt: bỏ lọc finalize, bỏ kiểm owner nháp,
  bỏ `candidate_errors`, nuốt mọi mã, dry-run đẩy cursor, và M02–M05 của reviewer;
  khôi phục khớp SHA-256.

### Lượt 2 — CHANGES REQUIRED, F6

Reviewer xác nhận F1, F2, F3, F5 **CLOSED**; F4 **PARTIALLY CLOSED** vì finding mới:

| Finding | Remediation | Regression |
| --- | --- | --- |
| F6 MEDIUM — `--dry-run` lặp vô hạn khi cả lô owner không resolve được (`continue` bỏ qua bước đẩy con trỏ) | Con trỏ dry-run đẩy theo **cả lô** ngay sau khi lấy, giống lượt thật; owner lỗi vẫn đếm vào `candidate_errors` | `test_dry_run_moves_past_owners_that_fail_to_resolve`, hai data set: lô chỉ gồm owner lỗi (`owner_limit=1`) và lô trộn lỗi/đọc được (`owner_limit=2`); chạy không `--customer` để phủ tenant kế tiếp; khẳng định không ghi source, audit hay cursor; có bộ ngắt để lỗi quay lại thành test đỏ thay vì treo |

Bằng chứng implementer: 31/31 SQLite; mutation đưa con trỏ về hành vi cũ trên bản sao
riêng làm cả hai data set đỏ, khôi phục khớp SHA-256; Sync/Ingestion/Retrieval/Embedding
130 passed, 1 skipped trên MariaDB 11.4.12 schema dựng mới; toàn suite 1279 passed,
0 failed. Danh sách `integration-mysql` 33 file **không** chạy lại sau bản vá F6 (chỉ
đổi nhánh chỉ-đọc của dry-run).

### Lượt 3 — PASS WITH DOCUMENTED RISKS

Reviewer xác nhận F4 và F6 **CLOSED**; không có finding runtime mới. Một finding LOW
không chặn:

| Finding | Remediation | Bằng chứng implementer |
| --- | --- | --- |
| F7 LOW — dataset "lô trộn" thực ra cho `[bad, bad]` rồi `[good]` | Đổi thứ tự fixture thành owner đọc được trước, rồi hai usage của owner ambiguous: lô 2 là `[good, bad]` rồi `[bad, bad]`; test khẳng định trực tiếp owner của lô đầu thay vì suy từ tên dataset | Với thứ tự mới, mutation đưa con trỏ về hành vi cũ làm **cả hai** dataset đỏ (trước đó dataset trộn không nhạy với lỗi này vì owner đọc được vẫn đẩy con trỏ); 31/31 SQLite; khôi phục khớp SHA-256 |

F7 chưa được reviewer chạy lại; nó chỉ đổi test, không đổi code runtime.

Còn lại theo báo cáo, không phải finding: quét xoá chưa giới hạn bộ nhớ theo lô ở
tenant rất lớn; mất cache kéo dài vòng quét; backoff có thể trì hoãn lỗi đã tự hết
khi identity chưa đổi; event class không tự `ShouldDispatchAfterCommit` (đảm bảo
dựa vào đường dispatch sau transaction và listener after-commit).

## Giới hạn và việc còn lại

* Chưa kiểm: Redis worker và scheduler thật; hai tiến trình sync song song thật
  (MariaDB test mô phỏng worker thắng giữa lượt); MariaDB 10.4; tải tenant lớn;
  apply `learnforge_db`; GitHub CI.
* Trước apply `learnforge_db`: review độc lập cả bốn migration AI (Owner phương án
  a, 2026-09-26), brief [LF-AI-Migrations-Pre-Apply-Reviewer-Brief](LF-AI-Migrations-Pre-Apply-Reviewer-Brief.md).
* Modifier xếp hạng retrieval: hoãn tới consumer đầu tiên (Owner 2026-09-26).
* Review độc lập xương sống (bước 3 lộ trình): **PASS WITH DOCUMENTED RISKS** ở lượt 3
  ([báo cáo](LF-AI-Knowledge-Backbone-Independent-Review.md)); Owner chốt đóng
  Source/Chunk ngày 2026-09-26 (§ đầu hồ sơ).
