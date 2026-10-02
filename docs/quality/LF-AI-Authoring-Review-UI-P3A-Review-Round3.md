# AI Authoring Review UI — Independent P3-A Implementation Review, Round 3

Version: 1.0

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-09-30

Document Path: quality/LF-AI-Authoring-Review-UI-P3A-Review-Round3.md

Reviewer: Codex — reviewer độc lập các lượt 1–3

Reviewed Design: v0.7, Approved; D10 đã được Owner duyệt

Reviewed Contract: v0.8, Frozen

Classification: Existing-Feature Change — independent implementation review

Initial Audit Level: HIGH

Final Audit Level: HIGH

Audit Level Escalation: None

P3-A Verdict: **REJECT**

Regression Final Verdict: **FAIL**

Findings By Severity: **0 BLOCKER, 1 HIGH, 1 MEDIUM, 0 LOW** còn mở. HIGH O1 mới; MEDIUM N4 tiếp tục từ lượt 2, không đếm hai lần.

## 1. Kết luận và độc lập

**Chưa đóng P3-A.** N1, N2, N3 đã được sửa trong các tình huống được báo ở lượt 2. P14–P16 và B9a/B9b/B10 đều đạt. Tuy nhiên phản hồi bulk vẫn có thể xoá bản nháp người dùng đang nhập mà không hỏi; reviewer tái hiện trên module nguyên bản bằng Node và trình duyệt thật. N4 đóng một phần: cả 15 mutation cũ bị bắt, nhưng ba mutation bổ sung có ý nghĩa vẫn qua cả Node và Protocol.

Reviewer chưa sửa implementation Bước 7, Phần 2, P3-A, thiết kế hoặc hợp đồng trong lịch sử phiên được cung cấp. Hai lượt trước chỉ tạo báo cáo. Lượt này chỉ tạo file Round3 trong repo gốc; không vá, reset, stash hay commit. Mutation chỉ trên bản sao vật lý trong `/tmp/lf-p3a-r3-yujzct4z`, không symlink vendor. Tài liệu §13.1/§13.2 được coi là khẳng định cần kiểm, không làm evidence.

Đọc theo thứ tự AGENTS → README → INDEX/routing → Guardrails → Regression Audit → brief v1.2/lượt 3 → báo cáo lượt 2 và lượt 1 → design v0.7 → design review §6 → contract Frozen. Giữ thứ tự authority Governance → owner contract → Approved design. Phạm vi thay đổi lượt này là JS, test và tài liệu; không tự mở schema/API hoặc P3-B/C.

Impact graph: Activity host/authoringRole → Blade/app.js → browser transport/review state → AI HTTP/service → Course/Learning/Media owner services. Audit HIGH vì quyền đọc, nội dung nhạy cảm, idempotency và mất bản sửa. D10 cho phép nút Xem hiển thị; **không tái mở R9 vì D10**.

## 2. Snapshot đầu / cuối

24/24 khớp prefix bàn giao ở đầu lượt; SHA-256 đầy đủ dưới đây là bytes working tree. Kiểm trước thực thi, trước cleanup và sau ghi báo cáo; **đầu = cuối**, không có drift. Bản mutation được phục hồi và so SHA sau từng ca.

| File | SHA-256 đầu = cuối |
| --- | --- |
| `resources/js/ai-authoring.js` | `1625ff4851466400190078b65dd8470c8365f9d6aeca7be3855ee4ce3669e31d` |
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
| `tests/Unit/AiAuthoringScriptProtocolTest.php` | `90a591128943cff70b6596e806c7ada61018c1fa964f3c9a6723c11de3552eea` |
| `tests/Unit/AiAuthoringScriptBehaviorTest.php` | `1ecca50bfd1975f7fa1820e13ff857b4d525be2decc8c1ebf9f0d0073d9eaea5` |
| `tests/js/fake-dom.mjs` | `8038ec74f43865ee3f9e776558549483e2daaab09f4beac2da193d0023ca8043` |
| `tests/js/ai-authoring.behavior.test.mjs` | `1667282d4d057e18384b73984120064b2ae206d7cf524ec38582ca7161c7a5c4` |
| `docs/platform/LF-AI-Authoring-Review-UI-Design.md` | `fb705e7f16e12ab94ba2ce6cedab05d9e8f88c6bdeaa3340f08a193581036302` |
| `routes/modules/ai-authoring.php` | `61ef30963f09a99305ccf01edb6c7eb06f1d544cf9f1459a2d274c539c4b2d86` |
| `app/Http/Controllers/AiAuthoringController.php` | `4d2f380d4a807916e396ce6bd84e1a412a47bc9e694b67650024ed5e7f193f82` |
| `docs/platform/LF-AI-Authoring-Proposal-Contract.md` | `fd23a83289e499015f46b66f1afff152130423742aa1f773f3be690bb59cd18f` |

## 3. Trạng thái N1–N4 và R1–R11

`JS:n` chỉ `resources/js/ai-authoring.js:n`; T/P/B/M là evidence của reviewer ở §5. H2 chỉ evidence lịch sử của chính reviewer trong [báo cáo lượt 2](LF-AI-Authoring-Review-UI-P3A-Review-Round2.md), không phải kết quả chạy mới.

| Finding | Trạng thái | Bằng chứng và phạm vi kết luận |
| --- | --- | --- |
| N1 — bulk note/held DOM | **ĐÓNG** | P14, B9a, B9b; JS:339–350 `releaseBulk`, dropDetail:605–623 scrub + clear note. Giá trị textarea thật rỗng; đi từ held node lên cha cũng không còn canary. BFCache persisted=true vẫn chưa chứng nhận. |
| N2 — Save A phá draft B | **ĐÓNG** | P15/B10: response A về, editor B vẫn giữ SECOND_UNSENT_DRAFT. Nhánh ngoài detail generation gọi `loadList(true,true)`. Không đồng nhất fix này với mọi luồng bulk; O1 mới ở §4. |
| N3 — success ghi đè denial | **ĐÓNG** | P16 và test hành vi thứ18 chạy cả Generate/Decision/Bulk; ba mutation M20–M22 đều bị bắt. Epoch sau reread giữ error_forbidden. |
| N4 — oracle bỏ lọt mutation | **ĐÓNG MỘT PHẦN** | M1–M15 đều bị bắt; M16 whitespace computed sink, M28 confirmation epoch, M29 status epoch sống. P19/P20 riêng làm M29/M28 đỏ, source nguyên bản đạt. Xem §4.2. |
| R1 — teardown | **ĐÓNG** trong các lỗi đã báo | Node20, P14, B9a/b chứng minh current detail/editor/note/bulk cleanup. Không còn bằng chứng leak N1 trên snapshot này. P18 là lịch async sau pagehide chưa có engine proof, ghi riêng ở §5.2, không gọi BFCache PASS. |
| R2 — transport/async | **ĐÓNG MỘT PHẦN** | Status classification/denial guards, late detail/command/create/bulk có test đạt; N2/N3 đóng. Bulk reread vẫn làm mất editor hiện hành O1. |
| R3 — retry/draft | **ĐÓNG MỘT PHẦN** | Unknown lock, exact body/UUID, discard/new ID, reread identity và guardDraft đều đạt Node20. O1 còn đường xoá draft không hỏi. |
| R4 — tenant media root | **ĐÓNG**, không thấy hồi quy | Seed source/hash không đổi; T8 mới từ chối dir/file/link/dangling link. H2 P13 root collision + transaction rollback và T3 MariaDB giữ giá trị lịch sử; không chạy seed lượt này. |
| R5 — remote media disk | **ĐÓNG**, không thấy hồi quy | T8 mới từ chối s3/driver lạ và env ngoài local/testing trước DB (mock table never). Seed:64–79/118–124 kiểm trước query. Không claim bảo vệ mọi mount/adapter override tùy ý. |
| R6 — cursor selection | **ĐÓNG** | Node test moving to another page; JS:448 xoá selected không phụ thuộc reset; M10 bị bắt. |
| R7 — generation recovery | **ĐÓNG** | Node generation incomplete dùng cùng UUID, GET đúng generation ID; soft404 không revoke. JS:955–963/1001–1033; M29 nêu khoảng trống test, guard thực tế đang có. |
| R8 — per-row identity | **ĐÓNG** | Node bulk two same-kind + unknown-only retry; JS named/ref/showBulkResult còn #UUID. H2 B6 là browser thực backend; chưa lặp backend browser lượt này. |
| R9 — list/mobile | **ĐÓNG** trong finding cũ | CSS/partial không đổi so H2 B7 (390px, desktop mở/thu sidebar); JS table giữ data-label, wrapper, loaded count. D10 đã duyệt. Không gắn kết quả mobile mới cho harness tối giản. |
| R10 — test strength | **ĐÓNG MỘT PHẦN** | 20 tests hữu ích và 15 mutation cũ caught; O1 thoát suite, N4 vẫn còn. |
| R11 — D8 backlink | **ĐÓNG**, không thấy hồi quy | Partial:7–17/54 và MariaDB test file không đổi; route HTML flat/section từ cây Course, thiếu Activity/Lesson không link. H2 T3 đã chạy ba backlink; lượt này chỉ đối chiếu source/hash. |

“Không thấy hồi quy” trên file không đổi + evidence H2 không tương đương chạy lại 104 test MariaDB hoặc mọi màn hình ở lượt 3.

## 4. Findings còn mở

### P3A-R3-O1 — HIGH — Bulk reread xoá bản nháp hiện hành không hỏi

**Vị trí:** JS:1181–1236, đặc biệt `reopen` ở 1229 và `openDetail` ở 1234; `openDetail`:637 gọi `dropDetail`, editor bị bỏ ở 620. Các nút Edit/ô nhập không bị khoá bởi bulkBusy.

**Tình huống:** mở đề xuất A, chọn A vào bulk, xác nhận Reject và giữ response. Trong lúc chờ, bấm Edit ở A rồi nhập BULK_NEW_DRAFT. Response bulk thành công trở về. `runBulk` chỉ xét openUuid có thuộc outcomes, không xét detail generation/draft vừa tạo, rồi tự mở lại detail. Không đi qua guardDraft.

**Bằng chứng độc lập:** P17 trên source nguyên bản: expected BULK_NEW_DRAFT, actual undefined. B11 bằng browser thật + transport tổng hợp giữ Promise: trước release `draft=BULK_NEW_DRAFT, status=bulk_running, confirmCount=3`; sau release `draft=null, status=bulk_summary, confirmCount=3`. Edit và input đều thao tác được từ UI; không gọi showEdit bằng evaluate. Số confirm không tăng lúc mất draft. Harness tự đồng ý các dialog để kiểm schedule; không dùng nó làm bằng chứng focus/shared dialog hay backend giao dịch thật.

**Tác động:** mất nội dung chưa gửi; vi phạm bảo vệ draft §4.2. Backend version guard không cứu bản nháp chưa gửi. Dù backend sau bulk trả trạng thái rejected và không cho sửa nữa, UI vẫn phải ngăn nhập hoặc thông báo trước khi huỷ, không âm thầm mất nội dung.

**Đề nghị:** quy định rõ interaction giữa bulk và editor; khoá thao tác xung đột khi lệnh đang chạy, hoặc bảo vệ detail generation/draft trước reread và yêu cầu quyết định rõ khi phải bỏ bản sửa. Thêm test response bulk đến sau khi editor được mở/đổi proposal; không chỉ test single Save A→B. Reviewer không vá.

### P3A-R2-N4 — MEDIUM, tiếp tục — Scanner và test async vẫn có mutation có ý nghĩa sống sót

**Vị trí:** `tests/Unit/AiAuthoringScriptProtocolTest.php:67–78`, `tests/js/ai-authoring.behavior.test.mjs`, guards thật JS:1009 và 1164.

**Bằng chứng:** M15 cũ nay bị Protocol bắt. Nhưng M16 chỉ thêm khoảng trắng hợp lệ: `const member = 'inner' + 'HTML'; node[ member ] = String(text);` sau dòng textContent. Node **20/20** và Protocol **23/23** đều pass; regex computed assignment không cho whitespace bên trong ngoặc. Đây là sink trên bản đột biến, **không phải XSS hiện hữu trong source giao**.

M28 bỏ epoch riêng sau await bulk confirmation và M29 bỏ epoch riêng refreshGeneration đều qua hai bộ. Reviewer thêm P20: giữ dialog → revoke → load list được phép trở lại → trả confirm cũ; module thật gửi 0 POST, mutant gửi 1. P19: giữ status GET → revoke → trả completed cũ; module thật giữ error_forbidden/không reread, mutant đổi status/gọi GET. Cả hai mutant thất bại với oracle bổ sung. Điều này phân biệt mutation có ý nghĩa với dòng thừa.

M27 bỏ nhánh xoá child text node cũng sống, nhưng nội dung nhạy cảm hiện được dựng ở leaf; chưa chứng minh mutant này có tác động thực tế trên cây sản phẩm. **Không tính M27 thành lỗi sản phẩm hay bằng chứng có leak hiện hữu.**

**Đề nghị:** thêm regression riêng confirmation/recovery schedules và bulk draft O1; cải thiện sink oracle cho syntax hợp lệ thông thường hoặc dùng phân tích cú pháp/browser canary thích hợp. Không hứa regex chứng minh mọi JavaScript. Không cần backend/contract amendment cho các sửa này.

## 5. Evidence reviewer thực chạy

### 5.1. Test và kiểm cấu trúc

| ID | Phạm vi | Kết quả mới lượt 3 |
| --- | --- | --- |
| T1 | `node --test tests/js`, bản sạch | **20 passed**, 0 failed; suite chưa bắt O1. |
| T2 | HostSection + ScriptProtocol + ScriptBehavior, explicit SQLite :memory: | **38 passed, 142 assertions**, 2.31s; Protocol riêng có23 tests. |
| T3 | Full `php artisan test --compact`, safe SQLite env | **1371 passed, 41 skipped, 7 failed, 11586 assertions**,91.45s. Không tái lập claim1396/23/11913. |
| T4 | Pint --test file PHP đổi lượt3; docs:lint; schema:drift --docs-only; git diff --check | PASS. Docs94 legacy allowlisted; schema101 migration files. Không chạy formatter ghi repo. |
| T5 | Offline dependency copy + npm run build | PASS1.07s; không npm install; VITE giá trị tổng hợp/localhost. |
| T6 | route:list JSON; key equality VI/EN | **41 routes;206/206 khóa**, chênh rỗng. |
| T7 | Own probes P14–P20 | **5 pass,2 fail**: P17 là O1; P18 là observation lifecycle chưa chứng minh lỗi engine. |
| T8 | PHP seed guards, DB::table never, reflection claimMediaFolder | Production/staging/preview/LOCAL/rỗng exit1; s3 và driver lạ exit1; dir/file/symlink/dangling collision đều từ chối; **0 DB calls**. |
| H2 | MariaDB 104 tests/941 assertions, seed/Mapping/AI HTTP/Proposal/Publication/Promotion | **Evidence lịch sử lượt2**, không rerun lượt3. Source PHP, routes, seed và các test snapshot tương ứng không đổi. |

T3 có cùng bảy case lỗi với lượt 1/2: bốn Office expected ready/actual processing_failed; hai FasterWhisper provider_unavailable do runtime bị loại; corrupt video expected audio_extraction_failed/actual processing_failed. H2 đã đối chiếu baseline hẹp sau bỏ tracked P3-A changes và vẫn bảy lỗi. Lượt3 không lặp baseline rollback đó, không khẳng định đã giải thích mọi nguyên nhân Office/video; full suite **không PASS**. SQLite không thay MariaDB CHECK/FK/trigger/concurrency. Không dùng số skipped làm PASS.

### 5.2. Probe và browser

| ID | Schedule/evidence | Kết quả |
| --- | --- | --- |
| P14 | Nhập bulk textarea, phát input; giữ count node rồi revoke; đi lên top ancestor | field.value rỗng, graph không CANARY, instance count=null. PASS. |
| P15 | Hold PATCH A → Edit B → release A | editor B còn B_UNSENT. PASS. |
| P16 | afterGeneration completed → list HTML403 | status error_forbidden. PASS. Node test18 bổ sung cả Decision/Bulk. |
| P17 | Hold bulk result → Edit cùng proposal → release | draft undefined. FAIL O1. |
| P18 | Hold bulk → fire pagehide → resolve → fresh detail GET trả OK | detailData đã null lúc pagehide nhưng lại có CANARY_BODY sau response. Đây là **event giả trong Node**, epoch không đổi và openUuid còn nên runBulk reread. Không chứng minh BFCache engine cho phép schedule đó, cũng không chứng minh server bypass authority: GET mới vẫn chạy. Ghi rủi ro cần engine test; **không tính finding bảo mật xác nhận**. |
| P19 | Hold status GET → revoke → completed cũ | source PASS; mutant M29 FAIL; không reread/không đổi denial ở source. |
| P20 | Hold bulk confirmation → revoke → authorised list reload → confirm cũ | source PASS0 POST; mutant M28 FAIL1 POST. |
| B9a | Real textarea CANARY_B9A → revoke → inspect held upward graph | `values=[]`, `heldField=""`, `heldTreeValues=[""]`, retainedCount=false, retainedButtons=0. PASS. |
| B9b | Real textarea CANARY_B9B → click Leave page → browser Back | Observer sau handler ghi pagehide values `["","on","on"]`; không canary. pagehide/pageshow **persisted=false**. PASS cleanup thường; không chứng nhận BFCache. |
| B10 | Real browser hold Save A → chuyển/Edit B → release A | SECOND_UNSENT_DRAFT còn; openUuid B, confirmCount1. PASS N2. |
| B11 | Real browser hold Bulk Reject A → Edit A → nhập → release | BULK_NEW_DRAFT → null, confirmCount3 không đổi. FAIL O1. |

B9–B11 dùng module byte-identical với SHA JS snapshot, DOM thật và synthetic transport. Không mock phương thức của AiAuthoring; chỉ fetch/confirmation harness. Không dùng DOM giả thay browser. Không lấy harness tối giản (nhãn là key, không layout LF) làm bằng chứng i18n, responsive hoặc shared confirmation.

### 5.3. Mutation độc lập

Bản sao app vật lý, original repo readonly. Mỗi variant chạy Node20 và Protocol23 rồi phục hồi SHA `1625ff4851466400190078b65dd8470c8365f9d6aeca7be3855ee4ce3669e31d`. **29 variants:25 caught,4 survive.** Trong4 survivor, M27 chưa chứng minh tác động; M16/M28/M29 có ý nghĩa. Không gán mutation score cho mọi lỗi có thể có.

| ID | Đột biến | Node / Protocol | Kết quả |
| --- | --- | --- | --- |
| M1 | textContent → innerHTML | fail/fail | Bắt |
| M2 | textContent → literal concatenated member | fail/fail | Bắt |
| M3 | pagehide callback không teardown | fail/pass | Bắt |
| M4 | không release editor | fail/pass | Bắt |
| M5 | không release noteField | fail/pass | Bắt |
| M6 | không khoá controls khi locked | fail/pass | Bắt |
| M7 | không clear pending identity khi openDetail | fail/pass | Bắt |
| M8 | vô hiệu mọi epoch compare | fail/pass | Bắt |
| M9 | HTML403 thành unexpected | fail/pass | Bắt |
| M10 | selected.clear chỉ khi reset | fail/pass | Bắt |
| M11 | bỏ epoch riêng runBulk | fail/pass | Bắt; survivor cũ đã đóng |
| M12 | bỏ epoch riêng runCreate | fail/pass | Bắt; survivor cũ đã đóng |
| M13 | không clear bulkNote scalar trong releaseBulk | fail/pass | Bắt; tương đương hành vi M13 cũ sau refactor |
| M14 | thay textContent bằng computed member variable | fail/fail | Bắt |
| M15 | giữ textContent, thêm computed member variable assignment | pass/fail | Bắt; survivor cũ đã đóng |
| M16 | M15 + whitespace `node[ member ]` | pass/pass | Sống; N4 |
| M17 | bỏ releaseBulk tại revoke | fail/pass | Bắt |
| M18 | bỏ releaseBulk tại pagehide | fail/pass | Bắt |
| M19 | bỏ bulkNoteField.value clear | fail/pass | Bắt |
| M20 | bỏ post-reread epoch announce Generate | fail/pass | Bắt |
| M21 | bỏ post-reread epoch announce Command | fail/pass | Bắt |
| M22 | bỏ post-reread epoch announce Bulk | fail/pass | Bắt |
| M23 | late Save trở lại loadList(true) | fail/pass | Bắt |
| M24 | bỏ scrub(detailHost) | fail/pass | Bắt |
| M25 | bỏ scrub input value branch | fail/pass | Bắt |
| M26 | bỏ scrub leaf textContent branch | fail/pass | Bắt |
| M27 | bỏ scrub child text-node branch | pass/pass | Sống, chưa chứng minh tác động |
| M28 | bỏ epoch sau bulk confirmation | pass/pass | Sống; own P20 bắt |
| M29 | bỏ epoch sau refreshGeneration GET | pass/pass | Sống; own P19 bắt |

## 6. Verdict 12 câu hỏi

| # | Verdict | Bằng chứng/kết luận |
| --- | --- | --- |
| 1 Quyền host | **APPROVE** | T2 Host; controller552–588 và service101–129 cùng actorRole với proposalContext. Admin active/teacher active + assignment3roles; creator/page-only không thay quyền AI. H2 HTTP authority evidence giữ riêng. |
| 2 Disclosure | **APPROVE WITH CHANGES** | N1 đã đóng qua P14/B9; partial config-only, source text/no storage. P18 và engine BFCache/two-tab còn chưa chứng nhận, không gọi leak engine xác nhận. |
| 3 XSS/free text | **APPROVE WITH CHANGES** | Source el dùng textContent, control.value; không thấy sink hiện hữu. N4 scanner vẫn bypass bằng whitespace; H2 B3 là canary browser lịch sử, chưa bao hết mọi field. |
| 4 Command/idempotency | **REJECT** | Frozen retry/UUID/guard đạt Node; single late Save fixed. O1 xoá draft bởi bulk reread. Two-tab UI chưa kiểm. |
| 5 Bulk | **REJECT** | Eligibility/version/cap100/ordered results/unknown-only retry/cursor reset đúng source/test; O1 mất draft là lỗi thực. |
| 6 Generate | **APPROVE** trong paths đã kiểm | Incomplete recovery đúng request/same UUID; N3 fixed. Framework readonly/admin-only handoff source/T2. 202/failed wording đúng; UI ba quota paths chưa exhaustively browser. |
| 7 Hợp đồng/honesty | **APPROVE** | stateNote/reuse branch/confidence-weight không đổi; không apply/publish bốn loại chữ, reuse chỉ Reject. H2 B4/B5/DB counts là historical evidence; không đòi B/C. |
| 8 Demo seed | **APPROVE** trong supported local disk | T8 fresh pre-DB/collision guards; source/hash nguyên; H2 ledger/no durable settings/atomic mkdir race không rerun. Không blanket mọi cấu hình/mount. |
| 9 i18n/a11y/UI | **APPROVE WITH CHANGES** | T6 206keys; N3 live status fixed; D10 hợp lệ. H2 mobile/shared focus giữ historical status. Zoom200%, screen reader, toàn error focus chưa chứng nhận. |
| 10 Kiểm chứng | **REJECT** | T1/T2 pass,15mutants cũ caught; O1 thoát suite, N4 còn. T3 full suite có7fail; ma trận còn gap. |
| 11 Hồi quy | **APPROVE WITH CHANGES** | Source host/manual backlink/CSS scoped/import root guard giữ; T3 regression rộng, H2 MariaDB owner flows. Không tự nâng skipped/test lịch sử thành PASS mới. |
| 12 Chưa kiểm | **APPROVE WITH CHANGES** | §7–8 ghi rõ engine/browser/backend chưa rerun và baseline không tái lập; không suy “chưa ai kiểm” từ thiếu evidence. |
| **Toàn P3-A** | **REJECT** | O1 HIGH cần sửa và độc lập xác nhận; N4 MEDIUM còn một phần. |

## 7. Acceptance §6 ↔ evidence truy vết

Đủ bảy nhóm gốc, phân rã dưới đây. H2 là evidence reviewer lượt2; CHƯA PHỦ nghĩa chưa có evidence tương ứng được chứng nhận, không khẳng định chưa ai từng thử.

| Acceptance | Evidence | Trạng thái/phần chưa phủ |
| --- | --- | --- |
| Authority admin/teacher3roles/creator/no assignment/ended/observer | T2 Host, shared actorRole | PASS mới cho Host; H2 HTTP không rerun |
| Guest401/middleware403/object404/foreign tenant-parent-receipt | H2 HTTP104 suite; source routes/controller không đổi | Historical PASS; browser teacher riêng CHƯA PHỦ |
| Revoked render/GET/write/replay; admin-only URL/nút | T2, Node denial; H2 B8/HTTP | PASS host/runtime schedules; backend replay H2 |
| Initial AI HTML không payload | partial:6–25, Host config whitelist | PASS; attachment vốn ở host không là AI payload |
| AI/Media/criteria/human HTML/script/event/closing-script | Source el/value; H2 B3; M1/M2/M15/M16 | PARTIAL; N4; mọi field browser canary CHƯA PHỦ |
| Audit503 null/source deny/stale/tombstone | Node denial/old answer; H2 HTTP/source | Runtime cleanup PASS; backend H2 |
| Source erasure/Activity missing | H2 source/tombstone; canonical source | Dedicated erasure UI end-to-end CHƯA PHỦ |
| Session expiry/nonJSON/late responses | Node20, P19; guards/source | PASS tested schedules; P18 lịch sau pagehide chưa chứng nhận engine |
| History/BFCache/tab/cache/storage/log/URL | B9b, Protocol/source; H2 source | Normal pagehide PASS; persisted=true/two tabs/cache forensic CHƯA PHỦ |
| Five-kind schema/bounds/unknown fields/integer guards | H2 validator/HTTP; source editor | Backend H2; exhaustive browser bounds CHƯA PHỦ |
| Confidence/nullable weight/reuse deferral | H2 B5; stateNote/payload/showActions | Historical PASS/source unchanged |
| No blind bulk, exact revision eligibility | Node reviewed/selectBox tests/source; H2 B6 | PASS core; no cross-page global select |
| Client422 vs generic server422/nonJSON/429 | Node nonJSON + source failureMessage; H2 HTTP | PARTIAL; browser422/429 exhaustive CHƯA PHỦ |
| Same UUID/body retry, changed body/new ID/reread | Node unknown/discard/reread; H2 replay | PASS tested schedules; browser actual lost-response DB oracle CHƯA PHỦ |
| Double click/two tabs/late writes/draft | Node guards; P15/B10; P17/B11 | Save race PASS; bulk draft FAIL O1; two tabs CHƯA PHỦ |
| Mixed ordered outcomes/duplicate/max100/unknown-only retry | Node bulk2cases, H2 HTTP bounds | PASS runtime/H2 backend; real-browser mixed+unknown DB oracle CHƯA PHỦ |
| Cursor/filter/Activity reset | Node cursor/guardDraft, M10, source | PASS runtime; >25 browser rows CHƯA PHỦ |
| 202 manual refresh/failed no worker claim | Node recovery + source afterGeneration | PARTIAL; real202→failed browser CHƯA PHỦ |
| 10 AI gates/quota entitlement/hold/overage | GATE_MESSAGES/source; Node incomplete; T3 gate tests | Three distinct browser quota scenarios CHƯA PHỦ |
| competency/text accept≠apply; reuse/new/awaiting_admin | H2 B4/B5, source stateNote | Historical PASS; no B/C controls introduced |
| Operations/inheritance/retry/cancel/rebase/target/context | Frozen contract; H2 owner backend subsets | **N/A P3-A UI**, không đánh thiếu B/C |
| Seed production/staging/force/unknown config | T8 env/disk; signature has no force; H2 force parser | PASS guards; force parser H2 |
| Synthetic sources/no real provider/secret/durable allowlist | Seed/fake provider/in-memory settings source; H2 ledger | Historical/default-local evidence; không chạy seed lần này |
| Tenant filesystem collision/symlink/race | T8 collision fresh; H2 exclusive race + real DB root collision | PASS fresh guard; transaction/race H2 |
| VI/EN/long text/empty/loading/error | T6 keys, Node/messages source; H2 browser states | Keys PASS; long-bound UI và filtered-empty browser CHƯA PHỦ |
| Sidebar/mobile/zoom200% | CSS/table source unchanged; H2 B7 | H2 desktop/mobile PASS; zoom200% CHƯA PHỦ |
| Focus/dialog/keyboard/aria-live/busy/per-row | Node18 N3; real B9–11 DOM; H2 shared confirmation/focus | Core historical PASS; screen reader/all error focus CHƯA PHỦ |
| Bounded history/list | source PAGE_SIZE25/reviews max100 | Source only; >=100 histories browser CHƯA PHỦ |
| Manual Mapping/Activity/media/Version publication | T3; H2 Mapping/Publication/Promotion104 | PARTIAL; seven baseline media failures; MariaDB not rerun |
| D8 flat/section/Lesson/missing/revoked | Partial7–17/54; unchanged MappingHttp tests; H2 three backlink cases | Historical PASS; revoked backlink browser CHƯA PHỦ |

## 8. Lệnh, zero-egress, điều chưa kiểm và cleanup

| Lệnh/nhóm thực chạy | Nơi và kết quả |
| --- | --- |
| cat/sed/rg/nl, git status --short, git diff --check, Python SHA-256 | Repo chỉ đọc; snapshot24/24; status giữ nguyên trước ghi báo cáo |
| rsync -a, exclude .git/node_modules/runtime/storage/*/.env*/bootstrap cache PHP | Copy app/vendor vật lý; vendor0symlinks. Temp ban đầu do mkdtemp đặt ở OS temp rồi được chuyển vào `/tmp` trước mọi test/DB/mutation |
| `node --test tests/js` | T1 và29mutation; restore SHA sau mỗi variant |
| `php vendor/bin/phpunit --no-configuration --do-not-cache-result tests/Unit/AiAuthoringScriptProtocolTest.php` | Protocol chạy từng variant, không DB |
| `DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_HOST=invalid DB_PORT=0 DB_SOCKET=…/nonexistent.sock php artisan test --compact [optional targeted files]` | T2/T3, copy only; không lấy DB default repo |
| `node --test …/own-probes.mjs`, thêm --test-name-pattern cho P19/P20 | T7; mutant M28/M29 làm own oracle fail; SHA restore |
| `php -d zend.assertions=1 -d assert.exception=1 …/seed-guards.php` | T8; DB mock never; không seed/thay schema |
| Pint --test; docs:lint; schema:drift --docs-only; route:list --path=ai-authoring --json | T4/T6; app copy; schema docs-only không DB |
| rsync resources/node_modules vào build copy riêng; npm run build | T5 offline, không install/download; dependency tree chỉ dùng cho build |
| mariadbd --no-defaults --version; mariadb-install-db --no-defaults --datadir=…/db --auth-root-authentication-method=normal --skip-test-db | MariaDB11.4.12 tạm |
| mariadbd --no-defaults --skip-networking --datadir=…/db --socket=…/db.sock --pid-file=…/db.pid --log-error=…/db.log | PID12641, skip_networking=1 xác nhận qua socket |
| mariadb --no-defaults --socket=…/db.sock -u root; CREATE DATABASE lf_p3a_r3; SELECT version/skip_networking | Chỉ DBtạm, không learnforge_db/XAMPP. DB để trống, không migrate/seed nên AUTO_INCREMENT900 không áp dụng |
| `DB_CONNECTION=mysql DB_DATABASE=lf_p3a_r3 DB_HOST=localhost DB_PORT=0 DB_URL= DB_SOCKET=…/db.sock php -S 127.0.0.1:18993 …/router.php` | WebPID14114 tại review.localhost; static harness không dùng Laravel/không queryDB; env nếu dùng DB chỉ sockettạm |
| CUA browser B9a/B9b/B10/B11 | Tab riêng, module byte-identical, synthetic responses; UI actions chỉ synthetic data |
| tab.close; kill14114; mariadb-admin --no-defaults --socket=…/db.sock -u root shutdown | Dừng đúng processes; kiểm PID/socket rồi cleanup root |

**Zero-egress trước khi mở browser:** trong copy, loại hai dòng favicon ngoài ở mỗi layout app/auth. Router harness gửi CSP `default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; font-src 'self'; object-src 'none'; base-uri 'none'`. Harness không tham chiếu URL ngoài; fetch AI được trả bằng Response tổng hợp, lifecycle beacon chỉ same-origin. Không thay layout gốc. Đây là biện pháp bỏ external references và CSP theo lựa chọn user cho phép, **không phải chứng nhận OS-wide network firewall**. Không dùng web/search, provider thật hoặc remote storage.

**Chưa kiểm/chưa tái lập:** full1396/23 của implementer;14mutations implementer không có exact patch nên reviewer chạy29variants mô tả riêng; MariaDB104 H2 không rerun; seed ledger/concurrent mkdir/force parser dựa historical evidence và source unchanged; engine BFCache persisted=true, two-tab races, zoom200%, screen reader, actual lost HTTP response DB oracle, quota fault paths và các gap trong §7. Không suy thiếu evidence thành “chưa ai kiểm”. Không đo model quality/large-list performance hay B/C UI vì ngoài phạm vi.

**Cleanup:** tab harness đã đóng; không thay viewport. WebPID14114 và MariaDBPID12641 đã tắt; socket/pid files biến mất. Đã xoá toàn `/tmp/lf-p3a-r3-yujzct4z`: app/vendor, build/node_modules, DB, harness/event logs, probes/mutants, test-generated media và canary filesystem. Không seed tenant, không media reviewer tạo trong repo gốc. Không ghi secret/mật khẩu vào báo cáo. Chỉ file báo cáo này được thêm; snapshot24/24 giữ nguyên sau ghi.

## 9. Điều kiện đóng

1. Sửa O1 HIGH và cung cấp snapshot mới; reviewer xác nhận bằng schedule bulk→editor→late response trên module và browser. Không dùng số tests passed thay oracle mất draft.
2. Hoàn tất N4 hoặc đăng ký phần MEDIUM còn lại với owner/hạn đúng governance; bằng chứng phải phân biệt mutation có tác động với mutation tương đương. Giữ các regression N1–N3/M1–M15 đã đạt.
3. Ghi đúng trạng thái các gap §7, đặc biệt BFCache/two-tab/a11y, và full-suite media baseline. Không gọi waiver/skip/historical evidence là PASS mới.
4. P3-A chưa đóng; P3-B vẫn cần P3-A đóng và hai amendment `allowed_actions` + DTO tên/ứng viên Node được Owner duyệt riêng.
