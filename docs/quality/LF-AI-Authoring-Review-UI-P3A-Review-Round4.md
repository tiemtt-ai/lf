# AI Authoring Review UI — Independent P3-A Implementation Review, Round 4

Version: 1.0

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-09-30

Document Path: quality/LF-AI-Authoring-Review-UI-P3A-Review-Round4.md

Reviewer: Codex — reviewer độc lập các lượt 1–4

Reviewed Design: v0.7, Approved; D10 đã được Owner duyệt

Reviewed Contract: v0.8, Frozen

Classification: Existing-Feature Change — independent implementation review

Initial Audit Level: HIGH

Final Audit Level: HIGH

Audit Level Escalation: None

P3-A Verdict: **REJECT**

Regression Final Verdict: **FAIL**

Findings By Severity: **0 BLOCKER, 1 HIGH, 1 MEDIUM, 0 LOW** còn mở. HIGH Q1 mới; MEDIUM N4 tiếp tục, không đếm trùng.

## 1. Kết luận và độc lập

**O1 đã đóng:** P17 và B11 trên snapshot mới giữ bản nháp khi bulk trả lời muộn; nút đọc lại đi qua xác nhận. P18 không còn đọc lại nội dung proposal trong khi pageHidden. **Cả M1–M29 đều bị bắt**. N1–N3 không hồi quy trong các lịch đã kiểm.

Tuy nhiên, **Retry của danh sách vẫn xoá bản sửa không hỏi** sau lỗi tải thêm. Reviewer tái hiện bằng handler thật trong Node và bằng thao tác trên trình duyệt thật. Vì vậy R2/R3 chưa đóng hết và P3-A vẫn REJECT. N4 còn một phần vì các mutation bổ sung M34–M36 qua cả hai suite; M37 được phân loại tương đương trong call graph hiện hành, không tính thành lỗi.

Reviewer chưa sửa code/canonical docs của Bước 7, Phần 2, P3-A hoặc thiết kế/hợp đồng trong lịch sử phiên được cung cấp. Ba lượt trước chỉ tạo báo cáo; lượt này chỉ tạo file Round4 trong repo gốc. Không vá, reset, stash, commit hay thay nội dung working tree của implementer. Mutation và harness chỉ trong bản sao vật lý `/tmp/lf-p3a-r4-3bhjrnsd`; vendor có **0 symlink**.

Đọc AGENTS → README → INDEX/routing → Guardrails → Regression Audit → brief v1.3/lượt4 → các báo cáo Round3/Round2/lượt1 → design v0.7, nhất là §13.3 → acceptance design review §6 → contract Frozen. Các phần canonical không đổi đã được đọc trong cùng phiên review; lượt này đối chiếu lại các quy tắc draft/authority/transport. §13.1–§13.3 là khẳng định cần kiểm, không phải evidence. D10 hợp lệ; không tái mở R9 vì nút Xem.

Current/requested behavior: giữ draft trước refresh tự động, không rehydrate nội dung sau rời trang, bảo vệ mọi đường reset. Source of truth: Governance → ADR/owner contract → design Approved. Impact graph: Activity host → Blade/app.js → state/transport của UI → AI HTTP/service → Media/Course/Learning owner services. Audit HIGH vì disclosure, concurrency/idempotency và nguy cơ mất dữ liệu nhập. Không đổi schema/API/auth boundary; không mở phạm vi B/C.

## 2. Snapshot đầu / cuối

24/24 khớp prefix bàn giao trước thực thi; kiểm lại trước cleanup và sau ghi báo cáo. Đây là SHA-256 đầy đủ của **working tree**, không phải HEAD. **Đầu = cuối**; mỗi mutation cũng được SHA-restore riêng.

| File | SHA-256 đầu = cuối |
| --- | --- |
| `resources/js/ai-authoring.js` | `6547266d624199558d886f035afb5f5660cfd7fdbe755e3896a88a75e5a90f70` |
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
| `tests/Unit/AiAuthoringScriptProtocolTest.php` | `a90fa1e0cb832f73e8c350b6468960e96663547fa2fd789d3d1cf0cdb557550e` |
| `tests/Unit/AiAuthoringScriptBehaviorTest.php` | `1ecca50bfd1975f7fa1820e13ff857b4d525be2decc8c1ebf9f0d0073d9eaea5` |
| `tests/js/fake-dom.mjs` | `8038ec74f43865ee3f9e776558549483e2daaab09f4beac2da193d0023ca8043` |
| `tests/js/ai-authoring.behavior.test.mjs` | `3308b025b7be6cb53514bceeb65293bcc98737bb0879b7dad7c7b1304b66c20e` |
| `docs/platform/LF-AI-Authoring-Review-UI-Design.md` | `c812f06ae9ffee898e00a0e01c52cf9a9ac5542f38a4096129575f7ac788dbb8` |
| `routes/modules/ai-authoring.php` | `61ef30963f09a99305ccf01edb6c7eb06f1d544cf9f1459a2d274c539c4b2d86` |
| `app/Http/Controllers/AiAuthoringController.php` | `4d2f380d4a807916e396ce6bd84e1a412a47bc9e694b67650024ed5e7f193f82` |
| `docs/platform/LF-AI-Authoring-Proposal-Contract.md` | `fd23a83289e499015f46b66f1afff152130423742aa1f773f3be690bb59cd18f` |

## 3. Trạng thái findings

`JS:n` = `resources/js/ai-authoring.js:n`. T/P/B/M là evidence mới tại §5. H2/H3 là evidence của chính reviewer trong báo cáo lượt2/lượt3, **không phải chạy mới**. Không lấy số test implementer làm PASS.

| Finding | Trạng thái | Evidence/phạm vi |
| --- | --- | --- |
| O1 — bulk phá draft | **ĐÓNG** | P17, B11: draft giữ nguyên, không detail GET thêm; nút reread_discard xuất hiện. P22/B11 Cancel giữ draft, Confirm mới reread. JS:580–593/1259. |
| N4 — weak oracle | **ĐÓNG MỘT PHẦN** | M1–M29 caught, gồm whitespace/M27 mixed text/M28/M29. M34–M36 vẫn sống; own oracle bắt M34/M35. §4.2. |
| R1 — teardown | **ĐÓNG** trong các lỗi đã báo | Node26, P14, P18, B9a/b. Textarea thật và held ancestor sạch; pageHidden=true trên pagehide thật. Không chứng nhận BFCache persisted=true. |
| R2 — transport/async | **ĐÓNG MỘT PHẦN** | Denial/late detail/write/create/bulk/status guards đạt; N2/N3/O1 fixed. Q1 vẫn là đường recovery/reset làm mất editor. |
| R3 — frozen retry/draft | **ĐÓNG MỘT PHẦN** | Node unknown lock/exact body+UUID/discard/reread identity; P15/B10 giữ draftB. Q1 Retry list xoá draft chưa có quyết định rõ. |
| R4 — tenant media root | **ĐÓNG**, không thấy hồi quy | Seed/test hash không đổi; source claimMediaFolder độc quyền. H2 root-collision transaction/race và H3 guard dir/file/link vẫn là evidence lịch sử. Không chạy seed lượt4. |
| R5 — remote media disk | **ĐÓNG**, không thấy hồi quy | Seed:64–79/118–124 kiểm local/root trước query; hash không đổi. H3 env/driver probes DB0, H2 sentinel adapter0; không rerun riêng lượt4. |
| R6 — cursor selection | **ĐÓNG** | Node moving-to-another-page + M10 caught; JS:452 clear selection cho cả cursor. Q1 phát sinh khi retry lỗi cursor, không phải giữ selection cũ. |
| R7 — generation recovery | **ĐÓNG** | Node incomplete sameUUID/status-specific GET/soft404; P19 và M29 caught bảo vệ authority epoch. |
| R8 — row identity | **ĐÓNG** | Node mixed result/unknown-only retry; B11 có #11111111 và View theo dòng; named/ref/source nguyên. |
| R9 — list/mobile | **ĐÓNG** trong finding cũ | CSS/partial hash không đổi từ H2 B7; table data-label/count/actions còn. D10 cho phép View. Lượt4 không dùng harness tối giản để claim responsive LF mới. |
| R10 — verification | **ĐÓNG MỘT PHẦN** | 26behavioral +39targeted đạt nhưng Q1 thoát, N4 còn. |
| R11 — D8 backlink | **ĐÓNG**, không thấy hồi quy | Partial7–17/54, MappingHttp hash không đổi. H2 ba backlink cases flat/section/Lesson-missing đã pass; không rerun MariaDB lượt4. |
| N1 — bulk note/held DOM | **ĐÓNG**, không hồi quy | P14/B9a/b; real input empty, instance count=null/buttons0. |
| N2 — Save A phá draftB | **ĐÓNG**, không hồi quy | P15/B10: SECOND_UNSENT_DRAFT còn sau responseA. |
| N3 — success vượt denial | **ĐÓNG**, không hồi quy | P16, Node test18 cả3flows; M20–M22 caught. |

## 4. Findings còn mở

### P3A-R4-Q1 — HIGH — Retry danh sách xoá draft không hỏi

**Vị trí:** JS:500 `this.button('retry', () => this.loadList(true))`; JS:436–448 reset mặc định keepDetail=false; JS:630–654 dropDetail xoá editor. Trái khẳng định “đường do người dùng đều có guardDraft” ở design §13.3.

**Tình huống:** mở proposal A → Edit, nhập RETRY_DRAFT → Load more → GET list lỗi mạng/HTTP500 (không mất quyền). Draft vẫn hiện. Người dùng bấm Retry để tải danh sách lại. Handler reset list kèm detail, không guardDraft, không nêu việc bỏ bản sửa.

**Evidence:** P21 gọi các handler load_more/retry thật trên snapshot: assert draft được giữ hoặc có confirmation thất bại (`draft erased without confirmation`). B12 trình duyệt, transport tổng hợp trả HTML500 cho đúng GET list:

- Trước Retry: `draft=RETRY_DRAFT`, `open=A`, `status=error_unexpected`, `confirmCount=3`, `detailReads=2`, `listReads=3`.
- Sau Retry: `draft=null`, `open=null`, `status=loaded_count`, `confirmCount=3`, `detailReads=2`, `listReads=4`.
- Harness đã đặt câu trả lời confirmation kế tiếp thành Cancel, nhưng không có confirmation nào được gọi. Đây là click Retry UI thật; không gọi loadList trực tiếp bằng evaluate để tạo lỗi.

**Tác động:** mất bản sửa chưa gửi dù chỉ thử lại việc tải danh sách; version guard backend không cứu nội dung chưa gửi. Vi phạm §4.2/draft bảo vệ, cùng loại rủi ro dữ liệu với O1 nhưng khác call path.

**Đề nghị:** retry danh sách phải bảo toàn open detail/draft, hoặc hỏi rõ trước khi reset nó. Rà tập trung các entry points vào hàm reset, không chỉ refreshOpen. Test tải cursor lỗi rồi Retry với dirty/locked editor; cả nhánh Cancel và Confirm nếu retry được thiết kế là discard. Không vá trong review.

### P3A-R2-N4 — MEDIUM, tiếp tục — Test mới chưa phủ hết điều kiện mới và sink rule

**Vị trí:** `tests/js/ai-authoring.behavior.test.mjs` phần O1/lifecycle mới; `tests/Unit/AiAuthoringScriptProtocolTest.php:18,69–89`; code được bảo vệ JS:206,586.

**Evidence mới:** cả M1–M29 đều đỏ đúng như cần. Nhưng:

1. **M34:** riêng callback reread_discard đổi viewProposal → openDetail, bỏ guard/confirmation. Node26 + Protocol24 vẫn pass. Own P22 làm mutant đỏ: đã chọn Cancel nhưng editor vẫn bị xoá; source thật P22 pass.
2. **M35:** bỏ reset pageHidden=false trong pageshow. Hai suite vẫn pass. Own P18 (event lifecycle tổng hợp) bắt được cờ vẫn true; source thật pass. Sau một BFCache restore, guard refreshOpen của mutant sẽ tiếp tục chặn auto detail reread. Đây là bằng chứng logic của persisted path, **không phải engine BFCache đã tái hiện**.
3. **M36:** thêm sau textContent: `const member = 'inner' + 'HTML'; node[ member ] += String(text);`. Hai suite vẫn pass. Regex chỉ bắt phép gán `=`, không `+=`; fake DOM không parse HTML. Đây là sink ở **mutant**, không phải chứng minh source bàn giao có XSS.

**M37** bỏ uuid !== openUuid trong refreshOpen cũng sống, nhưng caller duy nhất hiện nay là runBulk, tính reopen từ chính openUuid rồi gọi ngay, không có await ở giữa; chưa có đường UI hiện hành làm điều kiện khác nhau. Phân loại **tương đương trong call graph hiện tại**, không tính thành lỗi hoặc yêu cầu test gọi method sai tiền điều kiện để ép đỏ.

**Đề nghị:** bổ sung oracle cho nút recovery Cancel/Confirm và cả hai nửa pagehide/pageshow; bổ sung regression Q1. Với sink, bảo vệ các dạng mutation có ý nghĩa bằng parser hoặc browser canary phù hợp, không tuyên bố regex chứng minh mọi JavaScript. Source hiện tại giữ các guard đúng, nhưng coverage vẫn chưa đạt khẳng định toàn diện.

## 5. Evidence thực thi

### 5.1. Test/build/lint

| ID | Reviewer chạy | Kết quả lượt4 |
| --- | --- | --- |
| T1 | node --test tests/js, snapshot sạch | **26 passed**,0failed |
| T2 | HostSection + ScriptProtocol + ScriptBehavior, SQLite :memory: | **39 passed,146 assertions**,2.44s |
| T3 | Full php artisan test --compact, explicit safe SQLite env | **1372 passed,41 skipped,7 failed,11590 assertions**,95.03s |
| T4 | Pint --test Protocol + hai lang files; docs:lint; schema:drift --docs-only; git diff --check | PASS;94legacy metadata allowlisted;101migration files docs-only |
| T5 | npm run build, dependency offline copy | PASS1.02s, không npm install/network |
| T6 | route:list JSON, VI/EN key comparison | **41routes;208/208keys**, chênh rỗng; hai key mới có đủ dịch |
| T7 | Own P14–P22 | **8pass,1fail**; duy nhất P21=Q1 |
| T8 | M1–M37, Node26 + Protocol24 cho từng variant | **33caught,4survive**;3survivor có ý nghĩa và1tương đương (§5.4) |
| H2/H3 | Seed/Mapping/AI HTTP/Proposal/Publication/Promotion MariaDB104/941 ở H2; guard probes H3 | Evidence lịch sử của reviewer; không rerun backend MariaDB/seed lượt4 |

T3 không tái lập 1397passed/23skipped/11917assertions của implementer. Bảy case giống lượt1–3: bốn Office processing_failed thay ready; hai FasterWhisper provider_unavailable do runtime bị loại; corrupt video processing_failed thay audio_extraction_failed. H2 đã chạy baseline hẹp bỏ tracked P3-A changes và vẫn bảy lỗi; lượt4 không lặp baseline mutation đó. Không quy chúng cho thay đổi JS/i18n này, không gọi full suite PASS hoặc khẳng định đã giải thích sâu cả năm lỗi binary. SQLite không chứng minh MariaDB CHECK/FK/trigger; skipped không phải PASS.

### 5.2. Probe và browser

| ID | Kịch bản | Kết quả |
| --- | --- | --- |
| P14 | Real field value trong fake DOM + input, giữ count node lên ancestor, revoke | field rỗng, không canary, count=null. PASS |
| P15 | Hold Save A, EditB, releaseA | B_UNSENT còn. PASS |
| P16 | completed Generate → list HTML403 | error_forbidden thắng. PASS |
| P17 | Hold bulk → Edit cùng proposal → release | BULK_NEW_DRAFT còn; reread_discard xuất hiện. PASS O1 |
| P18 | Hold bulk → pagehide → release → pageshow persisted=true giả | Trong hidden không GET detail, payload=null; sau pageshow cờ false và detail GET tăng1. PASS logic; không gọi engine proof |
| P19 | Hold generation status → revoke → completed cũ | Không reread/không thay denial. PASS |
| P20 | Hold bulk confirmation → revoke → authorised list reload → confirm cũ |0POST. PASS |
| P21 | Dirty editor → Load more HTML500 → Retry | draft mất, không hỏi. FAIL Q1 |
| P22 | refreshOpen giữ dirty → reread_discard Cancel rồi Confirm | Cancel giữ KEEP; Confirm mới xoá editor và GET lại. PASS source; M34 FAIL |
| B11 | UI thật: ViewA/chọnA/holdBulk/Reject/Edit/nhập/release | BULK_NEW_DRAFT còn; detailReads1 không tăng; confirmCount1; có stale_with_draft/reread_discard. PASS |
| B11 recovery | Cancel next confirmation → reread_discard; sau đó Confirm | Cancel giữ draft, confirmCount2/detailReads1; Confirm draft=null, confirmCount3/detailReads2. PASS |
| B12 | UI thật: Edit RETRY_DRAFT → fail next list500 → Load more → Retry | draft=null/open=null, confirmCount3 giữ nguyên. FAIL Q1 |
| B9a | Bulk textarea CANARY_B9A, revoke, giữ ancestor sau detach | values[], heldField rỗng, heldTreeValues[""]; count=false/buttons0. PASS |
| B9b / lifecycle | ViewA + bulk note, click Leave page thật rồi Back | pagehide pageHidden=true, open=A nhưng input canary rỗng; pageshow pageHidden=false ở trang tải mới. Cả hai **persisted=false** |
| B10 | Hold SaveA → EditB → releaseA | SECOND_UNSENT_DRAFT còn; open=B, confirmCount1. PASS N2 |

Browser dùng module **byte-identical** với snapshot; DOM thật, fetch trả synthetic Response/Promise để điều khiển thứ tự. Harness tự trả kết quả confirmation có thể chọn true/false, nên chứng minh callback được gọi và draft lifecycle, **không thay kiểm shared dialog focus/accessibility hoặc backend transaction**. Nhãn harness là message key và layout tối giản; không lấy nó chứng nhận giao diện LF/mobile/i18n rendering. Không dùng evaluate để gọi private state/method sản phẩm.

P18 cần diễn giải chính xác: pageHidden chỉ chặn detail reread qua refreshOpen; `loadList(true,true)` của runBulk vẫn có thể đọc metadata sau pagehide. Không có payload trong list và probe không thấy rehydrate detail. Không nâng câu “không đọc gì” của §13.3 thành chứng nhận zero request khi hidden. BFCache persisted=true engine vẫn CHƯA CHỨNG NHẬN.

### 5.3. Rà toàn bộ điểm có thể bỏ editor

| Điểm gọi / đường đi | Đối chiếu source và evidence |
| --- | --- |
| start:184 → loadList(true) | Khởi tạo trước editor; không draft |
| pagehide:192 → dropDetail(false) | Rời trang đã có beforeunload/hasDraft; P18/B9b teardown; late detail seq vô hiệu |
| pageshow:210–212 → loadList(true)/openDetail | Restore phải revalidate; pagehide đã clear; P18 event giả đạt, engine gap khai báo |
| revoke:390 → dropDetail(true) | Quyền bị thu hồi, bỏ draft theo design; Node denial/P14/B9a |
| filter change:408–414 → loadList(true) | guardDraft trước reset; Node leaving-unsent-edit test Cancel/Confirm |
| loadList:447 → dropDetail(true) | Primitive reset; phải xét caller. Retry:500 là ngoại lệ không guard, Q1 |
| Load more:511 → loadList(false) | Không drop detail; P21/B12 xác nhận editor còn sau lỗi trước Retry |
| refreshOpen:580–593 → openDetail | pageHidden/current UUID/hasDraft guard; P17/P18/P22/B11 |
| viewProposal:596–600 → openDetail | guardDraft; node row/flow test, B10/B11 recovery |
| openDetail:663 → dropDetail;682 → renderDetail | Chỉ async GET duy nhất gọi renderDetail; seq check:671 trước render, không để response cũ render sau drop |
| closeDetail:699–703 → dropDetail(true) | guardDraft; Node leaving-unsent-edit |
| runCommand success same-detail:1459 | Form đang busy, setBusy(false) rồi mở lại trong cùng lượt đồng bộ, không user event chen trước openDetail; Node save/retry paths |
| runCommand late other-detail:1468 | loadList(true,true), không reset editorB; P15/B10 |
| runCommand not_found:1480 | Own command báo object gone; drop theo denial/recovery, không âm thầm bỏ vì background unrelated read |
| runCommand conflict:1516 | Same-detail guard; thông báo conflict_draft_lost và reread own command theo §4.5; không giữ draft dưới UUID cũ |
| afterGeneration:1012;runBulk:1259 | List keepDetail=true; bulk dùng refreshOpen; P16/P17/B11 |
| showEdit Cancel:1610–1618 → editor=null/showActions | guardDraft, rõ thao tác Cancel; không auto callback bỏ draft |
| renderDetail:714 → editor=null | Chỉ caller openDetail đã liệt kê; không tìm thấy caller độc lập khác |

Rà source không đồng nghĩa chứng minh mọi interleaving khả dĩ. Q1 là ngoại lệ thực thi được, đủ bác bỏ khẳng định rà soát không còn đường mất draft; không coi việc “do user click” tự động là user đã đồng ý discard.

### 5.4. Mutation

Mỗi variant chạy Node26 + Protocol24, rồi phục hồi SHA JS `6547266d624199558d886f035afb5f5660cfd7fdbe755e3896a88a75e5a90f70`. Không chạy đồng thời mutation với full/targeted suite. Browser dùng bản module public riêng byte-identical không bị các mutation xen vào.

| ID | Mutation | Node / Protocol | Phân loại |
| --- | --- | --- | --- |
| M1 | textContent → literal innerHTML | fail / fail | Bắt |
| M2 | textContent → concatenated member | fail / fail | Bắt |
| M3 | pagehide callback trống | fail / pass | Bắt |
| M4 | bỏ editor release | fail / pass | Bắt |
| M5 | bỏ noteField release | fail / pass | Bắt |
| M6 | bỏ locked control disable | fail / pass | Bắt |
| M7 | bỏ pending clear khi openDetail | fail / pass | Bắt |
| M8 | vô hiệu mọi epoch compare | fail / pass | Bắt |
| M9 | HTML403 thành unexpected | fail / pass | Bắt |
| M10 | selected.clear chỉ reset | fail / pass | Bắt |
| M11 | bỏ epoch runBulk | fail / pass | Bắt |
| M12 | bỏ epoch runCreate | fail / pass | Bắt |
| M13 | bỏ scalar bulkNote clear trong releaseBulk | fail / pass | Bắt |
| M14 | thay textContent bằng variable sink | fail / fail | Bắt |
| M15 | giữ textContent + variable sink | pass / fail | Bắt |
| M16 | M15 thêm whitespace trong [ member ] | pass / fail | Bắt |
| M17 | bỏ releaseBulk tại revoke | fail / pass | Bắt |
| M18 | bỏ releaseBulk tại pagehide | fail / pass | Bắt |
| M19 | bỏ bulk input.value clear | fail / pass | Bắt |
| M20 | bỏ post-reread epoch Generate | fail / pass | Bắt |
| M21 | bỏ post-reread epoch Command | fail / pass | Bắt |
| M22 | bỏ post-reread epoch Bulk | fail / pass | Bắt |
| M23 | late Save trở lại loadList(true) | fail / pass | Bắt |
| M24 | bỏ scrub(detailHost) | fail / pass | Bắt |
| M25 | bỏ scrub input value | fail / pass | Bắt |
| M26 | bỏ scrub leaf text | fail / pass | Bắt |
| M27 | bỏ scrub child text-node | fail / pass | Bắt |
| M28 | bỏ epoch sau bulk confirmation | fail / pass | Bắt |
| M29 | bỏ epoch sau status GET | fail / pass | Bắt |
| M30 | refreshOpen bỏ hasDraft check | fail / pass | Bắt |
| M31 | refreshOpen bỏ pageHidden check | fail / pass | Bắt |
| M32 | pagehide không set hidden=true | fail / pass | Bắt |
| M33 | refreshOpen không đọc khi không draft | fail / pass | Bắt |
| M34 | reread_discard bypass viewProposal/guard | pass / pass | Sống, có ý nghĩa; N4 |
| M35 | pageshow không reset pageHidden | pass / pass | Sống, có ý nghĩa; N4 |
| M36 | computed sink += | pass / pass | Sống, có ý nghĩa; N4 |
| M37 | refreshOpen bỏ UUID equality check | pass / pass | Tương đương current call graph |

## 6. Verdict 12 câu hỏi

| # | Verdict | Kết luận/evidence |
| --- | --- | --- |
| 1 Host authority | **APPROVE** | T2 Host/shared actorRole; controller/service không đổi. H2 HTTP covers inactive/foreign/replay giữ historical status |
| 2 Disclosure | **APPROVE WITH CHANGES** | N1–N3/P18 đạt; initial partial config-only/no content storage. BFCache engine/two tabs/cache forensic chưa chứng nhận |
| 3 XSS/free text | **APPROVE WITH CHANGES** | Source textContent/value/Blade escape; không thấy actual sink. N4 M36 là scanner gap, không gọi source bị exploit |
| 4 Command/draft/idempotency | **REJECT** | Retry body/UUID và O1 đạt; Q1 mất draft khi retry list |
| 5 Bulk | **APPROVE** trong paths đã kiểm | P17/B11 fixed; exact revision/no blind accept/unknown-only retry/selection reset đạt Node; backend envelope H2, không rerun |
| 6 Generate | **APPROVE** trong paths đã kiểm | SameUUID recovery/status epoch, framework/admin handoff, 202/failed wording đúng source/tests; exhaustive quota browser còn gap |
| 7 Contract/honesty | **APPROVE** | Accept≠apply/publish, text4kinds stop, reuse Reject-only/confidence-weight riêng; source/H2 unchanged |
| 8 Seed | **APPROVE** trong supported local disk | Source/hash unchanged, H2 realledger/atomic collision/race + H3 preDB guards; không claim chạy lại seed hoặc mọi filesystem config |
| 9 i18n/a11y/UI | **APPROVE WITH CHANGES** |208keys bằng nhau, hai label mới đúng nội dung; D10 hợp lệ. Shared focus/mobile H2; zoom200%/screenreader chưa chứng nhận |
| 10 Verification | **REJECT** |29mutants cũ caught nhưng Q1 thoát và N4 còn; full suite7baselinefail, không PASS |
| 11 Regression | **APPROVE WITH CHANGES** | Scoped CSS/root init/host/manual mapping/backlink không đổi; T3 rộng, H2 owner MariaDB; baseline media limits giữ rõ |
| 12 Chưa kiểm | **APPROVE WITH CHANGES** | §7–8 tách historical/source/runtime/browser/engine gaps; không suy thiếu evidence thành chưa ai thử |
| **Toàn P3-A** | **REJECT** | Q1 HIGH cần sửa + reviewer xác nhận; N4 một phần |

## 7. Acceptance design review §6 ↔ evidence

H2/H3 là evidence lịch sử của reviewer. CHƯA PHỦ là chưa có chứng nhận ca tương ứng, không phải tuyên bố chưa ai từng kiểm. Bảy nhóm gốc được phân rã dưới đây.

| Acceptance | Evidence | Trạng thái/gap |
| --- | --- | --- |
| Admin/teacher3assignments/creator/page-only/ended/observer | T2 Host, actorRole unchanged | PASS Host mới; teacher browser riêng CHƯA PHỦ |
| Guest401/middleware403/object404/tenant-parent-receipt | H2 HTTP104; source routes/controller | Historical PASS, không rerun backend |
| Revoked read/write/replay/admin-only URL-button | T2/Node/P14/P19/P20; H2 B8/HTTP | Runtime schedules PASS; backend H2 |
| Initial AI HTML không payload/data leak | Partial config whitelist + T2 | PASS vùng AI; attachment host không phải AI disclosure mới |
| AI/Media/criteria/human XSS/event/closing-script | Source el/value; H2 canary B3; T8 mutations | PARTIAL N4; tất cả field browser canary CHƯA PHỦ |
| Audit503/null/source deny/stale/tombstone | Node denial/late tests; H2 HTTP/Proposal | PASS runtime + historical backend |
| Erasure/missing Activity/no resurrection | Source/H2 tombstone tests | Dedicated erasure UI end-to-end CHƯA PHỦ |
| Late response/session expiry/nonJSON | Node26/P15/P16/P19/P20/B10 | PASS tested schedules |
| pagehide/history/BFCache/tab/cache/URL/log | P18/B9b, Protocol/source | Normal lifecycle PASS; persisted=true engine/two tabs/cache forensic CHƯA PHỦ |
| Five kinds/schema/bounds/unknown/integer guards | Source editor, H2 validator/HTTP | Historical backend PASS; exhaustive browser bounds CHƯA PHỦ |
| Confidence/nullable weight/reuse | Source/H2 B5 | Historical PASS, unchanged |
| No blind bulk/exact read revision | Node reviewed/selectBox, B11 | PASS core |
| Client422/server422/nonJSON/429 | Node/source/H2 validation; B12 HTML500 | NonJSON safe text PASS; retry recovery FAIL Q1; exhaustive422/429 browser CHƯA PHỦ |
| SameUUID/body response lost/changed command/new ID | Node unknown/discard/reread; H2 backend replay | PASS tested; actual lost HTTP response DB oracle CHƯA PHỦ |
| Draft protection/double-click/two-tab concurrency | P15/P17/P21/P22/B10/B11/B12 | O1 PASS; Q1 FAIL; actual two-tab CHƯA PHỦ |
| Mixed outcomes/duplicateIDs/max100/unknown-only retry | Node bulk cases/H2 HTTP | PASS runtime/H2; real mixed+unknown browser DB oracle CHƯA PHỦ |
| Cursor/filter reset/no global select | Node + M10, P21/B12 | Selection PASS; list-error Retry draft FAIL Q1 |
|202/manual refresh/failed/no worker | Node recovery/source/H2 status | PARTIAL; real202→failed browser CHƯA PHỦ |
|10AI codes/quota entitlement/hold/overage | Source GATE_MESSAGES, Node gate recovery/T3 gate tests | Three distinct UI quota browser paths CHƯA PHỦ |
| Text/competency accept≠apply; node reuse/new/awaitingadmin | Source stateNote + H2 B4/B5/DBcounts | Historical PASS; no B/C claimed |
| Operations/targets/inheritance/retry/cancel/rebase | Contract/backend historical subsets | **N/A P3-A UI** |
| Seed env/force/local synthetic/remote refusal | Source + H2/H3 guards/ledger | Historical PASS; not rerun seed |
| Tenant media collision/link/race/no durable config | H2 transaction/race + H3 guards; source/hash | Historical PASS, no new DB media written |
| VI/EN/long text/empty/loading/error | T6 keys/source/H2 browser; B12 error | Keys PASS; all longbounds/filter-empty browser CHƯA PHỦ |
| Sidebar/mobile/200% | Unchanged CSS/table; H2 B7 | Historical mobile/desktop PASS;200% CHƯA PHỦ |
| Focus/dialog/keyboard/status/aria/per-row | Node N3; B11 callback+DOM; H2 shared dialog/focus | Partial; harness confirmation not real dialog; screenreader/errorfocus exhaustive CHƯA PHỦ |
| Bounded histories/list | Source25/100 bounds | Source only; >=100 history browser CHƯA PHỦ |
| Manual Mapping/Activity/media/Version publication | T3, H2 owner104 suite | Historical owner PASS;7media failures remain; MariaDB not rerun |
| D8 flat/section/Lesson/missing/revoked | Source partial, H2 three backlink tests | Historical PASS; revoked backlink browser CHƯA PHỦ |

## 8. Lệnh, an toàn, mục chưa kiểm và cleanup

| Lệnh / thao tác reviewer thực chạy | Nơi/kết quả |
| --- | --- |
| cat/sed/rg/nl, git status --short, git diff --check, hashlib SHA-256 | Repo chỉ đọc;24hash đầu/cuối; không sửa snapshot/canonical |
| rsync -a exclude .git/node_modules/runtime/storage/*/.env*/bootstrap cache PHP | App copy dưới /tmp; vendor vật lý0symlink; không dùng env/secret repo |
| node --test tests/js; PHPUnit --no-configuration --do-not-cache-result Protocol | Baseline và37mutants; logs riêng; restore SHA mỗi variant |
| node --test own-probes.mjs; --test-name-pattern P18/P22 trên M35/M34 | T7 + mutant-oracle;8pass1fail source; hai mutant bổ sung đỏ |
| DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_HOST=invalid DB_PORT=0 DB_SOCKET=…/nonexistent.sock php artisan test --compact [targeted optional] | T2/T3 copy only, không lấy DB mặc định |
| php artisan docs:lint; schema:drift --docs-only; route:list --path=ai-authoring --json; Pint --test Protocol/lang | T4/T6; no formatter write original; schema docs-only |
| Copy resources/node_modules vào build riêng; npm run build | Offline, no install; VITE tổng hợp hostlocalhost:18994 |
| mariadbd --no-defaults --version; mariadb-install-db --no-defaults --datadir=…/db --auth-root-authentication-method=normal --skip-test-db | MariaDB11.4.12 disposable |
| mariadbd --no-defaults --skip-networking --datadir=…/db --socket=…/db.sock --pid-file=…/db.pid --log-error=…/db.log | PID64633, socket-only; query xác nhận skip_networking=1 |
| mariadb --no-defaults --socket=…/db.sock -u root; CREATE DATABASE lf_p3a_r4 | DB trống; không migrate/seed/learnforge_db/XAMPP; AUTO_INCREMENT900 không áp dụng vì không seed |
| DB_CONNECTION=mysql DB_DATABASE=lf_p3a_r4 DB_HOST=localhost DB_PORT=0 DB_URL= DB_SOCKET=…/db.sock php -S 127.0.0.1:18994 …/router.php | WebPID66720; review.localhost. Static harness không queryDB, cấu hình DB chỉ tạm |
| CUA real browser B9a/b/B10/B11/B12 | Module byte-identical, synthetic transport; canary tổng hợp |
| tab.close; kill66720; mariadb-admin --no-defaults --socket=…/db.sock -u root shutdown; kiểm PID/socket | Processes tạm dừng trước xoá root |

**Zero-egress:** trước mở browser đã loại hai dòng link favicon ngoài trong mỗi layout app/auth **chỉ ở copy**. Router gửi CSP self-only: default/script/style/connect/img/font cùng origin (inline script/style phục vụ harness, img thêm data:), object-src none/base-uri none. Harness không có tài nguyên ngoài; AI fetch dùng synthetic Response; lifecycle beacon chỉ `/reviewer-event` same-origin. Không gọi provider thật, web search, remote storage hoặc thêm secret. Không claim đã bật OS-wide firewall. Layout gốc giữ nguyên.

**Mục chưa kiểm/không tái lập:** baseline1397/23/11917; mọi command PHP MariaDB/seed/race/backend suite không chạy lại lượt4 vì thay đổi JS/i18n/tests/docs, dùng H2/H3 có nhãn lịch sử. Không seed nên không phát sinh tenant Media folder. BFCache engine persisted=true, hai tab, zoom200%, screenreader và các dòng CHƯA PHỦ §7 vẫn chưa được chứng nhận. P18 event giả không thay engine proof. Không đo provider quality/large-list performance/B/C vì ngoài phạm vi. Không dùng claim “mọi mutation” không kèm exact patch của implementer thay37variants reviewer thực chạy.

**Cleanup xác nhận:** tab tạm đóng; không thay viewport. WebPID66720 và MariaDBPID64633 tắt, socket/pid files không còn. Toàn `/tmp/lf-p3a-r4-3bhjrnsd` đã xoá, gồm DB, app/vendor, build/node_modules, harness/beacons, logs/probes/mutants và mọi test-generated media/canary. Không có media mới trong repo gốc. Chỉ tạo file báo cáo Round4;24snapshot SHA không đổi sau ghi; không ghi password/key vào báo cáo.

## 9. Điều kiện đóng P3-A

1. Sửa Q1 HIGH; reviewer xác nhận Retry sau lỗi cursor/list với draft đang nhập và unknown-locked command. Không dùng nhãn Retry như đồng ý bỏ dữ liệu.
2. Giữ regressions O1/N1–N3/M1–M29; xử lý hoặc đăng ký phần MEDIUM N4 có owner/hạn theo governance. Ưu tiên oracle hành vi recovery/lifecycle và sink có tác động, không ép đỏ mutation tương đương M37.
3. Giữ ma trận gap, baseline media classification và giới hạn lịch sử rõ ràng. Chưa có quyền gắn PASS cho engine/browser cases chưa chạy.
4. P3-A chưa đóng; P3-B vẫn cần P3-A đóng và Owner duyệt riêng hai amendment allowed_actions + DTO tên/ứng viên Node. Review này không mở gate đó.
