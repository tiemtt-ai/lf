# AI Knowledge Backbone — Independent Review

Version: 1.0

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-09-26

Document Path: quality/LF-AI-Knowledge-Backbone-Independent-Review.md

Audit Level: HIGH

Final Verdict: **PASS WITH DOCUMENTED RISKS** — Lượt 3

## 1. Kết luận và tính độc lập

Xương sống đã có luồng tự đồng bộ, system principal bị giới hạn, lifecycle hai pha và retrieval tái kiểm quyền. Các sửa đổi VAD khôi phục kỳ vọng đúng; probe xoá cũ xác nhận lỗi F1 trước đây đã được sửa ở luồng thông thường. Tuy nhiên, **chưa đủ điều kiện đóng Source/Chunk**: finalize có thể bỏ đói những source đã đủ điều kiện erase; nguồn nháp không archive khi owner biến mất; lỗi candidate bị nuốt. Ngoài ra còn hai nhóm lệch tài liệu.

Reviewer: Codex, session review độc lập này. Reviewer chỉ từng viết `AI-Packet-Reassessment-2026-09-26.md` và probe của review trước, không viết/sửa implementation hoặc tài liệu canonical của bước 1–3. Đủ tư cách theo § Ràng buộc độc lập của brief. Đã đọc brief trước, áp dụng routing từ docs/README, LF-INDEX, Architecture Guardrails và Regression Audit, sau đó tự kiểm code, contract và tái lập; Implementation Record chỉ dùng để đối chiếu, không dùng số liệu của implementer làm bằng chứng của reviewer.

Snapshot: user chưa thay `<COMMIT>`; áp dụng phương án working tree mà brief cho phép, trên HEAD **`01cce9e37064829778676156cb34ef06fe786e47`**. Đây là review working tree có thay đổi chưa commit, không phải verdict cho riêng HEAD. Đã hash 1.013 file tracked/non-ignored tại đầu lượt; bỏ `.env*` và thư mục probe cũ khỏi bản sao. Các thay đổi mapping/HTTP mapping và whitespace `composer.lock` nằm ngoài phạm vi đánh giá, dù vẫn có trong snapshot dùng để chạy regression.

Không sửa code, test, migration hay tài liệu canonical trong repo. Mutation và probe chỉ ở `/private/tmp/lfbb-review.aems32/`; artifact duy nhất tạo trong repo là báo cáo này. `vendor` được **copy vật lý**, không symlink; Reflection trả `/private/tmp/lfbb-review.aems32/snapshot/app/Services/AiKnowledgeSyncService.php`.

## 2. Findings

### F1 — HIGH — Finalize đầu hàng đợi chặn erase source không có embedding

**Vị trí:** `app/Services/AiKnowledgeSyncService.php:148–160`, đặc biệt `orderBy('id')->limit(...)` ở dòng 151.

`finalizeDeletions()` luôn chọn lại những ID nhỏ nhất còn `deletion_pending`. Source bị embedding barrier giữ lại vẫn chiếm slot ở mọi lượt. Khi đủ một batch bị giữ, các source phía sau không được gọi finalize, dù chúng không có embedding hoặc embedding đã xoá hết. Event không phải bảo đảm giao nhận; vì vậy fast path theo Media File không cứu được trường hợp lệnh đối soát phải phục hồi event bị mất.

**Tái lập độc lập, cùng kết quả SQLite và MariaDB 11.4:** tạo hai source theo ID tăng dần; source đầu có embedding `pending` thuộc model run `running`, source sau không có embedding. Đặt `ai.knowledge_sync.deletion_limit=1`, tombstone cả hai Media, gọi `reconcileTenant()` bốn lần. Mỗi lượt `held_by_embedding_barrier=1`, `deleted=0`. Cả hai source vẫn `deletion_pending`; source sau vẫn giữ **2 chunk có content**. Assertion mong source sau `deleted` thất bại. Với mặc định 500, cùng hiện tượng xảy ra nếu 500 source đầu bị giữ; limit 1 là phép thu nhỏ hợp lệ của đúng thuật toán.

Probe: `test_finalize_must_not_starve_an_unrelated_source_without_embeddings`. Đây là lỗi tính tiến triển của xoá, **không** phải đề xuất vượt qua barrier. Đề nghị chọn batch công bằng hoặc lọc source có thể finalize trước khi áp limit, đồng thời giữ kiểm barrier dưới transaction/lock. Thêm regression với source bị giữ và source đủ điều kiện ở phía sau. Reviewer không vá.

### F2 — MEDIUM — Activity nháp biến mất nhưng source vẫn active

**Vị trí:** `app/Services/MediaReadService.php:115–130`, nhánh `ownerType === 'course_activity'` ở dòng 130. Đường tạo trạng thái thực tế: `app/Services/CourseTemplateVersionDuplicatingService.php:76` gọi routine xoá draft ở `:576–613`, xoá activity ở `:590–593` mà không gỡ generic Media usage.

A3 yêu cầu archive khi activity biến mất. `knowledgeOwnerHoldsMedia()` chỉ kiểm usage/file đối với `course_activity`; không kiểm owner còn tồn tại cùng tenant. Source do lệnh chuẩn bị tay tạo trước khi draft bị thay thế có thể tồn tại mãi ở `active`, cùng chunks/embedding, trong khi owner đã mất.

**Tái lập độc lập trên cả hai DB:** tạo activity nháp thật, usage active và transcript; gọi `ingestMedia()` bằng admin hợp lệ; gọi chính routine `deleteDraftContent()` hiện hữu qua Reflection. Xác nhận activity đã mất nhưng usage còn active, rồi reconcile. Kết quả `archived=0`, source vẫn `active`; assertion `archived` thất bại. Probe gọi routine nội bộ, không tuyên bố đã chạy toàn HTTP flow duplicate/restore.

Probe: `test_removed_draft_activity_must_archive_its_hand_prepared_source`. Fixture hiện có trong test draft của sync dùng owner giả `515151`, nên không chứng minh được owner existence. Retrieval hiện vẫn từ chối owner đã mất qua authorizer, nên chưa thấy mở quyền đọc; lỗi ở lifecycle/retention và trạng thái dữ liệu chuẩn bị. Đề nghị Media-owned eligibility port kiểm sự tồn tại và tenant của working activity, đồng thời regression cho owner bị xoá nhưng usage còn sót. Không tự ingest draft.

### F3 — MEDIUM — Candidate lỗi bị bỏ qua, đối soát báo lượt thành công rỗng

**Vị trí:** `app/Services/MediaReadService.php:172–177`; phía gọi `app/Services/AiKnowledgeSyncService.php:207–218` chỉ thấy exception thoát ra ngoài.

`catch (MediaReadException) { continue; }` bỏ mọi loại lỗi mà không trả outcome hoặc log. Vì listing metadata không audit, lỗi không xuất hiện ở audit, bộ đếm `failed`, backoff hay log của sync. Operator không phân biệt được “không có revision phù hợp” với “ready candidate hỏng, bị từ chối đọc”.

**Tái lập trên cả hai DB:** một transcript `ready` có locale `invalid_locale` — dữ liệu lỗi nhưng SQL schema cho phép. Gọi `readForKnowledgeSync()` trực tiếp trả `locale_unavailable`; gọi reconciliation với cùng dữ liệu trả toàn bộ counters bằng 0, không tạo source và `failed=0`. Assertion yêu cầu ghi nhận lỗi thất bại. Đây là probe dữ liệu malformed để kiểm observability, không khẳng định pipeline Media bình thường sinh locale này.

Probe: `test_metadata_candidate_errors_must_be_visible_to_reconciliation`. Đề nghị phân biệt lỗi vắng mặt/race dự kiến với lỗi candidate cần chẩn đoán; chuyển outcome hoặc log mã ổn định và identifiers, không text/message exception. Không đổi D4 thành audit mọi metadata read.

### F4 — LOW — Sync Contract còn mô tả trạng thái cũ và CLI chưa tồn tại

**Vị trí:** `docs/platform/LF-AI-Knowledge-Sync-Contract.md:76–84`, `:195`, `:199–202`; đối chiếu `app/Console/Commands/AiKnowledgeSync.php:19` và cursor ở `app/Services/AiKnowledgeSyncService.php:169–178,197–203`.

Bảng “Hiện trạng đã kiểm trong code” vẫn nói không có revision event, caller xoá, lịch purge hay archive, mâu thuẫn phần triển khai ngay phía trên và code đã chạy. Trigger table quảng bá `--dry-run`; chạy `php artisan ai:knowledge-sync --dry-run` thực tế trả **`The "--dry-run" option does not exist.`** Ngoài ra phát biểu độ trễ tối đa bằng một chu kỳ không đúng khi tenant cần nhiều batch hoặc gặp backoff.

Đề nghị đánh dấu rõ bảng trước triển khai là lịch sử, thống nhất contract CLI với phạm vi đã duyệt, diễn đạt độ trễ theo vòng quét và lỗi/retry. Đây không phải yêu cầu reviewer tự thêm dry-run hay đổi policy.

### F5 — LOW — Bảng nén processing_version bỏ sót OCR sau language profile

**Vị trí:** `docs/platform/LF-Media-Processing-Contract.md:74–89`; đối chiếu `app/Services/MediaProcessingOrchestrator.php:543–557`.

Guard dùng prefix `document-` áp dụng cho **cả `ocr` và `structured_extraction`**, sau khi thêm `+lp-…`; bảng contract chỉ gán nhánh này cho structured extraction. Vì vậy chưa thể trả lời “bảng khớp đủ năm guard” là đạt hoàn toàn.

**Tái lập không DB/provider:** cấu hình OCR `local_document`, base version bằng 80 ký tự `x`, locale `vi`. Chuỗi sau `+document-v2` và `+lp-…` dài 108; `versionFor('ocr', …)` trả `document-6db4fcc9f331b71b46fb99165b7943473f748fd2930e96427839d39fa4550496`, khớp hash độc lập. Đây là OCR nhưng dùng `document-`, không phải nhánh duy nhất `document-v2-` mà bảng mô tả cho OCR. Nén thực hiện tại từng bước ghép; nên ghi rõ điều này khi có nhiều thành phần nối tiếp.

Đề nghị sửa mô tả cho khớp runtime đã được duyệt, không đổi algorithm hoặc identity lịch sử. DOC-CONFLICT-0039 vẫn phân loại GAP đúng, nhưng evidence đóng còn thiếu trường hợp này.

## 3. Trả lời A–G

### A. Revision identity

1. **Các sửa test là khôi phục đúng amendment VAD, không nới timing/citation invariant.** Helper video ghép base + hash VAD + profile ffmpeg; Audio sửa cả version hiện hành, version archived được pin và version dùng để kiểm fingerprint. Mutation bỏ VAD trong runtime làm đúng 7 test đỏ, khôi phục hash rồi chạy lại snapshot sạch.
2. Test `test_a_new_processing_version_archives_the_previous_audio_revision` đọc được bản archived với version có VAD trước khi truyền fingerprint sai. Vì vậy assertion `revision_mismatch` nay kiểm revision tồn tại, không còn thất bại vì selector trỏ version không có.
3. Assertion Video so toàn identity + quy tắc nén chặt hơn tìm `+ffmpeg-`/`+stt-`, và chạy được trên video thật tổng hợp. Năm guard độ dài nằm tại `versionFor` dòng 545,555,576,587,672; contract thiếu phạm vi OCR ở guard thứ hai (**F5**). Frame OCR/structured identity hash vô điều kiện là quy tắc riêng, không tính là guard vượt 100.

### B. System principal

1. `readResolved` là private. Tìm toàn repo xác nhận chỉ hai chỗ truyền actor NULL: metadata listing và `readForKnowledgeSync`; `read/currentRevision` vẫn yêu cầu `int actorId`. Hai helper A1 còn lại chỉ trả eligibility/list owner. Không thấy route/controller HTTP gọi system reader; command duy nhất orchestration là `ai:knowledge-sync` trong scope đã duyệt.
2. Consumer cố định `ai_knowledge_sync`, owner version, `published|deprecated`, tenant joins và active usage được kiểm; content allowlist không có `variant/caption_asset`, không có tham số pin revision cũ hoặc xin crop/URL ở system entrypoint. Code và suite kiểm draft, detached, deleted, cross-tenant và actor không được phân công. Helper archive cho draft thiếu existence check là **F2**, không phải đường đọc null-actor cho draft.
3. Có che lỗi cần thấy: **F3**. Việc metadata không audit đúng D4; việc mất mọi diagnostic không được chứng minh là an toàn bằng đó.
4. Media eligibility dùng tenant-aware joins với Course, cùng kiểu resolve owner như `CourseMediaOwnerContextAuthorizer`. A1/D3 đã phê duyệt boundary này. AI sync không đọc trực tiếp Media/Course tables; dùng Media Read, `MediaService::deletedMediaFileIds()` và port Course title. Query `saas_customers` ở command/listener để điều phối tenant không phải đọc nguồn Course/Media.
5. `media_access_logs.user_id` nullable đúng schema doc (`docs/database/media/media_access_logs.md:86,107`) và test MariaDB đã ghi NULL thật. Audit có tenant, Media, consumer, owner, usage/content type, thời điểm, decision/error. Sync dùng current selector nên version/fingerprint trong audit selector có thể NULL; revision đầy đủ nằm ở Source/Chunk. Chưa tuyên bố audit là ledger revision độc lập chỉ từ một dòng log.

### C. Event A2

1. Dispatch ở `ProcessMediaProcessingJob.php:131–142`, sau transaction persist và ngoài catch purge asset (`:115–127`). Job và hai listener đều after-commit trong đường queue hiện hành. Probe inject lỗi listener dispatch vẫn để job/transcript `ready`; lỗi không chạy vào purge catch. Event class tự nó không implement `ShouldDispatchAfterCommit`; bảo đảm hiện tại dựa vào execution path của job/listener, không phải mọi caller tuỳ ý dispatch event bên trong transaction đều được trì hoãn.
2. Danh sách `ocr`, `structured_extraction`, `speech_to_text`, `frame_ocr`, `caption` khớp A2. Test A2 chạy trên cả DB xác nhận failed STT và virus scan không phát, revision ready phát một lần. Không chạy provider thật riêng cho từng OCR/frame branch của event; các nhánh còn lại được đối chiếu code và regression hiện có.

### D. Thuật toán đồng bộ

1. Thứ tự delete request → purge → finalize → archive → ingest đúng. Probe tenant `suspended` xác nhận vẫn erase/archive, không ingest Media mới. `reconcileTenant(bool)` nhận cờ từ caller; command và ready listener thực hiện check tenant active.
2. Suite lặp/idempotent và MariaDB constraints đạt. Reviewer còn chạy **hai tiến trình PHP thật**, đồng bộ tại logical-key gap read: một tiến trình `ingested=1`, một tiến trình `failed=1` với mã `registration_conflict`; database chỉ có 1 source active generation 1 và 2 chunks active. Rerun tiến trình thua: `unchanged=1`, `failed=0`. Chứng minh không trùng và hội tụ cho race này; chưa chứng minh mọi deadlock được retry trong cùng lượt. Không gọi đây là soak/Redis-worker test.
3. Probe `owner_limit=1` đi qua 3 owners và archive hết sau các lượt tuần tự. Cache bền và hữu hạn owners thì cursor quay vòng; mất cache quét lại từ đầu. Mất cache lặp lại hoặc lượng owner mới tăng không ngừng có thể làm tail/old owners chậm không giới hạn; không có cam kết tối đa 10 phút. Delete request vẫn load toàn bộ distinct Media IDs/source IDs trước khi chia batch, nên tải bộ nhớ/thời gian theo kích thước tenant chưa được load-test. Finalize có lỗi starvation xác nhận ở **F1**.
4. Các lỗi invalid/mixed/provenance/non-deterministic phù hợp backoff theo revision. `ambiguous_source`, `language_profile_unavailable`, `structure_unavailable` có thể hết khi usage/provenance thay đổi mà fingerprint/version không đổi; backoff khi đó chỉ là trì hoãn, không phải “vĩnh viễn” tuyệt đối. Revision fingerprint hoặc version đổi làm khoá mới; existing test và probe riêng từng thành phần đạt. Khoá không chứa Media ID, nên thay file cùng identity có thể giữ backoff cũ; rủi ro chậm tối đa theo cấu hình, chưa có test riêng cho tình huống này. Lỗi bị nuốt ở listing không tới cơ chế backoff (**F3**).
5. Các log mới của sync/command/dispatch chỉ chứa identifiers, mã lỗi ổn định hoặc class exception; không log text Media, title hay message exception. Không tuyên bố đây là audit toàn bộ logging của backend ngoài scope.

### E. Lifecycle và xoá

1. `archiveSource()` khoá source; `pending|active|failed|stale → archived`; embeddings `pending → deletion_pending`, `ready|failed → stale`. Mutation đổi pending thành stale bị test bắt. MariaDB chạy thành công dưới CHECK/FK thật.
2. `archived` không được reuse; reattach tạo generation tiếp theo. Existing test kiểm bản cũ vẫn archived, bản mới generation 2. Mutation archive sai trạng thái bị bắt.
3. Tập xoá gồm pending/active/failed/stale/archived, không lọc riêng source type; schema Media hiện chỉ cho owner working/version. Probe riêng xác nhận erase cả archived + stale + current, suite xác nhận nguồn nháp tạo tay. Store lỗi giữ content, retry ACK mới erase; mutation bỏ delete barrier bị bắt. Tuy nhiên eventual erase toàn hàng đợi **chưa đạt vì F1**.
4. Probe cũ dùng `MediaService::deleteMedia()` thật trên fixture synthetic: reading_order đạt; assertion content còn non-NULL thất bại chính vì content là NULL. Chỉ đổi assertion này thành `assertNull` trên bản sao thì cả hai probe đạt, bao gồm Media deleted, retrieval rỗng và source deletion lifecycle. Hash bản probe tạm được khôi phục; artifact cũ trong repo không đổi.

### F. Retrieval và tài liệu

1. DTO có `media_file_id`, fingerprint/version, locator/part, `reading_order`, `source_text_quality` (`AiKnowledgeRetrievalService.php:174–188`). NULL reading_order/quality được giữ; không mặc định NULL thành 0/normal. Suite và probe reading_order=17 đạt; mutation bỏ key bị bắt.
2. Tìm `AiKnowledgeRetrievalService`, `knowledgeRetriev*`, `->retrieve(` trong `app/routes/config` không thấy consumer sản phẩm. Docblock, LF-AI và record đều ghi Owner hoãn modifier/context expansion tới consumer đầu tiên; không nâng việc hoãn thành exempt vĩnh viễn.
3. README AI, routing INDEX, ADR-0006/0017 và LF-AI đã phản ánh backend hiện có, Partial khi còn activation/UI. `docs:lint` kiểm manifest đạt; không xem lint là kiểm semantic. Còn lệch ở **F4/F5**. DOC-CONFLICT-0038 là STALE, 0039 là GAP đúng phân loại; không có lý do mở lại 0012/0036, vốn đúng ở thời điểm đóng.

### G. Điều kiện đóng Source/Chunk

| Điều kiện | Đánh giá độc lập |
| --- | --- |
| Docs/schema/migration/implementation không drift | Chưa đạt hoàn toàn: F4/F5. Docs-only drift và reconstruction MariaDB là bằng chứng riêng; không thay review migration/apply gate ngoài scope. |
| Ingestion/rebuild idempotent | Đã kiểm suite, từng thành phần identity và race hai tiến trình; race thua cần lượt tiếp theo, không có duplicate trong probe. |
| Không trộn revision/tenant | Guard đọc/đăng ký/retrieval kiểm được; mutation tenant và mixed revision bị bắt. Phạm vi corpus/owner orphan còn F2. |
| Delete barrier và eventual erase | Barrier được chứng minh, fail-store giữ content, ACK mới xoá; tính tiến triển của batch còn F1. |
| Retrieval tái kiểm quyền | Đạt trong phạm vi suite: relational state + exact Media revision + owner authorization; không dùng system principal để phục vụ người đọc. |
| Mutation bảo vệ các bất biến | Kết quả chi tiết bên dưới. Phải giữ phân biệt guard redundant với test gap; reviewer không thêm regression vào repo. |
| Quan sát lỗi sync | Chưa đạt: F3 che lỗi candidate. |

**Điều kiện đóng còn thiếu:** implementer xử lý F1–F3 và thống nhất F4/F5, bổ sung regression cho các tình huống đã tái lập, rồi reviewer độc lập chạy lại vùng ảnh hưởng trên snapshot xác định. UI đề xuất, provider activation, frontend tutor, ranking modifier đã hoãn và review migration không bị kéo vào gate này.

## 4. Môi trường, lệnh và kết quả tự tái lập

Mọi đường dẫn dưới đây là của reviewer, không phải output implementer. Thư mục bằng chứng: `/private/tmp/lfbb-review.aems32/`. Bản sao code: `snapshot/`; probe riêng: `probes/`. Không copy `.env` thật. `.env` rỗng chỉ để Laravel test bootstrap không phát warning; APP_KEY là chuỗi dummy đã có trong workflow CI, không phải secret mới.

MariaDB binary `/usr/local/opt/mariadb@11.4/bin/mariadbd`; kiểm server trước suite trả:

```text
VERSION()                 @@datadir                                    @@explicit_defaults_for_timestamp  @@skip_networking
11.4.12-MariaDB            /private/tmp/lfbb-review.aems32/dbdata/       1                                  1
```

Server chỉ Unix socket `/private/tmp/lfbb-review.aems32/s.sock`, log xác nhận `port: 0`. Database ban đầu là `lfbb_review`; hai test có guard prefix được chạy lại trên database tạm riêng `lf_bb_review`. Không kết nối `learnforge_db`, không gọi cổng 3306. Khởi tạo và chạy bằng `--no-defaults`, `--skip-networking`, `--explicit-defaults-for-timestamp=ON`; `innodb_flush_log_at_trx_commit=2` chỉ trên instance dùng một lần.

`run.sh` là wrapper cho `php artisan`, đặt `APP_ENV=testing`, `DB_URL=''`, engine SQLite `:memory:` hoặc MySQL/socket/database nêu trên, user root/password rỗng của instance tạm. HTTP/HTTPS/ALL_PROXY trỏ `127.0.0.1:9`; `HF_HUB_OFFLINE=1`, `TRANSFORMERS_OFFLINE=1`, tắt HF telemetry. Không gọi provider bên ngoài, không thêm credential, không gửi source ra mạng. Các test Audio/Video bắt buộc dùng fixture `say`/`ffmpeg` tổng hợp và engine local; runtime/model local STT/Docling được chỉ đường dẫn tuyệt đối về `runtime/…` đã có, script ứng dụng vẫn lấy từ bản sao. Các lượt cần macOS local process hoặc Unix socket chạy ngoài sandbox với cùng cấu hình cô lập.

Đặt:

```bash
R=/private/tmp/lfbb-review.aems32
CORE=(tests/Feature/AiKnowledgeSyncServiceTest.php tests/Integration/AiKnowledgeSyncMariaDbTest.php tests/Feature/AiKnowledgeIngestionServiceTest.php tests/Feature/AiKnowledgeRetrievalServiceTest.php tests/Feature/AiEmbeddingServiceTest.php)
MEDIA=(tests/Feature/MediaRevisionLifecycleTest.php tests/Feature/AudioProcessingLocalReviewTest.php tests/Feature/VideoTranscriptCaptionLocalReviewTest.php)
```

Các lệnh test chạy tuần tự, luôn thêm `--log-junit "$R/<tên>.xml"` và redirect log tương ứng. Lượt 33 file lấy đúng danh sách `tests/…php` trong job `integration-mysql` của `.github/workflows/application-tests.yml` (33 file), sau khi **DROP/CREATE riêng `lfbb_review` trên socket tạm**; không tái dùng dữ liệu/schema của lượt trước. Probe hai tiến trình là một thí nghiệm concurrency riêng, không phải chạy song song các suite.


| Lượt | Lệnh thực chạy (rút gọn phần redirect/JUnit) | Passed | Failed/Error | Skipped | JUnit |
| --- | --- | ---: | ---: | ---: | --- |
| SQLite Knowledge | `zsh "$R/run.sh" sqlite test "${CORE[@]}"` | 115 | 0 | 7 | `sqlite-core.xml` |
| SQLite Media | `zsh "$R/run.sh" sqlite test "${MEDIA[@]}"` | 42 | 0 | 0 | `sqlite-media.xml` |
| MariaDB Knowledge + Media | `zsh "$R/run.sh" maria test "${CORE[@]}" "${MEDIA[@]}"` | 163 | 0 | 1 | `maria-core-media.xml` |
| SQLite probe độc lập | `zsh "$R/run.sh" sqlite test "$R/probes/BackboneIndependentProbeTest.php"` | 5 | 3 | 0 | `sqlite-probes.xml` |
| MariaDB probe độc lập | `zsh "$R/run.sh" maria test "$R/probes/BackboneMariaProbeTest.php"` | 5 | 3 | 0 | `maria-probes.xml` |
| Dispatch failure | `zsh "$R/run.sh" sqlite test "$R/probes/DispatchFailureProbeTest.php" --filter test_reviewer_dispatch_failure` | 1 | 0 | 0 | `dispatch-probe.xml` |
| Probe reviewer cũ, kỳ vọng cũ | `zsh "$R/run.sh" sqlite test "$R/probes/ReadingOrderContractProbeTest.php" --filter test_review_probe` | 1 | 1 | 0 | `legacy-old.xml` |
| Probe cũ, đổi riêng kỳ vọng content NULL trên bản sao rồi restore | `Lệnh trên với assertNull; SHA ở legacy-hashes.json` | 2 | 0 | 0 | `legacy-fixed-expectation.xml` |
| Chuẩn bị concurrency | `zsh "$R/run.sh" maria test "$R/probes/PrepareConcurrencyProbeTest.php"` | 1 | 0 | 0 | `concurrent-prepare.xml` |
| 33 file integration, schema mới | `zsh "$R/run.sh" maria test <33 paths từ integration-files.json>` | 536 | 2 | 1 | `maria-integration.xml` |
| Hai test guard prefix, database mới lf_bb_review | `zsh "$R/run-retry.sh" maria test tests/Feature/AiProviderExecutionGateTest.php tests/Integration/SaasUsageQuotaPacketMariaDbTest.php --filter <hai tên test đỏ>` | 2 | 0 | 0 | `maria-retry.xml` |
| Toàn suite mặc định | `zsh "$R/run.sh" sqlite test` | 1270 | 0 | 22 | `sqlite-full.xml` |

Các lượt thử probe ban đầu có lỗi fixture reviewer (trùng idempotency key/locator khi cố tạo cùng version trên cùng Media). Chỉ sửa fixture riêng trong `/tmp` để thay đổi riêng một thành phần identity; **không** ghi các lỗi fixture đó thành finding sản phẩm. Kết quả probe cuối trong bảng đã bỏ lỗi setup, còn đúng F1–F3. Lượt bootstrap đầu thiếu `.env` tạo warning; tạo file rỗng rồi chạy lại trước khi lấy số liệu cuối.

| Lệnh khác | Kết quả tự kiểm |
| --- | --- |
| `php artisan docs:lint` | PASS; 94 mục legacy metadata trong allowlist có sẵn, không phải finding mới |
| `php artisan schema:drift --docs-only` | PASS; 100 migration files, mode docs-only, không kết nối database |
| `php artisan ai:knowledge-sync --dry-run` trên copy/SQLite | Option không tồn tại, chứng minh F4 |
| `php "$R/compaction.php"` | OCR+LP dài 108, prefix `document-`, hash khớp phép tính độc lập, chứng minh F5 |
| Hai `zsh "$R/concurrent.sh" maria A/B` đồng thời, rồi rerun A | Không duplicate: 1 source generation 1, 2 chunks; loser registration_conflict; rerun unchanged=1 |
| Reflection App class; kiểm symlink vendor | Class ở snapshot, vendor là bản copy vật lý |
| Hash snapshot và restore mutation | Xem § Snapshot cuối |

Danh sách 33 file và lệnh đầy đủ được giữ tại `/private/tmp/lfbb-review.aems32/integration-files.json` và `integration.sh`. Filter chạy lại nguyên văn: `test_mariadb_revalidation_refund_and_execution_claim_serialize_on_the_run|test_two_connections_serialize_capacity_on_the_entitlement_lock`.

### Đối chiếu tên test, không suy từ tổng số

Lượt 33 file đầu có hai lỗi setup của reviewer: tên `lfbb_review` không qua guard `starts_with(lf_)` ở AiProviderExecutionGateTest và child quota_store_worker. Guard được giữ nguyên. Reviewer tạo database `lf_bb_review` rồi chạy lại đúng hai test, không sửa code/test và không chạy lại toàn bộ 33 file. Vì vậy không ghi lượt 33-file ban đầu là xanh, không lấy số của implementer thay thế kết quả này. Kết quả rerun được tách riêng bên dưới.

**sqlite-probes: 5 passed, 3 failed/error, 0 skipped.**

- `BackboneIndependentProbeTest::test_finalize_must_not_starve_an_unrelated_source_without_embeddings`
- `BackboneIndependentProbeTest::test_metadata_candidate_errors_must_be_visible_to_reconciliation`
- `BackboneIndependentProbeTest::test_removed_draft_activity_must_archive_its_hand_prepared_source`

**maria-probes: 5 passed, 3 failed/error, 0 skipped.**

- `BackboneMariaProbeTest::test_finalize_must_not_starve_an_unrelated_source_without_embeddings`
- `BackboneMariaProbeTest::test_metadata_candidate_errors_must_be_visible_to_reconciliation`
- `BackboneMariaProbeTest::test_removed_draft_activity_must_archive_its_hand_prepared_source`

**maria-integration: 536 passed, 2 failed/error, 1 skipped.**

- `Tests\Feature\AiProviderExecutionGateTest::test_mariadb_revalidation_refund_and_execution_claim_serialize_on_the_run`
- `Tests\Integration\SaasUsageQuotaPacketMariaDbTest::test_two_connections_serialize_capacity_on_the_entitlement_lock`

**maria-retry: 2 passed, 0 failed/error, 0 skipped.**
Không có tên test đỏ trong JUnit của lượt này.

**sqlite-full: 1270 passed, 0 failed/error, 22 skipped.**
Không có tên test đỏ trong JUnit của lượt này.

### Test bị skip trong các lượt bắt buộc

Skip không được tính là PASS. Ba suite Media đều chạy đủ trên cả hai DB, không skip. SQLite skip các physical-MariaDB checks theo thiết kế; MariaDB skip real Qdrant vì không cấu hình service, phù hợp cấm kết nối/provider thật. Hai smoke test MediaProcessingSubstrate trong suite mặc định cần LF_REAL_AUDIO_FIXTURE/LF_REAL_VIDEO_FIXTURE riêng nên skip; không tính chúng là PASS, dù bộ Audio/Video local chuyên biệt đã tự tổng hợp fixture và chạy thật. Dưới đây là tên chính xác từ JUnit của SQLite core, toàn suite mặc định và integration:

- `Tests\Integration\AiKnowledgeSyncMariaDbTest::test_create_stale_archive_and_delete_hold_under_the_physical_constraints` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Integration\AiKnowledgeSyncMariaDbTest::test_a_revision_registered_by_another_worker_mid_pass_is_reused_not_duplicated` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Integration\AiKnowledgeSyncMariaDbTest::test_archive_takes_a_real_row_lock_on_the_source` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\AiKnowledgeIngestionServiceTest::test_delete_barrier_uses_a_real_for_update_lock_on_mariadb` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\AiEmbeddingServiceTest::test_generation_constraints_are_physically_enforced_on_mariadb` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\AiEmbeddingServiceTest::test_real_qdrant_maintenance_passes_a_broken_collection_and_recovers_it` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\AiEmbeddingServiceTest::test_mysql_all_ready_pass_uses_one_candidate_query_without_identity_batch_queries` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\AiProviderExecutionGateTest::test_mariadb_revalidation_refund_and_execution_claim_serialize_on_the_run` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "video usage (F4 scope)"` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "frame text content (F4 scope)"` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "page locator (F3: Media regions are region)"` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "page zero"` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "failed status (F5 removed)"` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "unknown owner type"` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "partial bbox"` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "bbox outside the frame"` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "ready without text"` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "deleted still holding text"` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "deleted without completion time"` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "deletion pending without request time"` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_a_row_cannot_cite_another_tenants_media_file` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_a_row_cannot_reference_another_tenants_model_run` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\CourseTemplatePublishConcurrencyTest::test_template_lock_serializes_same_template_but_not_another_template` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\MediaProcessingSubstrateTest::test_real_local_audio_provider_persists_timespans_and_media_read_returns_them` — Skipped theo điều kiện môi trường/driver trong test.
- `Tests\Feature\MediaProcessingSubstrateTest::test_real_local_video_pipeline_persists_transcript_caption_and_media_read_outputs` — Skipped theo điều kiện môi trường/driver trong test.

## 5. Mutation và độ nhạy regression

Mỗi mutation thay đúng một vị trí trên bản sao, chạy suite tuần tự, khôi phục trong `finally`, đối chiếu SHA-256. M01–M11 dùng bốn suite SQLite Sync/Ingestion/Retrieval/Embedding (không tính test MariaDB bị skip); M12 dùng ba suite Media local. Không suy mutation sống sót trong tập này thành “toàn repo không có test”.

| ID | Thay đổi | Test hiện có trong lượt mutation | Probe reviewer đối chứng |
| --- | --- | --- | --- |
| M01 | Bỏ withReadyDocumentJob ở listing | Sống sót | Redundant: readResolved vẫn lọc ready document job trước khi trả metadata |
| M02 | Bỏ exact media_file_id ở owner-holds | Sống sót | Bị bắt: `test_replacing_media_archives_the_exact_previous_file` |
| M03 | Bỏ stale/archived khỏi delete scan | Sống sót | Bị bắt: `test_media_delete_erases_archived_stale_and_current_sources` |
| M04 | Bỏ so fingerprint ở alreadyActive | Sống sót | Bị bắt: `test_revision_fingerprint_alone_and_version_alone_trigger_rebuild` |
| M05 | Bỏ so processing_version ở alreadyActive | Sống sót | Bị bắt: `test_revision_fingerprint_alone_and_version_alone_trigger_rebuild` |
| M06 | Archive pending embedding thành stale | Bị bắt: 1 test đỏ | Không cần chạy lại bằng probe mới |
| M07 | Bỏ reading_order DTO | Bị bắt: 1 test đỏ | Không cần chạy lại bằng probe mới |
| M08 | Bỏ tenant filter owner listing | Bị bắt: 1 test đỏ | Không cần chạy lại bằng probe mới |
| M09 | Bỏ embedding delete barrier | Bị bắt: 3 test đỏ | Không cần chạy lại bằng probe mới |
| M10 | Bỏ mixed_revision rejection | Bị bắt: 1 test đỏ | Không cần chạy lại bằng probe mới |
| M11 | Archive chunk thành active | Bị bắt: 1 test đỏ | Không cần chạy lại bằng probe mới |
| M12 | Bỏ VAD identity | Bị bắt: 7 test đỏ | Không cần chạy lại bằng probe mới |

Kết quả: **7/12** mutation bị tập test hiện có đang chạy bắt; **4 mutation bổ sung** bị probe reviewer bắt; 1 mutation không đổi outcome nhờ guard thứ hai. M03 cũng nằm trong assertion lifecycle của `AiKnowledgeSyncMariaDbTest`, nhưng reviewer không chạy riêng mutation này trên MariaDB nên không ghi là đã tự kiểm mutation MariaDB. Nên đưa các probe đúng-file, riêng fingerprint/version và historical delete vào regression thường trực; reviewer không sửa test repo.

Tên test đỏ khi bỏ VAD (đối chiếu JUnit, không suy từ tổng số):

- `test_a_new_processing_version_archives_the_previous_ready_revision`
- `test_a_new_transcript_revision_archives_the_caption_built_on_the_old_one`
- `test_a_caption_callback_landing_after_a_new_transcript_revision_is_rejected`
- `test_a_version_bump_does_not_archive_a_coexisting_locale`
- `test_the_archived_revision_stays_readable_by_explicit_version`
- `test_a_new_processing_version_archives_the_previous_audio_revision`
- `test_real_video_pipeline_from_course_usage_to_transcript_caption_and_read`

### SHA-256 mutation

SHA trước khớp snapshot; SHA sau khôi phục **bằng SHA trước cho mọi lượt**, kể cả bốn lượt probe follow-up. Bảng sau ghi đủ hash mutated; hash file nguyên bản nằm ở § Snapshot cuối.

| ID | File | SHA-256 mutated | Restored |
| --- | --- | --- | --- |
| M01_ready_document_listing | `app/Services/MediaReadService.php` | `41fdd595d72604f70b65952c531176138fed3533fdcafffad41af7b503ffba07` | `3ac17ecfeeb1cc17b1f14b6ff112f550b8baea9d88511a3088b3495f023954ed` |
| M02_exact_media_owner | `app/Services/MediaReadService.php` | `5d9df2202794d5b8333456106c35556817c5ba433f0b70ba4d62d6413274853c` | `3ac17ecfeeb1cc17b1f14b6ff112f550b8baea9d88511a3088b3495f023954ed` |
| M03_historical_delete | `app/Services/AiKnowledgeSyncService.php` | `89f097cadd88e9e9d5bbf51c2f7033e9b5ad74f4c623a3359f810d71338e0f78` | `6a7e0d226e902de65d3e19cd0f282de1e43098822f6bfcb69cce614c668063b0` |
| M04_active_fingerprint | `app/Services/AiKnowledgeSyncService.php` | `3126c54183ccd0fb2dabc6a4740acc38a48411775ae3bf7b59c8bed85092b1a7` | `6a7e0d226e902de65d3e19cd0f282de1e43098822f6bfcb69cce614c668063b0` |
| M05_active_version | `app/Services/AiKnowledgeSyncService.php` | `eef27def6b0eacd22d6a78e685894ab0d033fe524ab7bc9676e21dbd49abaa72` | `6a7e0d226e902de65d3e19cd0f282de1e43098822f6bfcb69cce614c668063b0` |
| M06_pending_archive | `app/Services/AiKnowledgeIngestionService.php` | `feca04d6eb2cc2fdbe277a8f8e5d4b292e8e790f3d7032427666e35a1bf96b69` | `e7528330ebf76c4517fce4db979f4ceaa84c1fee5cff9512e168b70a800ef619` |
| M07_reading_order | `app/Services/AiKnowledgeRetrievalService.php` | `bc338c27325edacbf385e0d0be8b3b32b3e16558e3d350d8a8de08265aef74c5` | `3abd8135d7ad581f2bd459fd5b949ad9714ad33eb444903a0eb72b545c2083ad` |
| M08_owner_tenant | `app/Services/MediaReadService.php` | `7659e168866448c585c118f79a5eae80a9d48cec4799b413719d42e61b347d9c` | `3ac17ecfeeb1cc17b1f14b6ff112f550b8baea9d88511a3088b3495f023954ed` |
| M09_delete_barrier | `app/Services/AiKnowledgeIngestionService.php` | `8f6ed2078e7eb0472221e11fe9459c5ab237885480ff500da93ebbd8e2b82bbc` | `e7528330ebf76c4517fce4db979f4ceaa84c1fee5cff9512e168b70a800ef619` |
| M10_mixed_revision | `app/Services/AiKnowledgeIngestionService.php` | `b292ff726072b541695727c5aa5f1740ad48c004af3af2d7bb36231228d498e0` | `e7528330ebf76c4517fce4db979f4ceaa84c1fee5cff9512e168b70a800ef619` |
| M11_archive_terminal | `app/Services/AiKnowledgeIngestionService.php` | `18de9c066e439f9e079e2b931a54f3a5497fafc1a192f63a30deb199fcf38323` | `e7528330ebf76c4517fce4db979f4ceaa84c1fee5cff9512e168b70a800ef619` |
| M12_vad | `app/Services/MediaProcessingOrchestrator.php` | `a5d8321ae0e590046137c60aeff4a94380a38b1e23659282e7d2497afcedec62` | `48a821b20334fe316e76f316e9692cbba18aace46fe561089f3f567882dbac2e` |
| M02_exact_media_owner-probe | `app/Services/MediaReadService.php` | `5d9df2202794d5b8333456106c35556817c5ba433f0b70ba4d62d6413274853c` | `3ac17ecfeeb1cc17b1f14b6ff112f550b8baea9d88511a3088b3495f023954ed` |
| M03_historical_delete-probe | `app/Services/AiKnowledgeSyncService.php` | `89f097cadd88e9e9d5bbf51c2f7033e9b5ad74f4c623a3359f810d71338e0f78` | `6a7e0d226e902de65d3e19cd0f282de1e43098822f6bfcb69cce614c668063b0` |
| M04_active_fingerprint-probe | `app/Services/AiKnowledgeSyncService.php` | `3126c54183ccd0fb2dabc6a4740acc38a48411775ae3bf7b59c8bed85092b1a7` | `6a7e0d226e902de65d3e19cd0f282de1e43098822f6bfcb69cce614c668063b0` |
| M05_active_version-probe | `app/Services/AiKnowledgeSyncService.php` | `eef27def6b0eacd22d6a78e685894ab0d033fe524ab7bc9676e21dbd49abaa72` | `6a7e0d226e902de65d3e19cd0f282de1e43098822f6bfcb69cce614c668063b0` |

## 6. Giới hạn kiểm chứng và trạng thái cleanup

* Chưa chạy Redis worker/scheduler thật, recovery qua queue service thật, tải tenant lớn, soak hoặc nhiều kiểu interleaving. Hai tiến trình PHP chỉ chứng minh race đã mô tả, không thay các mục này.
* Không kiểm MariaDB 10.4, GitHub CI, Qdrant/provider thật, production data hoặc apply `learnforge_db`. Không ký PASS cho các mục này.
* Không re-review DDL Foundation/migration apply, UI đề xuất, activation, frontend tutor hoặc backend Bước 7 ngoài tương tác trong brief. Schema fresh + physical tests không phải đối chiếu toàn bộ `information_schema` từng cột/index với toàn docs.
* Rủi ro còn lại cần vận hành ghi nhận: scan delete không bounded toàn bộ, cache reset kéo dài vòng quét, backoff cho lỗi có thể hết mà identity chưa đổi, event class chưa có bảo đảm after-commit độc lập với execution path. Chưa phát hiện mở quyền retrieval trong các trường hợp đã kiểm.
* PHP 8.3.35; PHPUnit 11.5.55; MariaDB 11.4.12 trên macOS x86_64. Không suy kết quả này thành mọi môi trường deployment.

Lần gọi shutdown đầu bị bộ duyệt quyền tự động quá hạn trước khi thực thi; retry duy nhất được cho phép đã thành công. Không có bước cleanup nào còn bị chặn.

**Cleanup tự xác minh:** mariadb-admin shutdown trả exit 0; server process kết thúc exit 0; server.log ghi Shutdown complete. Đã xoá datadir dbdata (cả lfbb_review và lf_bb_review); socket và PID file không còn. Chỉ giữ log/JUnit/probe/bản sao review trong thư mục /tmp, không còn instance MariaDB chạy.

## 7. Snapshot và tính toàn vẹn cuối lượt

Đối chiếu cuối lượt: **1.013/1.013 file gốc và 1.013/1.013 file bản sao khớp SHA-256 ban đầu**; không có file canonical/code/test bị reviewer sửa. Artifact báo cáo này là file mới duy nhất của lượt review trong repo.

Manifest `snapshot-sha256.json` SHA-256: `06203bf91c10d37c2073b98e167daef13fe7d9829fc276d9a1438f16048fb0a5`.

| File | SHA-256 đầu = cuối |
| --- | --- |
| `app/Services/AiKnowledgeSyncService.php` | `6a7e0d226e902de65d3e19cd0f282de1e43098822f6bfcb69cce614c668063b0` |
| `app/Services/AiKnowledgeIngestionService.php` | `e7528330ebf76c4517fce4db979f4ceaa84c1fee5cff9512e168b70a800ef619` |
| `app/Services/MediaReadService.php` | `3ac17ecfeeb1cc17b1f14b6ff112f550b8baea9d88511a3088b3495f023954ed` |
| `app/Services/AiKnowledgeRetrievalService.php` | `3abd8135d7ad581f2bd459fd5b949ad9714ad33eb444903a0eb72b545c2083ad` |
| `app/Jobs/ProcessMediaProcessingJob.php` | `a3f0091ae8ba03f01cc7a13986f6793ec2380ef56d6fd75d74d9957c6224a0aa` |
| `app/Services/MediaProcessingOrchestrator.php` | `48a821b20334fe316e76f316e9692cbba18aace46fe561089f3f567882dbac2e` |
| `app/Events/MediaRevisionReady.php` | `3c3bbdc9d8bd840829ded16c04954a1008d8e4fdb0ca4116c4f1c81978ea4b4c` |
| `docs/platform/LF-AI-Knowledge-Sync-Contract.md` | `e4d36d9637da977a02106e9c60d75eedfff999f6ec262427661a6ae6fa116017` |

Các bằng chứng bổ sung (SHA-256; nằm dưới thư mục reviewer, không ghi vào canonical docs):

| Artifact | SHA-256 |
| --- | --- |
| `probes/BackboneIndependentProbeTest.php` | `37f303bc6f785f4b48f68c817d2743ae405ee92bcc60686a0a2693a4d777d995` |
| `probes/BackboneMariaProbeTest.php` | `b8e0305595a9c5d804eb72570aa51b24892a9e9483d6020c7c12371a8eb26e74` |
| `probes/DispatchFailureProbeTest.php` | `214f403cb6386f47b70c63d76f5cd74ab62dd0ee5b1a6f1f69036a34b826758a` |
| `probes/PrepareConcurrencyProbeTest.php` | `244912d3d01ce3672d446092f00ef9a23e0db468a182499c54bd263dd8ea84d4` |
| `concurrent.php` | `281862a38a9ca97a8f75a2b654f067baf4b8dfa9e5d3143676d1b2a9fa7f34f3` |
| `compaction.php` | `b11c8f9d3e731019465dbc4576dd2eb2444ce3774f4fcb63af56a7e4e11e1cae` |
| `run.sh` | `ecab114ca9bfdff2a04b5011c25cd48eae68594de961520e545d3444f133bcf4` |
| `mutation-results.json` | `147258b6efcb9815a3cfb911c74f8423b3b31e1bf282752bf1e3c142b1774688` |
| `followup-mutation-results.json` | `4b7329dcd9234d1bc846cba5d3961427f458d8cd9093dda7ea9b089e261b0cfd` |
| `legacy-hashes.json` | `2cd71aca71e57eda7a1117b7d151b5b9811131db2dd2bbc15f7e9a1990d1103e` |
| `sqlite-core.xml` | `be00cdd2efcf18f1340dbc7ef38992ac079d0dbd67509a6533a634226f8952b8` |
| `sqlite-media.xml` | `d96ea9765aeaed36c242393dda1dc9b594fe22ec5216d951b54f4d593cb59ecc` |
| `maria-core-media.xml` | `91b91fed72113df7b6fab3313e52e1194a2f9ff7787599979aabf83337d74b67` |
| `maria-integration.xml` | `274381a80da46faa180646cd5c359279d91392492381b058ba62345b5ba6e495` |
| `sqlite-full.xml` | `3be604497ad3707808d23b333c23f4a7bc9c824b3b39bd12b83a5aca6ebcfc68` |
| `maria-retry.xml` | `2385909a97a65cd87c7987ed7e56840e1ae576fc748f3f949e9417e0f9c1c77e` |
| `run-retry.sh` | `a8466e395774e49968366441b7c1104764f486fe9cf205db2bfd07aeccd7378f` |
| `sqlite-probes.xml` | `57a048355909c5214e06300d58bc240f5904c19fa6cf08833df1e51b288dd364` |
| `maria-probes.xml` | `27780b359c0a24e72bddda3b95e93e637a31ce7be30dbf3e41c7bb5c313c04ff` |
| `integration-files.json` | `aaf21546fe7d9e2652111b39f58206851da30738e20b8ac09669a6f5cfe5845e` |
| `integration.sh` | `7c9cd8e3933de87387fc1905f5273eda1e68534345a56bf126dde7c987c124d1` |
| `cleanup.json` | `ee5203e1076aa2b9a96facd6f9c8c19e95d4523b113ab706f6e30f5dccf20295` |
| `final-hash-check.json` | `cde8a3c97cb3877148055d8a5a9d2ba592002ee5b5cab5b4f971a6087121fe5d` |

**Findings by severity:** BLOCKER 0; HIGH 1; MEDIUM 2; LOW 2. **Final Verdict: CHANGES REQUIRED.**


## Lượt 2 — Re-review

### Kết luận và snapshot

**Verdict lượt 2: CHANGES REQUIRED.** F1, F2, F3 và F5 đã đóng trên các tình huống yêu cầu. F4 **PARTIALLY CLOSED**: CLI và các sửa tài liệu đã có; dry-run không ghi dữ liệu trong probe bình thường, nhưng nhánh bắt lỗi owner làm con trỏ không tiến, gây vòng lặp vô hạn (F6 — MEDIUM). Không thấy HIGH/BLOCKER mới trong phạm vi đã kiểm. Kết quả suite xanh không thay thế probe âm này.

Reviewer vẫn là tác nhân review lượt 1, không viết implementation/remediation. Chỉ sửa chính báo cáo này; không vá finding. `<COMMIT>` chưa được thay bằng commit mới: HEAD vẫn `01cce9e37064829778676156cb34ef06fe786e47`, nên áp dụng quy tắc working-tree snapshot của brief. Bản sao vật lý ở `/private/tmp/lfbb-review2.zr8k8q27/snapshot`; vendor được copy, không symlink. Reflection trả đúng `…/snapshot/app/Services/AiKnowledgeSyncService.php`.

Manifest đầu lượt gồm **1.014 file**, SHA-256 `39cc65028b9baf9e7fd38c1a28d9e4a9f48b409d4117e3f12e4cf6dabce9c83e`. Báo cáo lượt 1 trước khi append có SHA-256 `fbf75ad8f0d0d73788628c1fae4bb63d0f117c7fd6c9c5b335ea895a96d3f241`, đúng bản reviewer đã giao. Đối chiếu snapshot lượt 1 xác nhận chỉ 14 đường dẫn thay đổi/thêm, gồm remediation, đăng ký tài liệu và chính báo cáo; Ingestion, Retrieval, Orchestrator, processing job/event/listener không đổi. Danh sách đầy đủ: `changed-since-round1.json` trong thư mục bằng chứng.

Instance độc lập: MariaDB **11.4.12**, `@@skip_networking=1`, `@@explicit_defaults_for_timestamp=1`, datadir `/private/tmp/lfbb-review2.zr8k8q27/dbdata/`, socket `…/s.sock`, database **`lf_bb_review2`**. Dùng `--no-defaults`; không truy cập `learnforge_db` hay cổng 3306. Không copy `.env`/secret; dùng key fixture công khai, provider fake, các engine Audio/Video cục bộ với model có sẵn và chế độ offline. Không cài/download hay gửi dữ liệu ra mạng.

### Trạng thái finding lượt 1

| Finding | Trạng thái | Bằng chứng của reviewer ở lượt 2 |
| --- | --- | --- |
| F1 — HIGH, finalize starvation | **CLOSED** | Probe cũ trên SQLite/MariaDB: `deletion_limit=1`, source ID đầu có embedding pending + writer running, source sau không embedding. Ngay pass đầu `deleted=1`, `held_by_embedding_barrier=1`; source sau deleted, content NULL; bốn pass vẫn giữ source đầu deletion_pending. Test regression mới xác nhận content source đầu còn. Probe mới chèn embedding **sau query chọn source** rồi invoke finalizer: `deleted=0`, barrier=1, content còn; MariaDB ghi đủ ba SELECT FOR UPDATE (source/chunks/embeddings). RF1 và M09 đều bị bắt. |
| F2 — MEDIUM, draft owner biến mất | **CLOSED** | Chạy lại probe thật `ingestMedia()` → reflection gọi `CourseTemplateVersionDuplicatingService::deleteDraftContent()` → owner không còn, generic usage vẫn active → reconcile `archived=1`, source archived, trên cả hai engine. RF2 bị test mới bắt. Lỗi usage mồ côi thuộc task Course × Media vẫn tồn tại trong fixture tái lập, nhưng không còn giữ Knowledge source active. |
| F3 — MEDIUM, nuốt candidate lỗi | **CLOSED** | `invalid_locale` trên ready transcript → Media Read `locale_unavailable`; reconcile `failed=1` mỗi lần. Probe mới chạy ba pass, hai trong cùng cửa sổ và một sau 61 phút: warning đúng hai lần, chỉ sáu trường ID/type/error_code; không có text hay exception message. Dry-run trả `candidate_errors=1`, không đặt backoff. RF3 bị test mới bắt. Sáu race settling được kiểm thêm bên dưới. |
| F4 — LOW, contract/CLI lệch | **PARTIALLY CLOSED** | Contract v1.1: bảng lịch sử tại `LF-AI-Knowledge-Sync-Contract.md:80`, CLI ở `:203`, độ trễ vòng quét `:208–212` đã sửa đúng. Command có `--dry-run`; probe bắt mọi query và so serialized cache store cho lô nhiều owner (`owner_limit=1`) gồm Media đã xoá, candidate lỗi, revision mới: 29 query, không INSERT/UPDATE/DELETE/DDL, không SELECT text/content của derived Media, không audit, không đổi cursor/backoff. RF4 gọi reconcile thay plan bị regression bắt. Tuy nhiên dry-run không kết thúc với một lô toàn owner lỗi — F6. |
| F5 — LOW, thiếu guard nén OCR+LP | **CLOSED** | Đối chiếu contract v2.48 `:74–94` với `MediaProcessingOrchestrator.php:545,555,576,587,672`: năm guard, bốn dòng bảng do hai guard speech cùng prefix. Năm probe tuple độc lập đều so exact expected/actual. OCR base 80 ký tự + document suffix + LP có chiều dài 108, ra `document-6db4fcc9f331b71b46fb99165b7943473f748fd2930e96427839d39fa4550496`; không còn bỏ sót nhánh này. DOC-CONFLICT-0039 cập nhật đúng v2.48. |

### Finding mới

#### F6 — MEDIUM — Dry-run lặp vô hạn khi toàn bộ lô owner không resolve được

**Vị trí:** `app/Services/AiKnowledgeSyncService.php:129–140`, cụ thể `continue` ở `:132` bỏ qua `$after = $owner['usage_id']` ở `:138`. Điều kiện vòng lặp chỉ kiểm kích thước lô ở `:140`.

**Tình huống:** cấu hình hợp lệ `ai.knowledge_sync.owner_limit=1`; một Version Activity published có hai active audio usages trỏ hai Media files khác nhau. SQL schema cho phép trạng thái này; Media Read chủ động từ chối bằng `ambiguous_source`. `knowledgeSyncOwners(0,1)` trả một usage; `revisionsForKnowledgeSync()` ném; catch tăng candidate_errors rồi continue; `$after` vẫn 0. Lượt tiếp theo lấy đúng usage đó, không bao giờ thoát. Với cấu hình mặc định 200, lỗi tương tự xảy ra khi cả một lô đầy không resolve được. Những owner/tenant phía sau không được báo cáo, query chạy liên tục tới khi process bị dừng.

**Tái lập:** `Round2ProbeTest::test_dry_run_progresses_after_owner_listing_errors` và bản MariaDB `Round2MariaProbeTest` dùng dữ liệu thật, không mock service và không sửa code ứng dụng. Query listener chỉ đếm query listing và ném `RuntimeException` ở lần 4 để chặn vòng lặp có chủ đích. Kết quả cả SQLite/MariaDB: `batches=4`, command exit 1, `Tenant … failed: RuntimeException`, `tenant_errors=1`; assertion command hoàn tất không cần bộ ngắt bị đỏ. Không có listener này, code không có điều kiện tiến/thoát trên nhánh đó. Đây là lỗi kết thúc thuật toán, không phải DB timeout hay provider lỗi.

Mã probe tối thiểu dưới đây đặt trong test class riêng dùng `Tests\TestCase`, `RefreshDatabase`, `Tests\Support\Ai\KnowledgeSyncFixture` và import facades `DB`, `Artisan`; chỉ chạy trong bản sao review. Exception của listener là bộ ngắt, không phải bản vá:

```php
 public function test_dry_run_progresses_after_owner_listing_errors(): void {
  $f=$this->tenant('r2loop'); $this->audioOnVersion($f);
  $m=$this->mediaFile($f,'audio','second'); $this->usage($f,$m,'course_version_activity',$f['version_activity_id'],'audio');
  config(['ai.knowledge_sync.owner_limit'=>1]); $batches=0;
  DB::listen(function($q)use(&$batches){
   if(str_contains($q->sql,'media_file_usages') && str_contains($q->sql,'core_course_template_versions') && str_contains($q->sql,'order by')) {
    if(++$batches>3) throw new RuntimeException('Reviewer stopped repeated identical owner batch');
   }
  });
  $exit=Artisan::call('ai:knowledge-sync',['--customer'=>$f['customer_id'],'--dry-run'=>true]);
  fwrite(STDERR,'LOOP '.json_encode(['batches'=>$batches,'exit'=>$exit,'output'=>Artisan::output()]).PHP_EOL);
  $this->assertSame(0,$exit,'dry-run must terminate without reviewer loop breaker');
  $this->assertLessThanOrEqual(3,$batches);
 }
```

**Phạm vi ảnh hưởng:** dry-run mới thêm; chưa thấy ảnh hưởng tới `reconcileTenant()` vì ingest thật cập nhật cursor theo toàn lô trước vòng foreach. Regression dry-run hiện có chỉ thử owner đọc được/candidate error trả về trong envelope; nó không thử exception ở cấp owner.

**Điều kiện đóng:** bảo đảm con trỏ tiến qua cả owner lỗi và có regression cho lô đầy toàn lỗi, lô trộn lỗi/thành công và tenant tiếp theo. Chạy lại trên SQLite/MariaDB, vẫn giữ các bất biến không ghi/no content/no audit. Reviewer không đề xuất exemption và không vá.

### Đánh giá F3, regression và A–G

**A — Revision identity.** Orchestrator và ba file Media tests không thay đổi so với snapshot đã review lượt 1; chạy lại cả SQLite/MariaDB, bao gồm Audio/Video thật. Kỳ vọng VAD vẫn ghép exact identity; fingerprint mismatch trên revision tồn tại và video compaction không bị làm yếu. Probe năm guard độc lập bổ sung bằng chứng cho F5; không coi hash là chuỗi chứa tên engine.

**B — System principal.** Hai caller production của `revisionsForKnowledgeSync()` vẫn chỉ là reconcile và dry-run (`AiKnowledgeSyncService.php:125,:299`); cả hai đã dùng envelope `revisions`/`candidate_errors`. Không thấy đường HTTP mới hoặc consumer retrieval sản phẩm. F2 thêm tenant-scoped existence check ở `MediaReadService.php:143–144`; cùng file/tenant/usage vẫn được kiểm trước đó. System reader vẫn chỉ dành cho `ai_knowledge_sync`, published/deprecated Version, không crop, không working Activity. Suite core chạy lại các từ chối quyền và audit nullable user.

Phân loại settling (`MediaReadService.php:78,:197–201`) **hợp lý cho race giữa listing và resolving**, không phải lời bảo đảm rằng mọi lỗi Media sẽ tự lành. Probe đổi từng trạng thái pending/processing/failed/archived/detached/missing ngay sau query candidate: listing không tạo revision sai, không candidate error; sau phục hồi Media, lượt sau trả một revision ở cả hai engine. Locale sai tồn tại ổn định không bị che nữa. `failed` của Media là trạng thái upstream, không phải ingestion đã thành công; nếu nó kéo dài, Knowledge vẫn chưa có nội dung. Sync không phải monitor đầy đủ cho mọi lỗi Media: rows không ready vốn không vào candidate; cần dựa vào trạng thái/job monitoring của Media. Chưa kiểm tải lỗi kéo dài/aging alert; không nâng kết quả này thành bảo đảm eventual recovery khi Media không được sửa. Các mã ngoài settling vẫn nổi ở counters/log. Exception cấp owner không được nuốt trong reconcile, nhưng phát hiện F6 ở dry-run.

**C — Event.** Job/event/listener SHA không đổi từ lượt 1. Các test revision-ready, failure/non-readable types và tenant/context chạy lại trong core Media. Dispatch vẫn sau transaction persist trong đường worker và ngoài catch purge; listener `ShouldQueueAfterCommit`. Giới hạn event class không tự implements `ShouldDispatchAfterCommit` cho caller tùy ý và Redis/scheduler thật vẫn là rủi ro đã ghi, không được tuyên bố đã giải quyết ở lượt 2. Probe dispatch-throw và thử hai tiến trình của lượt 1 là bằng chứng lịch sử, không báo thành đã chạy lại lần này.

**D — Đồng bộ.** Reconcile thật vẫn xoá → purge → finalize → archive → ingest; tenant inactive không ingest nhưng vẫn erase/archive. Finalizer lọc blocker trước limit và tái kiểm dưới khoá, không bypass barrier. Cursors của reconcile và backoff vẫn được core/probe kiểm; cache reset/unbounded deletion discovery và tải tenant lớn vẫn chưa benchmark. Dry-run mới có F6; không đồng nhất bằng chứng no-write với bằng chứng luôn hoàn tất.

**E — Lifecycle/xoá.** M02/M03/M04/M05 nay bị test repository mới bắt, không còn chỉ dựa vào probe reviewer. Archive exact old file, xoá source lịch sử, fingerprint-only/version-only rebuild được tái lập. RF1/RF2, probe thật deleteDraftContent và post-selection barrier xác nhận bản vá không làm mất safety. Mutation M09 bỏ barrier bị hai test bắt. Không mở lại task usage mồ côi ngoài phạm vi.

**F — Retrieval/tài liệu.** Retrieval SHA không đổi, test DTO `reading_order`/NULL, quyền và stale/deleted candidates chạy lại. `rg` trong app/routes không tìm thấy consumer sản phẩm. LF-AI và docblock vẫn giữ việc hoãn modifier tới consumer đầu tiên. Docs v1.1/v1.26/v2.48, conflict0039 và đăng ký báo cáo được đối chiếu; đoạn brief/record mô tả số liệu trước remediation được hiểu là lịch sử, không dùng làm bằng chứng lượt này. Không mở lại conflict0012/0036.

**G — Đóng Source/Chunk.** F1/F2/F3/F5 có đủ bằng chứng đóng; schema constraints được chạy trên MariaDB, không suy từ SQLite. F4 còn F6 nên **chưa ký đóng toàn bộ bước review**. Điều còn thiếu cụ thể là sửa/kiểm dry-run luôn tiến qua owner lỗi; không yêu cầu UI, activation, trợ giảng frontend, ranking modifier hay backend Bước 7. Review DDL Foundation trước apply là gate riêng, chưa được thay thế bằng báo cáo này.

### Lệnh và kết quả do reviewer chạy

Tất cả log/JUnit/script/probe dưới `/private/tmp/lfbb-review2.zr8k8q27/` (gọi là `$R` bên dưới); `$R/run.sh` export môi trường offline, test DB và chuyển cwd vào snapshot. Các suite dùng chung storage/database chạy tuần tự. Thời gian ở `validation-results.json` là wall-clock của process; có thể khác Duration PHPUnit do thời gian bootstrap/DB.

| Lệnh / phạm vi | Kết quả | Bằng chứng |
| --- | --- | --- |
| `mariadb-install-db --no-defaults …`; server `--skip-networking`; `migrate --force` | Fresh schema, migration exit 0; MariaDB 11.4.12, skip_networking=1 | `install.log`, `server-identity.log`, `migrate.log` |
| `run.sh sqlite test AiKnowledgeSyncServiceTest + BackboneIndependentProbeTest` | 37 pass / 0 fail / 0 skip; 197 assertions | `sqlite-start.xml`, `sqlite-start.log` |
| `run.sh sqlite test $R/probes/Round2ProbeTest.php` | 4 pass / 1 fail / 0 skip; 66 assertions | `probes2-sqlite.xml`, `probes2-sqlite.log` |
| `run.sh sqlite test` — 5 core + 3 Media files | 164 pass / 0 fail / 7 skip; 1102 assertions | `sqlite-core-media.log`, `sqlite-core-media.xml` |
| `run.sh maria test` — 8 probe cũ + 5 probe mới | 12 pass / 1 fail / 0 skip; 87 assertions | `maria-probes.log`, `maria-probes.xml` |
| `run.sh maria test` — 5 core + 3 Media files | 170 pass / 0 fail / 1 skip; 1126 assertions | `maria-core-media.log`, `maria-core-media.xml` |
| Unix socket: `DROP DATABASE lf_bb_review2; CREATE DATABASE lf_bb_review2 …` | exit 0 | `fresh-integration-db.log` |
| `run.sh maria test` — đúng 33 file workflow, schema mới | 545 pass / 0 fail / 1 skip; 2894 assertions | `integration33.log`, `integration33.xml` |
| `run.sh sqlite test` — toàn suite mặc định | 1277 pass / 0 fail / 22 skip; 11278 assertions | `default.log`, `default.xml` |
| `run.sh sqlite docs:lint` | exit 0 | `docs-lint.log` |
| `run.sh sqlite schema:drift --docs-only` | exit 0 | `schema-docs.log` |
| `php $R/compaction-all.php` — 5 tuple vượt ngưỡng | exit 0 | `compaction.log` |

Lệnh đầy đủ, đường dẫn 33 file và exit code nằm trong `validation.py`, `validation-results.json`, `integration-files.json`; không thay suite bằng filter. Core MariaDB có 171 case; skip duy nhất là test Qdrant thật không bật. SQLite core có thêm sáu skip vì yêu cầu engine MariaDB. Ba suite Media gồm 42 test đã pass trên cả hai engine, không có skip Audio/Video.

**Đối chiếu JUnit theo tên:**

- 33 integration: lượt 1 đỏ 2, lượt 2 đỏ 0. Lượt 1: `Tests\Feature\AiProviderExecutionGateTest::test_mariadb_revalidation_refund_and_execution_claim_serialize_on_the_run`, `Tests\Integration\SaasUsageQuotaPacketMariaDbTest::test_two_connections_serialize_capacity_on_the_entitlement_lock`. Lượt 2: không.
- default: lượt 1 đỏ 0, lượt 2 đỏ 0. Lượt 1: không. Lượt 2: không.
- Probe reviewer duy nhất đỏ ở cả hai engine: `Round2ProbeTest::test_dry_run_progresses_after_owner_listing_errors` / `Round2MariaProbeTest::test_dry_run_progresses_after_owner_listing_errors` (F6). Tất cả tám probe cũ pass. Không tính probe âm vào tổng suite repository.
- Hai lỗi guard prefix DB của lượt 1 đã không tái diễn với `lf_bb_review2`; không dùng tổng số xanh để che một tên test đỏ.

**Skip mặc định (tên đầy đủ để kiểm lại):**

- `Tests\Feature\AiEmbeddingServiceTest::test_generation_constraints_are_physically_enforced_on_mariadb`
- `Tests\Feature\AiEmbeddingServiceTest::test_real_qdrant_maintenance_passes_a_broken_collection_and_recovers_it`
- `Tests\Feature\AiEmbeddingServiceTest::test_mysql_all_ready_pass_uses_one_candidate_query_without_identity_batch_queries`
- `Tests\Feature\AiKnowledgeIngestionServiceTest::test_delete_barrier_uses_a_real_for_update_lock_on_mariadb`
- `Tests\Feature\AiProviderExecutionGateTest::test_mariadb_revalidation_refund_and_execution_claim_serialize_on_the_run`
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "video usage (F4 scope)"`
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "frame text content (F4 scope)"`
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "page locator (F3: Media regions are region)"`
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "page zero"`
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "failed status (F5 removed)"`
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "unknown owner type"`
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "partial bbox"`
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "bbox outside the frame"`
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "ready without text"`
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "deleted still holding text"`
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "deleted without completion time"`
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_check_constraints_refuse_invalid_rows with data set "deletion pending without request time"`
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_a_row_cannot_cite_another_tenants_media_file`
- `Tests\Feature\AiVisionInterpretationsSchemaTest::test_a_row_cannot_reference_another_tenants_model_run`
- `Tests\Feature\CourseTemplatePublishConcurrencyTest::test_template_lock_serializes_same_template_but_not_another_template`
- `Tests\Feature\MediaProcessingSubstrateTest::test_real_local_audio_provider_persists_timespans_and_media_read_returns_them`
- `Tests\Feature\MediaProcessingSubstrateTest::test_real_local_video_pipeline_persists_transcript_caption_and_media_read_outputs`

Các lần hiệu chỉnh harness reviewer được giữ tách khỏi finding sản phẩm: lần đầu probe gọi `Artisan::output()` hai lần làm cạn buffer; probe settling từng dùng nhầm `media_files.deleted_at` không tồn tại và capture biến theo reference qua vòng lặp; đã sửa **chỉ probe riêng**, chạy lại cả hai engine. Probe compaction ban đầu thử thêm structured-extraction cần Media identity/DB nên bỏ trường hợp thừa, giữ đủ năm guard cần kiểm. Các lỗi harness này không được tính là lỗi implementation.

### Mutation: regression mới có thực sự bắt lỗi

Một mutation mỗi lần trên snapshot, restore trong `finally`; mọi SHA khôi phục bằng SHA trước. Chín mutant đầu chạy 4 file feature Sync/Ingestion/Retrieval/Embedding, rồi chạy core và toàn suite trên cây đã khôi phục. Sau đó RF1_exact_old_finalizer đảo nguyên hàm finalize về lượt 1 để loại khả năng chỉ bắt chênh counter; chạy regression riêng, restore SHA và chạy lại chính baseline đó. Các test mới của repository bắt M02–M05 trực tiếp, không đưa probe reviewer vào mutation runner. M09 bổ sung kiểm barrier.

| Mutation | File | Test repository bắt lỗi | SHA-256 trước = sau | SHA-256 mutant |
| --- | --- | --- | --- | --- |
| M02_exact_media_owner | `app/Services/MediaReadService.php` | `test_replacing_the_media_archives_the_exact_previous_file` | `d95c2585c4d65eb31c9af788e45efe9d6334613e70c1368db23921d30831eeca` | `2beb3c5da48477a04fbc1b113210ec45eba23676046694149597a056b9791a01` |
| M03_historical_delete | `app/Services/AiKnowledgeSyncService.php` | `test_deleting_media_erases_stale_archived_and_current_sources` | `07dcfcecb19198b29df18a2e0786d15cf54609416d8e8ecd16eaeafdff71388d` | `5879da92101570d604e5bc6c61784c2a8e90c9afda796366ddec1f9dee178204` |
| M04_active_fingerprint | `app/Services/AiKnowledgeSyncService.php` | `test_fingerprint_alone_and_version_alone_each_trigger_a_rebuild` | `07dcfcecb19198b29df18a2e0786d15cf54609416d8e8ecd16eaeafdff71388d` | `e90c4b93ba391638d4d774de002d47b39bfc702c0b4447ba5b91b0a58f934fdf` |
| M05_active_version | `app/Services/AiKnowledgeSyncService.php` | `test_fingerprint_alone_and_version_alone_each_trigger_a_rebuild` | `07dcfcecb19198b29df18a2e0786d15cf54609416d8e8ecd16eaeafdff71388d` | `f47144fdc1482cf0df1fc8e1e2175daf6f3a954b046daf9c15d2afbde1a262f8` |
| M09_delete_barrier | `app/Services/AiKnowledgeIngestionService.php` | `test_delete_barrier_requires_all_embeddings_to_be_deleted_before_tombstoning`, `test_purge_is_what_releases_the_source_delete_barrier` | `e7528330ebf76c4517fce4db979f4ceaa84c1fee5cff9512e168b70a800ef619` | `8f6ed2078e7eb0472221e11fe9459c5ab237885480ff500da93ebbd8e2b82bbc` |
| RF1_finalize | `app/Services/AiKnowledgeSyncService.php` | `test_deletion_waits_for_the_vector_purge_and_retries_until_acknowledged`, `test_finalize_does_not_starve_sources_already_free_of_embeddings` | `07dcfcecb19198b29df18a2e0786d15cf54609416d8e8ecd16eaeafdff71388d` | `1c5b40e855f9a5e04948e77b3e5908b019f09efafda62a21ae35e77569089894` |
| RF2_draft_owner | `app/Services/MediaReadService.php` | `test_a_removed_draft_activity_archives_its_hand_prepared_source` | `d95c2585c4d65eb31c9af788e45efe9d6334613e70c1368db23921d30831eeca` | `aa4820bafc3af02404c2e02fbf34e2b3a4a98b3b8777a6d729b809360744d1ad` |
| RF3_candidate_errors | `app/Services/MediaReadService.php` | `test_a_refused_ready_candidate_is_counted_and_logged_once_per_window` | `d95c2585c4d65eb31c9af788e45efe9d6334613e70c1368db23921d30831eeca` | `398d2c9b3d802f60498d1b7b3065777e97ffeb373caa13d29439087ef7f8a894` |
| RF4_dry_run | `app/Console/Commands/AiKnowledgeSync.php` | `test_dry_run_reports_the_pass_and_writes_nothing` | `0c0476a563137036d5a865ab104fbd80e4b7f97be2291a09cec0c96a3ca4b6f3` | `2a564d009292eb107a3675fad66531e593fec4f4c6b73e2312564fdcadfa762f` |
| RF1_exact_old_finalizer | `app/Services/AiKnowledgeSyncService.php` | `test_finalize_does_not_starve_sources_already_free_of_embeddings` | `07dcfcecb19198b29df18a2e0786d15cf54609416d8e8ecd16eaeafdff71388d` | `a16086baf840a15f329eb797cd15bdb33525377cfe88d8cdff36d882ff6bd735` |

**10/10 mutant bị bắt**, mỗi lần exit 1 và JUnit xác định đúng assertion liên quan. RF1 bỏ prefilter blocker trước limit; RF1_exact_old_finalizer đảo nguyên hàm finalize, assertion source sau phải deleted bị đỏ (không chỉ counter); RF2 bỏ draft-owner existence; RF3 nuốt candidate_errors; RF4 gọi reconcile thay plan. `mutation-results.json`, `mutations.py` và từng `<mutation>.xml/.log` giữ command/bằng chứng chi tiết. Kết quả này không chứng minh nhánh exception cấp owner của dry-run được bao phủ: F6 chứng minh nó còn thiếu.

Baseline sau khôi phục RF1_exact: 1 pass / 0 fail / 0 skip; 5 assertions (`finalize-restored.xml/.log`).

### SHA-256 và bảo toàn snapshot

| File | SHA-256 đầu lượt (đối chiếu cuối lượt) |
| --- | --- |
| `app/Services/AiKnowledgeSyncService.php` | `07dcfcecb19198b29df18a2e0786d15cf54609416d8e8ecd16eaeafdff71388d` |
| `app/Services/MediaReadService.php` | `d95c2585c4d65eb31c9af788e45efe9d6334613e70c1368db23921d30831eeca` |
| `app/Console/Commands/AiKnowledgeSync.php` | `0c0476a563137036d5a865ab104fbd80e4b7f97be2291a09cec0c96a3ca4b6f3` |
| `app/Services/AiKnowledgeIngestionService.php` | `e7528330ebf76c4517fce4db979f4ceaa84c1fee5cff9512e168b70a800ef619` |
| `app/Services/AiKnowledgeRetrievalService.php` | `3abd8135d7ad581f2bd459fd5b949ad9714ad33eb444903a0eb72b545c2083ad` |
| `app/Jobs/ProcessMediaProcessingJob.php` | `a3f0091ae8ba03f01cc7a13986f6793ec2380ef56d6fd75d74d9957c6224a0aa` |
| `app/Services/MediaProcessingOrchestrator.php` | `48a821b20334fe316e76f316e9692cbba18aace46fe561089f3f567882dbac2e` |
| `tests/Feature/AiKnowledgeSyncServiceTest.php` | `0afaed0d54a598a2862aebb99efabe9ddb0ddb7cb1d9b4444c3b512076abe3a6` |
| `tests/Support/Ai/KnowledgeSyncFixture.php` | `8e6f990e371e85f5863ee47077bb95161389c734bfeabb07c3cd825a161093f7` |
| `docs/platform/LF-AI-Knowledge-Sync-Contract.md` | `446ed087e519e225fac69c7e516cf4bca4222eec162d620ee0981e44ab614610` |
| `docs/platform/LF-Media-Read-Contract.md` | `76f3a43464bd14a58de7f72a59537879c6549e91f491b7e635ee7805dc56e966` |
| `docs/platform/LF-Media-Processing-Contract.md` | `74dcd94810df716b521ee418dd66923db3ae13534629ab20cd82491e53ea0131` |

SHA đầy đủ 1.014 file trong `snapshot-sha256.json`. SHA các harness/bằng chứng dùng lại:

- `probes/BackboneIndependentProbeTest.php`: `37f303bc6f785f4b48f68c817d2743ae405ee92bcc60686a0a2693a4d777d995`.
- `probes/BackboneMariaProbeTest.php`: `b8e0305595a9c5d804eb72570aa51b24892a9e9483d6020c7c12371a8eb26e74`.
- `probes/Round2ProbeTest.php`: `2e6b44f3966863cd4121894e760a9b80dbc6b0180794a0908543d6a7eaae198d`.
- `probes/Round2MariaProbeTest.php`: `3faef0a7c23ae249a16783e40758f908835d62fe5f2ea9e852bb0b4e189e0143`.
- `mutations.py`: `9c44b34c5ed6feb94205de21139641b422e0cfa286a8b5104c621bb0ca08abfe`.
- `mutation-finalize-exact.py`: `d39cb29db2012cd3e6a612456533fcb9f3a5f2af563d2bd8cda60cbee9b59885`.
- `validation.py`: `192f7ab1104906ce6e06799c5b6335a191ff615a80b59e120563ef5497deb859`.
- `compaction-all.php`: `43575c5359b844280dafb694540c17ee0a927c123c2213c1ce6f9806abd1dd01`.
- `run.sh`: `4fe91abc5792b5caa70a4aa6d0d97adff695a192d4aa25f125156e7e2eee7ed3`.

### Chưa kiểm và điều kiện đóng

Không chạy Qdrant/provider bên ngoài, Redis worker/scheduler deployment, tải tenant lớn, nhiều worker thật ở lượt 2, MariaDB 10.4, GitHub CI hay production apply. Probe barrier dùng query listener để điều khiển interleaving và kiểm SQL khoá; không gọi đó là stress test đa tiến trình. Fresh schema cùng integration constraints chứng minh phạm vi test trên MariaDB 11.4, không phải review migration/DDL gate riêng. Không kiểm UI, activation, tutor, modifier ranking hay backend Bước 7 ngoài tương tác đã nêu.

Sau bản vá F6 cần chạy lại probe toàn lô owner lỗi và chứng minh dry-run vẫn không ghi DB/cache/audit hoặc đọc nội dung. Đây là điều kiện còn thiếu để đóng Source/Chunk trong lượt review này; không cần chờ AI provider thật.

**Finding đang mở sau lượt 2:** BLOCKER 0; HIGH 0; MEDIUM 1 (F6); LOW 0 độc lập — F4 còn một phần do F6. **Verdict lượt 2: CHANGES REQUIRED.**

### Đối chiếu cuối và dọn môi trường

Trước khi append báo cáo, đối chiếu lại **1.014/1.014 file** ở cả cây gốc và snapshot: không lệch SHA (`pre-report-hash-check.json`). Cả 10 mutation đã restore đúng SHA; vendor không symlink. Nội dung lượt 1 giữ nguyên byte, ngoại trừ cập nhật header Final Verdict để chỉ rõ lượt 2; Last Updated vẫn là 2026-09-26 vì hai lượt cùng ngày. Sau khi ghi, chỉ chính báo cáo này được phép lệch snapshot.

MariaDB shutdown exit 0, server process exit 0; PID 47240 không còn, socket/PID file không còn; **datadir đã xoá**. `cleanup.json` ghi cả bốn kiểm tra false. Bản sao code, probes, JUnit và log được giữ ở thư mục bằng chứng để đối chiếu, không còn instance DB hoạt động.

Mutation M06/M07/M08/M10/M11/M12 và thử hai tiến trình thật của lượt 1 không chạy lại trong lượt 2; không gộp chúng vào con số 10 mutant mới. Các kết luận tương ứng dùng bằng chứng lịch sử đã ghi ở lượt 1, đối chiếu diff, và core/default regression chạy lại; không coi đó là stress test mới.


## Lượt 3 — Re-review

### Kết luận và phạm vi

**Verdict lượt 3: PASS WITH DOCUMENTED RISKS. F4 và F6: CLOSED.** Phạm vi lượt này chỉ là tiến con trỏ dry-run và các bất biến chỉ đọc còn mở từ lượt 2. Không có finding runtime BLOCKER/HIGH/MEDIUM mới trong phạm vi đã kiểm. Có một lưu ý LOW về coverage của dataset mang tên “mixed” (F7 bên dưới); reviewer đã kiểm lô trộn thật bằng probe độc lập trên cả hai engine. Lưu ý coverage này không chặn đóng lỗi runtime F6/F4 hay phần chuẩn bị Source/Chunk.

Reviewer không phải implementer/remediator; không vá code/test/canonical docs. Giữ nguyên nội dung lượt 1–2, chỉ đổi Final Verdict ở header và append lượt 3. Snapshot vẫn là working tree trên HEAD `01cce9e37064829778676156cb34ef06fe786e47`; `<COMMIT>` chưa có giá trị mới. Không coi record implementer là kết quả của reviewer.

Bản sao vật lý `/private/tmp/lfbb-review3.wa6sk53u/snapshot` gồm **1.014 file**; SHA manifest `b0096061a2b1a6cd54bc0eb6992447a8517dca85e5e7fa391425bd3344f713db`. Báo cáo trước lượt 3 có SHA `b1602d7aded07f09b4839cf9043e448d21182a261575a129879c36612f387362`, khớp bản đã giao lượt 2. Đối chiếu cho thấy chỉ service Sync, test Sync, implementation record và báo cáo khác snapshot đầu lượt 2; phần báo cáo khác là append của reviewer ở lượt 2, không phải implementer sửa nội dung.

Vendor được copy thật, không symlink; Reflection trỏ vào `…/snapshot/app/Services/AiKnowledgeSyncService.php`. Dùng MariaDB **11.4.12** độc lập với `--no-defaults --skip-networking`, database **`lf_bb_review3`**, socket `/private/tmp/lfbb-review3.wa6sk53u/s.sock`, datadir `…/dbdata`; `@@skip_networking=1`, `@@explicit_defaults_for_timestamp=1`. Không truy cập `learnforge_db`/3306, không thêm secret, không gọi provider bên ngoài. Wrapper offline và engine Media cục bộ như lượt 2; mọi suite chia sẻ fixture/storage/database chạy tuần tự.

### F4/F6 và bất biến dry-run

| Mục | Trạng thái | Bằng chứng độc lập |
| --- | --- | --- |
| F6 — con trỏ không tiến khi toàn lô owner lỗi | **CLOSED** | Chạy lại nguyên probe `Round2ProbeTest::test_dry_run_progresses_after_owner_listing_errors` và `Round2MariaProbeTest` từ lượt 2, không sửa probe. Hai usage lỗi, limit=1: command exit 0, đúng **3** query lấy lô (hai lô một usage + lô rỗng), `candidate_errors=2`, `tenant_errors=0`. Bộ ngắt ở lần 4 không bị kích hoạt. |
| F4 — phần dry-run còn mở | **CLOSED** | Probe cũ không ghi dữ liệu vẫn pass. Probe mới bao phủ toàn lô lỗi với limit=1 và limit=2, lô trộn thật với limit=2, candidate locale lỗi và tenant kế tiếp. Không DML/DDL, không SELECT nội dung derived Media, không audit, không đổi serialized cache store dù đã seed owner/archive cursor và backoff/attempts. Command trả `would_ingest=2`, `candidate_errors=3`, `tenant_errors=0`; context tenant trước/sau bằng nhau. Các sửa contract v1.1 đã kiểm ở lượt 2 và không thay đổi. |

**Lý do bản vá đúng:** `AiKnowledgeSyncService.php:122–126` lấy owner theo usage ID tăng dần rồi cập nhật biến `$after` bằng usage cuối **trước** khi xử lý từng owner. Vì vậy `continue` trong catch không thể giữ con trỏ cũ. Lô rỗng giữ nguyên biến rồi thoát qua điều kiện kích thước lô; lô đầy toàn lỗi vẫn tiến. Đây là biến local trong dry-run, không gọi Cache::put và không đụng cursor của reconcile thật. Diff không thay selector, authority, corpus, content reader, counters hay lifecycle.

**Probe mới do reviewer viết:** `Round3ProbeTest::test_errors_mixed_batches_and_next_tenant_are_read_only`, ba dataset; bản MariaDB chỉ thay trait thành DatabaseTransactions để dùng schema vừa dựng. Dữ liệu không mock service:

- Tenant A: owner ambiguous có hai active usages; một owner đọc được; một owner có ready transcript mang locale không hợp lệ. Tenant B: một owner đọc được.
- Toàn lô lỗi: thứ tự owner `[A_bad, A_bad, A_good, A_invalid]`, limit 1 hoặc 2.
- Lô trộn thật: thứ tự `[A_bad, A_good, A_bad, A_invalid]`, limit 2. Probe assert trực tiếp hai owner đầu khác nhau, không suy từ tên dataset.
- Query listener chỉ ghi nhận SQL/bindings và có bộ ngắt an toàn; không sửa kết quả đọc. Limit 1: 7 listing queries cho cả hai tenant, 52 SQL tổng. Hai ca limit 2: 4 listing queries, 49 SQL tổng. Command đều trả về trước ngưỡng bộ ngắt; cache trước/sau giống hệt.
- Cấm INSERT/UPDATE/DELETE/REPLACE/DDL trong cửa sổ chạy command; cấm SELECT `*`/text/content/raw_text/structure từ bảng derived Media; so số audit và source/chunk; seed rồi so toàn bộ cache store để bắt cả việc tạo mới backoff. Fixtures nội dung dùng transcript; đường metadata-only chung và MediaReadService không đổi từ lượt 2. Không tuyên bố đã đọc lại mọi định dạng Media ở lượt này.

### Mutation và regression

Khôi phục **nguyên `planTenant()` của snapshot lượt 2** trên bản sao riêng. Vị trí cũ thực tế là cuối **thân** `foreach`, bị `continue` bỏ qua; không nhầm với đặt phép tiến con trỏ bên ngoài vòng foreach (vẫn có thể tiến đúng).

- SHA trước: `d7f987e91cc0140d9017ac087d42deb6da4f91df5ffb856935add6cca6d85a9e`.
- SHA mutant: `07dcfcecb19198b29df18a2e0786d15cf54609416d8e8ecd16eaeafdff71388d` — đúng SHA service lượt 2.
- Regression repository `test_dry_run_moves_past_owners_that_fail_to_resolve`: **cả hai dataset đỏ**, exit 1, do command cần bộ ngắt/không trả success. Không thêm probe reviewer vào lần mutation này.
- Restore trong `finally`: SHA sau bằng SHA trước. Chạy lại toàn file Sync: **31 pass**. Kiểm chứng MariaDB và default sau đó dùng cây đã khôi phục.

### F7 — LOW — Dataset “mixed” của regression chưa tạo lô trộn

**Vị trí:** `tests/Feature/AiKnowledgeSyncServiceTest.php:416–419`, nhãn dataset tại `:448`.

Fixture tạo usage thứ nhất của owner ambiguous, usage thứ hai của cùng owner đó, rồi mới tạo owner đọc được. `knowledgeSyncOwners()` sắp theo usage ID. Với dataset limit=2, lô đầu là `[bad, bad]`, lô tiếp là `[good]`; không có lô chứa cả owner lỗi và owner đọc được. Dataset vẫn bắt F6 đúng (mutation chứng minh), nhưng tên và mô tả coverage rộng hơn dữ liệu thực tế. Nếu sau này có hồi quy chỉ xảy ra trong một lô trộn, hai dataset này chưa bảo vệ trường hợp đó.

Reviewer đã tái lập thứ tự usage và kiểm thêm `[bad, good, bad, invalid]` trong probe riêng, pass cả SQLite/MariaDB với đầy đủ bất biến chỉ đọc. **Khuyến nghị không chặn:** đổi thứ tự fixture hoặc thêm dataset lô trộn thật vào regression repository. Không vá trong lượt review này. F7 là thiếu coverage lâu dài, không phải phát hiện lỗi xử lý lô trộn hiện tại.

### Giới hạn và điều kiện đóng Source/Chunk

Lượt 3 không chạy lại 33 file integration-mysql; kết quả **545 pass/1 skip của lượt 2** vẫn là bằng chứng lịch sử, không được tính thành kết quả lượt này. Bản vá runtime chỉ di chuyển biến cursor local của nhánh dry-run; MariaDB mới, test Sync, probe cũ/mới và toàn suite mặc định được chạy lại. Không review lại F1/F2/F3/F5, migration/DDL Foundation, UI, provider activation, tutor frontend hay modifier ranking đã hoãn.

Không thử Redis worker/scheduler deployment, tải tenant lớn, nhiều process thật, MariaDB 10.4, GitHub CI hay production. Rủi ro vận hành đã ghi ở lượt 1–2 (quét xoá chưa giới hạn bộ nhớ, mất cache kéo dài vòng quét, backoff và phạm vi after-commit của event) vẫn còn; lượt hẹp này không chứng minh chúng đã biến mất.

**Không còn điều kiện sửa runtime F4/F6 để đóng phần chuẩn bị Source/Chunk.** Kết hợp bằng chứng các lượt trước và re-review này, có thể đóng bước review xương sống với các rủi ro đã ghi; F7 là cải thiện regression được khuyến nghị, không là gate mới. Review DDL Foundation trước production apply vẫn là công việc riêng, chưa được thay bằng kết luận này. Không yêu cầu bật AI provider thật, UI hay frontend để đóng Source/Chunk.

Ghi chú bàn giao tài liệu: `docs/LF-INDEX.md:396` và `docs/quality/README.md:60` còn tóm tắt verdict/finding lượt 1. Không sửa các catalog đó trong lượt review chỉ được phép append báo cáo; implementer nên đồng bộ mô tả sau khi nhận verdict lượt 3. Đây không phải nội dung contract dry-run còn sai, và không mở lại các finding runtime đã đóng.

### Lệnh và kết quả do reviewer chạy

`$R=/private/tmp/lfbb-review3.wa6sk53u`; wrapper `run.sh` đặt cwd vào snapshot, DB Unix socket/SQLite memory, provider fake và môi trường offline. MariaDB target bắt đầu bằng database rỗng, RefreshDatabase tự dựng schema. Các số dưới đây lấy từ JUnit của chính lượt 3.

| Lệnh / phạm vi | Kết quả | Log/JUnit |
| --- | --- | --- |
| `run.sh sqlite test … --filter "dry_run|errors_mixed"` — regression + probe cũ/mới | 8 pass / 0 fail / 0 skip; 271 assertions | `sqlite-target.xml/.log` |
| `python3 $R/mutation.py` — restore nguyên planTenant lượt 2, chạy regression mới | 0 pass / 2 fail / 0 skip; 2 assertions | `mutation.xml/.log` |
| `run.sh sqlite test tests/Feature/AiKnowledgeSyncServiceTest.php` sau restore | 31 pass / 0 fail / 0 skip; 193 assertions | `sqlite-restored.xml/.log` |
| `run.sh maria test … --filter "AiKnowledgeSyncServiceTest|dry_run|errors_mixed"` — toàn file Sync + probe cũ/mới | 36 pass / 0 fail / 0 skip; 441 assertions | `maria-target.xml/.log` |
| `run.sh sqlite test` — toàn suite mặc định | 1279 pass / 0 fail / 22 skip; 11294 assertions | `default.xml/.log` |
| `run.sh sqlite test $R/probes/RegressionShapeProbeTest.php --filter test_dry_run_moves_past_owners_that_fail_to_resolve` | 2 pass / 0 fail / 0 skip; 16 assertions | `shape.xml/.log` |
| `run.sh sqlite docs:lint` | exit 0; 94 legacy allowlist, không issue mới | `docs-lint.log` |

Lệnh MariaDB target chạy ba file: `tests/Feature/AiKnowledgeSyncServiceTest.php`, `$R/probes/Round2MariaProbeTest.php`, `$R/probes/Round3MariaProbeTest.php`; SQLite target dùng hai bản probe RefreshDatabase tương ứng. Probe lượt 2 giữ nguyên SHA; chỉ filter hai method dry-run cho đúng phạm vi hẹp. Không chạy lại các probe barrier/settling khác ở lượt này.

**Đối chiếu tên test đỏ:**

- `sqlite-target`: không test đỏ.
- `maria-target`: không test đỏ.
- `default`: không test đỏ.
- `mutation`: `Tests\Feature\AiKnowledgeSyncServiceTest::test_dry_run_moves_past_owners_that_fail_to_resolve with data set "a batch made only of failing owners"`, `Tests\Feature\AiKnowledgeSyncServiceTest::test_dry_run_moves_past_owners_that_fail_to_resolve with data set "a batch mixing failing and readable owners"`.
- Probe F6 duy nhất đỏ ở lượt 2 nay pass nguyên bản trên cả hai engine; số listing=3, bộ ngắt không được gọi. Default lượt 2 và lượt 3 đều không có tên test đỏ; số case tăng đúng hai dataset regression mới. Skips default được ghi trong JUnit, không tính là pass.
- `RegressionShapeProbeTest` kế thừa nguyên test implementer, chỉ thêm listener read-only ghi thứ tự owner. Với limit=2, tenant A có `[1,1,2]`, tenant B `[3]` trên SQLite fresh: không có lô trộn. Điều này đối chiếu với probe reviewer actual-mixed `[1,2,1,3]`; cả hai chạy độc lập, không sửa test repository.

### SHA-256, bảo toàn và dọn môi trường

| File | SHA-256 snapshot lượt 3 |
| --- | --- |
| `app/Services/AiKnowledgeSyncService.php` | `d7f987e91cc0140d9017ac087d42deb6da4f91df5ffb856935add6cca6d85a9e` |
| `app/Services/MediaReadService.php` | `d95c2585c4d65eb31c9af788e45efe9d6334613e70c1368db23921d30831eeca` |
| `app/Console/Commands/AiKnowledgeSync.php` | `0c0476a563137036d5a865ab104fbd80e4b7f97be2291a09cec0c96a3ca4b6f3` |
| `tests/Feature/AiKnowledgeSyncServiceTest.php` | `60b4fa477e90e01674ebff832a693054119c7970cc17c1da9a44b595a4e635bc` |
| `docs/quality/LF-AI-Knowledge-Backbone-Implementation-Record.md` | `8d29c0104f92df9efd952494781890da0bd4d46136688e6aa3c9c3b097d02cbf` |

SHA các harness riêng để tái lập:

- `probes/Round2ProbeTest.php`: `2e6b44f3966863cd4121894e760a9b80dbc6b0180794a0908543d6a7eaae198d`.
- `probes/Round2MariaProbeTest.php`: `3faef0a7c23ae249a16783e40758f908835d62fe5f2ea9e852bb0b4e189e0143`.
- `probes/Round3ProbeTest.php`: `5e1115e4e607e20d200ab4da14ee2cf5c87571eb1877f01795f80e94b0938a4e`.
- `probes/Round3MariaProbeTest.php`: `672691dcb565a904e4bed0300cfc35476c44855f6921f30109d6c1725216d33e`.
- `probes/RegressionShapeProbeTest.php`: `a5613f8dcbda7fb7339728b12abe8543d15d97d8ae845adc6ae266defde7e273`.
- `mutation.py`: `6be46e1758493518f3be27551c61a848bf5adc43d85e7953b116dee1248d868a`.
- `run.sh`: `7672181ed08d248041a625861de206ab02325e5774bd384a48101bedab16a532`.

Manifest đầy đủ: `snapshot-sha256.json`; mutant/restore: `mutation-results.json`; server: `server-identity.log`, `server.log`; đối chiếu cuối và cleanup được ghi riêng. Không symlink vendor; Reflection xác nhận class thuộc bản sao riêng.

Đối chiếu trước append: **1.014/1.014 file** ở cây gốc và bản sao không lệch SHA; chỉ báo cáo này được thay đổi sau kiểm chứng. Lượt 1–2 giữ nguyên byte ngoại trừ header Final Verdict. Mutation restore đúng SHA; mọi test baseline/probe sau restore không có failure.

Cleanup: shutdown exit 0, server process exit 0; PID 51833, socket và PID file không còn; datadir đã xoá. `cleanup.json` xác nhận cả bốn điều kiện false. Giữ code copy/probe/JUnit/log làm bằng chứng, không còn MariaDB review hoạt động.

**Finding lượt 3:** BLOCKER 0; HIGH 0; MEDIUM 0; LOW 1 (F7, coverage dataset). **F4 CLOSED; F6 CLOSED. Final Verdict lượt 3: PASS WITH DOCUMENTED RISKS.**
