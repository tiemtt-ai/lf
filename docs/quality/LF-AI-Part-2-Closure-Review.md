# AI Part 2 — Final Independent Closure Review

Version: 1.2

Document Status: Review

Implementation Status: Partial

Last Updated: 2026-09-28

Document Path: quality/LF-AI-Part-2-Closure-Review.md

Reviewer: Codex — tư cách độc lập của §1–10 thuộc các lượt lịch sử; tác nhân hiện tại đã tham gia implementation sau round 2 và không đủ tư cách ký round 3 (xem §11).

Snapshot: `d7bf85fc3c40479d4c38bda0224d8305910f77d2` + working tree được chụp SHA-256 trước kiểm chứng.

Final Verdict: **BLOCKED** — round 3 chưa được review độc lập; kết luận kỹ thuật được kiểm chứng gần nhất vẫn là **CHANGES REQUIRED** ở §10.

Findings còn mở sau round 2: **BLOCKER 0 | HIGH 0 | MEDIUM 1 | LOW 0**

Current review: **Round 3 — 2026-09-28: BLOCKED do tư cách độc lập**, xem §11. §1–10 giữ nguyên lịch sử; không chuyển remediation thành PASS khi chưa có reviewer đủ tư cách tự kiểm chứng.

## 1. Kết luận và phạm vi chữ ký

Phần 2 chưa đủ điều kiện đóng. Hai probe độc lập phát hiện: một race có thể ghi đè model run đã `completed` thành `failed` (C1), và thiếu safety policy lại được coi là cho phép (C2). Có thêm drift nội dung tài liệu (C3) và khoảng trống regression cho fingerprint (C4). Không vá finding trong lượt này.

Bằng chứng tích cực đáng kể: toàn bộ **34 file** `integration-mysql` của snapshot chạy trên MariaDB 11.4.12 dựng mới đạt **551 PASS, 1 skip, 0 lỗi, 2.947 assertions**; 3 probe riêng về xoá xuyên nhánh/fingerprint đạt 50 assertions. Toàn suite mặc định đạt **1.269 PASS, 40 skip, 1 failure**, không được ghi thành PASS toàn suite. Failure còn lại là precondition STT local không có trong bản sao; chi tiết §8.

Đã đọc brief bắt buộc, README/INDEX, Guardrails và các ADR/contract liên quan. Hồ sơ implementer và các review trước chỉ dùng để định vị yêu cầu, không kế thừa số test hay waiver làm bằng chứng PASS. Reviewer không thay đổi code, migration, test hoặc tài liệu canonical; file mới trong repository của lượt này chỉ là báo cáo này.

Chữ ký chỉ bao phủ backend Phần 2 bước 0–7 trên snapshot: Foundation/Learning prerequisites, Knowledge sync, gate/quota, embedding/retrieval, Vision, Authoring backend + HTTP và tương tác xoá Media/publish. Không chứng nhận UI/Phần 3, model thật, provider activation, trợ giảng, ranking modifier đã hoãn, hoặc apply database thật.

## 2. Snapshot, môi trường và an toàn

`<COMMIT>` trong yêu cầu chưa được thay bằng hash cụ thể; reviewer ghi HEAD thực tế ở trên và khóa nội dung working tree bằng SHA-256. Trước lượt review đã có thay đổi catalog `docs/LF-DOCUMENTATION-MANIFEST.json`, `docs/LF-INDEX.md`, `docs/quality/README.md` và brief chưa tracked. Không nhận những thay đổi sẵn có đó là công việc của reviewer.

Bản sao vật lý: `/private/tmp/lfclosure.j4victsz/snapshot`; evidence root ký hiệu **R** bên dưới là `/private/tmp/lfclosure.j4victsz`. `vendor` được copy vật lý, kiểm cuối không có symlink. Không copy `.env` thật; wrapper `R/run.sh` dùng `.env` trống, key thử nghiệm, SQLite memory hoặc socket tạm được chỉ định rõ. Không dùng model/provider thật, không thêm credential provider và không gửi dữ liệu ra mạng. HTTP/vector/provider dùng fake; Qdrant live URL để trống. LibreOffice/PDF/FFmpeg của test Media là công cụ local, không phải activation AI.

MariaDB: binary Homebrew 11.4.12; datadir `R/db`, socket `R/mysql.sock`, pid-file `R/mysql.pid`, `--no-defaults --skip-networking`; database **lf_closure_review**; `DB_HOST=localhost`, `DB_PORT=0`, `DB_SOCKET` trỏ đúng socket này. Đã tự đọc `@@datadir`, `@@socket`, `@@skip_networking=1`, VERSION và ledger 100 migrations trên instance riêng. Không kết nối `learnforge_db`, `127.0.0.1:3307` hay XAMPP `:3306`.

Instance thử nghiệm dùng `innodb_flush_log_at_trx_commit=2`; đây là kiểm chức năng, constraint và row lock, không phải kiểm durability khi mất điện. Không chạy song song các suite/probe/mutation dùng chung fixture/storage/database. Bootstrap riêng của probe/mutation chỉ tái sử dụng schema sau integration; **hai full suite không dùng cách bỏ qua migrate này**.

Kết thúc: shutdown trả exit 0; process server trả exit 0; socket và pid-file biến mất; datadir và thư mục tmp của server đã xoá. Chỉ giữ bản sao source và evidence để tái lập.

SHA-256 manifest toàn bộ 1.028 file snapshot: `c2c29c73af6e4419143fd8fdaa4eaab62677816a585773ac245925afd19f442b`. Kiểm cuối: **1.028/1.028 file gốc và 1.028/1.028 file bản sao khớp**, mọi mutation khôi phục byte gốc. Các hash chính:

| File | SHA-256 trước = sau |
| --- | --- |
| [LF-AI-Part-2-Closure-Reviewer-Brief.md](/Applications/XAMPP/xamppfiles/htdocs/LF/docs/quality/LF-AI-Part-2-Closure-Reviewer-Brief.md) | `46ccabaf453f1307197765fc213b99920f5ad0f4de1e86c1662e6552e5dbd1e8` |
| [AiProviderExecutionGate.php](/Applications/XAMPP/xamppfiles/htdocs/LF/app/Services/AiProviderExecutionGate.php) | `aa29794378817fa8b78354d669b44cb51afd2a92085002c5bc4e5c23c8eee83a` |
| [AiModelRunRecorder.php](/Applications/XAMPP/xamppfiles/htdocs/LF/app/Services/Ai/AiModelRunRecorder.php) | `07f848ccbc5921669d5ab1decdeb18382f6f4e2eb86319cb4e692a24b53ab492` |
| [DatabaseUsageQuotaReserver.php](/Applications/XAMPP/xamppfiles/htdocs/LF/app/Services/Ai/DatabaseUsageQuotaReserver.php) | `35da418da04bce460a94475d9171c2470d881250402143d65cc37034a24747b5` |
| [AiKnowledgeSyncService.php](/Applications/XAMPP/xamppfiles/htdocs/LF/app/Services/AiKnowledgeSyncService.php) | `d7f987e91cc0140d9017ac087d42deb6da4f91df5ffb856935add6cca6d85a9e` |
| [AiKnowledgeIngestionService.php](/Applications/XAMPP/xamppfiles/htdocs/LF/app/Services/AiKnowledgeIngestionService.php) | `e7528330ebf76c4517fce4db979f4ceaa84c1fee5cff9512e168b70a800ef619` |
| [AiEmbeddingService.php](/Applications/XAMPP/xamppfiles/htdocs/LF/app/Services/AiEmbeddingService.php) | `664d4b51878d3ea4f74d6de4d50e6214b650c25b3f8c5a938294298da03971e0` |
| [AiKnowledgeRetrievalService.php](/Applications/XAMPP/xamppfiles/htdocs/LF/app/Services/AiKnowledgeRetrievalService.php) | `3abd8135d7ad581f2bd459fd5b949ad9714ad33eb444903a0eb72b545c2083ad` |
| [QdrantVectorStore.php](/Applications/XAMPP/xamppfiles/htdocs/LF/app/Services/Ai/QdrantVectorStore.php) | `464fbb0c44b53f222c4ebedfcc4a1ff643004ab49881a0b745a1a267e0e0f6b6` |
| [AiVisionInterpretationService.php](/Applications/XAMPP/xamppfiles/htdocs/LF/app/Services/AiVisionInterpretationService.php) | `ca5bef8f5ed16701c707ef06f4857567bd7595cc782adc3fa843326aa94394b4` |
| [AiAuthoringProposalService.php](/Applications/XAMPP/xamppfiles/htdocs/LF/app/Services/AiAuthoringProposalService.php) | `8e5ede418a0b557368e03e858f6bf82970a8f815d2068504c00cfcd445b83bd5` |
| [AiAuthoringPublicationService.php](/Applications/XAMPP/xamppfiles/htdocs/LF/app/Services/AiAuthoringPublicationService.php) | `960e36146a1372f78d145f292a2e2276a02ac156ec61a750ff34f56c97d64bce` |
| [AiAuthoringHttpReadService.php](/Applications/XAMPP/xamppfiles/htdocs/LF/app/Services/AiAuthoringHttpReadService.php) | `70b347bfa0701832907f6d53d59803b2871d21081dc337bfa8453e766e8ca01c` |
| [AiAuthoringErasureService.php](/Applications/XAMPP/xamppfiles/htdocs/LF/app/Services/AiAuthoringErasureService.php) | `9528968ecb12ae58f7b6180c88758f2910b78c0ecfb7d441253cba5073c8aa15` |
| [LF-SCHEMA-CONTRACT.json](/Applications/XAMPP/xamppfiles/htdocs/LF/docs/database/LF-SCHEMA-CONTRACT.json) | `b08e943f1cb5acf6f5c1365578b98338b3cb3a6268e459ecf5862415213ac97b` |
| [2026_09_08_000100_create_ai_foundation_knowledge_tables.php](/Applications/XAMPP/xamppfiles/htdocs/LF/database/migrations/2026_09_08_000100_create_ai_foundation_knowledge_tables.php) | `f87b6557b4a62f3f4619d9765067b50c72f63b1008498dc19135d9754881ec65` |
| [2026_09_13_000100_add_ai_embedding_generation.php](/Applications/XAMPP/xamppfiles/htdocs/LF/database/migrations/2026_09_13_000100_add_ai_embedding_generation.php) | `d244f76c98edce52bc6cf485722860f4318adb6f8325a53024f3d5d32410fe2f` |
| [2026_09_14_000100_create_ai_vision_interpretations.php](/Applications/XAMPP/xamppfiles/htdocs/LF/database/migrations/2026_09_14_000100_create_ai_vision_interpretations.php) | `91b4f59ac6cd71ea9ef5b7beb540e5373a2e90573717c47aa5bd48325c18fa8f` |
| [2026_09_15_000100_create_ai_authoring_proposal_packet.php](/Applications/XAMPP/xamppfiles/htdocs/LF/database/migrations/2026_09_15_000100_create_ai_authoring_proposal_packet.php) | `bf48be43d2c218d5fe205f240529b47361da108964fbcc28a9cbaf8a143c1994` |

Evidence tự lập: [gói log, JUnit, probe, mutation và manifest](/private/tmp/lfclosure.j4victsz/closure-review-evidence.tar.gz), SHA-256 `be5b62fddc49e2e61ef63e13a019973ab75539f481ddd4b099a67c37d77d3cb4`. Bản chi tiết rời nằm trong R; gói không chứa datadir, `.env` thật hay vendor. Các file `snapshot-sha256.json`, `mutation-results.json`, `m02-extra-results.json`, `final-integrity.json`, `cleanup.json` giữ toàn bộ đối chiếu, không chỉ prefix hash.

## 3. Bảng 10 điều kiện đóng

PASS bên dưới chỉ áp dụng invariant và ranh giới đã kiểm; không thay cho verdict toàn Phần 2.

| # | Điều kiện | Verdict | Bằng chứng reviewer tự tái lập / giới hạn |
| --- | --- | --- | --- |
| 1 | 0a và 0b PASS | **PASS** | Đọc lại boundary Foundation/Learning; Foundation packet 20 test MariaDB PASS; Learning authoring 15 + HTTP 20, runtime 1 + Teacher Judgment 17 PASS. Kiểm identity, tenant FK, CHECK, generation, quyền admin, draft-only và one-way publish. Câu Gate 2 cũ hiện được đánh dấu lịch sử rõ ở hồ sơ Phase 4E:894; không lấy waiver thay chữ ký. Không mở rộng sang toàn bộ bảng AI còn Proposed. |
| 2 | Docs/schema/migration/implementation không drift | **CHANGES REQUIRED** | `docs:lint` PASS; docs-only drift PASS, 100 migration, 0 findings; physical drift trước/sau probe PASS, không ERROR/WARNING, 48 INFO bảng deferred đúng dự kiến. Nhưng prose hiện trạng mâu thuẫn tại C3. |
| 3 | Ingestion/rebuild/delete idempotent | **PASS** | Ingestion 16, sync 31 + 3 MariaDB, embedding lifecycle và erasure suites PASS. Probe xuyên nhánh lặp reconcile cho cùng Media giữ snapshot ổn định, không nhân bản nguồn/đề xuất; rollback không chạy erasure. |
| 4 | Không trộn revision/tenant | **PASS WITH DOCUMENTED RISKS** | Composite FK/CHECK thật; retrieval kiểm riêng từng guard revision, actor/assignment và usage slot; tenant denial của Vision/Authoring/HTTP. Probe fingerprint-only bị từ chối ở code nguyên gốc. M01/M03/M09/M10/M13 bị bắt; C4 là nợ regression, không phải bằng chứng code hiện tại đang trộn nguồn. |
| 5 | Delete barrier được chứng minh | **PASS WITH DOCUMENTED RISKS** | MariaDB test khóa `FOR UPDATE`, source/chunk tombstone và generation; probe cùng một Media qua cả ba nhánh, vector chưa ack thì giữ content, ack rồi mới xóa; missed event được reconcile. Qdrant thực và payload sai kiểu chưa chứng minh; giữ AR-P3-2 trước activation. |
| 6 | Provider/quota fail-closed | **CHANGES REQUIRED** | 75 test gate/quota MariaDB PASS, gồm hai process khóa entitlement và revalidation/claim; M06/M07 bị bắt. Tuy nhiên C2 cho phép khi thiếu safety policy; C1 làm sai terminal audit trong nhánh execute không truyền prior. Không quan sát double-spend. |
| 7 | Retrieval luôn post-validate authorization | **PASS** | 30 test retrieval MariaDB PASS; source/chunk active, embedding ready, tenant, revision, actor/assignment, usage slot và audit fail-closed. M03/M12 bị bắt. Kết luận cho nguồn Media hiện được hỗ trợ, không cho non-Media extension AR-P3-7. |
| 8 | Vision provenance độc lập, không ghi Media evidence | **PASS** | 34 service + 23 schema test MariaDB PASS; anchor Media được copy, run linkage, page/bbox, input không vào provenance log, revalidation khi đọc, erasure sau commit. M09 đổi fingerprint bị bắt. Access log do Media sở hữu vẫn được ghi đúng boundary. |
| 9 | Proposal/human approval; accept ≠ publish; Mapping chỉ qua publish | **PASS** | 98 test Authoring backend/HTTP + 24 packet/apply-safety PASS; teacher sửa/duyệt, owner ports, successor/rebase, freshness, 41 route và cursor. Course promotion 12 test bổ sung PASS; M08/M14 bỏ freshness/published-framework bị bắt. Canonical Mapping chỉ ở call path Course publish → Learning promotion; không có đường AI ghi trực tiếp. |
| 10 | Mutation bảo vệ các invariant bắt buộc | **PASS WITH DOCUMENTED RISKS** | 14 mutation khác nhau: 13 bị regression hiện có bắt; M02 sống qua cả 16 test ingestion, nhưng bị probe riêng bắt. Sau restore: 23 test/181 assertions PASS, SHA khớp. C4 cần đưa case phân biệt fingerprint vào regression chính thức; private probe không tự trở thành test CI. |

## 4. Findings

### C1 — MEDIUM — Read/check/write model run có thể mở lại attempt đã completed

Vị trí: [app/Services/Ai/AiModelRunRecorder.php](/Applications/XAMPP/xamppfiles/htdocs/LF/app/Services/Ai/AiModelRunRecorder.php:120) và [app/Services/AiProviderExecutionGate.php](/Applications/XAMPP/xamppfiles/htdocs/LF/app/Services/AiProviderExecutionGate.php:134). Contract terminal: [docs/database/ai/ai_model_runs.md](/Applications/XAMPP/xamppfiles/htdocs/LF/docs/database/ai/ai_model_runs.md:120).

`record()` đọc trạng thái, gọi `assertTransition()` trên object đã đọc, rồi UPDATE theo id mà không khóa hoặc so sánh trạng thái hiện hành. Nhánh `execute(request, factory)` không truyền `prior` authorize ngoài transaction khóa run; khóa của nhánh có prior không bảo vệ cửa sổ này.

Probe `R/recorder-race.php` dùng **hai connection thật 636 và 637**, real `DatabaseCommercialEntitlements`/`DatabaseUsageQuotaReserver`, một spy adapter, cùng request/correlation/run UUID. Dùng `DB::listen` để dừng caller A ngay sau SELECT queued và cho B chạy xong trên connection khác, rồi cho A tiếp tục. Đây là interleaving tất định tại đúng cửa sổ read/write, không phải stress test ngẫu nhiên:

```text
B: queued → running → completed, allowed=true
A dùng snapshot queued cũ: completed → queued → running → failed
A trả AI_PROVIDER_CALL_FAILED
provider_calls=1; reservation=committed; usage_events=1
final ai_model_runs.status=failed; số token/latency của lần thành công vẫn còn
```

Tác động đã chứng minh: audit thành công bị gắn thành thất bại và trạng thái terminal bị mở lại; người gọi/reconciliation không thể tin lifecycle. **Không** chứng minh double-call, double-spend hay delete barrier kẹt vô hạn. Các consumer embedding/Vision/Authoring hiện truyền prior; probe nhắm nhánh public API không truyền prior, không khẳng định lỗi đã xảy ra trên cả ba consumer đó.

Lần probe đầu dự đoán trạng thái cuối `running` nên exit 2; output thực tế đã cho `failed` (release của committed hold là no-op). Giữ nguyên log/script ban đầu; sửa assertion của **probe riêng**, chạy lại với tenant mới, thu chuỗi trạng thái trên và exit 0. Không sửa production code. Exit 0 ở đây có nghĩa **tái lập được lỗi**, không phải PASS gate.

Đề nghị: serialize hoặc conditional-update phần kiểm/chuyển trạng thái trên đúng tenant/run; caller thua không được ghi đè terminal outcome. Regression cần tái lập cửa sổ hai connection ở nhánh không prior, giữ completed/usage nguyên vẹn và từ chối caller thua trước provider factory. Bằng chứng: `recorder-race.log`, `recorder-race.initial.log` và script trong gói.

### C2 — MEDIUM — Thiếu safety policy được diễn giải thành cho phép

Vị trí: [app/Services/AiProviderExecutionGate.php](/Applications/XAMPP/xamppfiles/htdocs/LF/app/Services/AiProviderExecutionGate.php:312). Yêu cầu: [docs/platform/LF-AI.md](/Applications/XAMPP/xamppfiles/htdocs/LF/docs/platform/LF-AI.md:198) (thiếu bất kỳ điều kiện nào phải không gọi provider và ghi blocked).

`safetyVerdict()` cast cấu hình thiếu thành mảng rỗng, nhận danh sách cấm rỗng, rồi khi `max_retention_class` không có thì trả `allowed=true`. Probe `R/safety-missing.php` giữ allow-list, tenant approval và entitlement/quota hợp lệ, dùng request chỉ mang classification `personal_data` và provider giả:

| Cấu hình | Kết quả tự quan sát |
| --- | --- |
| Policy mặc định hiện có | `allowed=false`, `AI_SAFETY_BLOCKED`, run blocked |
| `config(['ai.safety' => null])` | `allowed=true`, run completed, provider giả được gọi 1 lần |

Không phải một policy allow-all được review: toàn bộ policy bị thiếu. Probe dùng quota ledger thật, không gửi dữ liệu thật hoặc gọi model. Shipped default hiện an toàn và provider chưa activation; finding là hành vi fail-open khi cấu hình thiếu, không phải bằng chứng đã rò dữ liệu trong môi trường Owner.

Đề nghị: validate sự hiện diện/shape của policy hợp lệ; thiếu hoặc sai cấu hình phải từ chối trước adapter, ghi safety-blocked và trả reservation chưa sử dụng. Thêm regression cho missing default/purpose policy và policy malformed. Bằng chứng: `safety-missing.log` và script, exit 0 xác nhận lỗi.

### C3 — LOW — Mô tả hiện trạng triển khai và quota chưa đồng bộ

Các vị trí đã tự đọc:

- [docs/adr/ADR-0006-AI-Foundation.md](/Applications/XAMPP/xamppfiles/htdocs/LF/docs/adr/ADR-0006-AI-Foundation.md:27) nói backend authoring “remains Not Implemented”, trong khi cùng ADR:41–44 mô tả đã triển khai. Amendment có ngày lịch sử nhưng câu trạng thái chưa được đánh dấu rõ là lịch sử.
- [config/ai.php](/Applications/XAMPP/xamppfiles/htdocs/LF/config/ai.php:213) nói chưa domain nào sở hữu reservation và key trỏ counter; LF-AI:212–228 cùng code binding hiện tại đã dùng Commercial reservation ledger. Test real-store của reviewer cũng chạy thành công.
- [docs/platform/LF-AI.md](/Applications/XAMPP/xamppfiles/htdocs/LF/docs/platform/LF-AI.md:113) vẫn nói chưa apply migration, trong khi đoạn hiện trạng khác đã cập nhật claim schema dev ngày 2026-09-27. Đây là mâu thuẫn nội dung giữa các phát biểu của tài liệu, **không phải** reviewer xác nhận trạng thái thật của `learnforge_db`.

`docs:lint` và schema drift không kiểm nghĩa các câu này nên PASS không đóng finding. Đề nghị đánh dấu nội dung cũ là lịch sử hoặc sửa mô tả hiện tại cho nhất quán; reviewer không sửa canonical docs/config comment.

### C4 — LOW — Regression mixed-revision không tách được fingerprint khỏi version

Vị trí: [tests/Feature/AiKnowledgeIngestionServiceTest.php](/Applications/XAMPP/xamppfiles/htdocs/LF/tests/Feature/AiKnowledgeIngestionServiceTest.php:176); guard hiện hành tại [app/Services/AiKnowledgeIngestionService.php](/Applications/XAMPP/xamppfiles/htdocs/LF/app/Services/AiKnowledgeIngestionService.php:375).

M02 chỉ bỏ `source_fingerprint` khỏi danh sách khóa được so sánh giữa các unit; giữ nguyên `processing_version` và các guard khác. Test mixed-revision cũ vẫn PASS, rồi **cả 16 test của file ingestion vẫn PASS**. Fixture cũ chỉ đổi processing_version nên guard version còn lại che mutation.

Probe `ClosureProvenanceProbeTest::test_closure_fingerprint_only_mixture_is_refused` giữ nguyên version/media/locale, đổi duy nhất fingerprint của unit thứ hai: code gốc PASS, mutant FAIL tại “Fingerprint-only mixed revision must be rejected”, restore PASS. Vì vậy hiện chưa phát hiện trộn nguồn trong code gốc, nhưng CI của file này chưa bảo vệ độc lập một phần identity.

Đề nghị đưa case fingerprint-only, cùng assertion rollback không tạo source/chunk, vào regression chính thức. Không tuyên bố M02 sống qua toàn repository: reviewer đã chạy **toàn file ingestion**, không chạy full suite cho từng mutant.

## 5. Đánh giá riêng Bước 4/5/6/7

### Bước 4 — CHANGES REQUIRED

Tự chạy gate 42, real quota reserver 13 và quota packet 20 test trên MariaDB: 75 PASS/318 assertions. Test hai process thật quan sát lock timeout 1205 khi entitlement/run bị giữ, rồi từ chối reserve vượt capacity sau commit; không suy concurrency từ SQLite. Đã đọc thứ tự allow-list → tenant approval → entitlement → reservation → safety; adapter factory và credential resolution nằm sau quyết định cho phép. Các test canary exception/factory/ledger và immutable provenance chạy PASS; M06, M07, M11 bị bắt.

Tuy nhiên C1/C2 chứng minh bộ test xanh hiện tại chưa đủ đóng gate/model-run. Quota không double-spend trong probe C1; lỗi nằm ở lifecycle audit. Không coi empty allow-list hiện tại là bằng chứng safety policy thiếu đã được xử lý.

### Bước 5 — PASS WITH DOCUMENTED RISKS trong phạm vi backend; phụ thuộc sửa Bước 4

Embedding 51 case: 50 PASS, 1 live-Qdrant skip; retrieval 30 PASS; Qdrant adapter HTTP-fake 8 PASS. Tổng 88 PASS/1 skip/428 assertions. Tự kiểm lifecycle chỉ dùng pending/ready/failed/stale/deletion_pending/deleted, generation mới không tái dùng vector key cũ, retry giữ history, không requeue writer còn live, partial index failure đi qua purge, và reconcile không resurrect deletion hoặc hoàn tất nhầm attempt.

Relational là nguồn đúng. Retrieval không tin hit của index: tenant, source/chunk active, embedding ready, Media revision/slot hiện tại, quyền actor và audit đều được kiểm trước trả text. M03/M12/M13 xác nhận một số guard không chỉ tồn tại trên giấy. `reading_order` được giữ trong kết quả; không thêm modifier ranking. Search source trong `app`/`routes` chỉ tìm thấy định nghĩa `AiKnowledgeRetrievalService`, chưa có consumer sản phẩm của service này.

| Deferred item | Đánh giá closure Phần 2 |
| --- | --- |
| AR-P3-2 | Vẫn còn rủi ro ack delete của Qdrant với payload tenant sai kiểu. Không gọi `exists()` cùng filter để giả chứng minh absence. Rebuild index đúng keyword/is_tenant hoặc có cơ chế kiểm độc lập trước activation/index production. Không đóng item bằng fake store. |
| AR-P3-3 | Provider không hỗ trợ model có thể mint run/hold mỗi pass. Vẫn cần preflight/backoff trước activation; shipped unavailable provider/allow-list rỗng ngăn đường này trong cấu hình mặc định. |
| AR-P3-7 | Hit non-Media có thể làm hỏng cả lượt retrieval. Ranh giới hoãn chính xác là **trước khi thêm nguồn không gắn Media**, không chỉ trước activation. Ingestion hiện chỉ tạo nguồn Media. |

Ba item được ghi là điều kiện tương lai đúng phạm vi, không tự chặn đóng backend Phần 2; không cấp quyền activation. Chưa test Qdrant thật nên không ký về physical index deletion hoặc khả năng phục hồi Qdrant thật.

### Bước 6 — PASS trong phạm vi diễn giải region và backend

34 service + 23 schema case = 57 PASS/192 assertions trên MariaDB. Model gate/quota pre-check, run_uuid, source fingerprint/processing version và page/bbox được kiểm; refresh supersede slot có unique constraint và transaction; không ghi interpretation vào Media extraction evidence. Read qua Media authorization/revision và audit; từ chối nội dung mất nguồn trong lúc chờ purge. Listener được kiểm sau commit, rollback không erase; reconcile xử lý missed event và không bị file còn tồn tại giữ đầu hàng đợi. M01/M09 bị bắt.

Không chứng minh chất lượng model, PII detector của ảnh, live crop delivery hay video-frame interpretation. Những phần này không được suy thành PASS từ provider giả. Lỗi gate C2 vẫn là dependency phải sửa chung.

### Bước 7 — PASS cho backend/HTTP đã kiểm; phụ thuộc gate chung

7 file service/HTTP (proposal, application, publication, successor, rebase, erasure, HTTP): **98 PASS/939 assertions**; packet + apply-safety thêm **24 PASS/163 assertions**. Course promotion 12 case là bằng chứng bổ sung của owner boundary.

Tự inventory đúng **41 route: 23 admin, 18 teacher**, đều có tenant resolution, auth, verified, tenant-user và role middleware. Tenant được lấy từ context, không nhận từ payload. Test HTTP phủ assignment/role/tenant denial, idempotency, input whitelist, encrypted cursor gắn actor/filter/parent, audit-failure refusal và diagnostic canary chống payload/SQL leak. Đây là HTTP test, không phải browser/visual review.

Generate chỉ tạo proposal/revision/source; teacher sửa rồi accept ghi đúng revision, không publish hoặc ghi canonical Mapping. Application gọi owner ports, tạo draft Node/Intent theo quyền; Course publish cập nhật Course Version và gọi Learning-owned promotion trong transaction, revalidate Framework published/Node active và source/freshness. M08 bỏ freshness và M14 bỏ published Framework đều làm regression đỏ. Successor/rebase không tự rebind “latest”, cần review/fresh references; erasure giữ content-free lineage/audit và xoá payload nguồn.

Knowledge và Authoring cùng đọc Media Read theo thiết kế xương sống; không yêu cầu Authoring đi vòng qua Knowledge trước khi tạo proposal. Việc branch đề xuất dùng Media Read trực tiếp không bị đánh giá là bypass sai.

## 6. Xoá Media xuyên suốt ba nhánh

Reviewer tạo cùng một Media File có Knowledge/chunks/embedding, Vision derivative và Authoring proposal; tạo tenant B tương tự làm đối chứng. Provider và vector store là fake, thao tác schema/transaction/ownership là MariaDB thật. Vision row trong **probe xuyên nhánh** được seed hợp lệ theo schema cho cùng file; đường interpret Vision được chứng minh riêng bởi 34 service test, không gộp hai bằng chứng thành một E2E model thật.

Hai case của `ClosureDeletionProbeTest` tự xác nhận:

1. Rollback Media delete: cả ba nhánh còn sống. Trong outer transaction chưa commit, Vision/proposal chưa bị erase.
2. Sau commit: Vision và Authoring tombstone/erase; Knowledge giữ deletion_pending và chunk content khi vector chưa ack.
3. Sau ack: embedding được purge, source/chunk thành deleted, content NULL.
4. Bỏ job bằng Queue fake, rồi chạy ba reconciler: đạt cùng kết quả; replay giữ snapshot ổn định.
5. Snapshot bảy bảng AI của tenant B không thay đổi.

Cùng probe fingerprint, tổng **3 tests/50 assertions PASS**; chạy lại sau restore trong nhóm 23 test cũng PASS. Đây là proof barrier với adapter fake, không thay thế điều kiện Qdrant physical của AR-P3-2. Worker/scheduler thực với Redis không được chạy trong lượt này.

## 7. Mutation matrix

Mỗi lần chỉ thay một guard trong bản sao R/snapshot; ghi SHA gốc/mutant/restored trong `mutation-results.json`, restore trong `finally`, kiểm hash khớp trước case tiếp. Mọi test đỏ dưới đây là assertion đúng invariant, **0 PHP/SQL execution errors**, không phải lỗi syntax. Test tên đầy đủ và exact replacement có trong `mutation-plan.json`.

| ID | Invariant / thay đổi | Kết quả tự chạy |
| --- | --- | --- |
| M01 | Bỏ customer_id ở Vision deletion request | KILLED: xoá chéo tenant trả 1 thay vì 0 |
| M02 | Bỏ so fingerprint giữa unit ingestion | SURVIVED: targeted test và cả file 16/16 PASS; KILLED bởi probe fingerprint-only (1 failure); C4 |
| M03 | Bỏ so processing_version khi retrieval post-validates | KILLED: riêng dataset “version only” trả hit thay vì [] (3 dataset khác vẫn PASS) |
| M04 | Không stale nguồn revision cũ | KILLED: active thay vì stale |
| M05 | Bỏ source deletion barrier khi còn embedding | KILLED: không còn exception embedding_delete_barrier |
| M06 | Bỏ check capacity trong real quota reserver | KILLED: reserve thứ hai được cấp thay vì null |
| M07 | Bỏ refusal từ safety verdict | KILLED: không có AI_SAFETY_BLOCKED |
| M08 | Bỏ source freshness tại Authoring publication | KILLED: publish thành công khi phải rollback |
| M09 | Ghi fingerprint Vision toàn số 0 | KILLED: anchor không khớp Media Read |
| M10 | Bỏ binding actor/filter/parent của HTTP cursor | KILLED: 200 thay vì 422 |
| M11 | Bỏ kiểm immutable run provenance | KILLED: không ném AiProviderGateException |
| M12 | Bỏ allowed retrieval audit trước trả content | KILLED: audit failure không còn ngăn trả kết quả |
| M13 | Qdrant search gửi tenant “0” thay tenant hiện tại | KILLED: HTTP request contract không khớp |
| M14 | Bỏ điều kiện Framework Version published tại promotion | KILLED: deprecated Framework vẫn publish được |

Kết quả: **13/14 bị test hiện có bắt**, mutation còn lại bị probe reviewer bắt; không che mutation sống sót. C1/C2 là **probe trên code nguyên gốc**, không tính vào tỷ lệ mutation. Sau restore, `restored.xml`: **23 PASS/181 assertions**, không skip/error/failure. File fingerprint có SHA gốc/restored `e7528330ebf76c4517fce4db979f4ceaa84c1fee5cff9512e168b70a800ef619`; mutant `0fc6842cb57d7b48ab5f03bee27b4a6ba6722e7b7bf12832b1d811d443ee417c`.

## 8. Bảng lệnh và kết quả của reviewer

Các lệnh artisan/PHP dưới đây đều qua `R/run.sh sqlite|mysql`, cwd là bản sao, không chạy artisan trên checkout dùng `.env` của Owner. Danh sách integration lấy từ workflow snapshot, lưu nguyên thứ tự trong `integration-files.json`; JUnit đối chiếu theo tên test, không suy từ tổng.

| Lệnh / thao tác | Kết quả | Evidence trong R |
| --- | --- | --- |
| Chụp HEAD/status/SHA; copy source + vendor vật lý | 1.028 file; code snapshot bất biến | snapshot-meta.json, snapshot-sha256.json |
| MariaDB install-db; mariadbd --no-defaults … --skip-networking | 11.4.12, socket/datadir riêng, database lf_closure_review | db-init.log, db-identity.log |
| `php artisan test <34 file workflow> --log-junit=…` | exit 0; 551 PASS, 1 skip; 2.947 assertions; 2.517,92 s | integration.log/xml, integration-result.json, integration-by-class.json |
| `php artisan test --log-junit=…` lần đầu | 1.263 PASS, 40 skip, 7 đỏ; không dùng làm kết luận cuối do lỗi copy mode | default.log/xml, copy-mode-correction.json |
| Cùng full default suite sau khôi phục executable mode | exit 1; 1.269 PASS, 40 skip, 1 failure; 11.017 assertions; 109,70 s | default-final.log/xml, default-final.summary.json |
| `php artisan docs:lint` | exit 0, PASS; 94 entry legacy allowlist được công cụ báo | docs-lint.log |
| `php artisan schema:drift --docs-only --format=json` | exit 0, PASS; 100 migration, 0 findings | docs-drift.log |
| `php artisan schema:drift --connection=mysql --format=json` trước/sau probes | cả hai exit 0, PASS; ledger không pending/missing; 48 INFO deferred, 0 ERROR/WARNING | drift-mysql.log, drift-mysql-final.log |
| `php artisan route:list --path=ai-authoring --json` | 41 route, 23 admin + 18 teacher; middleware kiểm từ output | routes.json |
| PHPUnit private probes, filter test_closure | exit 0; 3 tests/50 assertions | probes-baseline.log/xml |
| `python3 R/mutations.py` | 14 mutations; 13 killed, M02 survived; SHA restore khớp | M01…M14.log/xml, mutation-results.json |
| `python3 R/m02-extra.py` | M02 toàn file 16 PASS; private probe 1 failure đúng fingerprint; restore khớp | M02-full.*, M02-probe.*, m02-extra-results.json |
| `python3 R/run-restored.py` | exit 0; 23 tests/181 assertions PASS | restored.log/xml |
| `php R/recorder-race.php` | exit 0 = tái lập C1; 2 connections, completed bị ghi failed; 1 call/1 usage | recorder-race.log; initial log giữ riêng (§4) |
| `php R/safety-missing.php` | exit 0 = tái lập C2; missing policy gọi spy, configured policy blocked | safety-missing.log |
| `php R/runtime-check.php` (chỉ đọc config/file existence) | STT python và model directory không có trong bản sao | runtime-check.log |
| `python3 R/verify-integrity.py` | Original/copy 1.028 file khớp; 0 vendor symlinks | final-integrity.json |
| mariadb-admin --no-defaults --socket=R/mysql.sock shutdown; xoá R/db | shutdown và process exit 0; socket/PID/datadir đã mất | cleanup.json, db-server.log |

### Đối chiếu test đỏ và skip

Failure còn lại của full default suite là đúng một tên:

`Tests\Feature\VideoTranscriptCaptionLocalReviewTest::test_a_corrupt_video_fails_extraction_without_output_or_workspace_residue`

Tại [tests/Feature/VideoTranscriptCaptionLocalReviewTest.php](/Applications/XAMPP/xamppfiles/htdocs/LF/tests/Feature/VideoTranscriptCaptionLocalReviewTest.php:175): expected `audio_extraction_failed`, actual `provider_unavailable`. Test set provider `faster_whisper_local`; provider preflight tại [app/Services/FasterWhisperSpeechToTextProvider.php](/Applications/XAMPP/xamppfiles/htdocs/LF/app/Services/FasterWhisperSpeechToTextProvider.php:74) kiểm Python/model **trước** extractAudio. `runtime-check.log` xác nhận `runtime/stt/.venv/bin/python` và `runtime/stt/models/small` không tồn tại trong bản sao. Không cài model hoặc gọi STT thật để làm xanh. Đây là hạn chế precondition của môi trường review đối với test Media, không phải bằng chứng regression Phần 2; cũng không được tự gọi là “baseline cũ đã chấp nhận”.

Lượt đầu 7 đỏ có hai mocked-STT errors do `artisan` bị copy từ mode 755 thành 644; đã sửa **mode bản sao**, không sửa byte/test. Lượt chạy cuối tái lập toàn suite và các Office failures lượt đầu không còn. Báo cáo dùng đúng tên failure cuối, không lấy số 7 trong hồ sơ cũ.

40 default skips gồm MariaDB-only constraints/locks, optional Docling/STT/video runtimes và live Qdrant; tên đầy đủ trong `default-final.summary.json`. Những case AI MariaDB-only thuộc 34 file đã được chạy thật trong integration. Test `CourseTemplatePublishConcurrencyTest` bị skip trên SQLite và không nằm trong danh sách 34 file, nên **không ký** cho test này. Integration chỉ skip `AiEmbeddingServiceTest::test_real_qdrant_maintenance_passes_a_broken_collection_and_recovers_it` vì không cấu hình endpoint thật.

## 9. Chưa kiểm và điều kiện để đóng

Chưa kiểm: live Qdrant/index payload thực; provider/model/STT/Docling thật, chất lượng output, image PII; Redis/scheduler/queue vận hành thật; crash/power-loss durability; browser/UI; database dev/production, rehearsal dump hoặc migration apply thật. Không dùng SQLite để chứng minh schema; không dùng skip làm PASS. Probe hai connection là interleaving có kiểm soát, không phải stress/load test tổng quát. Không chạy full suite cho mỗi mutant.

Để ký closure mới:

1. Implementer xử lý **C1/C2**, thêm regression cô lập hai tình huống, reviewer tái lập trên snapshot mới. C1 phải giữ terminal outcome dù caller thua; C2 missing policy phải blocked, không gọi factory/provider, không mất quota.
2. Đóng **C3**, giữ lịch sử nhưng không để câu trạng thái cũ bị đọc thành hiện tại; bổ sung regression fingerprint-only của **C4** vào suite chính thức và chứng minh mutation bị bắt.
3. Rerun các nhóm bị ảnh hưởng, đối chiếu SHA/JUnit; giữ limitation Media runtime và các item AR-P3-2/3/7 bằng đúng ranh giới. Việc không chạy model thật không tự là blocker của Phần 2, nhưng không được tuyên bố full default suite xanh.

**Verdict toàn Phần 2: CHANGES REQUIRED.** Backend đã có bằng chứng độc lập rộng; Owner waiver không còn được dùng thay bằng chứng, và hai lỗi gate/model-run còn mở phải được xử lý trước chữ ký đóng Phần 2.


## 10. Round 2 — Re-review sau remediation 2026-09-27

Ngày thực hiện: **2026-09-28**. Verdict lượt 2: **CHANGES REQUIRED**.

**C1 CLOSED; C2 PARTIALLY CLOSED (MEDIUM còn mở); C3 CLOSED; C4 CLOSED.** Không phát sinh mã finding mới: các biến thể sai hình dạng dưới đây là phần chưa hoàn tất của C2. Chưa đủ điều kiện đóng Phần 2.

### 10.1. Snapshot, tính độc lập và môi trường

Reviewer chỉ review, không viết/vá implementation, migration hoặc test trong repository. Đã đọc brief và phần remediation, đối chiếu hồ sơ implementer nhưng tự chạy lại các lệnh, probe và mutation. HEAD vẫn là `d7bf85fc3c40479d4c38bda0224d8305910f77d2`; đây là snapshot **HEAD + working tree**, không ký riêng nội dung HEAD chưa có remediation. Năm SHA yêu cầu đều khớp:

| File | SHA-256 trước = sau kiểm chứng |
| --- | --- |
| `app/Services/Ai/AiModelRunRecorder.php` | `a82626350d102c413be01bd917dffe881c728ce17a016fbc1d104e5a050528d0` |
| `app/Services/AiProviderExecutionGate.php` | `0e0ebbdfbb24aab75512c841b1927a8b63d2e6573486092c6c9e5c4335796cd5` |
| `tests/Feature/AiProviderExecutionGateTest.php` | `5f196ceac0bdebf8d07d2fc4a00d16cbe25697593909c0bca5027006d7cf57e8` |
| `tests/Feature/AiKnowledgeIngestionServiceTest.php` | `e106487a314141d5fe60db9c6782384352e75a9287ca8d471273391d2a584638` |
| `config/ai.php` | `666011c310372d6ad095f232a7427a19f0ca883bd84cbf4aa1e30eca8a233817` |

Evidence root **R2**: `/private/tmp/lfclosure-r2.xx8ovv7u`; bản sao vật lý `R2/snapshot`, vendor copy vật lý, **0 symlink**. Manifest `snapshot-sha256.json` gồm **1.029 file**, SHA-256 `69c2c37fe9103e864908eacab83b0460b9814e638819fce4fb065f0dfc8c3030`. Kiểm trước khi cập nhật báo cáo: **1.029/1.029 file gốc và 1.029/1.029 file bản sao khớp**, gồm cả những file đã mutation rồi khôi phục. Báo cáo này là thay đổi duy nhất reviewer thực hiện trong repository; các thay đổi working tree trước review được giữ nguyên.

MariaDB **11.4.12**, database **lf_closure_r2**, datadir `R2/db`, socket `R2/mysql.sock`, pid-file `R2/mysql.pid`, `--no-defaults --skip-networking`, `innodb_flush_log_at_trx_commit=2`. Tự đọc VERSION, datadir, socket và `@@skip_networking=1`. Wrapper đặt `DB_PORT=0`, socket tường minh, `.env` trống, provider spy/fake, Qdrant live URL rỗng, proxy trỏ cổng đóng và chế độ model offline. Không kết nối `learnforge_db`, Owner `:3307` hoặc XAMPP `:3306`; không dùng provider/model thật hoặc thêm secret. Các suite, mutation và probe chạy **tuần tự**. Full integration dựng mới toàn bộ 100 migrations; full suite mặc định dùng SQLite memory. Bootstrap tái sử dụng schema chỉ dùng cho mutation có filter, không dùng cho hai full suite.

Các thư mục `/tmp` của lượt trước không còn sau khi môi trường khởi tạo lại. Không tuyên bố đã đọc lại JUnit gốc lượt 1: đối chiếu lịch sử dựa trên tên/count đã ghi ở §8, còn toàn bộ JUnit lượt 2 được tạo mới. Probe race được dựng lại theo đúng interleaving đã mô tả ở §4 (sau SELECT, trước UPDATE), không lấy kết quả cũ thay lần chạy mới.

### 10.2. Trạng thái C1–C4 và bằng chứng độc lập

| Finding | Trạng thái | Bằng chứng lượt 2 |
| --- | --- | --- |
| C1 MEDIUM — race terminal model run | **CLOSED** | `recorder-race.php`: hai connection thật 627/628; caller thứ hai chờ khóa rồi nhận 1205 sau 3,02 giây (timeout 1 giây, transaction retry); caller đầu `queued → running → completed`. Retry sau khi caller đầu hoàn tất nhận `AI_RUN_TRANSITION_CONFLICT`; toàn bộ row terminal không đổi, `error_code=null`, factory/provider mỗi loại 1 call, reservation committed và đúng 1 usage event. Probe dùng Commercial/Usage ledger thật, adapter spy. |
| C2 MEDIUM — safety policy fail-open | **PARTIALLY CLOSED** | Probe 17 cấu hình: 12 đạt oracle, **5 sai hình dạng vẫn allowed**, chi tiết §10.3. Thiếu toàn bộ policy, thiếu trường, scalar forbidden, phần tử không phải chuỗi, ceiling sai kiểu/không biết đều blocked và refund đúng. Nhưng override/container sai kiểu có thể bị bỏ qua để kế thừa default. |
| C3 LOW — prose drift | **CLOSED** | Đối chiếu [ADR-0006:27](/Applications/XAMPP/xamppfiles/htdocs/LF/docs/adr/ADR-0006-AI-Foundation.md:27), [config/ai.php:214](/Applications/XAMPP/xamppfiles/htdocs/LF/config/ai.php:214), [LF-AI:113](/Applications/XAMPP/xamppfiles/htdocs/LF/docs/platform/LF-AI.md:113): trạng thái cũ được ghi là lịch sử; Commercial sở hữu reservation; claim migration được cập nhật. `docs:lint`, docs-only drift và physical drift đều PASS. Đây là kiểm nhất quán tài liệu, không chứng nhận claim database dev của Owner. |
| C4 LOW — M02 thiếu regression | **CLOSED** | Baseline 3 dataset PASS; bỏ riêng `source_fingerprint` khỏi danh sách guard → đúng dataset **fingerprint only** của `test_a_unit_differing_in_one_identity_component_is_rejected_atomically` FAIL, hai dataset kia PASS; khôi phục SHA → cả 3 PASS. Không sửa test canonical. |

C1 kiểm đúng cửa sổ cũ bằng callback đồng bộ trên hai connection. Dùng lock timeout ngắn để caller thứ hai trả về và giải phóng callback; đây không phải stress test nhiều process hoặc bằng chứng caller chờ vô hạn rồi tự tiếp tục. Bằng chứng bổ sung là retry sau commit vẫn bị từ chối trước factory và row completed không đổi. Regression mới và mutation ngược độc lập đều xác nhận vùng đã vá.

### 10.3. C2 còn mở — MEDIUM — Cấu hình override sai hình dạng bị bỏ qua

Vị trí: [AiProviderExecutionGate.php:312–325](/Applications/XAMPP/xamppfiles/htdocs/LF/app/Services/AiProviderExecutionGate.php:312), đặc biệt dòng **314** gộp policy và **322–323** kiểm array nhưng không kiểm list. Test hiện tại: [AiProviderExecutionGateTest.php:212](/Applications/XAMPP/xamppfiles/htdocs/LF/tests/Feature/AiProviderExecutionGateTest.php:212).

Tình huống tái lập nhỏ nhất: tenant có approval và entitlement hợp lệ, quota còn, provider/model nằm trong allow-list thử nghiệm; request `knowledge_embedding`, `derived_text`, retention `transient`. Cấu hình:

```php
'ai.safety' => [
    'default' => [
        'forbidden_data_classes' => ['personal_data'],
        'max_retention_class' => 'transient',
    ],
    'purposes' => ['knowledge_embedding' => 'invalid-policy'],
]
```

Kỳ vọng theo brief remediation: policy đã khai báo nhưng sai hình dạng phải `AI_SAFETY_BLOCKED`, reservation released, factory 0. Thực tế probe `safety-variants.php`: **allowed=true, model run completed, safety_metadata=null, factory=1, provider spy=1, reservation committed, usage_events=1**. `is_array($purpose) ? $purpose : []` biến override lỗi thành không có override, rồi dùng default hợp lệ; việc kiểm hai trường của policy sau gộp không phát hiện lỗi đầu vào này.

| Case riêng trong probe | Cấu hình lỗi | Thực tế |
| --- | --- | --- |
| `purpose_scalar` | `purposes.knowledge_embedding = 'invalid-policy'` | allowed, committed, factory/provider 1 |
| `purpose_false` | `purposes.knowledge_embedding = false` | như trên |
| `purpose_list` | `purposes.knowledge_embedding = ['personal_data']` thay cho map policy | như trên |
| `purposes_scalar` | cả `purposes = 'invalid-map'` | như trên |
| `forbidden_associative` | `forbidden_data_classes = ['first' => 'personal_data']` thay cho list | như trên; bộ kiểm chỉ kiểm kiểu giá trị, không kiểm list |

Bốn case đầu là bằng chứng trực tiếp việc bỏ qua override/container sai kiểu. Case associative bổ sung khoảng trống kiểm hình dạng danh sách; không khẳng định associative array tự làm mất hiệu lực các giá trị cấm, vì `array_intersect` vẫn dùng giá trị của nó. Không chứng minh rò rỉ personal data hay bypass default cấm personal data: request tái lập là `derived_text`. Tác động đã chứng minh là safety configuration lỗi vẫn đi tới provider boundary và tiêu quota thay vì fail-closed. Allow-list mặc định của sản phẩm vẫn rỗng; đây là lỗi boundary khi cấu hình provider được cho phép, không phải tuyên bố provider thật hiện đã được gọi.

Các ca đối chứng cũng chạy bằng ledger thật:

- 8 ca thiếu/sai trường cơ bản đều `AI_SAFETY_BLOCKED`, run blocked, reservation released, factory/provider 0, usage_events 0; evidence `missing_or_invalid_safety_policy`.
- Default hợp lệ không có override → allowed, đúng hành vi kế thừa.
- Cấm `personal_data` rõ ràng và partial override retention `none` → blocked, released, factory/provider 0.
- Policy đầy đủ với forbidden list rỗng tường minh → allowed, đúng lựa chọn được mô tả trong remediation.

Điều kiện đóng C2: phân biệt **không khai báo override** với **đã khai báo nhưng sai hình dạng**, kiểm container/map/list trước khi dùng fallback; thêm regression cho các hình dạng còn lọt, giữ đối chứng kế thừa hợp lệ và list rỗng tường minh. Chạy lại probe với oracle blocked/refund/no-factory. Reviewer không vá finding.

### 10.4. Mutation — chỉ trên bản sao riêng

Lệnh thực thi chi tiết, stdout và JUnit theo từng phase nằm trong `mutations.py`, `mutation-results.json` và các file `<label>-<phase>.log/.xml`. Mỗi mutation có baseline và restored run cùng filter; không chỉ dựa vào exit khác 0.

| Mutation | Baseline | Mutant | Sau restore | Kết luận |
| --- | --- | --- | --- | --- |
| M02 — bỏ duy nhất guard fingerprint tại `validatedRevision()` | 3 PASS | 1 failure: dataset `fingerprint only`; 2 PASS | 3 PASS | **KILLED**, đóng C4 |
| C1-reverse — đưa recorder về byte HEAD/lượt 1 | 2 PASS | race test ERROR `AI_PROVIDER_CALL_FAILED` ở gate:225/test:1087; completed-run test PASS | 2 PASS | **KILLED** bởi lỗi lifecycle đúng cửa sổ cũ, không phải setup/DB error |
| C2-reverse — đưa gate về byte HEAD/lượt 1 | 7 PASS | 6 invalid-policy dataset FAIL; explicit-empty-policy PASS | 7 PASS | **KILLED**, bảo vệ phần remediation cơ bản; không bao phủ 5 biến thể còn mở |

| File mutation | Original = restored SHA-256 | Mutant SHA-256 |
| --- | --- | --- |
| `AiKnowledgeIngestionService.php` | `e7528330ebf76c4517fce4db979f4ceaa84c1fee5cff9512e168b70a800ef619` | `0fc6842cb57d7b48ab5f03bee27b4a6ba6722e7b7bf12832b1d811d443ee417c` |
| `AiModelRunRecorder.php` | `a82626350d102c413be01bd917dffe881c728ce17a016fbc1d104e5a050528d0` | `07f848ccbc5921669d5ab1decdeb18382f6f4e2eb86319cb4e692a24b53ab492` |
| `AiProviderExecutionGate.php` | `0e0ebbdfbb24aab75512c841b1927a8b63d2e6573486092c6c9e5c4335796cd5` | `aa29794378817fa8b78354d669b44cb51afd2a92085002c5bc4e5c23c8eee83a` |

### 10.5. Bảng lệnh và kết quả reviewer tự chạy

`R2/run.sh mysql` luôn trỏ socket tạm; `sqlite` dùng `:memory:`. Danh sách integration lấy trực tiếp workflow vào `integration-files.json`: **34 file**, không phải 33. Không rút bớt test chậm. Thời gian dưới đây là do runner ghi, không lấy từ implementer.

| Lệnh / thao tác | Kết quả |
| --- | --- |
| `git rev-parse HEAD`, snapshot SHA-256 + kiểm cuối | HEAD như §10.1; 5 SHA yêu cầu khớp; 1.029 file gốc và bản sao khớp; vendor không symlink |
| MariaDB `--no-defaults --skip-networking` + `SELECT VERSION(), @@datadir, @@socket, @@skip_networking` trên socket riêng | 11.4.12, đúng đường dẫn R2, skip_networking=1 |
| `R2/run.sh mysql test <34 đường dẫn trong integration-files.json> --log-junit=R2/integration.xml` | **exit 0; 563 PASS, 1 skip, 0 failure/error; 3.027 assertions; 2.532,99 giây** theo PHPUnit (wrapper 2.533,83 giây) |
| `R2/run.sh sqlite test --log-junit=R2/default.xml` | **exit 1; 1.280 PASS, 41 skip, 1 failure; 11.090 assertions; 363,33 giây** theo PHPUnit (wrapper 365,03 giây) |
| `R2/run.sh sqlite docs:lint` | PASS, exit 0 |
| `R2/run.sh sqlite schema:drift --docs-only --format=json` | PASS, exit 0; 100 migration files, findings=[] |
| `R2/run.sh mysql schema:drift --connection=mysql --format=json` | PASS, exit 0; ledger không pending/missing-source; 0 ERROR/WARNING, 48 INFO bảng deferred đúng dự kiến |
| `python3 R2/mutations.py` → filtered PHPUnit baseline/mutant/restore | Ba mutation KILLED, mọi restored run PASS; SHA khôi phục khớp §10.4 |
| `R2/run.sh mysql php R2/recorder-race.php` | PASS, JSON `passed=true`, bằng chứng hai connection/ledger ở §10.2 |
| `R2/run.sh mysql php R2/safety-variants.php` | **exit 1: 12/17 ca đúng oracle, 5/17 sai**; đây là finding C2, không ghi PASS |
| `python3 R2/summarize.py integration.xml default.xml` | Đọc từng testcase/failure/error/skipped trong JUnit; kết quả lưu `*.summary.json` |
| `mariadb-admin --no-defaults --socket=R2/mysql.sock -uroot shutdown` | exit 0; process server exit 0; socket/pid biến mất; xoá đúng `R2/db` và `R2/dbtmp` |

**Đối chiếu test đỏ bằng JUnit:** integration không có tên đỏ; skip duy nhất là `Tests\Feature\AiEmbeddingServiceTest::test_real_qdrant_maintenance_passes_a_broken_collection_and_recovers_it` vì không bật Qdrant live.

Default chỉ có một tên đỏ: `Tests\Feature\VideoTranscriptCaptionLocalReviewTest::test_a_corrupt_video_fails_extraction_without_output_or_workspace_residue`, dòng 175, expected `audio_extraction_failed`, actual `provider_unavailable`. Trùng tên và sai khác đã ghi ở lượt 1; bản sao vẫn không có Faster Whisper runtime/model, test corrupt-video không gọi guard `requireRealFixture()` như các ca real-video khác. Không chạy model thật hoặc thay test để ép xanh. Vì vậy **toàn suite mặc định chưa PASS**. Số skip tăng 40 → 41 do regression race C1 mới yêu cầu hai connection MariaDB; ca này đã PASS trên integration. Raw XML lượt 1 không còn, nên không tuyên bố đã diff hai XML trực tiếp.

### 10.6. Cập nhật điều kiện đóng và verdict toàn Phần 2

| Điều kiện | Verdict hiện hành | Thay đổi / phạm vi bằng chứng |
| --- | --- | --- |
| 1 — Foundation/Learning prerequisites | **PASS** | Giữ kết luận lượt 1; các file integration liên quan chạy lại PASS |
| 2 — docs/schema/implementation không drift | **PASS** | C3 đóng; docs lint, docs-only và physical drift lượt 2 PASS |
| 3 — ingestion/rebuild/delete idempotent | **PASS** | Giữ kết luận lượt 1; integration liên quan PASS lại |
| 4 — không trộn revision/tenant | **PASS** | Đóng nợ regression C4; M02 nay bị test chính thức bắt |
| 5 — delete barrier | **PASS WITH DOCUMENTED RISKS** | Giữ giới hạn Qdrant live/AR-P3-2 của lượt 1; chưa activation |
| **6 — provider/quota fail-closed** | **CHANGES REQUIRED** | C1 đóng; C2 còn 5 biến thể sai hình dạng như §10.3 |
| 7 — retrieval post-validation | **PASS** | Giữ phạm vi Media hiện hỗ trợ; retrieval integration PASS lại |
| 8 — Vision provenance/Media boundary | **PASS** | Integration Vision PASS lại; không mở rộng sang model thật |
| 9 — human approval/accept ≠ publish | **PASS** | Backend/HTTP/packet integration PASS lại |
| **10 — mutation cho invariant bắt buộc** | **PASS** | Trong phạm vi remediation: M02 bị regression mới bắt; C1/C2 reverse bị bắt và restore PASS. Đóng nợ C4 của điều kiện 10. Không coi mutation là chứng minh mọi hình dạng policy: khoảng trống C2 vẫn chặn điều kiện 6 |

Các mutation khác và probe xoá xuyên ba nhánh của lượt 1 không được chạy lại trong phạm vi remediation này; không trình bày chúng như bằng chứng mới. Bảng hiện hành giữ kết luận lịch sử cho vùng không đổi, bổ sung full integration và kiểm tập trung đã mô tả.

**Điều kiện đóng còn thiếu:** hoàn tất C2 và chứng minh các trường hợp cấu hình lỗi bị chặn trước adapter, trả reservation, không ghi usage event; đưa các ca này vào regression. Full default còn một failure môi trường đã nêu, cần được chạy trong môi trường đủ precondition hoặc ghi nhận rõ trong quyết định closure, không được đổi thành PASS bằng waiver. Không phát hiện yêu cầu sửa Source/Chunk mới trong lượt này; C4 đã đóng.

### 10.7. Chưa kiểm và lưu bằng chứng

Không kiểm provider/model thật, Qdrant live, UI/frontend, activation, ranking modifier đã hoãn, hoặc apply database thật. Không xác minh trạng thái `learnforge_db` qua kết nối trực tiếp. Không kiểm crash durability hay stress concurrency nhiều process; probe C1 là interleaving tất định hai connection. Không chạy lại toàn bộ 14 mutation lượt 1. Những giới hạn này không được dùng để ký PASS cho phạm vi chưa kiểm.

Cleanup đã hoàn tất: server và shutdown đều exit 0, datadir/tmpdir đã xoá, socket/pid không còn. Source snapshot và log được giữ để tái lập. Gói evidence bên dưới nằm ngoài `/tmp`, không chứa datadir, vendor hay `.env` thật; có manifest, script probe/mutation, JUnit, stdout, integrity và cleanup.

Evidence: [gói round 2](/Users/amin/.codex/visualizations/2026/09/26/01a0dbc2-f426-7243-b422-9c2431c661ee/lf-part2-closure-round2-evidence.tar.gz), SHA-256 `6af419d0b78c4bf1c7a9cdc0d764797b24b3deea801ac54ea1436522f7c593e7`.


## 11. Round 3 — Re-review chưa thực hiện: BLOCKED

### 11.1. Ràng buộc độc lập

Đã đọc brief v1.2, mục “Remediation sau round 2”, hồ sơ implementer “Round 2 — C2 còn mở” và §10 của báo cáo. Brief § “Ràng buộc độc lập” quy định:

> Không đủ tư cách: tác nhân đã viết hoặc vá code của bất kỳ bước nào trong phạm vi, gồm session implementer Knowledge Sync/Bước 6/Bước 7 và implementer Bước 4–5.

Sau round 2, chính tác nhân trong thread này đã sửa `AiKnowledgeIngestionService.php`, `AiKnowledgeSyncService.php`, hai test liên quan và tài liệu canonical cho lỗi video locator/part. Việc đó được thực hiện theo yêu cầu “sửa code” của Owner, nhưng khiến tác nhân không còn đủ tư cách ký final independent closure review Phần 2. Việc đổi model trong cùng thread không xoá lịch sử tham gia implementation.

Vì vậy mục này chỉ ghi nhận trở ngại và snapshot bàn giao, **không phải chữ ký review kỹ thuật**. Không chạy safety probe, mutation, PHPUnit hoặc MariaDB trong lượt này; không dùng kết quả implementer để thay bằng chứng reviewer. Nội dung §1–10 được giữ nguyên; tính độc lập của các lượt lịch sử không bị viết lại theo vai trò hiện tại.

### 11.2. Đối chiếu snapshot để bàn giao

HEAD đọc được: `d7bf85fc3c40479d4c38bda0224d8305910f77d2`; các remediation còn nằm trong working tree nên không dùng HEAD thay cho SHA-256.

| File | SHA-256 đọc trực tiếp | So với yêu cầu |
| --- | --- | --- |
| `app/Services/AiProviderExecutionGate.php` | `aa9c53662dfb48adc2a78c1bdc814fa36f08cf37e39266de6dd7f8f4127bc372` | MATCH |
| `tests/Feature/AiProviderExecutionGateTest.php` | `5b76d3f21f8f89d7913cceff661bfe487b1954db27e4227aeba58d2e0a052659` | MATCH |
| `tests/Feature/VideoTranscriptCaptionLocalReviewTest.php` | `c603c8efec43a1d64cf9764914c620bf5a2860e15bf6992cbb53d0bcdbe7678c` | MATCH |
| `config/ai.php` | `d5f2de5f1966971b808775ce6c3429f159bc667bad6a923a12f0755f2138322f` | MATCH |
| `app/Services/Ai/AiModelRunRecorder.php` | `a82626350d102c413be01bd917dffe881c728ce17a016fbc1d104e5a050528d0` | MATCH |
| `tests/Feature/AiKnowledgeIngestionServiceTest.php` | `94fb77fa5d67df927c7b5e44a4578bf6d62fa9eb78a3cdd052abaaa3c2fac6ba` | **MISMATCH** |

Hash yêu cầu của `AiKnowledgeIngestionServiceTest.php` là `e106487a314141d5fe60db9c6782384352e75a9287ca8d471273391d2a584638`; file hiện tại đã thêm regression cho frame regions ở lượt sửa code kế tiếp, nên tuyên bố “không đổi” không còn đúng với working tree này. Không revert file để ép khớp snapshot.

Reviewer tiếp theo cần chốt snapshot bao gồm các thay đổi Knowledge ingestion/sync hoặc tách đúng snapshot remediation cần review; không thể mặc định toàn bộ vùng Source/Chunk vẫn không đổi rồi giữ nguyên chữ ký closure cho working tree mới. Hồ sơ implementer của thay đổi thêm nằm tại [bản lưu ngoài repository](/Users/amin/.codex/visualizations/2026/09/26/01a0dbc2-f426-7243-b422-9c2431c661ee/lf-knowledge-frame-sync-fix-2026-09-28/Knowledge-Frame-Sync-Fix.md), có cả vấn đề role `image`/CHECK còn chặn PDF; thông tin này là đầu mối bàn giao, chưa phải finding độc lập của round 3.

### 11.3. Điều kiện đóng và trạng thái finding

| Hạng mục | Trạng thái lượt 3 | Kết luận được kiểm chứng gần nhất |
| --- | --- | --- |
| C2 | **Chưa re-review** | **PARTIALLY CLOSED**, §10; chưa có bằng chứng độc lập để chuyển CLOSED |
| Điều kiện 6 — provider/quota fail-closed | **BLOCKED** trong lượt re-review | **CHANGES REQUIRED**, §10.6; chưa xác nhận remediation mới |
| Các điều kiện khác | Không ký lại trên snapshot mới | Giữ nguyên lịch sử §10, không coi là kiểm chứng mới cho các file đã thay đổi |
| Verdict closure toàn Phần 2, round 3 | **BLOCKED** | Kết luận kỹ thuật gần nhất: **CHANGES REQUIRED** |

BLOCKED ở đây là do không đáp ứng điều kiện reviewer độc lập và snapshot có sai khác; không phải kết luận rằng bản vá C2 mới thất bại. Không có finding implementation mới được ký trong lượt này.

### 11.4. Lệnh đã chạy và mục chưa kiểm

| Lệnh / thao tác | Kết quả |
| --- | --- |
| `git status --short`, `git rev-parse HEAD` | Working tree có thay đổi; HEAD như §11.2 |
| Đọc brief, §10 và mô tả remediation | Xác định điều kiện độc lập không được đáp ứng |
| `shasum -a 256` trên 6 file Owner chỉ định | 5 MATCH, 1 MISMATCH; bảng §11.2 |
| Kiểm nội dung báo cáo trước/sau | §1–10 giữ nguyên; chỉ sửa metadata đầu báo cáo và thêm §11 |

Chưa chạy toàn bộ các việc kỹ thuật Owner yêu cầu: safety-variants.php (17 cấu hình và các biến thể bổ sung), mutation C2/các nhánh mới, suite mặc định khi thiếu Faster Whisper, đánh giá bằng probe stub `/usr/bin/false`, và AiProviderExecutionGateTest trên MariaDB 11.4. Chưa ký PASS cho bất kỳ việc nào trong danh sách này. Không khởi tạo instance DB, không kết nối learnforge_db/cổng 3307 hoặc XAMPP/cổng 3306, không gọi provider thật, không sửa code/test/migration/canonical docs trong lượt này.

Điều kiện tiếp tục: giao cho reviewer chưa viết hoặc vá bất kỳ bước nào của Phần 2, chốt lại snapshot có hash khớp, rồi thực hiện đầy đủ phạm vi round 3. Không cần tạo báo cáo khác; reviewer đủ tư cách có thể bổ sung bằng chứng/kết luận tiếp ngay trong báo cáo này, giữ lại dấu vết BLOCKED để lịch sử minh bạch.

---

## 12. Round 3 — Independent closure review bởi reviewer K3 (2026-09-29)

### 12.1. Verdict và phạm vi chữ ký

**CHANGES REQUIRED. Chưa đủ điều kiện đóng Phần 2.** C2 vẫn **PARTIALLY CLOSED / MEDIUM**: năm hình dạng còn lọt ở round 2 đã bị chặn, nhưng key sai ngay cấp `ai.safety` vẫn bị bỏ qua để kế thừa default. Probe với ledger thật xác nhận adapter spy được gọi, reservation committed và usage event được ghi. Không dùng full-suite xanh để phủ định finding này.

**K3-R7 CLOSED** trong đường systemic ingestion failure của Knowledge Sync. Test video hỏng đã được xác nhận hoạt động khi không có Faster Whisper; stub không che mất bước FFmpeg cần kiểm.

Reviewer: Codex trong thread K3, chưa viết/sửa implementation hoặc tài liệu canonical của Phần 2. Đã đọc ràng buộc độc lập, điều kiện đóng và mục giao reviewer K3 trong brief v1.4. Việc mất tư cách tại §11 thuộc reviewer/thread trước; không áp dụng cho reviewer hiện tại. Mutation/probe chỉ trong bản sao riêng; workspace chỉ append §12. §1–11 và metadata lịch sử giữ nguyên byte-for-byte: prefix **61.440 bytes**, SHA-256 `b528cabea8ab7e6fb78900a26acb51ae42123835b578b83a030521a62a3a8b58`.

Các mục K3 đã PASS/CLOSED không được mở lại nếu implementation không đổi. Migration giữ hash `ace102dbe…`; ingestion giữ `a7b0b5d…`. Phần thay đổi Sync/contract liên quan K3-R7 được kiểm lại trực tiếp. Kết luận này không cấp quyền provider activation, production deployment hoặc frontend closure.

### 12.2. Snapshot và môi trường

HEAD: `d7bf85fc3c40479d4c38bda0224d8305910f77d2` cộng working tree. Không dùng HEAD thay cho nội dung bàn giao. **17/17 SHA-256 đầu/cuối khớp handoff**, không drift:

| File | SHA-256 đầu = cuối |
| --- | --- |
| `app/Services/AiProviderExecutionGate.php` | `aa9c53662dfb48adc2a78c1bdc814fa36f08cf37e39266de6dd7f8f4127bc372` |
| `app/Services/Ai/AiModelRunRecorder.php` | `a82626350d102c413be01bd917dffe881c728ce17a016fbc1d104e5a050528d0` |
| `app/Services/AiKnowledgeIngestionService.php` | `a7b0b5d2c0e3da9d6eeb9f8ac338be23b61d960ed944a10029f28326843aa506` |
| `app/Services/AiKnowledgeSyncService.php` | `7526b269bc231358931fcac148941989ceff827fc80e4c4708360a0233e4eff0` |
| `config/ai.php` | `d5f2de5f1966971b808775ce6c3429f159bc667bad6a923a12f0755f2138322f` |
| `database/migrations/2026_09_28_000100_widen_ai_knowledge_chunk_source_role.php` | `ace102dbe617b0ace302eca11365df3e68eda470936511807804d5b4024a4cc6` |
| `tests/Feature/AiProviderExecutionGateTest.php` | `5b76d3f21f8f89d7913cceff661bfe487b1954db27e4227aeba58d2e0a052659` |
| `tests/Feature/AiKnowledgeIngestionServiceTest.php` | `94fb77fa5d67df927c7b5e44a4578bf6d62fa9eb78a3cdd052abaaa3c2fac6ba` |
| `tests/Feature/AiKnowledgeSyncServiceTest.php` | `0d55412413b6d5aea9d79bc9edba1b358052a1e0b2a3e63a9c9bcabbaf136802` |
| `tests/Feature/VideoTranscriptCaptionLocalReviewTest.php` | `c603c8efec43a1d64cf9764914c620bf5a2860e15bf6992cbb53d0bcdbe7678c` |
| `tests/Integration/AiKnowledgeSourceRoleMigrationMariaDbTest.php` | `dd77264590424320e8d7b40cd6b3006922f6f1be9b91c66398c32b2b47b9d096` |
| `tests/Unit/KnowledgeSourceRoleVocabularyTest.php` | `f622f805ed1808439f90d420bee6356e0396de7a18599554dbe2b3bd9d89c5a6` |
| `tests/Unit/KnowledgeSourceRoleCheckParserTest.php` | `42ff88c1c6388584998b1672fb63270dedd4e9d8c1efefdcf81f2232c15aac0d` |
| `docs/platform/LF-AI-Knowledge-Sync-Contract.md` | `ed1ad18e570d2b64fb5c678fb2b40893e038f05768d7295e785c78a733dc5702` |
| `docs/adr/ADR-0006-AI-Foundation.md` | `2651560d0109ff99dcbdba827da52dc1d8732e07cafcd133d283a4bfe83db43b` |
| `.github/workflows/application-tests.yml` | `acdec542f6f4848a7d9c5b70a7b91d68feccb9a8c4d5f913b89c810174a559c9` |
| `docs/quality/LF-AI-Part-2-Closure-Reviewer-Brief.md` | `bd64a9e0db4bad872a9f028b12e22154c2b5558f4db31e8133e50edd393cb093` |

MariaDB **11.4.12**, PHP **8.3.35**, PHPUnit **11.5.55**. Root dùng một lần `/tmp/lf-closure-r3-llvzxgkp`; server initialize/start với `--no-defaults`, chạy `--skip-networking`, socket explicit `db.sock`; query xác nhận version/datadir/socket và `skip_networking=1`. Không TCP, không kết nối `learnforge_db` 127.0.0.1:3307 hoặc XAMPP :3306.

Hai bản sao vật lý, vendor thật và **0 symlink**: `app` dùng database `lf_closure_r3` cho integration rồi default/docs; `app-probe` dùng `lf_closure_r3_probe`, storage riêng và `TMPDIR=.../probe-tmp` cho probe/mutation. Không suite nào chạy đồng thời trên cùng database, fixture hoặc storage. Schema probe được `migrate:fresh --force` riêng thành công (605,81 giây); schema integration được dựng mới qua bootstrap test, không dùng bootstrap bỏ migration cho baseline. Chỉ các filtered test sau đó tái sử dụng schema probe đã dựng mới.

Không copy `.env` thật, runtime model hoặc venv. Cấu hình test tổng hợp dùng key test, fake provider và đường dẫn STT python/script/model không tồn tại; source script runtime được copy để các test mock có fixture đúng. Không gọi provider/model thật. FFmpeg thật chỉ được dùng cho kiểm extraction trong test Media. Không chạy Qdrant live.

### 12.3. C2 — PARTIALLY CLOSED, vẫn MEDIUM: root policy không kiểm key

Vị trí: `app/Services/AiProviderExecutionGate.php:366–378`, đặc biệt kiểm root tại dòng 367 và fallback dòng 371; đối chiếu `config/ai.php:79–82` cam kết cấu hình sai ở bất kỳ cấp nào, gồm unknown key, phải block.

`resolvedSafetyPolicy()` kiểm kiểu root và lớp `default`, kiểm key của `purposes` và lớp override được chọn, nhưng **không kiểm key của chính root `$safety`**. Vì vậy cấu hình sau không được hiểu là malformed; `purpose` bị bỏ qua, `purposes` được coi là không khai báo:

```php
'ai.safety' => [
    'default' => [
        'forbidden_data_classes' => ['personal_data'],
        'max_retention_class' => 'transient',
    ],
    'purpose' => [ // typo: phải là purposes
        'knowledge_embedding' => ['forbidden_data_classes' => ['derived_text']],
    ],
]
```

Probe dùng request `knowledge_embedding`, data class `derived_text`, retention `transient`; allow-list và tenant approval hợp lệ trong fixture; Commercial entitlement/reservation/model run/usage event là MariaDB thật, adapter là spy. Kỳ vọng malformed policy: `AI_SAFETY_BLOCKED`, run blocked, release hold, factory/provider 0, usage event 0.

| Đối chứng cùng policy | Kết quả thực |
| --- | --- |
| Key `purpose` sai | allowed=true; run completed; safety metadata null; reservation committed; factory=1, adapter=1, usage_events=1 |
| Chỉ sửa thành `purposes` đúng | AI_SAFETY_BLOCKED; run blocked; evidence forbidden_data_classes/derived_text; reservation released; factory=0, adapter=0, usage_events=0 |
| Root có `unexpected => true` hoặc key số `0` ngoài default hợp lệ | Cũng allowed/completed/committed, factory/provider 1, event 1 thay vì refuse cấu hình lỗi |

`safety-variants.php` chạy **52 lượt: 17 cấu hình lịch sử (trong đó có năm escape của round 2), replay riêng năm escape đó, thêm 30 hình dạng/đối chứng**. Kết quả **49 đúng oracle, 3 sai** chính là ba root case trên; 17/17 lịch sử và 5/5 replay đều đúng. Trong 30 ca mới có root/default/container scalar/list/object/null, override null/object/unknown key, typo/Unicode purpose, forbidden sparse/nested/unknown/Unicode, ceiling null/bool/case/space, đối chứng kế thừa và override hợp lệ. Sau đó chạy thêm cặp typo/correct ở bảng trên để cô lập nguyên nhân.

Đây là lỗi **fail-closed khi cấu hình sai**, không phải chứng minh đã rò rỉ personal data hoặc gọi provider thật. Allow-list mặc định vẫn rỗng. Request tái lập dùng derived_text và adapter spy; ảnh hưởng đã chứng minh là vượt safety boundary và tiêu quota khi cấu hình đáng lẽ bị từ chối.

Đề nghị implementer kiểm whitelist key ngay root `ai.safety` trước fallback, giữ phân biệt key không khai báo với key khai báo sai; bổ sung regression root typo/unknown/numeric key với oracle refund/no-factory/no-usage-event, giữ đối chứng kế thừa hợp lệ. Không sửa code trong review. C2 không thể chuyển CLOSED chỉ dựa vào các test đã xanh; đây là cùng lớp finding C2, không đổi thành một finding mới để bỏ sót lịch sử.

### 12.4. K3-R7 — CLOSED

Đọc trực tiếp catch QueryException và `systemicFailure(string, int)`: chỉ SQLSTATE và driver code được truyền sang frame tạo RuntimeException mới. Object QueryException không truyền/chained; SQL/bindings/driver message không thành argument của helper. Code không dựa vào cấu hình `zend.exception_ignore_args=On` để đạt điều này.

Regression chính thức `test_a_systemic_database_failure_stops_the_tenant_without_leaking_the_query`: **5 tests / 145 assertions PASS** ở baseline và sau restore, ép `zend.exception_ignore_args=0` và xác nhận trace thật sự có args. Mutation truyền `$exception` làm argument thứ ba vào helper khiến **cả 5 dataset FAIL**, đúng assertion không được giữ Throwable trong trace; không phải lỗi PHP syntax/setup.

Probe reviewer `trace-standalone.php` chạy ngoài PHPUnit: **16/16 PASS**, bằng bốn cặp SQLSTATE/driver (42000/1142, 40001/1213, HY000/2006, HY000/1205) × source/chunk insert × `reconcileTenant()`/`syncForMedia()`. Callback tạo QueryException có ba marker riêng cho SQL, binding và driver message. Mỗi case xác nhận:

* RuntimeException mới, message chỉ trạng thái/mã driver, previous=null; helper frame có đúng hai scalar args.
* Duyệt đệ quy args của mọi frame không thấy Throwable hoặc marker; `(string)` và `serialize()` exception đều không giữ marker.
* Chỉ một insert được thử, không đi tiếp owner sau và không có Knowledge source sót lại.

Probe PHPUnit mở rộng ban đầu kiểm cả object graph của runner rồi gọi `serialize($exception)` gặp **16 harness errors** “Serialization of 'Closure' is not allowed” ở baseline và restored run. Đây là Closure của PHPUnit trong trace; không dùng các lượt đó làm PASS hay finding sản phẩm. Script standalone loại runner khỏi call stack, vẫn giữ nguyên production code và kiểm serialize thành công; raw logs của cả hai cách được lưu để minh bạch. Phạm vi chữ ký là systemic failure thoát từ ingestion catch này, không tuyên bố mọi exception trong toàn ứng dụng đều được redact.

### 12.5. Test video hỏng — PASS trong phạm vi extraction

`VideoTranscriptCaptionLocalReviewTest::test_a_corrupt_video_fails_extraction_without_output_or_workspace_residue` dùng `/usr/bin/false` cùng script rỗng và model directory tạm để vượt kiểm tra runtime tồn tại. Nó không mô phỏng FFmpeg success và không đòi Faster Whisper. Video hỏng vẫn đi qua FFmpeg thật; test yêu cầu job failed/audio_extraction_failed, không transcript/caption job, workspace sạch, binary Media vẫn ready và Media Read trả failed.

Filtered baseline và sau restore: mỗi lượt **1 test / 7 assertions PASS**. Thay riêng executable stub trong bản sao test bằng wrapper tạo marker rồi `exec /usr/bin/false`: **1/7 PASS, marker không tồn tại**. Mutation provider bỏ gọi `extractAudio()` làm STT wrapper được gọi (marker tồn tại), và test **FAIL**: expected audio_extraction_failed, actual **processing_failed** sau normalization của job. Do đó stub phân biệt được đường thất bại extraction với đường chạy nhầm tới STT; không phải ép test xanh bằng bỏ qua assertion.

Trong full `php artisan test` không Faster Whisper, JUnit xác nhận chính test video hỏng **PASS / 7 assertions**, không bị skip. Năm ca real-video còn lại skip: `test_real_video_pipeline_from_course_usage_to_transcript_caption_and_read`, `test_an_ffmpeg_inventory_mismatch_fails_closed_before_extraction`, `test_a_queued_video_job_is_refused_when_the_gate_is_switched_off`, `test_a_long_ffmpeg_inventory_string_never_overflows_the_version_column`, `test_another_tenant_cannot_read_video_transcript_or_caption`. Đây không phải chứng nhận pipeline STT thật hoặc chất lượng transcript.

### 12.6. Full suites, JUnit và drift

Sai khác nhỏ của brief đã được xác định trước khi chạy: workflow có **37 lần xuất hiện đường dẫn test trên toàn file**, nhưng job **integration-mysql có đúng 35 đường dẫn**; hai lệnh còn lại thuộc `integration-qdrant` (QdrantVectorStoreIntegrationTest và live-Qdrant filter của AiEmbeddingServiceTest). Reviewer chạy đủ 35 đường dẫn của job MariaDB, không loại test chậm, không gọi đây là 37 file MariaDB. Danh sách nguyên văn nằm trong evidence `integration-files.json`.

Trước khi chạy xong, reviewer lấy `--list-tests-xml` để lập danh sách kỳ vọng: **35 class / 617 testcase integration**, **77 class / 1.376 testcase mặc định**. Đối chiếu tên gồm dataset với JUnit; skip được tách khỏi PASS.

| Lệnh | Kết quả của reviewer |
| --- | --- |
| Full integration-mysql, 35 paths | **616 PASS, 1 skip, 0 failure/error; 3.470 assertions; exit 0**. PHPUnit 2.879,80 giây, runner 2.880,56 giây. JUnit đủ **617/617 tên**, missing=[], unexpected=[] |
| Physical drift sau integration | **PASS**, exit 0; 101 migration files; pending=[], missing_source=[]; 48 INFO table.deferred, không BLOCKER/HIGH/MEDIUM/LOW |
| Full `php artisan test` không Faster Whisper | **1.335 PASS, 41 skip, 0 failure/error; 11.434 assertions; exit 0**. PHPUnit 97,80 giây, runner 98,15 giây. JUnit đủ **1.376/1.376 tên**, missing=[], unexpected=[] |
| `docs:lint` | **PASS**, exit 0, no issues; vẫn thông báo 94 file legacy metadata debt trong allowlist tạm thời, không coi debt đã được giải quyết |
| Docs-only drift | **PASS**, exit 0; 101 migration files, findings=[]; migration ledger not_checked đúng mode |

Skip integration duy nhất: `Tests\Feature\AiEmbeddingServiceTest::test_real_qdrant_maintenance_passes_a_broken_collection_and_recovers_it`; không bật Qdrant live. Không có tên test đỏ trong baseline integration. Coverage theo class đã đối chiếu: ingestion **20**, Sync feature **43** + MariaDB **3**, K3 migration **25**, provider gate **66**, real quota reserver **13** + quota packet **20**, embedding **51** (gồm skip trên), retrieval **30**, Qdrant HTTP-fake **8**, Vision schema/service **23/34**. Bảy class Authoring service/HTTP tổng **98**, packet/apply-safety thêm **24**. Danh sách đầy đủ 35 class cùng toàn bộ tên/dataset ở JUnit và summary JSON trong evidence.



41 skip mặc định được đối chiếu từng tên trong `default.summary.json`, không tính PASS: AiEmbeddingServiceTest 3, AiKnowledgeIngestionServiceTest 1, AiProviderExecutionGateTest 2, AiVisionInterpretationsSchemaTest 14, AudioProcessingLocalReviewTest 9, CourseTemplatePublishConcurrencyTest 1, DocumentProcessingLocalReviewTest 4, MediaProcessingSubstrateTest 2, VideoTranscriptCaptionLocalReviewTest 5. Các case MySQL của những class nằm trong job integration được kiểm ở lượt MariaDB riêng; các skip runtime/live/concurrency ngoài danh sách đó vẫn là chưa kiểm. Không suy diễn toàn bộ 41 skip đã được suite khác bao phủ.

Lệnh suite chính được runner gọi tuần tự trong `app`:

```sh
php artisan test <toàn bộ 35 đường dẫn integration-mysql> --log-junit=.../integration.xml
php artisan schema:drift --connection=mysql --format=json
php artisan test --log-junit=.../default.xml
php artisan docs:lint
php artisan schema:drift --docs-only --format=json
```

Environment mysql dùng `DB_DATABASE=lf_closure_r3`, `DB_HOST=localhost`, `DB_PORT=0`, root/password rỗng, `DB_URL=''`, socket private explicit; default dùng SQLite `:memory:`. Các đường STT thiếu được ép qua environment. Bootstrap tái sử dụng schema chỉ áp dụng các probe/mutation filtered sau fresh schema riêng, không áp dụng full integration.

### 12.7. Mutation matrix của lượt này

Không chạy lại 14 mutation lượt 1 theo phạm vi Owner giao. Các kết quả M01–M14 tại §7 và remediation §10 giữ là bằng chứng lịch sử, không đổi nhãn thành kết quả mới của reviewer này.

| Mutation trong app-probe | Baseline → mutant → restored | Kết quả |
| --- | --- | --- |
| C2 bỏ array_is_list của forbidden classes | Safety regression 22/150 PASS → 1 failure (map thay list) → 22/150 PASS | KILLED |
| C2 bỏ kiểm key của purposes | 22/150 PASS → 1 failure (unknown purpose) → 22/150 PASS | KILLED |
| C2 policy layer chỉ cần array, bỏ kiểm key | 22/150 PASS → 2 failures (list thay map; unknown override key) → 22/150 PASS | KILLED |
| K3-R7 truyền exception gốc làm argument thừa | 5/145 PASS → 5 failures giữ Throwable trong trace → 5/145 PASS | KILLED |
| Video bỏ extraction, đi thẳng tới STT stub | 1/7 PASS → 1 failure sai mã lỗi + marker STT xuất hiện → 1/7 PASS | KILLED |

Các mutant trên fail bằng assertion đúng invariant, không PHP/SQL harness error. Gate/Sync mutations lint PASS; mọi file bị mutation, gồm test wrapper video và provider, được phục hồi byte-for-byte rồi so với bản sao baseline. Hash gốc/mutant/restored của C2/K3-R7 nằm trong `mutation-hashes.jsonl`. Các harness errors của probe serialize được tách riêng ở §12.4, không gộp vào bảng này.

### 12.8. Điều kiện đóng hiện hành

| Điều kiện | Verdict round 3 | Căn cứ và giới hạn |
| --- | --- | --- |
| 1 — Foundation/Learning prerequisites | **PASS**, giữ phạm vi lịch sử | Gate thiết kế không đổi; integration Learning/Foundation chạy lại; không coi waiver là PASS mới |
| **2 — docs/schema/migration/implementation không drift** | **CHANGES REQUIRED** | Structural lint/drift được ghi riêng ở §12.6; semantic gap giữa cam kết malformed ở mọi cấp trong config và implementation root policy còn tồn tại (C2). Claim dev chưa tự kiểm |
| 3 — ingestion/rebuild/delete idempotent | **PASS** | Full ingestion/sync regression trên MariaDB; không tái ký chất lượng provider thật |
| **4 — không trộn revision/tenant** | **PASS** | Ingestion identity từng thành phần, frame part_index và Sync revision/tenant regression được chạy lại; K3 đã xử lý vocabulary, không map role |
| 5 — delete barrier | **PASS WITH DOCUMENTED RISKS** | Regression embedding/Knowledge/Vision/Authoring dùng fake adapter; giữ giới hạn Qdrant physical/AR-P3-2 và không claim rerun probe xuyên ba nhánh lịch sử |
| **6 — provider/quota fail-closed** | **CHANGES REQUIRED** | C1 regression giữ xanh; C2 root policy có tái hiện ledger thật; empty allow-list không thay thế safety proof |
| 7 — retrieval post-validate authorization | **PASS** trong phạm vi nguồn Media | Regression retrieval chạy lại; AR-P3-7 vẫn là gate trước khi thêm nguồn non-Media |
| 8 — Vision provenance độc lập | **PASS** trong phạm vi backend | Schema/service regression chạy lại với fake provider; không ký chất lượng model/crop/video interpretation |
| 9 — proposal trước approval, accept khác publish | **PASS** trong phạm vi backend/HTTP | Full Authoring packet/service/HTTP/promotion regression; frontend ngoài phạm vi |
| **10 — mutation bảo vệ invariant** | **PASS WITH DOCUMENTED RISKS** | Năm mutation lượt này KILLED và restored PASS; M01–M14 giữ evidence lịch sử theo phạm vi Owner cho phép không chạy lại. Root-policy gap chưa có regression, được giữ rõ trong C2/điều kiện 6, không suy test xanh là bảo vệ mọi hình dạng cấu hình |

Trạng thái finding: C1/C3/C4 giữ CLOSED theo §10 và regression liên quan được chạy lại; C2 **PARTIALLY CLOSED** với root-policy gap ở §12.3; K3-R7 **CLOSED** theo kiểm chứng mới; K3-R8/migration approval giữ nguyên. Không có finding BLOCKER/HIGH mới trong phạm vi lượt này. Toàn Phần 2 vẫn **CHANGES REQUIRED** vì C2, dù các suite chuẩn không có failure/error.

Điều còn thiếu để đóng: implementer xử lý root malformed policy, thêm regression với ledger oracle, reviewer độc lập xác nhận bằng cặp typo/correct và các root variants; giữ full-suite/docs/drift sạch trên snapshot mới. Không cần tái mở các mục K3 đã đóng chỉ vì finding C2 này.

### 12.9. Bằng chứng dev, giới hạn và cleanup

Thông tin backup `learnforge_db-before-k3-20260929-083246.sql` (hash rút gọn 400065f7…), restore thử, migrate 388 ms, CHECK 15 role, 3.186 chunk không đổi, sync ingested=3/failed=0 và 164/164 region/chunk khớp là **claim/evidence của implementer trong brief**. Reviewer không kết nối dev, không tự kiểm backup/apply/drift/đối soát những số liệu này; không trình bày chúng như PASS độc lập. Fresh ephemeral drift của lượt này là bằng chứng khác.

Chưa kiểm provider/model thật, Qdrant live/physical deletion, UI/frontend, production activation, production-size performance, account deploy hạn chế quyền hoặc crash/power-loss durability. `innodb_flush_log_at_trx_commit=2` chỉ là cấu hình instance test; không lấy test transaction/lock làm chứng minh durability. Các rủi ro AR-P3-2/3/7 giữ phạm vi đã ghi: Qdrant index/delete và preflight trước activation, nguồn non-Media trước khi thêm loại nguồn đó. Không kiểm lại toàn bộ 14 mutation hoặc probe xoá xuyên ba nhánh của reviewer cũ; full regression liên quan được chạy ở lượt này.

Setup có lần copy thiếu vendor khi khởi chạy probe sớm và một lần lệnh khởi động server được đưa ra khi chưa xác nhận init hoàn tất; các instance/schema setup đó được dừng/dựng lại tuần tự trước khi dùng làm evidence. Lỗi setup không tính thành failure sản phẩm hoặc PASS. Không có auto-review rejection còn treo.

Cleanup hoàn tất: shutdown command và server process đều exit 0; log có `Shutdown complete`; socket/pid đã biến mất. Sau khi export evidence đã xóa toàn bộ `/tmp/lf-closure-r3-llvzxgkp`, gồm hai bản sao, vendor, datadir, database, probes, mutations và raw logs trong /tmp; kiểm root không còn tồn tại. Các mutation đã restore trước cleanup. Hash nguồn được kiểm lại sau export/cleanup và vẫn 17/17 khớp. Prefix §1–11 được kiểm lại trước/sau append; không whitespace error trong phần thêm.

Evidence lưu ngoài repository: [gói round 3](/Users/amin/.codex/visualizations/2026/09/28/01a0e662-4d45-7dc0-a759-42fc721f2633/lf-part2-closure-round3-evidence.tar.gz), SHA-256 `6775c6ffcd526507e02419eef13935f360513423f51d0a2d5d4402ec64493c02`. Gói chứa JUnit, danh sách testcase kỳ vọng/thực tế và chênh lệch rỗng, logs, script probe/mutation, snapshot source, hash đầu/cuối và cleanup record. Không chứa vendor, datadir, model runtime hoặc `.env`; không có symlink. README của gói phân biệt baseline, expected mutation failures và harness-only errors.

**Kết luận cuối: CHANGES REQUIRED; C2 còn chặn closure. K3-R7 CLOSED, test video hỏng PASS; chưa ký đóng toàn Phần 2.**

---

## 13. Independent closure round 4 — C2 root safety policy, 2026-09-29

**Verdict hiện hành: PASS WITH DOCUMENTED RISKS cho toàn Phần 2. C2 CLOSED; điều kiện 2 và 6 PASS.** Kết luận này thay verdict còn mở ở §12; §1–12 giữ nguyên. Không có finding mới chặn closure trong phạm vi round 4.

### 13.1. Độc lập, phạm vi và snapshot

Reviewer K3 tiếp tục đủ tư cách: không viết/sửa code, migration, test hay tài liệu canonical của Phần 2. Lượt này chỉ append báo cáo; probe/mutation chạy trong bản sao riêng. Không lấy self-review của implementer làm kết luận độc lập. Đã đọc điều kiện đóng, Closure Reviewer Brief § Round 4 và Provider Execution Gate Implementation Review § “Round 3 — lớp gốc ai.safety”.

Theo phạm vi Owner giao, không chạy lại toàn job integration-mysql hoặc tái review K3 đã đóng. Đối chiếu **516 file có trong snapshot round 3** thuộc app/config/database/tests chỉ thấy gate và test gate thay đổi; không coi đây là kiểm kê mọi file mới ngoài snapshot. Các file bàn giao khác khớp round 3.

**18/18 SHA-256 đầu/cuối khớp**; bảng ghi chung giá trị ở hai thời điểm. Prefix §1–12 giữ nguyên **85.676 byte**, SHA-256 `21ceb193ed3005ee2282d7a41b2fc43a18fb7c60b2ca7e2f6a4c4aeb63a7efc0`.

| File | SHA-256 đầu = cuối |
| --- | --- |
| `app/Services/AiProviderExecutionGate.php` | `8fc1d36f06cbd622487388f3a16cdf07de895b6dd61a5402e6bb21151f901cd8` |
| `app/Services/Ai/AiModelRunRecorder.php` | `a82626350d102c413be01bd917dffe881c728ce17a016fbc1d104e5a050528d0` |
| `app/Services/AiKnowledgeIngestionService.php` | `a7b0b5d2c0e3da9d6eeb9f8ac338be23b61d960ed944a10029f28326843aa506` |
| `app/Services/AiKnowledgeSyncService.php` | `7526b269bc231358931fcac148941989ceff827fc80e4c4708360a0233e4eff0` |
| `config/ai.php` | `d5f2de5f1966971b808775ce6c3429f159bc667bad6a923a12f0755f2138322f` |
| `database/migrations/2026_09_28_000100_widen_ai_knowledge_chunk_source_role.php` | `ace102dbe617b0ace302eca11365df3e68eda470936511807804d5b4024a4cc6` |
| `tests/Feature/AiProviderExecutionGateTest.php` | `6b6c0808d52afead13dc078c299f80f88a6ecd6e7e2a57b2724294fb36dc5deb` |
| `tests/Feature/AiKnowledgeIngestionServiceTest.php` | `94fb77fa5d67df927c7b5e44a4578bf6d62fa9eb78a3cdd052abaaa3c2fac6ba` |
| `tests/Feature/AiKnowledgeSyncServiceTest.php` | `0d55412413b6d5aea9d79bc9edba1b358052a1e0b2a3e63a9c9bcabbaf136802` |
| `tests/Feature/VideoTranscriptCaptionLocalReviewTest.php` | `c603c8efec43a1d64cf9764914c620bf5a2860e15bf6992cbb53d0bcdbe7678c` |
| `tests/Integration/AiKnowledgeSourceRoleMigrationMariaDbTest.php` | `dd77264590424320e8d7b40cd6b3006922f6f1be9b91c66398c32b2b47b9d096` |
| `tests/Unit/KnowledgeSourceRoleVocabularyTest.php` | `f622f805ed1808439f90d420bee6356e0396de7a18599554dbe2b3bd9d89c5a6` |
| `tests/Unit/KnowledgeSourceRoleCheckParserTest.php` | `42ff88c1c6388584998b1672fb63270dedd4e9d8c1efefdcf81f2232c15aac0d` |
| `docs/platform/LF-AI-Knowledge-Sync-Contract.md` | `ed1ad18e570d2b64fb5c678fb2b40893e038f05768d7295e785c78a733dc5702` |
| `docs/adr/ADR-0006-AI-Foundation.md` | `2651560d0109ff99dcbdba827da52dc1d8732e07cafcd133d283a4bfe83db43b` |
| `.github/workflows/application-tests.yml` | `acdec542f6f4848a7d9c5b70a7b91d68feccb9a8c4d5f913b89c810174a559c9` |
| `docs/quality/LF-AI-Part-2-Closure-Reviewer-Brief.md` | `86bd1cf07e448451385e43ea15479b2eb557d014f6d600e835191fecc32e4a97` |
| `docs/quality/LF-AI-Provider-Execution-Gate-Implementation-Review.md` | `32a58e8b0213f194e7edfeb00f2ec8ad7b813ea3c2318b6b6d5cfee86bf5065c` |

### 13.2. Code và oracle trên ledger thật

`resolvedSafetyPolicy()` kiểm `is_array` rồi `onlyKeys($safety, ['default', 'purposes'])` trước khi đọc/gộp policy. Helper so key bằng `in_array(..., true)`: key số, sai case, khoảng trắng hoặc ký tự ẩn không được bỏ qua. Policy sai vẫn vào nhánh `AI_SAFETY_BLOCKED`, evidence `missing_or_invalid_safety_policy`; policy hợp lệ giữ default/partial override. Diff với round 3 xác nhận không có thay đổi hành vi khác trong gate.

Probe dùng `DatabaseCommercialEntitlements`, `DatabaseUsageQuotaReserver`, `AiModelRunRecorder` và ledger MariaDB thật; settings/adapter là fake/spy. Không fake quota ledger hoặc gọi provider mạng. Provider/model/tenant approval/entitlement được cấu hình hợp lệ để thử riêng safety, không dựa vào allow-list trống để block.

| Probe reviewer | Kết quả |
| --- | --- |
| Replay 52 lượt `safety-variants.php` §12.3 (17 gốc + replay 5 escape + 30 bổ sung) | **52/52 PASS**: 46 blocked, 6 đối chứng allowed |
| Cặp typo `purpose` / đúng `purposes` | **2/2 PASS**, cả hai blocked; evidence lần lượt `missing_or_invalid_safety_policy` / `forbidden_data_classes` |
| 26 biến thể root mới | **26/26 PASS**: 24 blocked, 2 đối chứng allowed |

Tổng **80/80 lượt đúng oracle**. Cả 72 lượt blocked: `AI_SAFETY_BLOCKED`, run `blocked`, reservation `released`, factory=0, adapter=0, usage event=0. Tám đối chứng allowed: run `completed`, reservation `committed`, factory=1, adapter=1, usage event=1. “Refund” ở đây là release hold trước sử dụng, không xóa usage đã committed.

26 ca mới gồm `Purpose`/`PURPOSES`, leading/trailing space, tab, NUL, zero-width space, Cyrillic е trông giống Latin e, key rỗng, số âm/dương, chuỗi `01`, `DEFAULT`/`default `, key có dấu chấm, key safety/policy đặt sai cấp; key lạ với value null/false/array/object; root object/empty array. Hai đối chứng kiểm thứ tự key đảo ngược và partial override hợp lệ. Từng cấu hình, DB identity và trạng thái ledger ở ba log JSON.

Regression mới của implementer cũng được đọc/chạy độc lập: test cặp typo/correct bind implementation Commercial thật, kiểm hold released, zero usage/factory/adapter và evidence riêng cho hai nhánh, không chỉ decision boolean.

### 13.3. Suites, JUnit, drift và môi trường

Bản sao có vendor copy thật, **0 symlink**, `.env` tổng hợp không secret. MariaDB **11.4.12** dựng mới bằng `--no-defaults`, server `--skip-networking`; identity xác nhận datadir/socket riêng trong `/tmp/lf-closure-r4-n6s18qtx`, `@@skip_networking=1`, database **lf_closure_r4**. Kết nối MySQL chỉ qua socket đó, host localhost/port 0/DB_URL rỗng. Gate MariaDB đầu tiên tự dựng schema qua RefreshDatabase; migrated flag chỉ dùng cho regression/mutation filtered sau fresh schema. Các suite chạy tuần tự.

| Lệnh trong bản sao | Kết quả cuối |
| --- | --- |
| `php artisan test tests/Feature/AiProviderExecutionGateTest.php` — SQLite memory | **69 PASS, 2 skip**, 415 assertions; exit 0; JUnit **71/71 tên**, missing=[], unexpected=[] |
| Cùng file gate — MariaDB fresh | **71 PASS, 0 skip**, 445 assertions; exit 0; JUnit **71/71 tên**; runner 304,29 giây gồm dựng schema |
| Full `php artisan test` — SQLite, không Faster Whisper | **1.340 PASS, 41 skip, 0 failure/error**, 11.475 assertions; exit 0; PHPUnit 94,50 giây; JUnit **1.381/1.381 tên**, missing=[], unexpected=[] |
| `php artisan docs:lint` | **PASS**, no issues; 94 file legacy metadata debt trong allowlist vẫn còn |
| `php artisan schema:drift --docs-only --format=json` | **PASS**, 101 migration files, findings=[]; ledger not_checked đúng mode |
| Kiểm thêm `php artisan schema:drift --connection=mysql --format=json` | **PASS** trên schema disposable mới; pending=[], missing_source=[]; 48 INFO table.deferred, không BLOCKER/HIGH/MEDIUM/LOW |

Danh sách kỳ vọng lấy bằng `--list-tests-xml`, đối chiếu JUnit theo class/method **và dataset**, không chỉ tổng số. Hai skip gate SQLite là `test_mariadb_revalidation_refund_and_execution_claim_serialize_on_the_run` và `test_a_concurrent_caller_cannot_overwrite_the_run_between_its_read_and_write`; cả hai PASS trên MariaDB. 41 skip full suite khớp chính xác tên skip round 3: Embedding 3, Ingestion 1, Gate 2, Vision schema 14, Audio runtime 9, Course publish concurrency 1, Document runtime 4, Media substrate 2, Video runtime 5. Không tính skip là PASS hoặc suy tất cả được suite khác bao phủ. Test video hỏng vẫn **PASS / 7 assertions**, không chứng nhận STT thật.

**Lượt full đầu tiên không xanh và vẫn lưu:** 1.332 PASS, 41 skip, 8 failure, 11.434 assertions; đủ 1.381 tên. Tám failure thuộc DocumentProcessingLocalReviewTest: mixed/scan PDF, blank PDF dataset #2, Office datasets #1/#2/#3/#5, mixed-blank locator. Reviewer đặt TMPDIR dưới `/tmp` và chạy trong sandbox. Diagnostic xác nhận cùng PNG: Tesseract qua `/tmp/...` lỗi Leptonica “failed to open”, qua đường thật `/private/tmp/...` thì exit 0 và trả đúng text fixture. LibreOffice trong sandbox abort (-6); với profile/output riêng ngoài sandbox thì exit 0 và tạo PDF. Một diagnostic gặp UnicodeDecodeError khi đọc stderr UTF-8 strict; đã chạy lại với replacement decoding, không phải lỗi sản phẩm.

Reviewer chỉ đổi môi trường runner: TMPDIR sang `/private/tmp/...`, full suite ngoài sandbox; giữ source/test/config và đường STT không tồn tại. Lượt cuối đạt kết quả trong bảng, không vá hoặc skip thêm test. Evidence giữ `default.xml` (8 lỗi môi trường), `default-retry.xml` (lượt cuối) và runtime logs. Không xóa lượt đỏ hoặc gán nó thành mutation. Không chạy full-suite factorial tách từng yếu tố môi trường; kết luận giới hạn ở môi trường đã kiểm sạch.

### 13.4. Mutation độc lập

Bỏ đúng dòng whitelist root trên bản sao, không thay test; filter `missing_or_malformed_safety_policy|mistyped_root_key`:

| Baseline | Mutant | Restored | Verdict |
| --- | --- | --- | --- |
| **20 PASS / 176 assertions** | **4 failures, 16 PASS, 0 errors**; PHP lint PASS | **20 PASS / 176 assertions** | **KILLED** |

Bốn failure đúng invariant: ba dataset root typo/unknown/numeric của malformed regression và `mistyped purpose` trên ledger thật. `correct purposes` vẫn PASS. Đây là assertion failures do mutant cho allowed, không SQL/harness error. Hash sau restore khớp baseline/bàn giao; hash baseline/mutant/restored và JUnit nằm trong evidence. M01–M14 lịch sử và năm mutation §12 giữ phạm vi cũ, không relabel thành chạy lại round 4.

### 13.5. Verdict hiện hành

| Điều kiện / finding | Verdict round 4 | Căn cứ |
| --- | --- | --- |
| **2 — docs/schema/migration/implementation không drift** | **PASS** | Root semantic gap đã xử lý và kiểm ledger; lint/docs-only/fresh physical drift PASS. Apply dev vẫn là evidence implementer, chưa được reviewer kiểm |
| **6 — provider/quota fail-closed** | **PASS** | 80 lượt oracle, gate SQLite/MariaDB, cặp typo/correct ledger thật và mutation độc lập |
| **C2** | **CLOSED** | Ba root escape §12.3 không còn lọt; không phát hiện escape mới trong các hình dạng đã thử |
| 1, 3, 4, 7, 8, 9 | Giữ **PASS**, phạm vi §12.8 | Snapshot liên quan không đổi; full default regression chạy lại; không tái ký integration đầy đủ lượt này |
| 5, 10 | Giữ **PASS WITH DOCUMENTED RISKS** | Giới hạn live deletion/activation và mutation lịch sử giữ nguyên; root-policy gap ở ghi chú điều kiện 10 §12.8 nay có regression và mutation KILLED |
| **Toàn Phần 2** | **PASS WITH DOCUMENTED RISKS — đủ điều kiện đóng trong phạm vi đã giao** | Không còn C2 chặn closure; waiver/self-review không được biến thành bằng chứng độc lập |

C1/C3/C4, K3-R7, K3-R8 và migration approval giữ CLOSED/PASS theo hồ sơ trước. Không kiểm lại trace K3-R7 riêng ngoài regression full suite, không chạy lại toàn integration-mysql §12 khi snapshot không đổi.

Giới hạn còn nguyên: provider/model thật, Qdrant live/physical deletion, AR-P3-2/3 trước activation và AR-P3-7 trước nguồn non-Media, UI/frontend, production activation/scale, account deploy hạn chế quyền, crash/power-loss durability chưa được chứng nhận. Closure backend không cấp phép kích hoạt provider thật hoặc xác nhận chất lượng model. Không kết nối `learnforge_db` hay XAMPP; số liệu backup/apply/sync dev trong brief là evidence implementer, **chưa được reviewer tự kiểm**.

### 13.6. Cleanup và evidence

Shutdown command/server process đều exit 0; log `Shutdown complete`, socket/pid biến mất. Đã export evidence và xóa toàn bộ root `/tmp/lf-closure-r4-n6s18qtx`, gồm bản sao/vendor, datadir/database, fixture/probe/mutation và logs; kiểm root không còn tồn tại. Mutation restore trước cleanup. Hash nguồn sau cleanup vẫn 18/18 khớp; prefix §1–12 giữ nguyên trước/sau append. Không sửa canonical/code/test/migration trong workspace, thêm secret hoặc gọi provider thật.

Evidence ngoài repository: [gói round 4](/Users/amin/.codex/visualizations/2026/09/28/01a0e662-4d45-7dc0-a759-42fc721f2633/lf-part2-closure-round4-evidence.tar.gz), SHA-256 `43f6c00419698508afe6d1e4e6754d6c8b19bc291ad13db877936ff1a6065baa`. Gồm logs/JUnit/lists/summaries, probe/mutation scripts, snapshot source, hash đầu/cuối và cleanup record. Không chứa vendor, datadir, model runtime hoặc `.env`; không có symlink. README phân biệt lượt full lỗi môi trường, lượt full cuối và expected mutation failures.

**Kết luận cuối: C2 CLOSED; điều kiện 2 và 6 PASS; toàn Phần 2 PASS WITH DOCUMENTED RISKS.**
