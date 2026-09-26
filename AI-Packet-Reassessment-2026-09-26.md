# Đánh giá lại packet AI từ 0a/0b đến Bước 7

Ngày đánh giá: 2026-09-26.

Classification: Architecture/implementation reassessment, read-only production code.
Initial Audit Level: HIGH. Final Audit Level: HIGH. Audit Level Escalation: None.
Final Verdict: **BLOCKED đối với closure toàn packet**: còn finding HIGH và chưa
phân loại đầy đủ failure toàn suite bằng matched baseline. Hai probe F1/F2 có
kết quả FAIL xác định. Đây không phải huỷ các nghiệm thu backend có phạm vi đã
được Owner ghi nhận.

## Phạm vi và căn cứ

Đối chiếu packet người dùng gửi với working tree trên HEAD
`01cce9e37064829778676156cb34ef06fe786e47`. Working tree đã có thay đổi tại
`CourseTemplateLearningMappingIntentService.php`, test HTTP tương ứng và
`composer.lock` trước lượt này. Đánh giá/test dùng working tree, không giả định
đây là snapshot HEAD sạch. Không sửa ba file đó, production code, migration hay
canonical policy. Báo cáo nằm tại working directory theo AGENTS.md.

Đây là lượt đánh giá lại trạng thái và các boundary trọng yếu, không phải chữ ký
Final independent closure review. Không ký lại hai gate 0a/0b bằng cùng một reviewer;
giữ nguyên nguồn và phạm vi các chữ ký lịch sử. Các miễn trừ đã ghi trong hồ sơ
được tôn trọng, không chuyển thành PASS và không suy rộng sang bước khác.

Documents Reviewed: docs/README.md; LF-INDEX routing; Architecture Guardrails;
Regression Audit; ADR-0006/0017/0020 và các amendment liên quan;
LF-AI; Authoring Proposal Contract; AI database README và contracts
Knowledge Source/Chunk/Embedding/Model Run; Foundation Round 3, Learning Gate 2,
Step 4/5/6/7 review records; Step 5 reviewer brief; Documentation Conflict Register.
Đã kiểm manifest/status, migration, schema contract và các service/HTTP/test liên quan.

Source of Truth: Media sở hữu evidence và quyền đọc; relational AI tables sở hữu
trạng thái dẫn xuất; Qdrant là index; Commercial sở hữu quota reservation;
Course sở hữu Intent/publication; Learning sở hữu Node/canonical Mapping.
Impact Graph: Media Read → Knowledge → model gate/quota → embedding/Qdrant;
Media deletion → consumers; Proposal → human review → Course/Learning owner ports
→ publication. Tenant, revision, authorization, audit và deletion đi xuyên các nhánh.

## Kết quả từng bước

| Bước | Đánh giá hiện tại | Điều còn phải phân biệt/hoàn tất |
| --- | --- | --- |
| 0a — Foundation review | Có Round 3 PASS ngày 2026-09-08 cho thiết kế/readiness bốn bảng | Người ký Round 3 sau đó viết DDL; chính hồ sơ yêu cầu code review độc lập cho DDL trước real apply. PASS thiết kế không tự chứng nhận mọi bản vá/runtime về sau. |
| 0b — Learning Gate 2 | Có independent Gate 2 PASS; điều kiện Learning cho Bước 7 đã có căn cứ | Câu “Gate 2 is not closed” đã chuyển thành lịch sử; DOC-CONFLICT-0036 RESOLVED. Không mở lại gate chỉ vì đọc subsection cũ. |
| 1 — Docs | Chưa đồng bộ hoàn toàn | Lint và docs-only drift xanh nhưng catalog/ADR/README còn phát biểu trạng thái cũ. Mastery amendment ADR-0006 v1.1 vẫn Proposed, không phải blocker của Knowledge migration. |
| 2 — Foundation migration | Có packet bốn bảng, tenant FK, NULL-safe identity, constraints, deferred FK và rollback preflight | Không chạy migration vào database ứng dụng. Physical test hiện tại ghi riêng bên dưới. Không dùng kết quả docs-only để suy ra live database khớp. |
| 3 — Ingestion/delete barrier | Service có deterministic chunking, exact revision, idempotency, stale, tombstone và barrier | Chưa nối xoá Media tới Source/Chunk/Embedding; chưa khép kín lifecycle toàn luồng (F1). |
| 4 — Governance/model run | Backend đã triển khai; hồ sơ ghi Owner closure/waiver 2026-09-12 | Gate có allow-list, tenant approval, entitlement, atomic reservation, safety và run audit. Provider activation là phạm vi riêng; không yêu cầu model thật để nghiệm thu backend. |
| 5 — Embedding/Qdrant | Backend có generation/retry/recovery/purge/retrieval, Owner waiver 2026-09-14 | F1/F2 ảnh hưởng độ đầy đủ toàn packet. AR-P3-2/3 còn điều kiện trước activation; FAIL tại b5da390 là lịch sử, không gán nguyên verdict đó cho code hiện tại. |
| 6 — Vision | Bảng riêng, model gate, provenance, xoá Media + reconciliation; backend đã được Owner nghiệm thu | v1 giới hạn document region; video interpretation ngoài scope. PII ảnh/activation và worker Redis thật chưa được kiểm chứng trong lượt này. Không ghi interpretation vào Media evidence. |
| 7 — Teacher-reviewed proposal | Sáu bảng + backend + 41 HTTP routes đã có; Owner nghiệm thu backend/HTTP ngày 2026-09-17 dưới waiver | UI chưa triển khai. Accept/edit/reject và owner promotion có backend; không được gọi toàn trải nghiệm giáo viên là hoàn tất. |
| Final closure | **BLOCKED** | Còn F1, implementation/documentation gaps, failure toàn suite chưa phân loại đầy đủ và các kiểm chứng chưa thực hiện; test hiện có xanh không thay các acceptance criteria này. |

Luồng promotion đã kiểm trong code: `AiAuthoringApplicationService` gọi owner
ports; `CourseTemplatePublishingService` gọi `AiAuthoringPublicationService`
trong transaction rồi `LearningMappingPromotionService` ghi Mapping. Publication
tái kiểm accepted revision, target published, context và Media freshness.
Accept không publish; summary/concept acceptance chỉ lưu nội dung đã duyệt,
không tự sửa Course instructions/objectives. Đây là phạm vi hiện hành của contract.

Packet cũ cần đọc cùng amendment đã duyệt: embedding có `failed → pending`
được Owner duyệt 2026-09-10. Đây là retry hợp lệ, không phải việc tự thêm
`processing` hoặc vi phạm lifecycle.

## Findings

### F1 — HIGH: Xoá Media chưa kích hoạt xoá Knowledge/embedding

`MediaService.php:647` phát `MediaFileDeleted`, nhưng `php artisan event:list`
chỉ có hai consumer: `EraseAuthoringProposalsOfDeletedMedia` và
`PurgeVisionInterpretationsOfDeletedMedia`. `requestSourceDeletion()` và
`finalizeSourceDeletion()` tại `AiKnowledgeIngestionService.php:206,247` không
có caller trong app. Scheduler cũng chỉ đối soát Vision/Authoring.

Hệ quả: sau khi detach rồi xoá Media, retrieval chặn nội dung nhờ tái kiểm quyền/
revision, nhưng chunk text và vector không được đưa vào đường xoá. Barrier đúng
khi gọi trực tiếp không chứng minh luồng xoá toàn hệ thống. Đây là O-6 đã ghi trong
review Bước 6; việc nằm ngoài scope Bước 6 không làm nó biến mất khỏi packet 0–7.

Đã tái lập bằng probe riêng trên SQLite in-memory: tạo fixture đã embed bằng
fake provider/vector store, detach usage, commit fixture, gọi
`MediaService::deleteMedia()`. Media thực sự `deleted`, retrieval trả `[]`,
chunk content vẫn khác NULL và source vẫn `active`; assertion yêu cầu source vào
`deletion_pending|deleted` thất bại. Không sửa production code để tạo kết quả này.

Đề nghị: AI consumer nhận sự kiện sau commit, đối soát missed events, đưa toàn bộ
source generations của file vào deletion_pending; purge vector với writer barrier,
rồi finalize chunk/source. Cần test Media deletion → vector failure/retry →
embedding deleted → chunk erasure, gồm hai tenant và sự kiện lặp. Không vá trong
lượt review này và không cho Media trực tiếp ghi bảng AI.

### F2 — MEDIUM: Retrieval chưa đáp ứng đủ contract provenance/ranking

`LF-AI.md:310` yêu cầu giữ reading order và các modifier về locale, role,
low-signal/quality, context expansion. `AiKnowledgeRetrievalService.php:98`
không select reading_order/source_text_quality; output tại dòng 158 không trả
reading_order; dòng 181 chỉ sắp lại theo thứ tự Qdrant. API không có query locale.

Probe độc lập ở thư mục tạm đặt chunk.reading_order=17 và truy hồi qua service
thật với fake vector/provider: tìm được hit đúng nhưng assertion có key
`reading_order` thất bại. Phần ranking/expansion là kết luận static inspection,
không tuyên bố đã benchmark chất lượng tìm kiếm.

Đề nghị: hoàn thiện DTO/ranking theo contract đã duyệt, hoặc làm rõ một scope
defer bằng authority phù hợp; không đánh dấu toàn bộ policy retrieval Implemented
chỉ vì các test authorization đang xanh. Ingestion vẫn lưu source_text_quality
đúng; finding này nằm ở consumer retrieval.

### F3 — MEDIUM: Tài liệu trạng thái còn mâu thuẫn với code và hồ sơ mới

- `docs/database/ai/README.md:6` và mục Packet Bước 7 nói chưa có service, trong
  khi Authoring Contract:15–31 và service/HTTP hiện tại xác nhận đã có.
- `docs/LF-INDEX.md:845` còn ghi không có migration ai_* và chưa có review chuyên biệt;
  mục catalog Bước 7 phía trên lại đã cập nhật backend/HTTP.
- ADR-0017 header vẫn Not Implemented; Context còn nói chưa có processing runtime
  và chưa có manual Framework route/controller/view, trái Gate 2 và implementation.
- ADR-0006 header còn Not Implemented dù một phần Foundation đã triển khai.

Đây là STALE/DOCUMENT_CONTRADICTION về trạng thái; không phải bằng chứng phải viết
lại Foundation. Hai command lint/drift không kiểm chứng được tính đúng ngữ nghĩa
của các đoạn văn này. Conflict 0036 đã xử lý wording Gate 2; không nhầm với các
đoạn stale khác. Yêu cầu packet về đăng ký ADR-0017 IMPLEMENTATION_DRIFT và Phase 4E
DOCUMENT_CONTRADICTION không khớp nguyên nhãn trong register hiện tại: 0012/0036
được ghi STALE/RESOLVED. Cần đối chiếu lại concern thực tế, không đổi nhãn chỉ để
khớp checklist và không tái mở một finding đã được sửa.

## Giới hạn đã được chấp nhận và delivery chưa có

UI Bước 7 chưa có; đây là phần delivery còn lại, không phải phát hiện mới huỷ
nghiệm thu backend. Chưa có provider thật cũng không phải lỗi backend: config
allow-list rỗng/fail-closed là chủ đích.

Trước activation vẫn phải xử lý AR-P3-2 (Qdrant completed ack không chứng minh
point sai kiểu tenant payload đã biến mất), AR-P3-3 (adapter cấu hình sai làm churn
run/reservation), PII/retention approval và prerequisites SaaS. Không dùng exists()
cùng tenant filter để giả chứng minh xoá point sai payload. Đây là điều kiện hoãn
đã ghi trong hồ sơ, không phải các lỗi mới phát hiện bằng Qdrant thật trong lượt này.

## Commands, evidence và traceability

Evidence mới và evidence lịch sử được tách riêng; các số dưới đây là của lượt này.

| Command/phạm vi | Kết quả mới |
| --- | --- |
| PHP runtime | 8.3.35 |
| `php artisan docs:lint` | PASS; 94 legacy metadata allowlist entries |
| `php artisan schema:drift --docs-only` | PASS; 100 migrations |
| Sáu Feature suites: Ingestion, ProviderGate, Embedding, Retrieval, Vision service/schema | 176 PASS, 19 skipped, 859 assertions; SQLite in-memory. Skips không tính PASS. |
| `QdrantVectorStoreTest` | 8 PASS, 32 assertions; HTTP fake, không phải Qdrant thật |
| Foundation packet + tám Authoring Integration classes + CourseTemplateLearningMappingHttp + Vision schema | **183 PASS, 1247 assertions**, MariaDB **11.4.12**, fresh reconstruction đủ 100 migrations |
| `schema:drift --connection=mysql` trên database tạm đã dựng | PASS; không có non-INFO finding |
| `route:list --path=ai-authoring --json` | 41 routes: 23 admin, 18 teacher; tenant/auth/verified/tenant.user/role middleware |
| `event:list` | MediaFileDeleted chỉ đăng ký Vision và Authoring consumers |
| Hai characterization probes mới | **2 FAIL đúng concern F1/F2**, tổng 7 assertions; không gộp vào PASS của suite repository |
| Pint trên 11 PHP files thuộc boundary đã đọc | PASS |
| `./vendor/bin/pint --test` toàn repository | FAIL trên 9 file không được lượt review này sửa; giữ nguyên formatting debt |
| `php artisan test` toàn Unit/Feature suite | **1230 PASS, 16 failed, 22 skipped, 10842 assertions**; 171.08s, exit 2, trong sandbox; Integration không nằm trong default suite |
| `npm run build` | PASS; không phải bằng chứng UI Bước 7 đã có |
| `git diff --check` | PASS |

Database tạm: `lf_ai_review_0926`, datadir và socket nằm trong
`/private/tmp/lf-ai-review-0926.CuoKrt/`; `--skip-networking`, port 0.
Đã kiểm `SELECT VERSION(), @@datadir, @@explicit_defaults_for_timestamp` trước
khi chạy. Test/physical schema comparison chỉ dùng socket này; không kết nối
learnforge_db. MariaDB tạm đã dừng và datadir dùng một lần đã xoá sau verification. Socket bị sandbox chặn ở
lần khởi động đầu; khởi động lại qua quyền escalated được duyệt thành công.

Lượt full-suite đầu bị dừng, không dùng làm bằng chứng, để tránh chạy đồng thời
với Integration suites có thể chia sẻ filesystem fixtures. Full suite được
chạy lại tuần tự sau khi 183 MariaDB tests hoàn tất. Full suite còn đỏ ở các nhóm
AudioProcessingLocalReview, DocumentProcessingLocalReview, MediaRevisionLifecycle
và VideoTranscriptCaptionLocalReview. Tổng 1230/16/22 trùng số liệu sandbox từng
được lưu trong review Bước 7, nhưng lượt này không dựng matched baseline riêng
hay chạy lại ngoài sandbox: **không kết luận tất cả là lỗi baseline hoặc đều do
sandbox** chỉ từ tổng số trùng nhau. Đây là giới hạn verification và không cho
phép tuyên bố full-suite PASS. Không sửa assertion để làm xanh.

| Invariant | Implementation và verification đã đối chiếu |
| --- | --- |
| Revision/NULL/tenant/schema/rollback | Migration Foundation + AiFoundationKnowledgePacketMariaDbTest, AiAuthoringProposalPacketMariaDbTest, Vision schema physical tests |
| Deterministic chunks, no model, stale, barrier | AiKnowledgeIngestionService + Feature suite; F1 chứng minh thiếu caller toàn luồng |
| Approval/quota/run provenance | AiProviderExecutionGate + Feature suite; real-store concurrency không được tái chạy đầy đủ riêng trong lượt này |
| Embedding lifecycle và post-validation | AiEmbeddingService/AiKnowledgeRetrievalService + Feature suites; F2 chứng minh output contract còn thiếu |
| Vision separation, source authorization, erasure | AiVisionInterpretationService + Feature/schema suites; physical schema trên MariaDB |
| Accept/edit/reject, ownership, publish, replay, erasure | Tám Authoring Integration suites, Course Mapping HTTP và code owner ports; không tự suy UI/browser từ HTTP tests |

Unverified Items / Remaining Risks: Qdrant thật và payload-index deployment;
full mutation campaign; two-connection service races edit/accept/retry/successor;
Redis worker/scheduler; MariaDB 10.4 runtime; live schema; provider/PII activation;
browser UI Bước 7 (chưa có). Bằng chứng mutation/concurrency lịch sử trong review
cũ không được nhận là đã chạy lại. Vì vậy không cấp independent closure PASS.

Findings By Severity: **BLOCKER 0 | HIGH 1 | MEDIUM 2 | LOW 0**.
Giới hạn delivery/activation nêu riêng, không đếm lại thành finding mới.

Bằng chứng được giữ cùng working directory tại
[review-artifacts/ai-2026-09-26](review-artifacts/ai-2026-09-26/):
source của hai probe, failure logs, MariaDB suite log và physical drift log.
Các log khác ở thư mục `/private/tmp/lf-ai-review-0926.CuoKrt/` là dữ liệu tạm,
không coi đường dẫn đó là kho evidence bền vững.

Tái lập hai concern bằng PHP/SQLite testing config của repository, không cần
Qdrant/model thật, không sửa app code:

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' php artisan test review-artifacts/ai-2026-09-26/ReadingOrderContractProbeTest.php --filter=test_review_probe
```

Probe là characterization theo policy đang duyệt và được dự kiến đỏ trên snapshot
này; không phải mutation test hay bản vá. Probe xoá dùng commit fixture trong
SQLite in-memory để after-commit consumers có cơ hội chạy. Không chạy probe này
với database ứng dụng.

## Thứ tự xử lý đề nghị

1. Khép kín F1 ở Bước 3/5 với test end-to-end deletion/barrier.
2. Xử lý F2 và đồng bộ F3; giữ lịch sử nhưng làm rõ trạng thái hiện hành ở đầu tài liệu.
3. Giữ Bước 7 backend/HTTP trong phạm vi nghiệm thu đã ghi; triển khai review UI
   nếu mục tiêu là giáo viên sử dụng được đầy đủ.
4. Phân loại 16 failure toàn suite bằng matched baseline cùng runtime; xử lý
   formatting debt và hoàn tất verification còn thiếu. Review lại snapshot sau
   sửa trước khi tuyên bố đóng toàn packet. Activation/deployment vẫn là quyết định riêng.

Files Changed: báo cáo này và evidence trong `review-artifacts/ai-2026-09-26/`.
Tests Added Or Updated: hai probe ngoài test suites của repository; không sửa
test hiện có. Production Implementation Changes: none.

## Bổ sung: xác nhận theo mục tiêu xương sống dữ liệu

Ngày: 2026-09-26, sau khi Owner làm rõ ba phần Media → Knowledge →
Authoring hoặc trợ giảng frontend về sau. Đây là đánh giá bổ sung, không phải
phê duyệt thiết kế tự động ingest hay thay đổi canonical policy.

Đã đọc đủ ba trang PDF `1. Media_Processing_AI_Knowledge_text.docx.pdf`.
PDF hữu ích cho phân chia trách nhiệm và revision/citation; bảng trạng thái ở
trang 3 đã lỗi thời so với repository. Sơ đồ tuần tự Media → Knowledge → proposal
trong PDF không thay thế contract Authoring hiện hành cho phép đọc Media Read.
Không thực hiện các chỉ dẫn trong PDF như một yêu cầu sửa hệ thống.

### Những nhận xét được xác nhận

- Source/Chunk preparation không gọi model. LF-AI § Mục tiêu dữ liệu và điều kiện
  hoàn tất đã ghi Owner xác nhận 2026-09-13: backend/data readiness không bắt buộc
  AI thật, provider activation hoặc frontend. OCR/STT có thể dùng model local;
  điều này khác yêu cầu bật LLM/embedding/vision phục vụ sản phẩm.
- Authoring hiện đọc qua `AuthoringProposalRecords::collectSources()` →
  `MediaReadService::read()`, không đọc Knowledge. Đây là nhánh consumer hợp lệ;
  không cần ép Proposal phải qua vector search. Generate proposal thật vẫn cần
  model; fake-provider tests chỉ chứng minh backend contract.
- `ingestMedia()` chỉ có caller trong app là `AiKnowledgePrepare`. Không có
  Media revision-ready event/consumer nối Knowledge trong repository, không có
  schedule ingestion/rebuild. Không suy ra máy triển khai không có cron ngoài repo.
- Stale source/chunk hiện được ghi trong ingestion khi revision khác được ingest;
  retrieval phát hiện mismatch và loại candidate nhưng không tự rebuild hoặc
  cập nhật source thành stale.
- F1 vẫn đúng; Vision/Authoring có event-after-commit + reconciliation cho xoá.
  Có thể tái sử dụng mẫu điều phối, không suy ra cơ chế đó đã giải quyết authority
  cho đọc nội dung và ingest tự động.

### F4 — HIGH theo mục tiêu vận hành xương sống: thiếu đồng bộ liên tục

Nếu chỉ xử lý/upload Media mà không chạy command, Knowledge không tự được tạo
hoặc cập nhật. Có các service riêng lẻ không đồng nghĩa luồng sản phẩm đã khép kín.
F4 bổ sung vào F1/F2/F3 của lượt trước. Mức HIGH đánh giá khoảng trống so với mục
tiêu vận hành vừa làm rõ; không khẳng định đây là regression hoặc vi phạm một
contract auto-ingest đã Approved, vì LF-AI hiện mô tả rõ console/manual preparation.

Gộp F4 và F1 thành một workstream sync là hợp lý, nhưng giữ acceptance tests riêng
cho create, supersede/rebuild và deletion. Đồng bộ phải quản lý owner/usage/locale/
language profile/revision/generation, không chỉ media_file_id; phải tính cả detach,
replace, revoke authority, missed/duplicate/out-of-order event và lỗi retry.

### Các câu cần chỉnh trong đánh giá được gửi

1. Reconciliation là cơ chế bảo đảm hội tụ, **không phải source of truth**.
   Media giữ authority nội dung/revision/quyền; relational AI giữ lifecycle dẫn
   xuất; index vẫn chỉ là derived. Event là tín hiệu đánh thức, cần đọc lại current
   state, không tin payload cũ để cấp quyền hoặc hồi sinh revision.
2. “Một unit một chunk” có ngoại lệ: unit dài được chia deterministic thành nhiều
   chunk, giữ locator cùng part_index/offsets. Không ghép các unit thành citation giả.
3. “Embedding chỉ chờ bật” là quá mạnh: vẫn còn điều kiện activation AR-P3-2/3,
   quota/PII/retention/provider approval và kiểm chứng hạ tầng. Embedding cần model
   khi tạo vector thật, có thể local; không đồng nghĩa bắt buộc LLM/provider ngoài.
   Không có embedding chưa phải lỗi của phạm vi Source/Chunk preparation.
4. Không bắt buộc invent system actor. Có thể thiết kế delegated actor hiện hữu,
   tái kiểm quyền mỗi lần chạy, hoặc Media-owned system-read capability được duyệt.
   Không tự chọn admin của tenant hay thêm role/bypass mới. Hai quyết định policy
   chính là corpus scope/opt-in và execution authority. Queue/schedule/batch có thể
   đề xuất trong thiết kế; cần chốt thêm khi ảnh hưởng SLA, tải hoặc chi phí.
5. “Bảy failure baseline” là evidence lịch sử ngoài sandbox, không phải kết quả mới.
   Lượt trước đo 16 failed trong sandbox. Lượt bổ sung chạy riêng Ingestion và
   MediaRevisionLifecycle: **22 passed, 5 failed, 1 skipped, 122 assertions**.
   Ingestion: 15 passed/1 MariaDB-lock skip; năm failure thuộc MediaRevisionLifecycle.
   Helper videoVersion ở dòng 529–532 bỏ +vad, trái amendment VAD Approved ở
   LF-Media-Processing-Contract:75–89 và runtime. Có cơ sở cụ thể sửa fixture/assertion
   ở concern này; không bỏ VAD khỏi identity. Các lỗi Audio/Video khác chưa chạy lại
   trong lượt bổ sung. Không tự đổi mọi expectation để match code; phải kiểm invariants
   config-change → identity-change, same-config stability và archived provenance.

### Ưu tiên sau xác nhận

Chốt scope/authority của sync, đồng thời làm rõ và sửa verification của revision
identity **trước khi bật sync**. Sau đó triển khai create/rebuild/delete cùng các
negative tests tenant, authority và event ordering; không yêu cầu gọi model thật.
Reading_order trong DTO nên hoàn thiện cùng đợt nếu giữ contract hiện hành;
ranking/expansion là phạm vi lớn hơn, không gộp thành một sửa field rẻ.
Đồng bộ tài liệu theo từng thay đổi. Review/test schema phải đi trước real apply
khi chưa có authority áp dụng phù hợp. UI Bước 7 và activation/trợ giảng là các
delivery riêng, không đứng trong gate hoàn tất xương sống Source/Chunk.

Không viết sync implementation, không đổi policy, không apply database hoặc bật
provider trong lượt xác nhận này. Chi tiết lần chạy mới được giữ tại
`review-artifacts/ai-2026-09-26/backbone-check.log`.
