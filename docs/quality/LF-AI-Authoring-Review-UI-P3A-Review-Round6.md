# AI Authoring Review UI — P3-A implementation review, lượt 6

Version: 1.0

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-09-30

Document Path: quality/LF-AI-Authoring-Review-UI-P3A-Review-Round6.md

Reviewer: Codex — reviewer độc lập các lượt P3-A trước. Tôi chưa sửa file nào trong snapshot, thiết kế hay hợp đồng; lượt này chỉ ghi báo cáo này trong repo. Test, đột biến và harness đặt trên bản sao vật lý trong `/tmp`. Snapshot là **working tree**, không phải commit.

Implementation Verdict: **APPROVE WITH CHANGES**. S1 và S2 đã đóng theo những ca tái lập; D11 đúng quyền/loại/link theo source và feature test nhưng neo chưa được thử trên trang Laravel thật. Còn một tình huống mất lựa chọn Generate khi rời trang không báo (finding MEDIUM), cùng giới hạn xác minh neo D11 dưới đây. Chưa dùng verdict này để tự ghi P3-A “đóng” hoặc mở P3-B.

Initial / Final Audit Level: **HIGH / HIGH**. Findings còn mở: **0 BLOCKER, 0 HIGH, 1 MEDIUM, 1 LOW**. Kết quả của implementer chỉ dùng làm điểm so, không dùng làm bằng chứng.

## 1. Snapshot và cách ly

Đã đọc đúng thứ tự AGENTS, README/INDEX/Guardrails/Regression Audit, brief v1.5, báo cáo Round5 và lịch sử, thiết kế v0.7, review thiết kế §6, Frozen contract. SHA-256 lấy trước khi đọc implementation và kiểm lại trước khi ghi; **28/28 đầy đủ khớp đầu/cuối**. Bảng này ghi cùng giá trị ở hai thời điểm:

| File | SHA-256 đầu = cuối |
| --- | --- |
| `resources/js/ai-authoring.js` | `db5769c370280f874359086c04a4201c6db36dc9a0994303616d3f4c47925681` |
| `resources/js/app.js` | `d5da869391295db61d1ffb2337ef8c081dbb4e25980052c4f6530fc7bcd542e4` |
| `resources/views/course-template-activities/partials/ai-authoring.blade.php` | `09d481ff98f4e577edbff007b0bc8775c7eaf80ac13d53a468f99772e2c4f513` |
| `resources/views/course-template-activities/show.blade.php` | `cd506b3ec05df4ad3231a98d4d073606b93e6ba3202e57170b594eefae4024b2` |
| `resources/views/course-template-lessons/partials/list.blade.php` | `42d7e6513d5a4d15e224e71980fb0b956fb808e70a611dd1053f7244be5e9703` |
| `resources/views/course-templates/partials/learning-mappings.blade.php` | `fbe914ccfd43aee976e0fca3391b556ad8b453a2758d619ec60491a499f17753` |
| `app/Http/Controllers/CourseTemplateActivityController.php` | `a6c073e3a28bbbff8868207a34fd25eaf25f71afa7b22a20c2b77da2579b56ef` |
| `app/Http/Controllers/CourseTemplateController.php` | `f6e7a7189857c283023c0ad655e3e3d5173a7f2349a8a98890956325446f393e` |
| `app/Services/CourseAuthoringContextService.php` | `d1146bb21a5e64cc229933ad92939701d03acf7ac5288f9e37d13fb3c304f231` |
| `app/Console/Commands/AiAuthoringDemoSeed.php` | `5748a8f05496d82d0fbe60d51976c4bb080fc5435d4ae631c9c588e7a4b03533` |
| `app/Support/AiDemo/DemoAuthoringProposalProvider.php` | `ebaba37948c2f0225ba90a6eaa01113b4f6abbaf43dd862b1c2a9721e2b5de87` |
| `app/Support/AiDemo/InMemoryTenantSettings.php` | `fcf385f10e4cee00e1004ed152adfe9e540119e1ba60063cacd17b5ad7ca8b69` |
| `resources/css/admin/admin-pages.css` | `f5d0ab10404ce748a8aad574f73e3fb7fac3834ff4c8c3dce2a1995b899cf632` |
| `resources/lang/vi/lf.php` | `0c2ba59101cbbd6b113fbda77e52a34affb1f2b5541a0d45d218385a2c84d535` |
| `resources/lang/en/lf.php` | `71cb96ab22ca4c4fcd2c30c87444be95806b608fb75e29c805c2360bdc1c58cb` |
| `tests/Feature/AiAuthoringHostSectionTest.php` | `3ab90d5983c0446313c5ebee8f03acb4dcc9e8a3c69acb66b0b8c7b89f0ec208` |
| `tests/Feature/AiAuthoringActivityEntryTest.php` | `dbaeb0dfcf1d1c7c1ce64632d928d85f4fff27524821c2e28f93a1d62c495a56` |
| `tests/Feature/CourseTemplateActivityManagementTest.php` | `d7d8132639af278322c23015a3bc72727f7cdb07da973ae296e9168d8b6a1e28` |
| `tests/Integration/AiAuthoringDemoSeedMariaDbTest.php` | `b5ea21021707933585299e57e0bd5b670805292a3dbf22c4dd66a6f3682fb3ee` |
| `tests/Integration/CourseTemplateLearningMappingHttpMariaDbTest.php` | `cd8e1127095c90add60c0471d04dcd0de6b6253b410d8e96a9ebb7f1b135376f` |
| `tests/Unit/AiAuthoringScriptProtocolTest.php` | `78f21c4c217e1b0f7795efc6bcc6286ec6b0bffd21359ab06441359bd61605f4` |
| `tests/Unit/AiAuthoringScriptBehaviorTest.php` | `1ecca50bfd1975f7fa1820e13ff857b4d525be2decc8c1ebf9f0d0073d9eaea5` |
| `tests/js/fake-dom.mjs` | `78bdd8ac1d7929badddf39b5bb49cd58b01099249f75ab477b4aa280b457b0b2` |
| `tests/js/ai-authoring.behavior.test.mjs` | `2518e62e5573824e60aa035e7fb6c8d56de98d5d53e9e8df8c313a9d2a975830` |
| `docs/platform/LF-AI-Authoring-Review-UI-Design.md` | `fa8125dbc9796d87df55c2cda795e4b16b2bb9282f10aa74587eae084cba4d26` |
| `routes/modules/ai-authoring.php` | `61ef30963f09a99305ccf01edb6c7eb06f1d544cf9f1459a2d274c539c4b2d86` |
| `app/Http/Controllers/AiAuthoringController.php` | `4d2f380d4a807916e396ce6bd84e1a412a47bc9e694b67650024ed5e7f193f82` |
| `docs/platform/LF-AI-Authoring-Proposal-Contract.md` | `fd23a83289e499015f46b66f1afff152130423742aa1f773f3be690bb59cd18f` |


Bản sao rsync loại `.git`, `node_modules`, `runtime`, `storage`, `.env*`; `vendor` là thư mục vật lý, không symlink. `.env` rỗng và APP_KEY tổng hợp chỉ trong copy. Không đọc/copy `.env` thật, không dùng provider thật hoặc kết nối ngoài. Trình duyệt dùng module JS byte-identical, hộp xác nhận là hàm `bootLfConfirmDialog` trích đúng bytes từ `app.js`, synthetic transport; HTML/CSS tối giản nên ca browser **không chứng nhận layout Laravel thật**. Harness chỉ tải tài nguyên same-origin, CSP `default-src 'self'; connect-src 'self'`; không tải favicon ngoài của layouts app/auth. Server bind `127.0.0.1:18996`, chỉ truy cập bằng `review.localhost:18996` và cấu hình DB socket tạm; harness không query DB.

## 2. Trạng thái finding cũ và D11

| ID | Trạng thái | Bằng chứng độc lập |
| --- | --- | --- |
| S1 — decision/bulk notes | **ĐÓNG** trong phạm vi note | `hasDraft` JS:622–623 nhận `noteField`, `hasUnsent`:627–628 nhận `bulkNote`; `showActions`:1398–1424 giữ note, `refreshOpen`:595–610 không tự đọc trên draft. Node41/41; browser B13 note→ViewB mở dialog, Cancel giữ note và reads=1; B14 bulk held→nhập note→release giữ note, reads=1; B17 note-only Leave giữ trang. Mỗi nhánh mutation S1 thử đều đỏ. |
| S2 — Generate cũ xoá lựa chọn mới | **ĐÓNG** cho kịch bản giao | `toggleCreate`:908–923 chỉ dựng form lần đầu, `afterGeneration`:1055–1081 chỉ reset nếu checked kinds bằng request đã gửi. Browser B15 pending→held status→chọn concept→old completed: `choices=[concept]`, `createHidden=false`; Node37–39/41 đạt, 3 mutation S2 đỏ. |
| N4 — oracle | **ĐÓNG** cho mutation lịch sử | 41 Node cases, sweep mới nhập 4 loại dữ liệu và nhìn sau mỗi nút. Reviewer tái dựng M1–M43 trên copy: 42 biến thể bị Node suite bắt, M37 sống vì tương đương trong call graph. M4/M5 ban đầu ghim sai constructor được sửa target `dropDetail` rồi chạy lại, đều đỏ. Test vẫn thiếu oracle cảnh báo rời trang khi chỉ chọn Generate; finding mới F1. |
| D11 — Activity entry | **ĐÓNG MỘT PHẦN** | `CourseTemplateController`:201–206 lấy `authoringRole` một lần; list Blade:297–300 giới hạn media types và dùng `$activityParameters`/route prefix có section; `ActivityController`:552–594 dùng cùng authority và `hasMediaSource`; partial:21–25 chỉ note trên non-Media. `AiAuthoringActivityEntryTest` và `CourseTemplateActivityManagementTest` nằm trong 78 test trọng tâm PASS. CSS:5477–5483 `scroll-margin-top:240px`; header desktop fixed tới 156px (`admin-layout.css`:34–70), **nhưng chưa đo neo trên trang thật/mobile**. |

R1 quyền host, Q1 list Retry, O1 bulk đến muộn và N1–N3 scrub/epoch giữ bằng source, Node41/41, targeted78/78, mutation replay và browser B13–B17 dưới đây. Không coi kết quả test là chứng minh mọi interleaving hoặc hai tab.

D11: source `actorRole` (`CourseAuthoringContextService`:123–143) chỉ user active của tenant hiện hành, admin hoặc teacher active có assignment `primary`/`assistant`/`reviewer` active. `proposalContext`:40–62 gọi chính `actorRole`; không dùng quyền mở page rộng hơn. Test D11 chạy cả sáu loại (`video`,`audio`,`document` có link; embedded video/quiz/live class không), ba role teacher, role assignment khác, creator có assignment đã kết thúc, route section, non-Media note. Hai assertion cũ trong `CourseTemplateActivityManagementTest`:97,215 đổi nhãn admin thành `Xem, Đề xuất AI, Sửa, Xóa`, giữ creator teacher `Xem, Sửa, Xóa`; đúng với quyền hẹp mới. View URL `activityViewUrl` được tính ở list:141–147 và nút Xem:279–295 không đổi; AI là link riêng. Không thấy đường D11 lộ URL/section cho actor không quyền AI: conditional Blade không render. Chưa có ca HTTP mới cho inactive user/foreign tenant **riêng list**, nhưng `authoringRole` có tenant/active guard và host test quyền hẹp.

## 3. Finding mới

### R6-F1 — MEDIUM — lựa chọn tạo yêu cầu mất khi rời trang mà không cảnh báo

**Vị trí:** `resources/js/ai-authoring.js:205–208,622–629,908–946`. `beforeunload` hỏi chỉ khi `hasUnsent()`, nhưng hàm đó bỏ qua `checkedKinds()`. Lựa chọn Generate đã tích và chưa gửi là dữ liệu người dùng vừa nhập; khi chỉ có lựa chọn này, bấm link rời trang đi ngay.

**Bằng chứng:** own Node probe đặt `summary.checked=true`, fire `beforeunload`, mong `preventDefault=true` nhưng được `undefined` (1 FAIL; P35 probe riêng PASS). Browser thực: sau B15 còn `choices=[concept]`, xoá decision/bulk note, click “Leave page”; trang chuyển tới “Left” ngay. Với decision/bulk note, cùng link **không chuyển trang** (B17). Đây là mất lựa chọn, không phải lộ nội dung hay lệnh đã gửi. §13.6 của thiết kế mô tả `hasUnsent()` chỉ gồm draft+bulk note, nên đây cũng là thiếu sót trong ranh giới đã duyệt, không tự ý coi là yêu cầu backend.

**Đề nghị:** Owner quyết định rõ liệu lựa chọn chưa gửi cần cảnh báo rời trang; nếu có, mở rộng định nghĩa `hasUnsent`, thêm test Node/browser choice-only; nếu cố ý cho mất, ghi rõ trong UI/thiết kế và đăng ký MEDIUM với owner/hạn theo quy tắc brief. Không lưu choices vào browser storage.

### R6-F2 — LOW — D11 chưa có oracle vị trí neo trên trang thật

**Vị trí:** `resources/css/admin/admin-pages.css:5477–5483`, D11 feature test. CSS 240px > desktop fixed header 156px theo source, nhưng test mới chỉ tìm `href="#ai-authoring"` và sự hiện diện của section; không đo bounding rect sau điều hướng, mobile/sidebar/zoom. **Đề nghị:** thêm một ca browser trên trang Laravel dùng DB tạm hoặc kiểm thủ công có kết quả ghi lại ở 100%/200% và màn hình hẹp. Không kết luận neo bị che; đây là giới hạn xác minh.

## 4. Replay dữ liệu chưa gửi và browser

| Probe Round5 | Kết quả lượt6 | Bằng chứng |
| --- | --- | --- |
| P23/P24/P25 | PASS | Node cases note navigation/Edit-Cancel/late bulk; B13/B14 DOM thật. |
| P26 | PASS | Node case bulk note survives list reload; source `bulkBar`:1184–1220 giữ scalar. |
| P27/P28 | PASS | Node37–39; browser B15 old status không đóng form mới. |
| P29/P30/P31 | PASS trong scripted schedules | Node list failure/cancel, late revoked bulk, SaveA→EditB/bulk/generation; source epoch/seq và `loadList` giữ detail. Không gọi là proof hai tab. |
| P32/P36 | PASS notes | Node34 và B17 giữ trang với note-only. Own probe choice-only FAIL F1. |
| P33/P34 | PASS | Node40 giữ editor ngay sau abandon unknown và lựa chọn qua cursor; M43/M40 đỏ. |
| P35 | PASS | Own probe trên `propose_new` sửa lần lượt title/body/rationale/code/label/node_type/role/weight/criteria, Close với Cancel: mỗi field giữ và mỗi lần hỏi. |
| B13 | PASS | Note A→ViewB: dialog mở; Cancel, note `NOTE_UNSENT_R6`, reads=1. |
| B14 | PASS | Bulk held→Reject Confirm→nhập `LATE_BULK_NOTE_R6`→Release: note giữ, reads=1, hiện `reread_discard`. |
| B15 | PASS | Pending Generate/held status→đổi summary sang concept→completed: panel vẫn mở, concept checked. |
| B16 | PASS một phần | Shared native dialog mở khi đổi filter với editor dirty; Escape giữ `KEYBOARD_DRAFT_R6`. Thao tác phím `ArrowDown/Enter` trên select không phát change trong harness, nên đường keyboard riêng chưa xác nhận; selectOption kích hoạt guard. |
| B17 | PASS notes | Decision+bulk note-only click Leave: vẫn trên trang, có `beforeunload`; harness browser tự dismiss dialog nên không đo văn bản dialog. Choice-only thì đi thẳng: F1. |

Rà chủ động các loại dữ liệu: editor/decision note được guard ở đổi đề xuất, đóng, filter và refresh sau bulk; `loadList` không đóng detail khi cursor/retry; Save readback giữ decision note vì Save không dùng nó, decision thành công mới dùng nó. Bulk note lưu scalar qua render lại list/cursor/filter; `beforeunload` nhận nó. Form Create giữ lựa chọn khi gập/mở, list reload, old status và chỉ reset khi người dùng Cancel hoặc completed của đúng selection; **rời trang là ngoại lệ F1**. Filters là state truy vấn, đổi filter được hỏi nếu detail draft; đổi filter không hứa giữ filter khi rời trang. Revoke vẫn scrub mọi dữ liệu, không coi là mất dữ liệu sai vì hợp đồng §4.1 yêu cầu xoá.

## 5. Mutation và kiểm chứng

Tất cả mutation chạy **trong copy**, một variant một lần, `node --check` trước Node41, khôi phục bytes/SHA-256 `db5769c370280f874359086c04a4201c6db36dc9a0994303616d3f4c47925681` sau từng biến thể. Đây là **biến thể tái dựng theo hành vi lịch sử**, không phải patch bytes của implementer. M4/M5 lượt chạy đầu vô tình thay constructor vì chuỗi giống nhau; tôi định vị lại `dropDetail` và chạy lại đúng đích (đều 2 test đỏ). Không tính kết quả đặt sai đích là evidence. M23 dùng contract đã thích nghi từ lượt5 (`closeDetail:true`).

| ID | Đột biến tái dựng | Node41 |
| --- | --- | --- |
| M1 | literal innerHTML | bắt (fail=40) |
| M2 | computed literal sink | bắt (fail=40) |
| M3 | pagehide callback return | bắt (fail=4) |
| M4 | keep editor reference | bắt (fail=2) |
| M5 | keep noteField reference | bắt (fail=2) |
| M6 | ignore locked | bắt (fail=1) |
| M7 | keep pending on reread | bắt (fail=1) |
| M8 | disable all epoch guards | bắt (fail=5) |
| M9 | HTML 403 unexpected | bắt (fail=2) |
| M10 | cursor retains selection | bắt (fail=1) |
| M11 | bulk epoch | bắt (fail=1) |
| M12 | create epoch | bắt (fail=1) |
| M13 | keep bulk scalar | bắt (fail=2) |
| M14 | variable HTML sink | bắt (fail=40) |
| M15 | extra variable sink | bắt (fail=40) |
| M16 | spaced variable sink | bắt (fail=40) |
| M17 | revoke bulk release | bắt (fail=1) |
| M18 | pagehide bulk release | bắt (fail=2) |
| M19 | bulk field value | bắt (fail=2) |
| M20 | create postread epoch | bắt (fail=1) |
| M21 | command postread epoch | bắt (fail=1) |
| M22 | bulk postread epoch | bắt (fail=1) |
| M23 | late command closes detail | bắt (fail=1) |
| M24 | no detail scrub | bắt (fail=1) |
| M25 | no value scrub | bắt (fail=1) |
| M26 | no leaf scrub | bắt (fail=1) |
| M27 | no text-node scrub | bắt (fail=1) |
| M28 | bulk confirm epoch | bắt (fail=1) |
| M29 | status GET epoch | bắt (fail=1) |
| M30 | refresh draft | bắt (fail=3) |
| M31 | refresh hidden | bắt (fail=2) |
| M32 | pagehide hidden | bắt (fail=2) |
| M33 | refresh no read | bắt (fail=2) |
| M34 | reread bypass guard | bắt (fail=1) |
| M35 | pageshow hidden reset | bắt (fail=1) |
| M36 | computed += sink | bắt (fail=40) |
| M37 | remove UUID equality | sống tương đương (fail=0) |
| M38 | default closeDetail | bắt (fail=8) |
| M39 | clear bulk note on render | bắt (fail=2) |
| M40 | reset create choice after list | bắt (fail=2) |
| M41 | constructed HTML sink | bắt (fail=40) |
| M42 | filter guard | bắt (fail=3) |
| M43 | discard clears title | bắt (fail=1) |

M37 bỏ so `uuid !== openUuid` nhưng các caller hiện truyền UUID đang mở; tương đương trong call graph được kiểm, không tính thiếu oracle. Mọi M khác bị Node41 bắt, bao gồm các nhánh Q1/O1/N1–N3 và 4 sink có ý nghĩa. M1/M2/M14/M41 còn được chạy PHP Protocol riêng, cả bốn đỏ; sink fake DOM ném khi tới `innerHTML`/`outerHTML`/`insertAdjacentHTML`. Test tĩnh không thể chứng minh không có mọi XSS sink tương lai. Actual source hiện dùng `textContent`/`.value` và Blade escape. F1 là **kịch bản mới** mà 43 mutation lịch sử không đại diện; vì vậy vẫn cần test choice-only beforeunload.

## 6. Verdict 12 câu của brief

| # | Verdict | Bằng chứng và giới hạn |
| --- | --- | --- |
| 1 Host authority | **APPROVE** | Shared `actorRole`, list/host test đủ admin +3 teacher roles/creator/ended; 78 targeted PASS. |
| 2 Disclosure | **APPROVE WITH CHANGES** | Initial partial config-only; revoke/epoch, Node denial/pagehide đạt; BFCache engine/two-tab/cache forensic chưa làm lượt này. |
| 3 XSS/free text | **APPROVE WITH CHANGES** | Actual source text/Blade escape, 4 sink mutation đỏ; browser all-fields XSS canary chưa làm. |
| 4 Command/idempotency | **APPROVE WITH CHANGES** | Frozen UUID/body/guards, unknown recovery, note preservation PASS; F1 choice-only leave; hai-tab race chưa làm. |
| 5 Bulk | **APPROVE** | Exact reviewed versions/max100/per-item result/retry unknown-only theo source+Node; B14 PASS. Backend MariaDB bulk không chạy lại. |
| 6 Generation | **APPROVE WITH CHANGES** | Framework pair/admin-only, 202/pending/failed và old status PASS; F1 lựa chọn chưa gửi. |
| 7 Contract/wording | **APPROVE** | Accepted≠applied note, confidence≠weight, reuse reject-only, không invent DTO fields; source/translation. |
| 8 Demo seed | **APPROVE WITH CHANGES** | Guard environment trước DB; production invocation trên DB tạm từ chối exit1 và DB 0 bảng; source in-process provider/tenant settings, folder mkdir exclusive. **Không seed thực/MariaDB ledger lại**. |
| 9 i18n/a11y/UI | **APPROVE WITH CHANGES** | VI/EN 210/210 keys; B13/B16/B17 dialog/focus subset; F1; anchor/mobile/200%/screenreader chưa chứng nhận. |
| 10 Verification | **APPROVE WITH CHANGES** | Node41, PHP targeted78, 43 mutation tái dựng (42 bắt, M37 tương đương); full suite 1386 pass/41 skip/7 media fail, build không chạy vì copy không node_modules; F1 chưa có test trong suite sản phẩm. |
| 11 Regression | **APPROVE WITH CHANGES** | Activity Management trong targeted PASS; Mapping Blade không đổi, scoped CSS; full suite media failures do excluded runtime, Version publish và Learning Mapping MariaDB không kiểm lại. |
| 12 Unchecked | **APPROVE WITH CHANGES** | Xem §8: implementer full baseline, MariaDB seed/32, browser neo và two-tab không tái lập. |
| **Toàn P3-A** | **APPROVE WITH CHANGES** | Không còn HIGH được tái hiện; F1 MEDIUM và D11 verification partial cần xử lý/ghi nhận trước closure. |

## 7. Ma trận acceptance review thiết kế §6

| Nhóm §6 | Test/ca lượt6 | Trạng thái/gap |
| --- | --- | --- |
| Authority/host | HostSection + ActivityEntry + shared `actorRole`; targeted78 | PASS subset; inactive/foreign riêng Activity list và revoke giữa write/replay chưa chạy HTTP MariaDB mới |
| Disclosure/XSS | Node41 refusal/epoch/pagehide; Protocol; sink mutations; B13–B17 | PASS subset; BFCache engine, two-tab, full-field browser canary, storage forensic **CHƯA PHỦ** |
| Review/transport | Node kinds/guards/422/HTML failures; source parser; P35 | PASS subset; all kinds real HTTP MariaDB **CHƯA PHỦ** |
| Replay/concurrency/bulk | Node41, B14/B15, mutation M1–M43, P35 | PASS scripted; double-click/two tabs/real bulk mixed outcome/duplicate IDs **CHƯA PHỦ** lượt này |
| Owner lifecycle B/C | Contract/source only | Ngoài P3-A, **CHƯA PHỦ** theo phân kỳ |
| Fixtures | Source seed + production refusal/empty temp DB | Seed local thật/ledger/media-folder collision/MariaDB32 **CHƯA TÁI LẬP** lượt này |
| UX/a11y/regression | VI/EN 210 keys, targeted78, B13–B17, full SQLite | Choice-only leave FAIL F1; anchor real/mobile/200%/screenreader, large-list, Version publish MariaDB **CHƯA PHỦ** |

## 8. Lệnh, điều chưa kiểm và cleanup

| Lệnh/công cụ | Kết quả |
| --- | --- |
| `sha256` 28 file original, đầu/trước báo cáo/cuối | 28/28 khớp; bảng §1. |
| `rsync` copy với exclusions; kiểm vendor vật lý | PASS; không dùng symlink. |
| `node --test tests/js` trên copy | 41 passed, 0 failed. |
| `php artisan test --compact` targeted 5 files trên copy/SQLite | 78 passed, 431 assertions. |
| Own `reviewer-probes.mjs` | P35 9 trường PASS; choice-only beforeunload FAIL F1. |
| `replay.py`: M1–M43 trên copy, `node --check` + `node --test tests/js` từng variant; PHP Protocol riêng 4 sink | 42/43 bị bắt, M37 tương đương; M4/M5 sửa target sau lượt đặt nhầm; SHA JS restore sau từng variant. |
| `php artisan test --compact` full SQLite copy | 1386 passed, 41 skipped, **7 failed**, 11646 assertions, 87.28s. Bốn Office processing và hai FasterWhisper thiếu runtime trong copy; một corrupt video báo processing_failed thay audio_extraction_failed. Không gọi full suite PASS, không tái lập baseline implementer 1411/23/11973. |
| `route:list --path=ai-authoring --json`; Pint 3 PHP files; `schema:drift --docs-only`; `docs:lint`; `git diff --check` original | 41 routes; Pint PASS; drift PASS; docs lint PASS trước báo cáo (94 legacy metadata debt); diff-check PASS. |
| `npm run build` trong copy | Không chạy được: `vite: command not found` do exclusion `node_modules`; **không** npm install/copy/symlink nó. |
| `ai:authoring-demo-seed --env=production` trên copy, DB MariaDB tạm | Exit1 từ chối trước schema access; DB `lf_p3a_r6` vẫn 0 bảng. Không gọi seed local. |
| MariaDB 11.4.12 | Datadir/socket `/tmp`, `--no-defaults --skip-networking`, DB `lf_p3a_r6`; shutdown bằng `mariadb-admin`, không truy cập TCP. |
| Browser `review.localhost:18996` | B13–B17 subset và F1; module nguyên bytes, synthetic fetch, same-origin CSP; không dùng trang Laravel thật. |

Lượt này không chạy `migrate:fresh` (~5 phút), MariaDB AI integration/seed thực, không tạo media file. Không đánh giá provider thật, hiệu năng list lớn, P3-B/C; không đo mobile/200%/screenreader, BFCache persisted thật, nhiều tab, hoặc ảnh hưởng publish Version bằng test MariaDB. `docs:lint` sau khi thêm report có thể yêu cầu catalog link/manifest nhưng ràng buộc chỉ cho tạo một file nên không sửa README/INDEX; cần owner đưa báo cáo vào catalog ở lượt bảo trì tài liệu.

**Cleanup:** đóng tab browser, dừng PHP server, shutdown MariaDB qua socket, xoá datadir/copy/harness/probes trong `/tmp`; không có Media tenant mẫu vì không seed. Xác nhận socket/server không còn và snapshot original không đổi ở lượt kiểm cuối dưới bảng §1. Không có outbound fetch: CSP same-origin, server access log chỉ localhost; MariaDB `--skip-networking`.

Điều kiện đóng P3-A: Owner xử lý F1 (sửa+test hoặc quyết định thiết kế minh thị và đăng ký MEDIUM có owner/hạn), xác nhận neo D11 trên trang Laravel thật; reviewer xác nhận lại. N4 lịch sử đã đóng qua replay mutation. P3-B còn đợi Owner duyệt hai amendment `allowed_actions` và DTO tên/ứng viên Node theo brief; waiver không thay PASS.
