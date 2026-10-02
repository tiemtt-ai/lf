# AI Authoring Review UI — Independent P3-A Implementation Review, Round 2

Version: 1.0

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-09-29

Document Path: quality/LF-AI-Authoring-Review-UI-P3A-Review-Round2.md

Reviewer: Codex — reviewer độc lập lượt 1 và lượt 2

Reviewed Design: v0.7, Approved; D10 được Owner duyệt

Reviewed Contract: v0.8, Frozen

Classification: Existing-Feature Change — independent implementation review

Initial Audit Level: HIGH

Final Audit Level: HIGH

Audit Level Escalation: None

P3-A Verdict: **REJECT**

Regression Final Verdict: **FAIL**

Findings By Severity: **0 BLOCKER, 2 HIGH, 2 MEDIUM, 0 LOW** còn mở trong lượt này; không cộng trùng finding lượt 1.

---

## 1. Kết luận và độc lập

**Chưa đóng P3-A.** Bảy finding cũ đã đóng, bốn finding đóng một phần. Denial thực qua GET và PATCH hiện xoá sạch DOM hiển thị và tắt lệnh ghi; retry của editor được khoá, seed từ chối tenant root có sẵn và remote disk; backlink và mobile cards đã có. Tuy nhiên, script vẫn giữ ghi chú bulk qua reference tới DOM đã tháo, pagehide để lại ghi chú trong textarea, và phản hồi lưu của đề xuất cũ làm mất bản nháp mới ở đề xuất khác. Bộ test mới hữu ích nhưng vẫn bỏ lọt đột biến có ý nghĩa.

Reviewer chưa sửa implementation Bước 7, Phần 2, P3-A, thiết kế hoặc hợp đồng trong lịch sử phiên được cung cấp. Lượt 1 chỉ tạo báo cáo; lượt này chỉ tạo báo cáo Round2 trong repo gốc. Không vá implementation hoặc tài liệu canonical. Các bản sao, probe, mutation và fixture trình duyệt đều nằm dưới `/tmp/lf-p3a-r2-5hnrcdzo`; vendor copy vật lý, không symlink. Không reset/stash/commit working tree của implementer.

Đọc AGENTS → README → INDEX/routing → Guardrails → Regression Audit → brief v1.1/lượt 2 → báo cáo lượt 1 → thiết kế v0.7 → design review §6 → contract Frozen. Đối chiếu thêm Admin Form/List §25 và shared confirmation; các quy tắc Development Standards, Architecture Review Checklist và ADR-0017 đã được đọc trong lượt 1, tiếp tục áp dụng cho review này. §13.1 là danh sách khẳng định cần kiểm, không phải evidence. D10 chỉ cho phép nút Xem thay menu; reviewer đồng ý, không tái mở R9 vì điểm này.

Current/requested behavior: UI duyệt nội dung trong working Activity phải tuân thủ live authority, thu hồi nội dung, frozen retry và bảo vệ draft. Source of truth: Guardrails → ADR/domain owner → contract Frozen → design Approved. UI không được tự apply/publish. Impact graph: Course host/authoringRole → Blade/app.js → JS transport → AI HTTP/read/service → Media authority/audit và Course/Learning owner services; seed → fake provider + in-memory settings → real Commercial ledger → local Media disk. Không thay schema/API/AI routes trong snapshot này. Audit HIGH vì disclosure, quyền, idempotency, draft loss và filesystem ownership.

## 2. Snapshot đầu / cuối

**24/24 khớp prefix bàn giao ở đầu lượt; 24/24 SHA-256 đầy đủ không đổi cuối lượt.** Đây là bytes working tree, không phải Git HEAD. Bảng ghi cả đầu và cuối bằng cột `Đầu = cuối`. Snapshot được kiểm trước đọc implementation, kiểm lại trước cleanup và khi ghi báo cáo cuối. SHA của bản đột biến được phục hồi sau từng ca.

| File | SHA-256 đầu = cuối |
| --- | --- |
| `resources/js/ai-authoring.js` | `eb37246ba1ba79364fd4b18206bd255eb4d9ad6dbb3789618c7e9ea2677c0a3b` |
| `resources/js/app.js` | `d5da869391295db61d1ffb2337ef8c081dbb4e25980052c4f6530fc7bcd542e4` |
| `resources/views/course-template-activities/partials/ai-authoring.blade.php` | `9301801b3d82436ce702c2e6473e05368e972a15b26eef79e35438b46be2bfb7` |
| `resources/views/course-template-activities/show.blade.php` | `cd506b3ec05df4ad3231a98d4d073606b93e6ba3202e57170b594eefae4024b2` |
| `resources/views/course-templates/partials/learning-mappings.blade.php` | `fbe914ccfd43aee976e0fca3391b556ad8b453a2758d619ec60491a499f17753` |
| `app/Http/Controllers/CourseTemplateActivityController.php` | `d56349503f58945d72b2238cbb75dfe41ac27c6d281e643a1e40759d3e6514ac` |
| `app/Services/CourseAuthoringContextService.php` | `5f36b85a2ddc67708eaae3db6e4a3cb75b01f73a3ab04ea5a24cc09b8794fa60` |
| `app/Console/Commands/AiAuthoringDemoSeed.php` | `5748a8f05496d82d0fbe60d51976c4bb080fc5435d4ae631c9c588e7a4b03533` |
| `app/Support/AiDemo/DemoAuthoringProposalProvider.php` | `ebaba37948c2f0225ba90a6eaa01113b4f6abbaf43dd862b1c2a9721e2b5de87` |
| `app/Support/AiDemo/InMemoryTenantSettings.php` | `fcf385f10e4cee00e1004ed152adfe9e540119e1ba60063cacd17b5ad7ca8b69` |
| `resources/css/admin/admin-pages.css` | `b59c67ae5ddf87a1b5507301f502b37beee0ce7bbe61e9b2f26874244edbc40f` |
| `resources/lang/vi/lf.php` | `31040bcd2c8897fe3da60471ec9f28892458d55ca7d3e2872b53f27e9d455abc` |
| `resources/lang/en/lf.php` | `afc4d5f0b0d9c9327960e555e489d287d469fd5b6c403069a9d38ead28478f67` |
| `tests/Feature/AiAuthoringHostSectionTest.php` | `3ab90d5983c0446313c5ebee8f03acb4dcc9e8a3c69acb66b0b8c7b89f0ec208` |
| `tests/Integration/AiAuthoringDemoSeedMariaDbTest.php` | `b5ea21021707933585299e57e0bd5b670805292a3dbf22c4dd66a6f3682fb3ee` |
| `tests/Integration/CourseTemplateLearningMappingHttpMariaDbTest.php` | `cd8e1127095c90add60c0471d04dcd0de6b6253b410d8e96a9ebb7f1b135376f` |
| `tests/Unit/AiAuthoringScriptProtocolTest.php` | `bd4f6641773e1e9c17e1c60e70bd973e3d6658c073473409e06eeee26c15a5de` |
| `tests/Unit/AiAuthoringScriptBehaviorTest.php` | `1ecca50bfd1975f7fa1820e13ff857b4d525be2decc8c1ebf9f0d0073d9eaea5` |
| `tests/js/fake-dom.mjs` | `2509647dbbb0b7eb95b5a70b674b03db575fd0682c8da3677670f7fe604bd161` |
| `tests/js/ai-authoring.behavior.test.mjs` | `5df3205920cea822a084ccee502618ca26914d032f09d215684c53821a7393bc` |
| `docs/platform/LF-AI-Authoring-Review-UI-Design.md` | `6a967dda974f7648810868c06563ff16672b9506c7a6111452c410c96a3477b6` |
| `routes/modules/ai-authoring.php` | `61ef30963f09a99305ccf01edb6c7eb06f1d544cf9f1459a2d274c539c4b2d86` |
| `app/Http/Controllers/AiAuthoringController.php` | `4d2f380d4a807916e396ce6bd84e1a412a47bc9e694b67650024ed5e7f193f82` |
| `docs/platform/LF-AI-Authoring-Proposal-Contract.md` | `fd23a83289e499015f46b66f1afff152130423742aa1f773f3be690bb59cd18f` |

## 3. Trạng thái R1–R11

`JS:n` là `resources/js/ai-authoring.js:n`. `T`, `P`, `B`, `M` là evidence reviewer thực chạy tại các mục dưới.

| Finding cũ | Trạng thái | Bằng chứng độc lập và phần còn lại |
| --- | --- | --- |
| R1 HIGH — teardown | **ĐÓNG MỘT PHẦN** | P1/P2/P9, B8-Save/B8-View đạt cho detail/editor/note/selection và DOM. Nhưng P10 mở rộng, P14 và B9 chứng minh bulk textarea còn qua DOM/parent reference; N1 HIGH. |
| R2 HIGH — transport/async | **ĐÓNG MỘT PHẦN** | P3/P7/P8: HTTP phân loại trước parse, denial status vô hiệu late detail. `request/revoke`, JS:232–245, 326–352 và guards sau request đúng ở đường cũ. Nhưng response của detail cũ vẫn gọi `loadList(true)` phá draft mới (P15/B10, N2); sau await reread còn announce vượt denial (P16, N3). |
| R3 HIGH — frozen retry/draft | **ĐÓNG MỘT PHẦN** | P5/P11/T1: unknown khoá controls; retry đúng UUID/body; discard rồi sửa tạo UUID mới; reread bỏ pending identity. Guard draft khi đổi filter/row/close và beforeunload có test. Lỗi cũ DRAFT_CHANGED gửi DRAFT_FIRST đã đóng. Vẫn có đường mất draft mới không hỏi từ late response, N2, không đếm thêm một HIGH trùng. |
| R4 HIGH — tenant root | **ĐÓNG** | P13 riêng trên MariaDB: tenant ID1006, root có `other-owner/keep.txt`, Activity folder mới; exit1, chỉ còn một canary nguyên vẹn, 0 proposals. T3 có cả existing leaf/root. P12 file/link/dangling link và race đạt. Seed:129–140 dùng mkdir độc quyền. |
| R5 HIGH — remote disk | **ĐÓNG** | P12 dùng DB mock cấm table calls và adapter sentinel: s3 + driver lạ exit1, adapter built=0; env ngoài local/testing cũng exit1 trước DB. T3 kiểm driver sentinel; mediaRoot được gọi trước query tenant (Seed:69–79,118–124). Không kết luận guard chống mọi filesystem mount/adapter override ngoài cấu hình được hỗ trợ. |
| R6 MEDIUM — cursor | **ĐÓNG** | JS:424 xoá selection cho cả reset=false; P4 và test `moving to another page...` pass; mutation bỏ reset ở cursor bị bắt. Reviewed metadata vẫn có thể giữ cho các dòng đã tải, nhưng lựa chọn không xuyên lần load. |
| R7 MEDIUM — generation recovery | **ĐÓNG** | JS:925–933 giữ UUID/pending cho gate_incomplete, nút đọc `/generation-requests/<UUID>`; T1 test generation kiểm cùng command dùng cùng UUID và soft404 không revoke. Không tự tạo yêu cầu mới để thay trạng thái cũ. N3 là lỗi announce sau reread, không phải lỗi link recovery cũ. |
| R8 MEDIUM — row identity | **ĐÓNG** | JS:named/ref, table và showBulkResult dùng #8 ký tự UUID + loại; B6 hai Node mapping có #b7e5ae01 và #65bef0dd, mỗi kết quả có nút Xem; T1 mixed outcomes và unknown-only retry pass. |
| R9 MEDIUM — list/mobile | **ĐÓNG** trong phạm vi finding cũ | B7 thực: desktop mở/thu sidebar, mobile390 td grid, thead none, 6 data-label cho 2 rows, không overflow toàn trang. Toolbar count/create và admin-table-has-actions đúng source. D10 chấp nhận nút Xem. Zoom200%/screen reader chưa được chứng nhận, xem ma trận. |
| R10 MEDIUM — weak oracle | **ĐÓNG MỘT PHẦN** | 14 behavioral tests pass; ba mutation cũ đều bị bắt bởi bộ kết hợp mới. Nhưng M11/M12/M15 sống sót (bỏ riêng epoch Bulk/Generate; thêm computed sink qua biến). Tests bỏ sót parent graph và input bulk thật; N4 MEDIUM. Không đồng nghĩa source hiện tại có XSS. |
| R11 MEDIUM — D8 backlink | **ĐÓNG** | T3 chạy cả ba test backlink của MappingHttp: flat HTML, section HTML, Lesson/missing Activity không link. Partial:7–17 dựng URL từ cây Course đã có, line54 giữ manual remove; không AI UUID lookup/API href. Revoked backlink click riêng chưa browser kiểm, authority middleware được T3 HTTP kiểm. |

## 4. Findings còn mở ở lượt 2

### P3A-R2-N1 — HIGH — Ghi chú bulk vẫn nằm trong bộ nhớ sau revoke và trong DOM lúc pagehide

**Vị trí:** JS:188–192,326–352,1050–1075; test `tests/js/ai-authoring.behavior.test.mjs:115–122,321–333`.

**Tái hiện:** nhập canary vào textarea bulk qua sự kiện input. (a) revoke forbidden; (b) rời trang bằng link. `revoke()` xoá `bulkNote` và thay list DOM nhưng không reset `bulkCountEl`/`bulkButtons`. `renderList` khi items rỗng không gọi `bulkBar()`, nên nhánh reset references ở 1051–1053 không chạy. Từ paragraph được giữ có thể đi qua parentElement đến textarea cũ. pagehide chỉ xoá chuỗi bulkNote, không thay/clear textarea của list.

**Bằng chứng:** P10/P14 fail trên module nguyên bản. B9a chạy cùng module byte-identical trong browser với synthetic transport: `retainedBulkParent=true`, `retainedTextareas=[CANARY_BULK_BROWSER]`, `retainedBulkButtons=3`, `visibleTextareas=0`. B9b điều hướng thật: observer pagehide chạy sau handler của module vẫn thấy `values=[CANARY_BFCACHE_BULK]`, `bulkNote=""`, `epoch=0`. Browser quay lại có persisted=false; không suy diễn đã quan sát BFCache persisted=true. B8 thực backend chỉ chứng minh DOM sạch, không phủ định leak reference này.

**Tác động:** human decision note có thể chứa nội dung/nguồn nhạy cảm, vẫn được instance giữ sau thu hồi; vi phạm §4.1/§4.2 và mục “mọi tham chiếu” ở §13.1. Không chứng minh unauthorized DB write hay một actor khác đã đọc được note.

**Đề nghị:** teardown chung phải xử lý cả bulk DOM và toàn graph reference; pagehide phải loại nội dung input thực, không chỉ scalar state. Test nhập vào textarea rồi phát input; kiểm ancestor/reachable graph trong browser, không chỉ subtree của các node trực tiếp được giữ.

### P3A-R2-N2 — HIGH — Phản hồi Save của A đến muộn làm mất bản nháp B

**Vị trí:** JS:1352–1388, đặc biệt nhánh `!stillHere` → `loadList(true)`; `loadList` reset gọi `dropDetail(true)` tại 418–421.

**Tái hiện:** mở A → sửa → Save nhưng giữ response; người dùng đồng ý rời draft A và mở B → Edit B, nhập `SECOND_UNSENT_DRAFT`; trả success của Save A. Epoch không đổi vì không có denial; detailSeq đổi. Code biết `stillHere=false` nhưng vẫn reset cả list/detail.

**Bằng chứng:** P15 sau sửa harness chờ detail B tải xong: editor title từ SECOND_UNSENT thành undefined. B10 browser thật, module nguyên bản với response tổng hợp được giữ bằng Promise: trước release `draft=SECOND_UNSENT_DRAFT`; sau release `draft=null`, `details=""`, status “2 proposals loaded.” Không có lần hỏi về draft B. Harness tự chấp nhận xác nhận rời A để kiểm race; đây không phải test response-loss trên HTTP/backend thật. Source đủ chỉ ra reset không qua guardDraft. Không sửa module để tạo lỗi.

**Tác động:** mất nội dung người dùng vừa nhập, không warning, trái §4.1 mã thế hệ theo proposal và §4.2 bảo vệ draft. UUID/guard backend không khôi phục được draft chưa gửi.

**Đề nghị:** response ngoài detail generation hiện hành không được reset editor của proposal khác; refresh metadata phải bảo toàn draft hiện tại hoặc yêu cầu quyết định rõ trước khi huỷ. Test response-order A→B riêng với authority epoch không đổi.

### P3A-R2-N3 — MEDIUM — Live region bị thông báo thành công cũ ghi đè lỗi mất quyền

**Vị trí:** JS:937–944 (`afterGeneration`),1383–1384 (`runCommand`),1190–1191 (`runBulk`, await rereads rồi announce).

**Tái hiện/bằng chứng:** P16: `afterGeneration('completed',7)`; GET list sau đó trả HTML403. `revoke` đặt revoked=true, announce error_forbidden; khi await trở về, afterGeneration vẫn announce create_done. Assert statusRegion=error_forbidden thất bại với actual=create_done. Hai nhánh command/bulk có cùng pattern source; lượt này chỉ fault-execute nhánh Generate, không gán PASS/FAIL runtime cho hai nhánh kia.

**Tác động:** banner lỗi vẫn còn và DOM nội dung đã bị gỡ, không phải bypass quyền; nhưng thông báo cuối cho assistive technology không còn phản ánh denial mới nhất. Yêu cầu “sau mỗi await” chưa trọn vẹn.

**Đề nghị:** kiểm lại generation/epoch sau các reread được await, trước mọi announce/side effect; giữ kết quả mất quyền mới nhất ưu tiên. Bổ sung test outcome-success followed by reread-denial.

### P3A-R2-N4 — MEDIUM — Behavioral/static suite còn các mutation sống sót

**Vị trí:** `tests/js/ai-authoring.behavior.test.mjs`, `fake-dom.mjs`, `tests/Unit/AiAuthoringScriptProtocolTest.php:56–64`.

**Bằng chứng:** M11 bỏ kiểm epoch riêng trong runBulk và M12 bỏ kiểm riêng trong runCreate: **14/14 Node + 22/22 Protocol (44 assertions) vẫn pass**. M15 thêm sau dòng textContent: `const member = 'inner' + 'HTML'; node[member] = String(text);` — cả hai bộ vẫn pass. FakeNode không parse HTML; scanner không phân giải biến này. Mutation thay thế dòng textContent bằng sink (M14) bị bắt không chứng minh mutation thêm sink cũng bị bắt.

Test pagehide hiện gán `app.bulkNote` trực tiếp, không nhập textarea; `leaks()` duyệt descendants của held nodes, không parentElement. Vì thế N1 thoát oracle. Test async chỉ bảo vệ một số flow sau denial, không bảo vệ race A→B của N2.

**Đề nghị:** bổ sung regression trực tiếp cho N1–N3 và guards riêng Generate/Bulk, có browser test XSS thật hoặc kiểm AST/sink phù hợp. Không cần hứa source regex chứng minh mọi dạng JavaScript. Không có sink XSS hiện hữu được phát hiện trong module chưa đột biến.

## 5. Evidence thực thi độc lập

### 5.1. Bộ test/build/lint

| ID | Lệnh/phạm vi reviewer thực chạy | Kết quả |
| --- | --- | --- |
| T1 | `node --test tests/js` trên snapshot sạch | **14 passed**. PHP wrapper được chạy thêm trong T2. |
| T2 | HostSection + ScriptProtocol + ScriptBehavior, SQLite :memory:, DB_URL rỗng, host invalid/port0/socket nonexistent | **37 passed,141 assertions**, 2.51s, lượt cuối sau phục hồi toàn bộ copy. |
| T3 | Seed + LearningMappingHttp + AiAuthoringHttp + ProposalService + AiAuthoringPublication + MappingPromotion, MariaDB tạm | **104 passed,941 assertions**,10.099s. Bao gồm 7 Seed và 25 MappingHttp cases (ba backlink trong đó). Không skip SQLite thay cho MariaDB. |
| T4 | Full `php artisan test --compact`, explicit safe SQLite env | **1370 passed,41 skipped,7 failed,11585 assertions**,165.28s. Không tái lập 1395/23/11912 của implementer. |
| T5 | Lặp filter 7 lỗi media sau tạm trả 8 tracked P3-A files trong copy về HEAD | **Cùng 7 failed +2 passed,18 assertions**,9.29s; SHA-256 phục hồi cả8. Đây là baseline đối chiếu hẹp, không tuyên bố full HEAD suite pass. |
| T6 | Pint --test 10 PHP files; docs:lint; schema:drift --docs-only; git diff --check | PASS; docs94 legacy metadata debt allowlisted; schema101 migration files. Không chạy formatter ghi repo. |
| T7 | npm run build với dependency offline copy riêng | PASS4.82s. VITE Reverb giá trị tổng hợp, hostlocalhost:18992. Không npm install. |
| T8 | route:list --path=ai-authoring --json; bộ khoá LF_ai_authoring_* VI/EN | **41 routes;206/206 keys**, chênh hai chiều rỗng. |

T3 dùng MariaDB11.4.12, `--no-defaults --skip-networking`, query xác nhận skip_networking=1; DB `lf_p3a_r2`, socket duy nhất trong thư mục tạm. 101 migrations DONE trước test; ALTER AUTO_INCREMENT saas_customers=900 trước mọi seed. Bootstrap PHPUnit kiểm literal đúng DB/socket và đặt RefreshDatabaseState::$migrated=true sau migration thật để tránh migrate lặp; vẫn transaction/rollback và assertions nguyên bản.

T4/T5: bốn Office cases trả processing_failed thay ready; hai FasterWhisper cases provider_unavailable vì runtime bị loại; corrupt-video case trả processing_failed thay audio_extraction_failed. T5 giữ cùng lỗi khi bỏ tracked P3-A changes. Không có bằng chứng quy chúng cho P3-A; nguyên nhân hệ thống sâu của năm lỗi Office/video chưa xử lý. **Full suite không PASS.** Hai ca thiếu runtime được phân loại trực tiếp, không cài provider/runtime hay dùng DB thật để ép xanh.

Lượt targeted đầu bị lẫn file probe bổ sung của reviewer trong tests/js nên wrapper báo fail4 expectations mới. Đã tách probe ra khỏi thư mục, chạy lại bộ sạch T2. Một lượt targeted chồng thời gian với việc thay baseline trong copy không dùng làm chứng cứ; T2 cuối được chạy tuần tự sau SHA restore. Build thử quá sớm khi copy dependencies chưa xong báo vite not found; T7 chỉ tính lượt sau copy hoàn tất. P13 harness ban đầu từ chối vì so `/tmp` với đường dẫn canonical `/private/tmp`; sửa đúng literal socket của harness rồi chạy lại. Các lỗi chuẩn bị này không tính là lỗi sản phẩm.

### 5.2. Đối chiếu P1–P13 và probe bổ sung

| Probe lượt1 | Kết quả lượt2, bằng chứng thực chạy |
| --- | --- |
| P1 | Own Node probe qua transport thật của module với HTML401/419/403/404/503: revoked=true, detailData/editor/noteField=null, selection0, không CANARY trong host. B8 bổ sung middleware thật. |
| P2 | Own probe `dropDetail(true)` sau editor/note: noteField/editor=null; T1 cũng kiểm commandArea/actionsHost. |
| P3 | Own transport probe tất cả status trên: phân loại theo HTTP, không đẩy403/404/503 thành unexpected. T1 thêm500 và JSON-path. |
| P4 | Own probe `loadList(false)` với next_cursor: selected.size=0; T1 pagination case. |
| P5 | T1 `after the proposal is read again...`: unknown decision→GET same guards→same choice dùng UUID mới; M7 bỏ clear bắt được lỗi. |
| P6 | Own probe hai runCreate song song khi response còn held: chỉ1 POST. Guard createBusy được bổ sung; không dùng direct-call probe này thay hai-tab UI concurrency. |
| P7 | Own probe refreshGeneration403 thu hồi content; T1 late-denial test. |
| P8 | Own probe held detailGET→status403→old detail success: detailData=null, không CANARY. |
| P9 | Own probe content_denied stale qua openDetail sau editor: payload và noteField=null, reviewed0. Không gọi renderDetail trực tiếp bỏ qua lifecycle thật. |
| P10 | T1 cách cũ (gán bulkNote trực tiếp) pass; own probe nhập textarea/input thật rồi pagehide **FAIL**: CANARY còn DOM. B9 xác nhận browser. |
| P11 | T1 dùng actual Save/Retry handlers: controls disabled khi unknown; retry giữ title DRAFT_FIRST cùng UUID/body. Discard→DRAFT_CHANGED tạo UUID mới. Bản sửa không còn đổi âm thầm dưới frozen retry. |
| P12 | Own PHP DB mock `table()->never`: production/staging/preview/LOCAL/rỗng exit1; force bị parser từ chối. s3 + remote_sentinel driver exit1, adapterBuilt0. Existing dir/file/link/dangling link đều từ chối. Hai child processes tranh cùng claimMediaFolder: exits[0,1]; canary ở symlink target nguyên vẹn. |
| P13 | Own real MariaDB transaction/rollback: next tenant1006 root có canary, Activity chưa tồn tại trên disk; seed exit1, files1→1, canary nguyên vẹn, proposals0. T3 cũng kiểm trường hợp này. |
| P14 mới | Revoke rồi đi từ held bulkCountEl lên parent: còn CANARY_BULK_TYPED; B9a tương đương trên real DOM. |
| P15 mới | Held save A, chuyển/sửa B, release A: mất SECOND_UNSENT; B10 real browser cùng module/scripted response xác nhận. |
| P16 mới | Generate completed rồi reread403: revoked=true nhưng cuối live region create_done; N3. |

Own supplemental Node suite có9 tests:5 pass,4 fail (P10 mạnh hơn, P14, P15, P16). Nó dùng helper DOM/transport của tests/js để gọi module snapshot, với assertions và schedules reviewer tự viết. Không gọi fake DOM là browser. P5/P11 tái chạy test hành vi tương ứng, kiểm source của oracle và mutation; không lấy con số implementer làm evidence.

### 5.3. Mutation

Tất cả chạy trên copy `mutations`, vendor vật lý, mỗi lần sửa một variant rồi phục hồi SHA-256 trước variant tiếp. Bộ chạy là Node14 + Protocol22; các mutation cố ý làm sai không được giữ lại. Ba mutation bắt buộc lượt1 là M1–M3. Reviewer thử thêm tổng cộng15 variants, **12 bị bắt,3 sống sót**.

| ID | Mutation | Node / Protocol | Kết luận |
| --- | --- | --- | --- |
| M1 | textContent → literal innerHTML | fail / fail | Bắt |
| M2 | textContent → `node['inner' + 'HTML']` | fail / fail | Bắt |
| M3 | pagehide callback không teardown | fail / pass | Behavioral bắt, static riêng vẫn không bắt |
| M4 | bỏ release editor trong dropDetail | fail / pass | Bắt |
| M5 | bỏ release noteField | fail / pass | Bắt |
| M6 | controls không khoá khi locked | fail / pass | Bắt |
| M7 | bỏ xoá pending identity ở openDetail | fail / pass | Bắt |
| M8 | bỏ mọi epoch guards của write/status flows | fail / pass | Bắt, không chứng minh từng flow được phủ |
| M9 | HTML403 → unexpected | fail / pass | Bắt |
| M10 | clear selection chỉ khi reset=true | fail / pass | Bắt |
| M11 | bỏ riêng epoch guard runBulk | **pass / pass** | **Sống sót** |
| M12 | bỏ riêng epoch guard runCreate | **pass / pass** | **Sống sót** |
| M13 | bỏ bulkNote scalar clear ở pagehide | fail / pass | Bắt nhưng không kiểm textarea thật |
| M14 | thay dòng textContent bằng sink qua biến member | fail / fail | Bắt; source oracle thiếu textContent cũng góp phần |
| M15 | giữ textContent, thêm sink qua biến member | **pass / pass** | **Sống sót**, N4 |

### 5.4. Browser B3–B8 và bổ sung

B3–B8 dùng build nguyên bản trong app copy, MariaDB tạm, host `ai-demo.localhost:18992`, tenant1007/admin303, Template109/Lesson110/Activity110. Chỉ dữ liệu tổng hợp. B9–B10 dùng file harness riêng tại cùng loopback host, module được copy byte-identical, transport trả JSON tổng hợp không nối DB. Harness chỉ bổ sung controls/evidence; không sửa logic module. Không gộp hai loại evidence thành browser E2E backend.

| Ca | Quan sát lượt2 |
| --- | --- |
| B3 | Edit competency title chứa b-tag, body chứa script và img/onerror; Save revision2. Title document vẫn Xem hoạt động, payload có0 b/script/img elements; canary hiện nguyên văn. Focus heading chi tiết sau reread. |
| B4 | Shared dialog focus ban đầu Hủy; Escape trả focus Chấp nhận; Enter xác nhận accept. Sau request hoàn tất: accepted revision2, thông báo chưa áp dụng, không Apply. Có một thao tác View sớm khi accept còn đang hoàn tất chưa mở đúng mapping; đã chờ trạng thái và thực hiện lại B5, không lấy trạng thái trung gian làm PASS. |
| B5 | Reuse_existing confidence58%, weight chưa đặt, chỉ Reject; propose_new confidence52%, weight0.5, Edit/Accept/Reject. |
| B6 | Đọc/chọn hai mapping rồi bulk Reject;2success/0failure/0unknown. Kết quả có mã khác nhau #b7e5ae01/#65bef0dd, mỗi dòng có View. Backend counts cuối: usage1, applications0, CourseVersions0, reviews2accept/1edit/3reject gồm seed. |
| B7 | Mobile390×844: td display:grid, thead:none, data-label Loại/Trạng thái/Thao tác, scrollWidth375<innerWidth390; screenshot card thực. VI→EN qua switcher. Desktop1280×900 expanded/collapsed: scrollWidth1265, bảng/actions còn đọc được. Không chứng nhận zoom200% hoặc screen reader. |
| B8-Save | Nhập canary title/body/detail note/bulk note; chuyển đúng admin303 tenant1007 sang inactive bằng SQL DB tạm; bấm Save revision. Vùng AI không còn input/textarea, payload/canary vắng; chỉ Create proposals disabled=true; focus Proposal list. |
| B8-View | Reactivate/reload, mở/chọn Concept và nhập note; inactive lần nữa; View cùng proposal. Kết quả như Save: values[], nút ghi bị gỡ/Create disabled, focus list. Sau đó phục hồi active để hoàn tất browser. |
| B9a | Nhập bulk note trong real DOM harness rồi revoke: DOM hiển thị sạch nhưng parent subtree bị giữ vẫn có canary; N1. |
| B9b | Click link rời harness và browser Back. Real pagehide observer sau handler module vẫn thấy canary textarea dù bulkNote rỗng. Cả pagehide/pageshow persisted=false; **CHƯA PHỦ BFCache persisted=true**. Không thay persisted bằng event giả rồi gọi đó là engine proof. |
| B10 | Browser harness giữ Save A; mở Edit B/nhập draft; release responseA làm details rỗng/draftnull. Synthetic confirmation đồng ý rời A; không có confirm khi draftB bị reset. N2. |

## 6. Verdict cho 12 câu hỏi

| # | Verdict | Bằng chứng/kết luận |
| --- | --- | --- |
| 1 Quyền host | **APPROVE** | Source controller552–588/shared actorRole; T2 host roles/creator/ended assignment; T3 active/inactive/foreign tenant/parent/replay tests. B8 denial thật đúng; không mở quyền UI thay backend. |
| 2 Lộ nội dung | **REJECT** | Initial partial chỉ config/messages/CSRF, read transport không lưu browser storage; detail denial đã sửa. N1 vẫn giữ human content; P14/B9. Late content response sau denial P8 pass, chưa BFCache persisted=true. |
| 3 XSS | **APPROVE WITH CHANGES** | Source textContent/value, B3 canaries không execute; không thấy actual sink. N4 scanner bypass còn, không chứng nhận mọi field/Media/criteria canary bằng browser. |
| 4 Lệnh/idempotency | **REJECT** | T1/T3 replay/guards, frozen editor đúng; N2 mất draft mới vì response cũ. Hai tab thực/response lost backend browser chưa tái lập đầy đủ. |
| 5 Bulk | **APPROVE WITH CHANGES** | Cursor/selected/version eligibility/cap100/mixed outcomes/unknown-only retry được T1/T3 kiểm; B6 per-row identity đúng. Bulk note teardown N1 và riêng epoch test N4 còn. |
| 6 Generate | **APPROVE WITH CHANGES** | Framework của Template/admin-only handoff source/T2; incomplete gate đọc đúng request và cùngUUID T1. 202/failed wording đúng,10 gate mappings source giữ. N3 live status sau denial; chưa browser ba quota cases. |
| 7 Hợp đồng/honesty | **APPROVE** | B4/B5, T3 và DBcounts: accept≠apply/publish, confidence≠weight, reuse chỉ reject, bốn loại chữ dừng. Không phát minh DTO/target names/B/C. |
| 8 Demo seed | **APPROVE** trong phạm vi supported local disk | T3/P12/P13: môi trường/force/remoteadapter/rootcollision/symlink/exclusiveclaim đạt; realledger1; fake provider/in-memory settings; second-run no-op. Không dùng DB thật. |
| 9 i18n/a11y/UI | **APPROVE WITH CHANGES** |206keys đồng bộ; B4/B7/B8 focus, keyboard/dialog/mobile đạt; D10 hợp lệ. N3 live announcements; zoom200%/screen reader/longbounds đầy đủ chưa kiểm. |
| 10 Kiểm chứng | **REJECT** |37targeted+104MariaDB+14Node pass, nhưng3mutation sống, N1/N2 thực tế bỏ lọt; acceptance còn high-risk gaps. Full suite7fail đã phân loại, không PASS. |
| 11 Hồi quy | **APPROVE WITH CHANGES** | D8 test passed; T3 ManualMapping/Publication/Promotion; Activity media link còn; CSS scoped ai-authoring; import root guard. N2 regression mất draft; media baseline hạn chế đã khai báo. |
| 12 Chưa kiểm | **APPROVE WITH CHANGES** | Mục7–8 phân biệt chưa tái lập với chưa có evidence; không khẳng định tuyệt đối “chưa ai kiểm”. Zero-egress browser chưa chứng minh vì external favicon tham chiếu bởi layout. |
| Toàn P3-A | **REJECT** | N1/N2 HIGH phải sửa và reviewer xác nhận lại; không mở P3-A/P3-B bằng số test pass. |

## 7. Acceptance §6 ↔ evidence

Đủ bảy nhóm gốc, phân rã để không che khoảng trống. `CHƯA PHỦ` là reviewer chưa có evidence thực thi thích hợp ở lượt này, không có nghĩa chắc chắn chưa ai từng kiểm.

| Nhóm / acceptance | Evidence lượt2 | Trạng thái |
| --- | --- | --- |
| Authority admin/teacher3roles/creator không assignment/observer/ended | T2 Host; T3 HTTP | PASS backend/host; browser teacher riêng CHƯA PHỦ |
| Guest401/middleware403/object404; inactive/foreigntenant/parent | T3 HTTP; B8 | PASS |
| Revoked write/replay; admin-only routes/buttons/foreign receipt | T3 HTTP; T2 | PASS backend; toàn script teardown FAIL N1 |
| Initial AI HTML không payload | Partial source + Host T2 | PASS trong vùng AI; host vốn có attachment, không coi là leak mới |
| HTML/script/event attrs/closing script AI/Media/criteria/human | B3 title/body; source el/payload/mapping; M1/M2/M15 | PARTIAL; Media/criteria mọi field browser CHƯA PHỦ; scanner N4 |
| Audit503 null/source detach/tombstone/stale | T3 HTTP/ProposalService; P1/P9 | PASS backend/detail removal; bulk memory FAIL N1 |
| Erasure source/Activity mất không hồi sinh nội dung | T3 tombstone/source tests, source deny | Dedicated erasure end-to-end UI CHƯA PHỦ |
| Session expiry/nonJSON denials/late detail afterdeny | P1/P3/P7/P8; T1 | PASS tested schedules |
| Pagehide/history/BFCache/cache/tab/URL/log | P10/P14/B9/source | FAIL N1; BFCache persisted=true, two tabs và browser cache/storage forensic CHƯA PHỦ |
| Fivekind/schema/bounds/unknown fields/integer guards | T3 HTTP/ProposalService; editor source; B3/B5 | PASS backend; exhaustive UI bounds CHƯA PHỦ |
| Confidence vs nullable weight/reuse deferral | B5; T3 | PASS |
| No detail→blind bulk/exactrevision | T1/T3; B3/B6 selection enable only afterread | PASS core |
| Client422 vs server422/nonJSON/rate429 | source submitEdit/failureMessage; T3 validation; P3 | PARTIAL; server422/429 browser CHƯA PHỦ |
| Frozen UUID/body retry/changedbody/newcommand | T1 unknown/discard/reread; T3 replay | PASS tested core; true browser lost-response DB oracle CHƯA PHỦ |
| Doubleclick/two-tab/race responses | P6/T3 guards; P15/B10 | FAIL N2; actual two-tab race CHƯA PHỦ |
| Mixed ordered outcomes/duplicateIDs/max100 | T1 mixed/unknown; T3 bulk/bounds | PASS backend/runtime; B6 browser all-success only |
| Cursor/filter reset/no global select | P4/T1/source | PASS; >25 real-browser pagination CHƯA PHỦ |
| 202 manual refresh/no worker; failed notsuccess | T1 generation/source; T3 status | PARTIAL browser202/failed CHƯA PHỦ; reread-denial FAIL N3 |
|10gatecodes/quota entitlement/refusal/overage | source GATE_MESSAGES; T1 incomplete UUID; backend gate tests in T4 | PARTIAL; three UI quota fault paths CHƯA PHỦ |
| Lifecycle text/competency accepted≠applied, awaitingadmin, reuse/new | B4/B5/DBcounts/T3 | PASS P3-A |
| Operations/target/context/inheritance/retrycancel/rebase | Some backend T3; no P3B/C UI claim | N/A P3-A UI; separate phase gate |
| Fixtures production/staging/force beforeDB | P12 mock; T3 | PASS |
| Fixtures localownership/symlink/mkdir race | P12/P13/T3 | PASS tested leaf/root collision + exclusive mkdir; no arbitrary filesystem attack proof |
| Fakeprovider/no credentials/durableapproval/realledger | source DemoProvider/InMemory; T3; seeded counts | PASS command scope; browser externalasset caveat §8 |
| VI/EN/empty/noresult/loading/error | T8/B7/B8/source | Keys/basic states PASS; empty/filteredempty browser CHƯA PHỦ |
| Desktop sidebar/mobile/carddata-label/longstrings/zoom200 | B7 | Mobile390 + desktop1280 PASS; full longbounds/tablet/zoom200 CHƯA PHỦ |
| Focus/dialog/keyboard/aria-live/busy/perrow | B4/B8/B6/source | Core PASS; N3 live error; screenreader/entire focus matrix CHƯA PHỦ |
| Boundedlist/history | source PAGE_SIZE25/reviews_limit100 + T3 bounds | Browser25+/100history CHƯA PHỦ |
| ManualMapping/Activitymedia/Versionpublish | T3 MappingHttp/Promotion/Publication; B3 attachment; T4 | PASS tested scopes;7media baseline failures remain unverified-runtime |
| D8 flat/section/Lesson/missing/revoked | T3 three backlink cases + authority tests; partial source | Flat/section/Lesson/missing PASS; revoked backlink click browser CHƯA PHỦ |

## 8. Lệnh, hạn chế và an toàn

| Command / thao tác thực chạy | Phạm vi/kết quả |
| --- | --- |
| cat/sed/nl/rg, git status/diff --check, Python hashlib | Repo chỉ đọc; cuối24/24hash không đổi. Báo cáo là file mới duy nhất. |
| rsync app excluding .git,node_modules,runtime,storage/*,.env*,bootstrap/cache/*.php | Copycode/vendor vật lý;0vendor symlinks. Build copy khác có dependencies offline; mutation copy vendor riêng. Không đọc/copy .env thật. |
| mariadb-install-db --no-defaults --datadir=.../db --auth-root-authentication-method=normal --skip-test-db | Bootstrap DB tạm; log khởi động có lock chờ installer kết thúc; server sau đó ready, query version/skip_networking đúng. |
| mariadbd --no-defaults --skip-networking --datadir=.../db --socket=.../db.sock --pid-file=.../db.pid --log-error=.../db.log | MariaDB11.4.12, PID35015; chỉ socket; không TCP3306/3307. |
| mariadb --no-defaults --socket=.../db.sock -uroot; CREATE DATABASE lf_p3a_r2; ALTER TABLE saas_customers AUTO_INCREMENT=900 | Tất cả SQL trên đúng instance tạm; các IDseed1006/1007 lớn hơn900. |
| php artisan migrate --force | Copy `.env` tổng hợp trỏ socket tạm;101migrations DONE. |
| DB_CONNECTION=mysql DB_DATABASE=lf_p3a_r2 DB_SOCKET=.../db.sock DB_HOST=localhost DB_PORT=0 DB_URL= php vendor/bin/phpunit -c phpunit-review-mysql.xml --do-not-cache-result [6files T3] |104tests941assertions; bootstrap validateDB/socket. |
| DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_HOST=invalid DB_PORT=0 DB_SOCKET=.../nonexistent.sock php artisan test --compact [T2 / toàn suite / baseline filter T5] | T2/T4/T5; không dựa vào default DB của repo. Baseline replacements chỉ copy, restore SHA. |
| node --test tests/js; node --test reviewer.probes.test.mjs | T1/probes; latter ban đầu trong copy tests/js, sau tách riêng. Transport scripted, không network. |
| node --test tests/js + php vendor/bin/phpunit --do-not-cache-result tests/Unit/AiAuthoringScriptProtocolTest.php trên từng mutation |15variants, SHArestore mỗi lần;12killed/3survived. |
| php seed-safety.php; php root-collision.php | P12/P13; DBmock hoặc DBtạm; symlink/race/canaries chỉ /tmp. |
| php artisan ai:authoring-demo-seed | Tenant1007 và media `app/storage/app/media/tenants/1007`;7proposals quafakeprovider,ledger1. Không in mật khẩu vào báo cáo. |
| php vendor/bin/pint --test [snapshot PHP]; php artisan docs:lint; php artisan schema:drift --docs-only; route:list; PHP translation set comparison | T6/T8 PASS. |
| VITE_REVERB_APP_KEY=(synthetic) VITE_REVERB_HOST=ai-demo.localhost VITE_REVERB_PORT=18992 VITE_REVERB_SCHEME=http npm run build | T7 PASS; localhost synthetic app settings, không secret thật, không install package. |
| php -S 127.0.0.1:18992 vendor/.../server.php từ copy/public; CUA browser | B3–B10. Source module ở harness SHA trùng snapshot. Hạn chế favicon dưới đây. |
| SQL active/inactive đúng users.id303/customer_id1007 | B8; restoredactive sau kiểm; DB sẽ bị xoá hoàn toàn. |
| viewport.reset; tab.close; kill37181; mariadb-admin --no-defaults --socket=.../db.sock -uroot shutdown | Browser/server/MariaDB đã dừng; xác nhận cleanup dưới đây. |

**Giới hạn zero-egress browser:** `resources/views/layouts/app.blade.php:6–7` và `auth.blade.php:6–7` có URL favicon ngoài `https://www.masterkorean.vn/images/favicon.svg`. Reviewer phát hiện sau B3–B8; không có network capture chứng minh request đã hoặc chưa được trình duyệt tự phát. Vì vậy **không chứng nhận đã bảo đảm zero-egress cho browser QA**. Đây là thiếu kiểm soát môi trường của reviewer, không phải finding mới do P3-A và không được giấu bằng câu “không ra mạng”. Không có thao tác điều hướng chủ động tới miền ngoài, cài package, thêm secret, gọi provider thật hoặc remote disk; harness chỉ gửi canary tổng hợp về loopback. Lượt kiểm tiếp theo phải chặn egress ở mức môi trường hoặc loại external asset chỉ trong bản sao trước khi mở browser.

Các claim chưa tái lập: fullsuite1395/23/11912; toàn bộ13mutation của implementer (reviewer làm15variants độc lập,3survived); BFCache persisted=true; browser response-loss/unknown retry có realDB exactly-once; hai tab; UI202/failed và ba quota paths; zoom200%/screenreader; đầy đủ Media/criteria XSS. Không thể biết tuyệt đối “chưa ai kiểm”; chỉ nêu chưa có evidence truy vết phù hợp. B/C, providerquality, activation, performance lớn không là yêu cầu thiếu của P3-A.

## 9. Cleanup và điều kiện đóng

**Đã dọn xong lúc 2026-09-29T23:20:34 (Asia/Ho_Chi_Minh).** Tab browser đã đóng, viewport reset. Web PID37181 và MariaDB PID35015 không còn tồn tại; socket/db.pid biến mất sau shutdown. Đã xoá toàn bộ `/tmp/lf-p3a-r2-5hnrcdzo`: datadir, app/build/mutation copies, vendor copies, synthetic environment, caches/logs, seed media tenant1007, fake-disk test media, canary tenant1006, symlink/race fixtures và browser harness/evidence tạm. Không có media reviewer tạo trong repo gốc. Không kết nối learnforge_db127.0.0.1:3307 hoặc XAMPP:3306. Không giữ mật khẩu hay credential trong báo cáo. Giới hạn external favicon được khai báo tại §8, không chứng nhận zero-egress browser.

Snapshot24/24 giữ nguyên. Chỉ file báo cáo Round2 này được tạo trong repo; báo cáo lượt1 và các thay đổi working tree trước lượt2 giữ nguyên. Không có code patch, migration, durable allow-list hay việc mở provider.

P3-A chỉ có thể đóng sau implementer xử lý N1/N2, reviewer độc lập tái kiểm snapshot mới và các đường browser tương ứng. N3/N4 cần sửa hoặc đăng ký theo quy tắc MEDIUM với owner/hạn cụ thể; các high-risk acceptance gaps không được thay bằng waiver hay số test tổng. Bảo đảm test môi trường không có egress trước browser lượt tiếp theo. Không sửa Frozen contract để hợp thức hoá lỗi UI.

P3-B vẫn cần P3-A được đóng và Owner duyệt riêng hai amendments `allowed_actions` + DTO tên/ứng viên Node. Review này không mở gate P3-B.
