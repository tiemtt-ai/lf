# AI Authoring Review UI — Independent P3-A Implementation Review, Round 5

Version: 1.0

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-09-30

Document Path: quality/LF-AI-Authoring-Review-UI-P3A-Review-Round5.md

Reviewer: Codex — reviewer độc lập các lượt 1–5

Reviewed Design: v0.7, Approved; D10 đã được Owner duyệt

Reviewed Contract: v0.8, Frozen

Classification: Existing-Feature Change — independent implementation review

Initial Audit Level: HIGH

Final Audit Level: HIGH

Audit Level Escalation: None

P3-A Verdict: **REJECT**

Regression Final Verdict: **FAIL**

Findings By Severity: **0 BLOCKER, 1 HIGH, 2 MEDIUM, 0 LOW** còn mở. S1 HIGH và S2 MEDIUM mới; N4 MEDIUM tiếp tục. Không đếm R2/R3/R10 thêm lần nữa.

## 1. Kết luận, độc lập và phạm vi

**Q1 ĐÓNG:** P21 và B12 xác nhận Retry danh sách giữ editor; P21 phủ thêm unknown-locked editor. Hợp đồng `loadList(reset, { closeDetail = false } = {})` ở JS:441 bảo toàn detail mặc định; caller duy nhất truyền closeDetail=true là đổi bộ lọc sau guardDraft (JS:408–415). **O1 và N1–N3 không hồi quy** trong các ca đã kiểm. M1–M36 bị bắt; M37 vẫn tương đương trong đồ thị gọi hiện tại.

Nhưng họ lỗi mất dữ liệu chưa hết: **ghi chú quyết định không được hasDraft nhận diện**, nên chuyển đề xuất hoặc bulk trả lời muộn xoá ghi chú không hỏi; ghi chú bulk cũng không kích hoạt cảnh báo rời trang (S1). Một trạng thái Generate cũ có thể đóng biểu mẫu chứa lựa chọn mới, rồi mở lại sẽ reset lựa chọn (S2). Hai lỗi có evidence source, probe độc lập và trình duyệt thật. Không có bằng chứng source hiện tại có XSS; sink sống sót ở §5 là **mutation**, không phải lỗ hổng XSS đã thấy trên snapshot.

Reviewer chưa sửa code hoặc tài liệu canonical của Bước 7, Phần 2, P3-A, thiết kế/hợp đồng trong lịch sử phiên. Các lượt trước chỉ tạo báo cáo. Lượt này chỉ tạo file Round5 trong repo gốc; không vá, reset, stash, commit hoặc thay đổi snapshot. Các probe/mutation chạy trong bản sao vật lý `/tmp/lf-p3a-r5-tlt59zz5/app`, vendor **0 symlink**. Working tree có thay đổi từ trước và được giữ nguyên.

Đã đọc theo thứ tự AGENTS → README → INDEX/routing → Guardrails → Regression Audit → brief v1.4/Lượt5 → Round4 và các báo cáo trước → design v0.7, nhất là §4.1–4.5/§13.4 → design review §6 → Frozen contract. Các phần canonical không đổi đã được đọc trong cùng chuỗi review; lượt này đối chiếu lại draft, authority, async, payload và replay. §13.1–§13.4 là khẳng định cần kiểm, không dùng thay evidence. Không tái mở D10 hoặc đòi chức năng B/C ngoài phạm vi.

**Current/requested behavior:** primitive tải danh sách nay bảo toàn editor; yêu cầu kiểm mọi dữ liệu chưa gửi và phản hồi muộn. **Source of truth:** Governance → owner contract/ADR → design Approved. **Invariants:** live tenant/actor authority; GET được ủy quyền trước disclosure; không browser persistence; frozen UUID/body/guards; accept ≠ apply/publish; giữ hoặc hỏi trước bỏ dữ liệu chưa gửi, ngoại trừ mất quyền bắt buộc xoá. **Impact graph:** Activity host/controller → Course authoringRole → Blade/app.js → AI UI state/HTTP → Course/Media/AI owner services → review ledger; seed → fake provider/in-memory settings → real gate/ledger/Media disk. Audit HIGH do concurrency, disclosure và mất dữ liệu nhập; không có schema/API/auth amendment mới cần reviewer tự quyết.

## 2. Snapshot đầu / cuối

24/24 khớp prefix bàn giao trước test; kiểm lại trước cleanup và sau ghi báo cáo. Bảng ghi SHA-256 đầy đủ của **working tree**, không phải HEAD. **Đầu = cuối**. Copy JS được phục hồi SHA sau từng mutation; bản JS riêng phục vụ browser byte-identical và không bị mutation.

| File | SHA-256 đầu = cuối |
| --- | --- |
| `resources/js/ai-authoring.js` | `2ccf67a14d343221c35e97106932991c4f509e2f6b1a0b8851648061d543b1de` |
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
| `resources/lang/vi/lf.php` | `90bbbbf2e5b13aaec97af228caefe61eabf560520fa1ca8b6a86b83a04396d3d` |
| `resources/lang/en/lf.php` | `b72c4a2be483258474132100dfd968294df1212d3d49ecfde9e22552355732a8` |
| `tests/Feature/AiAuthoringHostSectionTest.php` | `3ab90d5983c0446313c5ebee8f03acb4dcc9e8a3c69acb66b0b8c7b89f0ec208` |
| `tests/Integration/AiAuthoringDemoSeedMariaDbTest.php` | `b5ea21021707933585299e57e0bd5b670805292a3dbf22c4dd66a6f3682fb3ee` |
| `tests/Integration/CourseTemplateLearningMappingHttpMariaDbTest.php` | `cd8e1127095c90add60c0471d04dcd0de6b6253b410d8e96a9ebb7f1b135376f` |
| `tests/Unit/AiAuthoringScriptProtocolTest.php` | `ffb60cd2f23ea14fd3508c774b6dc098dcd4921a15b1c8516a4bd1a098941f81` |
| `tests/Unit/AiAuthoringScriptBehaviorTest.php` | `1ecca50bfd1975f7fa1820e13ff857b4d525be2decc8c1ebf9f0d0073d9eaea5` |
| `tests/js/fake-dom.mjs` | `8038ec74f43865ee3f9e776558549483e2daaab09f4beac2da193d0023ca8043` |
| `tests/js/ai-authoring.behavior.test.mjs` | `4903c47a70b00cfc2d1882a205778f9afa253202e77b0c8434af662f8e0073ed` |
| `docs/platform/LF-AI-Authoring-Review-UI-Design.md` | `ee8453abbc14285a7fc13752c9d254b306881b373cfeaf3db266a558eaf6ceaa` |
| `routes/modules/ai-authoring.php` | `61ef30963f09a99305ccf01edb6c7eb06f1d544cf9f1459a2d274c539c4b2d86` |
| `app/Http/Controllers/AiAuthoringController.php` | `4d2f380d4a807916e396ce6bd84e1a412a47bc9e694b67650024ed5e7f193f82` |
| `docs/platform/LF-AI-Authoring-Proposal-Contract.md` | `fd23a83289e499015f46b66f1afff152130423742aa1f773f3be690bb59cd18f` |

## 3. Trạng thái findings đã có

`JS:n` = `resources/js/ai-authoring.js:n`. T/P/B/M là evidence mới ở §5. H2/H3/H4 là evidence của chính reviewer trong báo cáo tương ứng, **không phải chạy mới lượt5**.

| Finding | Trạng thái | Evidence và giới hạn |
| --- | --- | --- |
| Q1 — Retry list phá editor | **ĐÓNG** | P21 dirty + unknown-locked; B12 draft RETRY_DRAFT_R5 còn, detailReads=1/confirms=0, listReads 2→3; JS:441–454/505. |
| N4 — oracle | **ĐÓNG MỘT PHẦN** | M34/M35/M36 đã bị bắt, M1–M29 giữ. M39–M41/M43 còn sống; own oracle bắt M39/M40/M43. M37 tương đương, không tính thiếu coverage. |
| R1 — teardown | **ĐÓNG** trong lỗi đã báo | Node30 denial/pagehide/held-parent, P30. H4 browser held-value evidence lịch sử. Không chứng nhận BFCache persisted=true engine. |
| R2 — transport/async | **ĐÓNG MỘT PHẦN** | Q1 đã đóng; HTML denial, late responses và epoch regressions đạt. S1/S2 còn là async refresh phá dữ liệu chưa gửi ngoài editor. |
| R3 — frozen retry/draft | **ĐÓNG MỘT PHẦN** | P21/P31/P33/P35, Node frozen UUID/body/guards đạt. S1 bỏ sót decision/bulk note trong định nghĩa draft. |
| R4 — tenant media root | **ĐÓNG**, không thấy hồi quy | Seed/test hash không đổi; claimMediaFolder mkdir độc quyền. H2 root-collision/transaction/race, H3 dir/file/link guards; không chạy seed lượt5. |
| R5 — remote media disk | **ĐÓNG**, không thấy hồi quy | PHP hash không đổi; pre-DB env/local driver/root guards. H2 sentinel adapter0/H3 DB0 lịch sử; không blanket mọi mount/adapter override. |
| R6 — cursor selection | **ĐÓNG** | Node cursor test/M10 caught; JS:457 selected.clear cả reset/cursor. P26 giữ bulk note trong khi selection bị xoá đúng contract. |
| R7 — incomplete generation recovery | **ĐÓNG** trong lỗi cũ | Node14 cùng UUID, request-specific GET, soft404; M29 caught. S2 là lỗi khác: trả trạng thái cũ tác động lựa chọn mới. |
| R8 — row identity | **ĐÓNG** | Node mixed outcomes/unknown-only retry; B14/B11 kết quả có #22222222 và View riêng. |
| R9 — list/mobile | **ĐÓNG** trong lỗi cũ | CSS/partial không đổi; H2 B7 desktop/sidebar/mobile/data-label. D10 hợp lệ. Harness lượt5 không chứng nhận layout LF mới. |
| R10 — verification | **ĐÓNG MỘT PHẦN** | 30 Node +39 targeted đạt; sweep chỉ editor và bỏ Save, thiếu các trường khác/async schedules; S1/S2 và M39–M41/M43 lọt. |
| R11 — D8 backlink | **ĐÓNG**, không thấy hồi quy | Partial/test MariaDB hash không đổi; H2 ba backlink cases. Không rerun MariaDB lượt5. |
| O1 — late bulk phá editor | **ĐÓNG**, không hồi quy | Node21/22/23, B11 dirty editor giữ; reread Cancel giữ, Confirm mới GET. S1 ghi chú là phạm vi chưa được hasDraft nhận diện, ghi finding riêng. |
| N1 — held bulk note | **ĐÓNG**, không hồi quy | Node15/16 giữ ancestor và real field value trong fake DOM, M17–M19 caught; H4 real DOM lịch sử. |
| N2 — SaveA phá draftB | **ĐÓNG**, không hồi quy | Node17, P31 SaveA + Generate completion giữ DRAFT_B/NOTE_B; M23 adapted caught. |
| N3 — success lấn denial | **ĐÓNG**, không hồi quy | Node18 cả ba flows, M20–M22 caught. |

## 4. Findings còn mở

### P3A-R5-S1 — HIGH — Ghi chú chưa gửi nằm ngoài cơ chế bảo vệ draft

**Vị trí:** JS:608–610 `hasDraft()` chỉ xét locked/editor.dirty; JS:198–204 beforeunload dùng hàm đó; JS:585–597 refreshOpen, 601–604 viewProposal, 635–654 dropDetail; JS:1348–1366 showActions dựng note mới; JS:1614–1620 Cancel editor gọi showActions.

**Tình huống và bằng chứng độc lập:**

1. A đang mở, nhập decision note, bấm Xem B: guardDraft trả true vì không có editor; note của A bị dropDetail scrub và mất. P23 FAIL dù harness đặt mọi confirmation thành Cancel. **B13** nhập NOTE_UNSENT_R5 rồi View B: note thành `""`, open A→B, detailReads 1→2, confirms giữ2; không có dialog.
2. Nhập note → Edit → Cancel khi chưa đổi trường editor: showActions tạo textarea mới rỗng. **P24 FAIL**, NOTE_UNSENT→`""`, không hỏi. Đây không phải bấm huỷ chính ghi chú.
3. Chọn B để bulk, xác nhận/gửi và giữ response → nhập ghi chú quyết định mới trong detail vẫn thao tác được → trả bulk success. refreshOpen coi không có draft rồi openDetail xoá note. **P25 FAIL; B14** trước release: `note=LATE_BULK_NOTE_R5`, detailReads=2, confirms=3, posts=1; sau: note rỗng, detailReads=3, confirms=3, posts=1. Ghi chú mới không nằm trong body bulk đã gửi; không có quyết định bỏ nó. Không phụ thuộc backend cập nhật thành accepted/rejected: ghi chú bị xoá trước GET mới.
4. Chỉ có decision/bulk notes, không editor: beforeunload không preventDefault. **P32/P36 FAIL** (P36 chỉ bulk note). **B17** nhập LEAVE_NOTE_R5 và LEAVE_BULK_R5, bấm Leave page: chuyển ngay sang trang Left không cảnh báo. pagehide vẫn phải scrub vì disclosure; thiếu nằm ở cảnh báo trước rời trang, không phải yêu cầu giữ nội dung trái quyền.

**Tác động:** mất lý do người dùng đang soạn mà chưa gửi/lưu, đặc biệt do response tự động; cùng invariant bảo vệ dữ liệu nhập của §4.2 và yêu cầu Lượt5. Không chứng minh sai ghi DB hay bypass authority. Gộp các đường có cùng nguyên nhân thành một HIGH; không tính thêm R2/R3 trùng.

**Đề nghị:** xác định draft theo phạm vi dữ liệu: editor + decision note cho detail; bulk note/choices cho rời trang. Giữ note qua Edit/Cancel hoặc xin quyết định rõ trước xoá; refresh chỉ được bỏ note thuộc chính lệnh đã xác nhận, không bỏ nội dung nhập sau khi gửi. Kiểm riêng note-only và bulk-note-only, late bulk, navigation, keyboard. Khi revoke, vẫn xoá ngay theo contract; không persistence để “cứu” draft. Reviewer không vá.

### P3A-R5-S2 — MEDIUM — Trạng thái Generate cũ đóng lựa chọn mới và mở lại reset chúng

**Vị trí:** JS:874–885 toggleCreate luôn createForm mới khi mở; JS:887–917 createBoxes mới; JS:1010–1021 afterGeneration completed ẩn panel không kiểm generation/form mới; JS:1031–1058 refreshGeneration chỉ kiểm authority epoch.

**Tái hiện:** chọn summary → gửi nhận pending → bấm làm mới trạng thái, giữ GET → đổi lựa chọn thành concept (chưa gửi) → completed của summary cũ đến. Panel bị đóng; mở Create lại thì không còn concept.

**Evidence:** P28 FAIL vì createPanel.hidden=true sau response cũ. **B15** trước release: choices=[concept], createHidden=false, confirms=3, posts=2; sau release: choices vẫn [concept] trong DOM ẩn nhưng createHidden=true; sau bấm create_open: choices=[], confirms=3/posts=2 không đổi. **P27** cũng chứng minh chỉ gập/mở bằng create_open xoá lựa chọn; không coi explicit nút Cancel một mình là lỗi cần hỏi.

**Tác động:** mất lựa chọn chưa gửi, trạng thái của yêu cầu cũ tác động biểu mẫu đang soạn cho yêu cầu mới. Không phải xoá nội dung dài hay ghi sai DB, nên MEDIUM. Không khẳng định lời “create_done” cho request cũ tự nó sai; sai ở lifecycle của form mới.

**Đề nghị:** giữ state lựa chọn qua đóng/mở và tách định danh request đang được theo dõi khỏi form đang sửa. Chỉ reset khi thuộc đúng submission và chưa có lựa chọn mới, hoặc người dùng chủ động bỏ. Test delayed status GET / hai generation tuần tự / re-open; không đổi UUID dưới cùng body và không làm mất recovery của request cũ.

### P3A-R2-N4 — MEDIUM, tiếp tục — Sweep vẫn bỏ sót dữ liệu và recovery path

**Vị trí:** `tests/js/ai-authoring.behavior.test.mjs:678–748` (Retry/sweep/reread/pageshow); `tests/Unit/AiAuthoringScriptProtocolTest.php:18,59–94`.

M34/M35/M36 đều đã đỏ đúng yêu cầu. Nhưng sweep chỉ nhập editor title, chụp tập nút hiện có một lần và không tạo response ordering/unknown outcome; không kiểm note hoặc create choices. Snapshot thật vẫn có S1/S2.

Mutation mới sống qua **Node30 + Protocol24**:

- **M39:** trong loadList success, thêm `this.bulkNote = "";` trước `this.revoked = false;`. Note bị mất sau tải cursor/refresh. Own P26 làm mutant FAIL; source PASS.
- **M40:** trước `this.listLoaded = true;`, thêm `for (const box of Object.values(this.createBoxes ?? {})) box.checked = false;`. Lựa chọn Generate mất sau pagination. Own P34 mutant FAIL; source PASS.
- **M43:** đầu discardUnsent thêm `if (this.editor) this.editor.controls.title.value = '';`. Bấm “Bỏ yêu cầu đó và sửa tiếp” xoá input. Own P33 mutant FAIL; source PASS. Test cũ tự ghi giá trị mới sau discard nên không nhìn thấy lần xoá này.
- **M41:** giữ textContent và thêm `const member = 'inner' + 'HTML'; node[(member)] = String(text);`. JavaScript hợp lệ, parser `node --check` đạt, nhưng regex không nhận parentheses quanh key; fake DOM không parse HTML. Đây là **sink ở mutant**, không chứng minh XSS source. Không đòi regex chứng minh mọi JavaScript; đề nghị oracle sink/browser/AST tương xứng với tuyên bố bảo vệ.

M37 bỏ check uuid!==openUuid vẫn sống; caller duy nhất runBulk lấy reopen từ openUuid rồi gọi ngay, không await ở giữa. **Tương đương trong call graph hiện tại**, không nâng thành finding/không ép test gọi sai tiền điều kiện. M23 cần thích nghi với API mới: `loadList(true)` giờ an toàn, nên mutation tương đương *lỗi cũ* phải truyền `{ closeDetail: true }` ở nhánh late command; đã bị bắt.

## 5. Evidence thực thi lượt5

### 5.1. Test, cấu trúc và build

| ID | Lệnh/phạm vi | Kết quả |
| --- | --- | --- |
| T1 | node --test tests/js | **30 passed**,0failed; wrapper chạy lại sau SHA restore ở T2 |
| T2 | HostSection + ScriptProtocol + ScriptBehavior, safe SQLite :memory: | **39 passed,155 assertions**,1.85s; Protocol riêng24tests |
| T3 | Full php artisan test --compact, copy sạch/safe env | **1376 passed,41 skipped,3 failed,11615 assertions**,89.63s |
| T4 | Pint --test Protocol; docs:lint; schema:drift --docs-only; git diff --check | Snapshot trước thêm report: PASS;94legacy allowlisted;101migration files. Sau thêm draft report vào copy: docs:lint FAIL3orphan records, xem §8. |
| T5 | Offline dependency copy, npm run build | PASS1.13s; không npm install/download |
| T6 | route:list JSON; VI/EN key comparison | **41routes;208/208keys**,delta rỗng |
| T7 | Own P21/P23–P36 | **15cases:8pass,7fail**;7fail là S1/S2 ở §4, không gọi suite PASS |
| T8 | M1–M43; Node30 + Protocol24 từng variant | **38caught,5survive**;4 meaningful survivors và1equivalent M37 |
| T9 | Own P26/P34/P33 trên M39/M40/M43 | Cả3mutants FAIL oracle; source3cases PASS; SHA restore từng lần |
| H2/H3/H4 | PHP MariaDB104tests941assertions/H2; seed guard probes/H3; browser teardown/H4 | Evidence lịch sử có source/hash tương ứng không đổi; không rerun backend/seed lượt5 |

**T3 failure classification:** hai FasterWhisper tại MediaProcessingSubstrateTest:304/346 báo provider_unavailable do runtime bị loại; corrupt video tại VideoTranscriptCaptionLocalReviewTest:195 trả processing_failed thay audio_extraction_failed. Cả ba đã có ở lượt trước; H2 baseline hẹp bỏ tracked P3-A changes vẫn lỗi tương ứng. Bốn ca Office trước đây lỗi **lần này không fail**; không lặp lời “cùng7lỗi”. Không tái lập 1397/23/11926 của implementer; không gọi full suite PASS. SQLite không chứng minh CHECK/FK/trigger/MariaDB concurrency;41skipped không là PASS.

**Lỗi chuẩn bị, không quy sản phẩm:** lượt full đầu do copy không .env/key tổng hợp nên MissingAppKey; một lần sửa key dài sai đã dừng. Sau key test đúng32bytes còn warnings dotenv thiếu .env (1327warnings); đã xác nhận bằng Host --display-warnings, tạo **.env rỗng chỉ ở copy**, rồi T2/T3 chạy sạch. Không đọc/copy .env hay key thật. Một flag PHPUnit không hỗ trợ đã bị từ chối trước chạy, bỏ flag và kiểm lại. Lượt query translation đầu escape regex sai trả0/0 đã sửa và xác nhận208/208. Không dùng các lượt chuẩn bị làm PASS. DB socket trong sandbox bị từ chối; chạy lại đúng instance tạm với quyền tạo Unix socket đã được chấp thuận, không đổi sang DB thật.

### 5.2. Probe độc lập

| Probe | Kịch bản | Kết quả |
| --- | --- | --- |
| P21 | Editor dirty và unknown-locked → cursor HTML500 → Retry thành công | PASS cả2nhánh, draft/locked nguyên |
| P23 | Decision note → ViewB, mọi confirmation Cancel | FAIL S1, note mất không hỏi |
| P24 | Decision note → Edit → Cancel editor chưa đổi | FAIL S1, note mới rỗng |
| P25 | Held bulk → nhập decision note mới → resolve | FAIL S1, note mất không hỏi |
| P26 | Bulk note → cursor thành công → Generate completed/list refresh | PASS, BULK_DRAFT giữ |
| P27 | Chọn summary → gập/mở Create bằng toggle | FAIL S2, checkbox mất |
| P28 | Pending Generate/status held → chọn concept mới → old completed | FAIL S2, form bị ẩn |
| P29 | Dirty editor/filter confirmation held → list cursor response → Cancel | PASS editor FILTER_DRAFT và filter cũ giữ; scheduler probe, không giả UI có thể click nền modal |
| P30 | Bulk held → revoke403 → list authorized → old bulk result | PASS không rehydrate, không GET mới cho response cũ |
| P31 | SaveA held → EditB + bulk note → Generate completed → SaveA completed | PASS DRAFT_B/NOTE_B giữ, kiểm hai luồng ghi chồng nhau |
| P32 | Decision/bulk note-only beforeunload | FAIL S1 không preventDefault |
| P33 | Unknown save → abandon frozen command để sửa tiếp | PASS text giữ và unlocked; M43 FAIL |
| P34 | Create selection → cursor thành công | PASS selection giữ; M40 FAIL |
| P35 | Chín field propose_new lần lượt dirty → Close, confirmation Cancel | PASS title/body/rationale/code/label/node_type/role/weight/criteria giữ, đều hỏi |
| P36 | Bulk-note-only beforeunload | FAIL S1; không có editor/note detail để lẫn oracle |

Probe dùng handler thật của module và synthetic fetch; FakeNode không thay browser. P29/P30 có điều khiển schedule nội bộ để kiểm response ordering; không gọi chúng là hai-tab UI hay backend transaction proof.

### 5.3. Browser thật, snapshot module nguyên bản

| Case | Thao tác và evidence |
| --- | --- |
| B12 | A→Edit RETRY_DRAFT_R5→Fail next list500→Load more→Retry. Sau retry draft nguyên; openA, detailReads1, confirms0; listReads2→3. **PASS Q1**. |
| B13 | Nhập NOTE_UNSENT_R5 vào noteA→ViewB. Note rỗng; detailReads1→2, confirms2 giữ. **FAIL S1**. |
| B14 | SelectB→Hold bulk→Reject/Confirm→nhập LATE_BULK_NOTE_R5→Release. Note mất, detailReads2→3, confirms3 giữ. **FAIL S1**. |
| B15 | Generate summary nhận pending→hold status refresh→đổi concept→release completed→mở Create. Hidden false→true; [concept]→[] khi mở; confirms3/posts2 giữ. **FAIL S2**. |
| B16 | Dirty FILTER_KEYBOARD_DRAFT→đổi filter bằng phím `s`→native shared dialog. Thao tác tiếp vào filter nền bị chặn, Tab ở2nút dialog, Escape huỷ, filter_all/draft giữ; focus về filter. **PASS ca keyboard/modal này**, không blanket a11y. |
| B11 regression | Dirty editorB→held bulk→đổi BULK_NEW_DRAFT_R5→release. Draft giữ, detailReads3, confirms5, có reread_discard. Cancel/Escape giữ, confirms6; Confirm mới bỏ editor/GET detailReads4, confirms7. **PASS O1/M34 behavior**. |
| B17 | Không editor, nhập decision LEAVE_NOTE_R5 và bulk LEAVE_BULK_R5→Leave link | Trang Left mở ngay không cảnh báo. **FAIL warning S1**; không phải chứng nhận BFCache. |

Harness tại `http://review.localhost:18995/reviewer.html`, tab3. Module sao **byte-identical** từ snapshot; browser JS riêng không mutation. Dùng **đúng function bootLfConfirmDialog trích nguyên bytes từ app.js**, native `<dialog>` với selectors tương ứng; không auto-answer confirmation như H4. Layout/message labels của harness tối giản, không phải trang LF đầy đủ. DOM thật và synthetic responses/held promises; không gọi private method sản phẩm từ browser evaluate. Không dùng harness để khẳng định transaction/backend authority, mobile CSS hoặc mọi thông điệp VI/EN đã render.

### 5.4. Mutation matrix

Mỗi mutation một phiên bản JS riêng trên copy, `node --check` trước chạy hai suite, phục hồi bytes và SHA sau mỗi variant. Node/Protocol: F=nonzero vì test fail, P=pass. Historical variant được tái dựng theo phép biến đổi đã mô tả ở Round4; không giả còn giữ patch/log tạm đã xoá của lượt trước. M23 là thích nghi có ghi rõ, không đánh lỗi từ no-op `loadList(true)`.

| ID | Biến đổi | Node / Protocol | Kết luận |
| --- | --- | --- | --- |
| M1 | literal innerHTML | F/F | Bắt |
| M2 | literal concat sink | F/F | Bắt |
| M3 | empty pagehide | F/P | Bắt |
| M4 | keep editor | F/P | Bắt |
| M5 | keep noteField | F/P | Bắt |
| M6 | ignore locked | F/P | Bắt |
| M7 | keep detail pending reread | F/P | Bắt |
| M8 | all epochs disabled | F/P | Bắt |
| M9 | HTML403 unexpected | F/P | Bắt |
| M10 | cursor selection retained | F/P | Bắt |
| M11 | no runBulk epoch | F/P | Bắt |
| M12 | no runCreate epoch | F/P | Bắt |
| M13 | keep bulk scalar | F/P | Bắt |
| M14 | replace variable sink | F/F | Bắt |
| M15 | add variable sink | P/F | Bắt |
| M16 | whitespace variable sink | P/F | Bắt |
| M17 | no releaseBulk revoke | F/P | Bắt |
| M18 | no releaseBulk pagehide | F/P | Bắt |
| M19 | keep bulk field value | F/P | Bắt |
| M20 | no postread create epoch | F/P | Bắt |
| M21 | no postread command epoch | F/P | Bắt |
| M22 | no postread bulk epoch | F/P | Bắt |
| M23 | late command destructive list adapted contract | F/P | Bắt |
| M24 | no detail scrub | F/P | Bắt |
| M25 | no scrub value | F/P | Bắt |
| M26 | no scrub leaf | F/P | Bắt |
| M27 | no scrub childtext | F/P | Bắt |
| M28 | no postconfirm bulk epoch | F/P | Bắt |
| M29 | no status epoch | F/P | Bắt |
| M30 | no refresh draft | F/P | Bắt |
| M31 | no refresh hidden | F/P | Bắt |
| M32 | pagehide false | F/P | Bắt |
| M33 | no pristine refresh | F/P | Bắt |
| M34 | reread bypass guard | F/P | Bắt |
| M35 | no pageshow reset | F/P | Bắt |
| M36 | plus-equal variable sink | P/F | Bắt |
| M37 | remove current UUID check | P/P | Tương đương hiện tại |
| M38 | default closeDetail true | F/P | Bắt |
| M39 | clear bulk note on list success | P/P | Sống, có ý nghĩa |
| M40 | reset create choices after pagination | P/P | Sống, có ý nghĩa |
| M41 | parenthesized computed sink | P/P | Sống, có ý nghĩa |
| M42 | filter no draft guard | F/P | Bắt |
| M43 | discard resets editor title | P/P | Sống, có ý nghĩa |


### 5.5. Rà toàn bộ đường bỏ draft

| Điểm gọi/primitive | Kết quả rà |
| --- | --- |
| start184 / loadList441–454 / Retry505 / cursor516 / lateCommand1473 / notFound1489 / afterGeneration1017 / runBulk1264 | loadList mặc định không dropDetail; Q1/N2 fixed. Selection xoá; bulkNote được render lại từ scalar. P21/P26/P31/P34. |
| filter408–415 | Chỉ caller closeDetail:true; guardDraft trước đó. Dirty editor Cancel/Confirm + keyboard B16 đạt. **Decision note không vào guard, S1**. |
| refreshOpen585–597 | pageHidden/current UUID guards, editor guard đúng; **note-only bỏ sót S1**. O1/M30–M35 đạt. |
| viewProposal601–604 / closeDetail703–708 | guardDraft có nhưng định nghĩa chỉ editor/locked; note-only S1. |
| openDetail660–687 / renderDetail716 | dropDetail trước GET; detailSeq kiểm ngay sau await; chỉ openDetail gọi renderDetail. Primitive phải nhận quyết định rời draft hợp lệ. |
| command success1464 / conflict1521 | Cùng detail, controls bị busy trước gửi, success/readback đồng bộ trước event khác; conflict có conflict_draft_lost cho editor. Không thấy editor loss mới. |
| notFound1485 / revoke390 | Object mất/authority loss phải scrub; P30 không khôi phục draft sau được quyền lại. Không yêu cầu confirmation để giữ dữ liệu bị cấm. |
| pagehide192 / pageshow210–212 | Teardown/authorized reread theo design; trước rời trang chỉ editor/locked cảnh báo → S1 cho notes. M3/M18/M32/M35 bắt. Engine persisted=true không kiểm mới. |
| showEdit / Cancel1614–1620 / showActions1348–1366 | Dirty editor được hỏi; note bị detached rồi replaced, P24 S1. |
| toggleCreate874–885 / afterGeneration1010–1021 / refreshGeneration1031–1058 | Hai đường reset/ẩn lựa chọn ngoài editor, S2. Không thuộc hợp đồng loadList nên đổi primitive list chưa giải quyết. |
| discardUnsent1410–1424 | Source giữ input và bỏ identity/locked đúng; P33. M43 cho thấy test cũ thiếu oracle ngay sau discard. |

## 6. Verdict 12 câu hỏi

| # | Verdict | Bằng chứng/kết luận |
| --- | --- | --- |
| 1 Quyền host | **APPROVE** | T2 Host14cases; controller552–588/service101–129 cùng actorRole: tenant/active admin/teacher active assignment3roles. Creator/page-only không thay authority AI. Backend H2 lịch sử, hashes không đổi. |
| 2 Disclosure | **APPROVE WITH CHANGES** | Node denial/held-parent/pagehide, P30; initial partial config-only. BFCache persisted=true/two-tab/cache forensic chưa chứng nhận. S1 không phải giữ nội dung trái quyền. |
| 3 XSS/free text | **APPROVE WITH CHANGES** | Source el textContent/control.value, không sink hiện hữu; N4 M41 scanner gap. Browser XSS canary H2 lịch sử, không exhaustive mọi field lần này. |
| 4 Command/idempotency/draft | **REJECT** | Frozen retry/UUID/guards và editor P21/P31/P33/P35 đạt; S1 note bị mất không hỏi, cảnh báo rời trang bỏ sót. |
| 5 Bulk | **REJECT** | Version eligibility/max100/no blind accept/unknown-only retry/cursor clear đạt source/Node. S1 late bulk phá decision note mới. |
| 6 Generate | **APPROVE WITH CHANGES** | Framework pairing/admin-only handoff,202/failed/gate recovery đúng source/Node/H2; S2 lifecycle của lựa chọn mới còn lỗi. |
| 7 Contract/honesty | **APPROVE** | confidence/weight tách; accept bốn loại chữ chỉ reviewed; propose_new chờ admin; reuse chỉ Reject. PHP/route không đổi; không thêm B/C hoặc hứa timestamps/DTO chưa có. |
| 8 Demo seed | **APPROVE** trong supported local disk | PHP/test hash không đổi; pre-DB env/disk guards/atomic mkdir source, H2 ledger/collision/race và H3 guards lịch sử. Không rerun seed/DB tests lần này. |
| 9 i18n/a11y/UI | **APPROVE WITH CHANGES** |208/208keys; B16 native dialog focus/Escape/Tab; H2 mobile lịch sử/D10. S1 warning notes còn;200%/screenreader/exhaustive error focus chưa phủ. |
| 10 Verification | **REJECT** | Tests30/39pass nhưng S1/S2 thoát; N4 còn4meaningful mutants. Ma trận §7 ghi gaps, SQLite không thay MariaDB. |
| 11 Regression | **APPROVE WITH CHANGES** | T3 rộng, T4/T5; source CSS scoped/root guard/backlink không đổi; H2 owner flows historical. Full3media failures, không blanket PASS. |
| 12 Chưa kiểm | **APPROVE WITH CHANGES** | §7–8 phân biệt evidence mới/lịch sử/chưa chứng nhận, baseline không tái lập; không suy “chưa ai kiểm” từ thiếu evidence. |
| **Toàn P3-A** | **REJECT** | Q1 đã đóng nhưng S1 HIGH còn. S2/N4 MEDIUM cần xử lý hoặc đăng ký đúng governance; không mở P3-B. |

## 7. Acceptance design review §6 ↔ evidence

Bảy nhóm acceptance gốc phân rã dưới đây. H2/H3/H4 có nghĩa evidence lịch sử reviewer, không chạy mới. CHƯA PHỦ là thiếu evidence được chứng nhận trong chuỗi review này, không khẳng định tuyệt đối “chưa ai kiểm”. Điều kiện trước P3-A lịch sử ở bản review thiết kế ban đầu đã được Owner/design approval sau đó giải quyết; không tái dùng câu “chưa xác nhận” lịch sử để phủ định v0.7.

| Acceptance | Evidence | Trạng thái/gap |
| --- | --- | --- |
| Admin/teacher3roles/creator/unassigned/ended/observer/inactive | T2 Host, service actorRole; H2 HTTP | Host PASS mới; backend H2 |
| Guest401/middleware403/object404/foreign tenant-parent-receipt | H2 HTTP104; source routes/controller unchanged | Historical PASS; dedicated teacher browser CHƯA PHỦ |
| Revoked render/GET/write/replay; admin-only controls | T2, Node30/P30, H2 HTTP/B8 | PASS runtime schedules; backend replay H2 |
| HTML ban đầu không AI payload | Partial allowlist + Host | PASS |
| AI/Media/criteria/human XSS/script/events/closing script | el/value/source, Protocol/M1/M2/M14–M16/M36/M41, H2 B3 | Source không sink; N4 gap; all-fields browser canary CHƯA PHỦ |
| Audit503 null/source deny/stale/tombstone/erasure | Node denial, H2 HTTP/source tests | Teardown PASS; dedicated erasure UI E2E CHƯA PHỦ |
| NonJSON/session expiry/late read/write/status | Node30, M8/M9/M11/M12/M20–M22/M28/M29, P30 | PASS tested schedules; S1/S2 khác loại data-loss |
| BFCache/history/tab/cache/storage/log/URL | source/Protocol; H4 pagehide persisted=false | persisted=true engine/two tabs/cache forensic CHƯA PHỦ |
| Five kinds/unknown fields/strict integer/bounds/schema_version | H2 validators/HTTP; P35 nine controls | Dirty detection PASS; exhaustive browser field bounds CHƯA PHỦ |
| confidence/nullable weight/reuse defer | source payload/stateNote; H2 B4/B5 | Historical PASS/source unchanged |
| no-detail→bulk blind accept/exact revision | Node reviewed/selectBox; H2 B6; browser B11/B14 | PASS eligibility |
| Client422 vs generic server422/429 | source errors, H2 HTTP, Node transport | Browser all422/429 focus paths CHƯA PHỦ |
| Same UUID/body retry/changed input/new UUID/reread | Node5–7/14, P21/P33; H2 replay | PASS core; real lost-response DB browser oracle CHƯA PHỦ |
| Doubleclick/two tabs/late responses/unsent inputs | Node busy/epochs; P23–P36; B12–B17 | **FAIL S1/S2**; two-tab UI CHƯA PHỦ |
| Mixed ordered bulk/duplicate/max100/unknown-only | Node12/13, H2 bounds, source runBulk | PASS core; mixed+unknown real DB browser CHƯA PHỦ |
| Cursor/filter reset/no global select | Node11/filter; P21/P26/P29/P34/B12/B16 | PASS editor/cursor; note-only S1; >25 real rows browser CHƯA PHỦ |
|202 manual refresh/no worker claim/failed | Node14/25; source; B15 synthetic pending→completed | Wording/recovery PASS; S2 choices; real202→failed browser CHƯA PHỦ |
|10 AI gates/quota entitlement/hold/overage | GATE_MESSAGES/source/Protocol/T3 gate tests | Three separate browser quota faults CHƯA PHỦ |
| competency/text accept≠apply; reuse/new/awaiting_admin | source/H2 B4/B5/backend counts | Historical PASS; B/C owner operations below N/A |
| Target/application/retry/cancel/inheritance/rebase | Frozen contract/H2 selected owner tests | **N/A P3-A UI**, không đòi B/C |
| Seed prod/staging/force/config, synthetic/no provider/no durable settings | source/hash; H2/H3 guards/ledger | Historical PASS; no seed rerun |
| Tenant folder collision/file/link/race | source atomic mkdir; H2/H3 | Historical PASS; no media written to original |
| VI/EN/long text/empty/loading/error | T6; source/H2; B12 error/recovery | Keys PASS; longbounds/all filtered-empty browser CHƯA PHỦ |
| Sidebar expanded/collapsed/mobile/200% | H2 B7, unchanged CSS/table | Historical mobile PASS;200% CHƯA PHỦ |
| Focus/dialog/keyboard/status/aria/busy/per-row | B16/real shared dialog/B11; Node N3 | Covered keyboard cases PASS; screenreader/all errors CHƯA PHỦ; S1 warning |
| Bounded histories/list | source25/100 limits | Source only;100+history browser CHƯA PHỦ |
| Manual Mapping/Activity structure/media/Version publish | T3/H2 owner104; scopedCSS/rootguard source | Historical backend PASS;3baseline media failures; no MariaDB rerun |
| D8 flat/section/Lesson/missing/revoked | Partial7–17/54; H2 backlink3tests | Historical PASS; revoked backlink browser CHƯA PHỦ |

## 8. Commands, safety, gaps và cleanup

| Lệnh/thao tác reviewer thực chạy | Phạm vi/kết quả |
| --- | --- |
| cat/sed/rg; Python hashlib; git status --short; git diff --check | Repo chỉ đọc;24hash đầu/cuối, status baseline giữ |
| rsync -a excludes .git/node_modules/runtime/storage/*/.env*/bootstrap cache PHP | Copy app vật lý; vendor0symlinks; không nạp repo env |
| node --test tests/js; node --check ai-authoring.js; php vendor/bin/phpunit --no-configuration --do-not-cache-result --bootstrap vendor/autoload.php Protocol | T1/T8;43variants; SHA restore từng lần |
| node --test probes.mjs; --test-name-pattern P26/P34/P33 với M39/M40/M43 | T7/T9; probe ngoài tests/js để không lẫn wrapper |
| APP_KEY=[synthetic test value, omitted] DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_HOST=invalid DB_PORT=0 DB_SOCKET=…/nonexistent.sock php artisan test --compact [targeted optional] | T2/T3, copy only; .env copy rỗng; không secret thật |
| PHPUnit Host --display-warnings; artisan Host --display-warnings | Xác định warning dotenv và sửa setup copy; không suppress warning để báo PASS |
| Pint --test Protocol; docs:lint; schema:drift --docs-only; route:list --path=ai-authoring --json | T4/T6; không format ghi original, không schema DB query |
| Offline copy resources/node_modules/package files/vite config→build riêng; npm run build | T5; VITE tổng hợp hostlocalhost; không install |
| mariadbd --no-defaults --version; mariadb-install-db --no-defaults --datadir=…/db --auth-root-authentication-method=normal --skip-test-db | MariaDB11.4.12 disposable |
| mariadbd --no-defaults --skip-networking --datadir=…/db --socket=…/db.sock --pid-file=…/db.pid --log-error=…/db.log | Unix socket-only; sandbox bind đầu bị chặn, retry được chấp thuận |
| mariadb --no-defaults --socket=…/db.sock -u root; CREATE DATABASE lf_p3a_r5; SELECT VERSION(),@@skip_networking | Version11.4.12/networking=1(off); database trống; không migrate/seed |
| DB_CONNECTION=mysql DB_DATABASE=lf_p3a_r5 DB_HOST=localhost DB_PORT=0 DB_URL= DB_SOCKET=…/db.sock APP_BASE_DOMAIN=localhost php -S127.0.0.1:18995 …/router.php | Chỉ review.localhost; static harness không queryDB; env chỉ temporary DB |
| CUA tab3 UI actions/snapshots | B11–B17; browser thật, synthetic transport/shared dialog function |
| tab.close; interrupt đúng web session; mariadb-admin --no-defaults --socket=…/db.sock -u root shutdown | Web/session exit0, MariaDB/session exit0; socket/pid biến mất trước xoá root |

**Zero-egress:** trước browser, loại external favicon links ở layouts app/auth **chỉ trong copy**. Router gửi CSP `default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; font-src 'self'; object-src 'none'; base-uri 'none'`. Harness chỉ cùng origin, AI transport trả Response tổng hợp. Không web search, provider thật, remote storage, npm install hay copy secrets. Không claim OS-wide firewall. Không kết nối learnforge_db127.0.0.1:3307 hoặc XAMPP3306. MariaDB query qua exact temporary socket; không TCP. Không seed nên AUTO_INCREMENT900 không áp dụng và không có tenant Media folder do seed.

**Lint artifact cuối:** đã đưa draft Round5 vào copy và chạy docs:lint lại: **3 issues** vì báo cáo mới chưa được nhắc trong docs/quality/README.md, docs/LF-INDEX.md và thiếu record trong LF-DOCUMENTATION-MANIFEST.json. Không phải lỗi source P3-A; không che bằng kết quả lint snapshot trước report. User chỉ cho tạo một file review nên reviewer không cập nhật ba file catalog này. Implementer/Owner cần đăng ký artifact sau khi nhận báo cáo theo quy trình tài liệu.

**Chưa tái lập/chưa kiểm:** baseline1397/23/11926 của implementer; exact11selected mutation patches của implementer không được cung cấp, thay bằng43variants reviewer định nghĩa; MariaDB104/941/H2 và seed/race probes/H3 không chạy lại vì PHP/route/test snapshots đó không đổi. BFCache persisted=true thật, hai tab, zoom200%, screenreader, mọi error focus/longbounds, real lost HTTP response với DB oracle và các dòng CHƯA PHỦ ở §7 chưa được chứng nhận. Browser harness không thay full LF backend E2E. Không đo provider quality/large-list performance/B/C ngoài phạm vi. Không suy “chưa ai kiểm” từ thiếu evidence; chỉ nói phạm vi reviewer xác nhận.

**Cleanup xác nhận:** tab3 đã đóng, không đổi viewport. Server web và MariaDB tạm đã dừng; db.sock/db.pid không còn. Toàn `/tmp/lf-p3a-r5-tlt59zz5` đã xoá sau khi giữ nội dung báo cáo trong bộ nhớ: DB, app/vendor, build/node_modules, logs, probes/mutants, harness, .env rỗng và test-generated media. Không có media reviewer tạo trong original repo. Chỉ thêm báo cáo Round5;24snapshot hashes đầu/cuối giữ nguyên. Không ghi key/password thật hoặc synthetic value vào báo cáo.

## 9. Điều kiện đóng

1. Sửa S1 HIGH và review độc lập lại note-only, Edit/Cancel, late bulk và beforeunload cho từng loại dữ liệu nhập. Giữ Q1/O1/N1–N3 đã đạt; không thay bảo mật revoke để khôi phục draft trái quyền.
2. Xử lý S2 và phần N4 còn lại, hoặc đăng ký MEDIUM có owner/hạn theo governance. Oracle phải nhìn ngay sau hành động có thể xoá dữ liệu; không chỉ nhập giá trị mới rồi kiểm lệnh gửi. Không ép M37 tương đương phải đỏ.
3. Giữ rõ baseline media và coverage gaps; không nâng test lịch sử, skipped hoặc waiver thành PASS mới.
4. **P3-A chưa đóng.** P3-B vẫn cần P3-A đóng và Owner duyệt riêng hai amendment allowed_actions + DTO tên/ứng viên Node. Review này không mở gate đó.
