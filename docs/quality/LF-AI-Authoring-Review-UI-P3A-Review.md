# AI Authoring Review UI — Independent P3-A Implementation Review

Version: 1.0

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-09-29

Document Path: quality/LF-AI-Authoring-Review-UI-P3A-Review.md

Reviewer: Codex — phiên reviewer độc lập P3-A, 2026-09-29

Reviewed Design: LF-AI-Authoring-Review-UI-Design v0.6, Approved

Reviewed Contract: LF-AI-Authoring-Proposal-Contract v0.8, Frozen

P3-A Verdict: **REJECT**

Classification: Existing-Feature Change — independent implementation review

Initial Audit Level: HIGH

Final Audit Level: HIGH

Audit Level Escalation: None

Findings By Severity: **0 BLOCKER, 5 HIGH, 6 MEDIUM, 0 LOW**

Regression Final Verdict: **FAIL** (acceptance failures đã tái hiện; không phải PASS có miễn trừ)

---

## 1. Kết luận, độc lập và phạm vi

**Chưa đóng P3-A.** Host authority và backend owner boundaries phù hợp; các luồng thuận sửa, accept, reject và bulk hoạt động trên dữ liệu tổng hợp. Nhưng browser state chưa thu hồi sạch sau denial/đóng trang, có đường response đến muộn khôi phục nội dung sau khi đã nhận denial, retry có thể gửi bản sửa cũ trong khi editor hiển thị bản mới, và seed chưa cô lập thư mục tenant/disk đúng yêu cầu an toàn.

Trong lịch sử phiên review được cung cấp, reviewer không viết/sửa implementation Bước 7, Phần 2, P3-A, thiết kế hay hợp đồng canonical. Trước và trong lượt này không sửa bất kỳ file snapshot nào. File duy nhất tạo trong repo là báo cáo này; không vá code, test, migration, index, manifest hoặc tài liệu thiết kế. Các mutation và probe chỉ nằm trên bản sao riêng trong `/tmp`, vendor được copy thật, không symlink. Không dùng khẳng định hoặc số test của implementer làm chứng cứ của reviewer.

Phạm vi: bốn lát P3-A và `ai:authoring-demo-seed`; không yêu cầu implement P3-B/C, provider thật, candidate Node DTO hay mở rộng `allowed_actions`. D8 backlink vẫn là yêu cầu trong thiết kế §8.4 và không thấy quyết định hoãn riêng trong gói bàn giao; ghi finding để Owner/implementer xử lý, không tự mở rộng scope.

Audit HIGH vì disclosure Media, authorization, idempotency/concurrency, review decisions và filesystem ownership. Source of truth: Guardrails → ADR-0017 → contract Frozen → thiết kế Approved; source thực tế là đối tượng được kiểm, không thay quy tắc. Không phát hiện schema/route AI bị đổi bởi P3-A. Không tạo migration.

Đã đọc theo thứ tự AGENTS, README, INDEX, Guardrails, Regression Audit, reviewer brief, thiết kế v0.6, design review (đặc biệt §6), contract Frozen; đối chiếu thêm phần liên quan của Development Standards, Architecture Review Checklist, ADR-0017 và Admin Form/List/Confirmation Standard. Các điều kiện lịch sử chưa pass trong design review lượt 1 không được dùng phủ định approval v0.6 đã ghi ở thiết kế §10.

### 1.1. Current / requested behavior, impact và invariants

Current behavior: mục AI gắn vào working Activity, JS đọc metadata/detail và gửi các lệnh hiện có. Requested behavior: triển khai đúng thiết kế, đặc biệt disclosure lifecycle, frozen retry, bulk theo revision đã xem và seed chỉ local/test, không mạng, không đụng tenant có sẵn.

Impact graph đã đọc: Activity controller → Course `authoringRole` → shared `actorRole`; Blade → app.js import → AI module → 41 API routes → HTTP controller/read adapter → Course/Media/AI owner services → review ledger; seed → fake provider + in-memory approval → real gate/Commercial ledger → Media disk. Manual Mapping/Version publication giữ owner flows hiện có. Không thấy JS ghi trực tiếp Course/Learning tables, publish, Evidence hoặc Mastery.

Invariants được giữ ở backend: customer_id/parent scope, live authority, L/R guards, append-only human decisions, accept ≠ apply/publish, confidence ≠ weight, no provider call trên human-only commands. Findings tập trung vào việc UI giữ/loại bỏ state và confinement của demo command.

## 2. Snapshot đầu / cuối

**19/19 SHA-256 đầy đủ khớp prefix bàn giao và không đổi đầu/cuối.** Đầu được chụp trước khi đọc implementation; cuối sau verification và sau khi dọn môi trường tạm. Bảng dưới ghi cả hai thời điểm bằng cột `Đầu = cuối`, không chỉ hash của Git HEAD. Working tree có thay đổi của implementer từ trước; không reset/stash/commit.

| File | SHA-256 đầu = cuối |
| --- | --- |
| `resources/js/ai-authoring.js` | `9643cb4dc8ab409c6b9fedfb40b271ebf6a982c1c129d68488ec388114ab3824` |
| `resources/js/app.js` | `d5da869391295db61d1ffb2337ef8c081dbb4e25980052c4f6530fc7bcd542e4` |
| `resources/views/course-template-activities/partials/ai-authoring.blade.php` | `2d4ec40400a7a58fff1be67903939aaa969cf0eebb00396e4d60bb708d79b2b9` |
| `resources/views/course-template-activities/show.blade.php` | `cd506b3ec05df4ad3231a98d4d073606b93e6ba3202e57170b594eefae4024b2` |
| `app/Http/Controllers/CourseTemplateActivityController.php` | `d56349503f58945d72b2238cbb75dfe41ac27c6d281e643a1e40759d3e6514ac` |
| `app/Services/CourseAuthoringContextService.php` | `5f36b85a2ddc67708eaae3db6e4a3cb75b01f73a3ab04ea5a24cc09b8794fa60` |
| `app/Console/Commands/AiAuthoringDemoSeed.php` | `971a504838a99c7d6e75f80f66d8793fbea01109318a548cf4f77eb6b517e6c6` |
| `app/Support/AiDemo/DemoAuthoringProposalProvider.php` | `ebaba37948c2f0225ba90a6eaa01113b4f6abbaf43dd862b1c2a9721e2b5de87` |
| `app/Support/AiDemo/InMemoryTenantSettings.php` | `fcf385f10e4cee00e1004ed152adfe9e540119e1ba60063cacd17b5ad7ca8b69` |
| `resources/css/admin/admin-pages.css` | `7ead1b346f61fa5aae7f8cd8fdba1be81ea5d12669242dfd3176c6b9c9ef0952` |
| `resources/lang/vi/lf.php` | `e66096296e981b00423862ac281fd5940ae66830ce9f373e2a0bafc501ae56c4` |
| `resources/lang/en/lf.php` | `8bccd352562f96cb4bd99b8ac8aae8df55036e293f924869af5f84772bdd247e` |
| `tests/Feature/AiAuthoringHostSectionTest.php` | `3ab90d5983c0446313c5ebee8f03acb4dcc9e8a3c69acb66b0b8c7b89f0ec208` |
| `tests/Integration/AiAuthoringDemoSeedMariaDbTest.php` | `fb7418e91f51686acf95390a1a7edca3bf531370152f8fe576ba4f80f15e5534` |
| `tests/Unit/AiAuthoringScriptProtocolTest.php` | `d6ce6c62289d5f05e8d3a34752e00ce1e88288924ce5df0eca52ab606615ca3b` |
| `routes/modules/ai-authoring.php` | `61ef30963f09a99305ccf01edb6c7eb06f1d544cf9f1459a2d274c539c4b2d86` |
| `app/Http/Controllers/AiAuthoringController.php` | `4d2f380d4a807916e396ce6bd84e1a412a47bc9e694b67650024ed5e7f193f82` |
| `docs/platform/LF-AI-Authoring-Proposal-Contract.md` | `fd23a83289e499015f46b66f1afff152130423742aa1f773f3be690bb59cd18f` |
| `docs/platform/LF-AI-Authoring-Review-UI-Design.md` | `78c69670459456b58f697609ae7f8064002bec61558df00e39af8cffc9d43358` |

## 3. Phương pháp và chứng cứ độc lập

Ký hiệu: **S** = tự đọc source; **T** = test reviewer thực sự chạy; **P** = probe độc lập gọi logic thực tế với dữ liệu tổng hợp; **B** = thao tác trình duyệt thật. Probe JS dùng Node `vm` và DOM tối thiểu, không phải browser E2E, không giả rằng nó chứng minh layout/BFCache engine. Không có network trong probe này: transport được thay bằng kết quả tổng hợp.

Đường dẫn `JS:n` bên dưới là `resources/js/ai-authoring.js:n`. `Host` = `tests/Feature/AiAuthoringHostSectionTest.php`; `Protocol` = `tests/Unit/AiAuthoringScriptProtocolTest.php`; `HTTP` = `tests/Integration/AiAuthoringHttpMariaDbTest.php`; `Seed` = `tests/Integration/AiAuthoringDemoSeedMariaDbTest.php`.

### 3.1. Test chạy được

| ID | Reviewer thực chạy | Kết quả |
| --- | --- | --- |
| T1 | Host + Protocol, SQLite `:memory:` trong bản sao; DB_URL rỗng, host invalid, port 0, socket không tồn tại | **35 passed, 133 assertions** |
| T2 | Protocol riêng trước mutation | **21 passed, 37 assertions** |
| T3 | Seed + HTTP, MariaDB 11.4.12 tạm | **33 passed, 606 assertions** |
| T4 | AiAuthoringProposalServiceMariaDbTest + AiAuthoringPublicationMariaDbTest + CourseTemplateLearningMappingHttpMariaDbTest + CourseTemplateLearningMappingPromotionMariaDbTest | **66 passed, 310 assertions** |
| T5 | Full default suite, SQLite trong bản sao | **1368 passed, 41 skipped, 7 failed, 11577 assertions**, 149.46 giây |
| T6 | Lặp các ca media thất bại với 7 file P3-A tracked trong bản sao tạm trả về HEAD, rồi phục hồi SHA-256 | **Cùng 7 failures**, thêm 2 ca khớp filter pass, 18 assertions; 6.48 giây |
| T7 | pint --test các PHP thay đổi; docs:lint; schema:drift --docs-only; git diff --check | PASS; docs lint ghi 94 mục legacy metadata debt trong allowlist, không lỗi mới; 101 migration files ở docs-only |
| T8 | npm run build trong bản build riêng, dependencies offline copy thật | PASS, 3.88 giây; build cho browser với biến VITE tổng hợp chỉ localhost: PASS, 3.44 giây |
| T9 | route:list --path=ai-authoring --json; so sánh bộ khóa translation bằng PHP | **41 routes = 23 admin + 18 teacher**; **197/197 khóa VI/EN giống nhau** |

MariaDB được tạo bằng `mariadb-install-db --no-defaults`, chạy `mariadbd --no-defaults --skip-networking`, Unix socket `/tmp/lf-p3a-review-Bhsn1b/db.sock`, chỉ database `lf_p3a_review`. Query xác nhận `@@version=11.4.12-MariaDB`, `@@skip_networking=1`. Migration đã chạy hết trên database trống. PHPUnit MariaDB dùng bootstrap riêng kiểm đúng socket/database và đặt `RefreshDatabaseState::$migrated=true` sau migration để tránh chạy lại cùng migration; vẫn dùng transaction/rollback của RefreshDatabase. Không đổi test assertion để pass.

**Phân loại T5:** 4 ca Office trong `DocumentProcessingLocalReviewTest.php:450` trả `processing_failed` thay vì ready; 2 ca FasterWhisper trong `MediaProcessingSubstrateTest.php:304,346` trả `provider_unavailable` vì bản sao loại runtime; ca corrupt video `VideoTranscriptCaptionLocalReviewTest.php:195` trả `processing_failed` thay `audio_extraction_failed`. Cùng 7 failures tái hiện ở T6 khi bỏ thay đổi P3-A tracked. Vì vậy không có chứng cứ quy lỗi chúng cho P3-A. Hai lỗi thiếu runtime có nguyên nhân trực tiếp xác định; nguyên nhân hệ thống sâu hơn của 5 lỗi binary còn lại chưa phân giải. Không mở rộng quyền/chạy provider thật để làm xanh suite. **Không tái lập được baseline 1393/23/11904 của implementer, không báo full-suite PASS.**

### 3.2. Probe độc lập và kết quả quan trọng

| ID | Cách tái hiện / kết quả quan sát |
| --- | --- |
| P1 | Render detail hợp lệ có `CANARY_BODY`; GET lại lần lượt trả session/forbidden/not_found/unavailable. Cả 4 ca: DOM không còn payload, nhưng `detailData.payload.body = CANARY_BODY`, `actionsHost.children.length > 0`, `reviewed.has(uuid)=true`. |
| P2 | Mở editor, nhập human note, gọi `clearDetail(true)`: `detailData=null` nhưng instance vẫn giữ đúng `actionsHost` cũ chứa input title `CANARY_TITLE`, và `noteField.value=CANARY_HUMAN_NOTE`. |
| P3 | Gọi transport thật với response `text/html`: 401/419 → session; **403/404/503 → unexpected**, `removesContent=false`. Không render HTML nhưng cũng không áp dụng quy tắc thu hồi bắt buộc. |
| P4 | Một dòng đã xem/chọn; đặt next cursor rồi gọi `loadList(false)`: `selectionRetained=true`, `reviewedRetained=true`. |
| P5 | Lệnh trả network; GET detail thành công lại cùng L/R; quyết định lại cùng input: hai `request_id` giống nhau. `openDetail` không xoá pending identity sau reread. |
| P6 | Gọi trực tiếp `runCreate` hai lần đồng thời tạo hai UUID khác nhau. **Chỉ là probe nội bộ; không dùng nó để kết luận double-click UI có thể tái hiện**, vì `submitCreate` có guard và retry button bị thay DOM. |
| P7 | Đang hiển thị detail hợp lệ; `refreshGeneration()` trả forbidden: nội dung vẫn hiện và vẫn nằm trong `detailData`. |
| P8 | Detail GET đang chờ; status GET trả forbidden; cho detail GET cũ hoàn tất ok sau đó: payload được render lại. Không có shared authority epoch để vô hiệu response cũ. |
| P9 | Render `content_denied=stale,payload=null` sau nhập note: `noteField.value` cũ và `actionButtons` cũ vẫn được instance giữ. |
| P10 | Dispatch `pagehide` handler: `bulkNote=CANARY_BULK_NOTE` và reference `noteField` còn. Không coi gọi handler là kiểm BFCache thật. |
| P11 | Dùng các handler editor/Retry thật: lưu title `DRAFT_FIRST`, request trả network; sửa input thành `DRAFT_CHANGED`; bấm Retry. Input hiện `DRAFT_CHANGED`, nhưng hai request đều gửi title `DRAFT_FIRST`, cùng UUID. |
| P12 | PHP command với DB mock cấm mọi `table()` call: production/staging/preview/LOCAL/rỗng đều exit 1 trước DB; `--force` bị parser từ chối. Gọi `standIn()` khi `media.disk=review_network_sentinel`: giá trị disk **vẫn giữ nguyên**, provider đổi sang DemoAuthoringProposalProvider. Không thực hiện remote I/O. |
| P13 | Trên MariaDB tạm, tạo canary `tenants/103/other-owner/keep.txt` trước seed; Activity đích chưa có folder. Seed **exit 0**, dùng tenant ID 103, số file trong tenant root **1 → 2**, giữ nguyên canary và tạo 7 proposals, 1 usage event. Chứng minh ghi thêm vào tenant root đã tồn tại dù không ghi đè canary. |

### 3.3. Mutation trên bản sao

Mỗi mutation sửa duy nhất bản sao `resources/js/ai-authoring.js`, chạy Protocol, rồi phục hồi bytes và SHA-256 `9643cb4d…3824` đầy đủ khớp snapshot. Không symlink vendor; không chạy mutation trên repo thật.

| Mutation | Kết quả test | Nhận xét |
| --- | --- | --- |
| Thêm `element.innerHTML = '<b>canary</b>'` | 1 failure / 21 tests | Bắt được literal sink |
| Thêm `element['inner' + 'HTML'] = '<b>canary</b>'` | **21 pass / 37 assertions** | Không bắt computed property |
| Đổi pagehide callback thành `() => {}` | **21 pass / 37 assertions** | Chỉ kiểm tên event, không kiểm cleanup behavior |
| Bỏ `if (seq !== this.detailSeq)` đầu tiên | 1 failure | Regex bắt được một shape GET guard; không chứng minh các async flow khác |

Test không phải vô dụng; nó bắt hai mutation có chủ đích. Nhưng không đủ oracle cho khẳng định “mọi async response / không giữ content”. Không phát hiện sink XSS thực tế trong source hiện tại chỉ từ việc mutation sống sót.

### 3.4. Ca trình duyệt thật

Dùng Codex in-app browser, build của snapshot, host `ai-demo.localhost:18991`, PHP server loopback + DB tạm. Không dùng learnforge_db. Cấu hình test dùng session file/HTTP local và VITE Reverb key **tổng hợp không phải secret**, host cũng localhost; không kích hoạt provider thật. Lần build không có VITE key ban đầu làm bootstrap Echo báo thiếu app key; build lại bằng cấu hình tổng hợp giúp module chạy. Đây là dependency baseline đã có, không quy thành lỗi mới của P3-A.

| ID | Thao tác và bằng chứng |
| --- | --- |
| B1 | Admin tổng hợp đăng nhập, mở Activity 105 của Template 104: attachment `phan-so.txt` còn, mục AI tải 7 dòng metadata; checkbox pending chưa xem disabled. |
| B2 | Mở Generate, chọn summary, submit với cấu hình provider mặc định: hiện “AI chưa sẵn sàng cho tổ chức của bạn.” Không thấy error details/SQL/raw code. |
| B3 | Mở competency, heading detail nhận focus; Edit title `P3-A <b>CANARY</b>` và body chứa literal script. Save tạo revision 2. DOM `.ai-authoring__payload` có **0 phần tử b/script**, title document vẫn “Xem hoạt động”, canary hiện nguyên văn. |
| B4 | Accept mở shared dialog, focus ở Hủy; Escape đóng và trả focus về Chấp nhận. Dùng Enter xác nhận: competency accepted revision 2, thông báo chưa áp dụng, không nút Apply. |
| B5 | Mở reuse_existing: chỉ có Reject, không Edit/Accept, weight trống riêng với confidence 58%. Mở propose_new: có Edit/Accept/Reject, weight 0.5 riêng với confidence 52%. |
| B6 | Xem rồi chọn hai mapping; bulk Reject qua shared dialog. Kết quả 2 success/0 failure/0 unknown, cả hai hàng chuyển rejected; kết quả hai dòng cùng tên “Ánh xạ Node — Thành công”, không phân biệt proposal. |
| B7 | VI → EN bằng language switcher; vùng AI hiện bản dịch English. Desktop 1280×900, sidebar expanded/collapsed quan sát được; mobile 390×844 không overflow toàn trang (`scrollWidth=375`, `innerWidth=390`), nhưng table không có `data-label`, giữ bảng hẹp và bulk buttons xuống nhiều dòng. Không kết luận mọi long text/mobile state đều đạt. |
| B8 | EN: mở và chọn Concept pending. Trong DB tạm đổi đúng admin tenant 103 sang inactive. Bấm View lại: detail hiện “You do not have permission to do this.”; **checkbox vẫn checked, Accept selected và Reject selected đều disabled=false**. Đây là denial thật qua middleware, không chỉ mocked transport. |

Đếm sau B1–B8 trên tenant demo: usage events **1**, applications **0**, Course Template Versions **0**, reviews **2 accept / 1 edit / 3 reject** (bao gồm 1 accept + 1 reject do seed). Không có Course publish/apply side effect từ thao tác review.

## 4. Findings

### P3A-R1 — HIGH — Thu hồi detail chưa xoá nội dung khỏi bộ nhớ và chưa vô hiệu bulk

**Vị trí:** JS:170–179, 441–474, 501–508, 1099–1123, 1179–1186; `clearDetail`, `openDetail`, `renderDetail`, `showActions`.

**Tình huống:** đã đọc/sửa/chọn proposal, sau đó GET bị 401/403/404/503; hoặc đóng/chuyển proposal, source denied, pagehide. `openDetail` thay DOM nhưng không reset `detailData`, pending identity hay reviewed/selected. `clearDetail` không xoá references tới `actionsHost`, `noteField`, `actionButtons`, `commandArea`/retry closures hoặc `bulkNote`. Detached DOM vẫn là bộ nhớ script, không được xem là đã xoá dữ liệu.

**Bằng chứng:** P1/P2/P9/P10 và B8; B8 xác nhận UI vẫn cho bấm bulk sau denial. Backend còn kiểm lại nên chưa chứng minh unauthorized DB write; lỗi là vi phạm disclosure/state-machine và việc disable mutations theo thiết kế §4.1.

**Đề nghị:** một đường teardown đầy đủ cho DOM, references, draft/notes/retry closures và eligibility; gọi khi bắt đầu chuyển detail và mọi authority/content denial. Sau denial không giữ capability UX từ lần đọc cũ. Test runtime phải kiểm references và nút/selection, không chỉ DOM text.

### P3A-R2 — HIGH — Denial không nhất quán giữa transport/status read và không vô hiệu phản hồi cũ

**Vị trí:** JS:221–241 (`request`); 734–817 (`runCreate`, `refreshGeneration`); 961–1012 (`runBulk`); thiết kế §4.1/§4.6.

**Tình huống:** response 403/404/503 không JSON hoặc JSON lỗi parse bị đổi thành unexpected; các caller xử lý như lỗi chưa rõ thay vì thu hồi. GET generation status trả 403 chỉ hiện message, vẫn để nội dung proposal cũ. Nếu một detail GET đã được authorize trước đó nhưng đến muộn, nó còn có thể render sau denial này.

**Bằng chứng:** P3/P7/P8. `runCreate`, `runBulk`, `refreshGeneration` không có mã thế hệ sau await; guard riêng list/detail không đủ cross-flow. Không báo “response nào cũng được bảo vệ” như bảng khẳng định brief.

**Đề nghị:** phân loại HTTP authority/unavailability trước khi dựa vào JSON; dùng một epoch/lifecycle invalidation phù hợp scope actor/tenant/visit cho mọi async flow. Denial từ bất kỳ flow liên quan phải invalidate outstanding content reads và teardown. Phân biệt unknown write outcome với failed content read, vẫn bảo toàn frozen command đúng lúc còn quyền.

### P3A-R3 — HIGH — Retry bản sửa cũ có thể làm mất bản đang hiển thị trong editor

**Vị trí:** JS:269–279, 449–474, 1139–1151, 1192–1198, 1257–1282, 1395–1403; thiết kế §4.2/§4.5.

**Tình huống:** Save timeout/network → editor được bật lại; người dùng sửa tiếp; Retry vẫn đóng trên command đầu tiên. UI không nói Retry sẽ gửi bản cũ. Khi command đó thành công, GET/rerender thay editor bằng nội dung bản cũ, không giữ/báo mất thay đổi mới. Ngoài ra GET detail thủ công không xoá `pendings`, nên quyết định mới sau reread có thể lấy UUID cũ. Đóng/đổi filter/rời trang không có dirty warning; `edit_note` chỉ nói confidence/source/history, không cảnh báo mất draft.

**Bằng chứng:** P11 gửi `DRAFT_FIRST` hai lần cùng UUID khi input đang là `DRAFT_CHANGED`; P5 giữ UUID sau reread. Luồng thành công tại JS:1160–1173 đọc lại và `renderDetail` dựng lại actions. B3 xác nhận editor/rerender thật hoạt động như source. Chưa fault-inject timeout trên browser thật; phần race là probe runtime.

**Đề nghị:** giữ bất biến retry cùng UUID/body nhưng không để editor mới bị âm thầm thay bằng frozen draft: khóa editor unresolved hoặc tách rõ “thử lại bản đã gửi” và “bản sửa mới”, cảnh báo/preserve bản chưa gửi. Sau reread để quyết định mới phải bỏ identity cũ. Không đơn giản đổi UUID cho retry unknown, vì sẽ phá idempotency. Bổ sung dirty-navigation/session messaging theo §4.2.

### P3A-R4 — HIGH — Seed chỉ guard thư mục Activity, vẫn ghi vào tenant root đã có

**Vị trí:** `app/Console/Commands/AiAuthoringDemoSeed.php:167–179`; Seed:99–115; `app/Services/MediaService.php:1045–1064`.

**Tình huống:** DB tạm cấp customer ID trùng thư mục tenant thuộc DB khác trên cùng disk, nhưng activity ID/path chưa tồn tại. `directoryExists(tenants/<id>/course/activities/<activity>)` trả false nên upload vào tenant root đó. Guard hiện tại chỉ bắt đúng leaf trùng, không đáp ứng “không ghi vào thư mục media của tenant đã tồn tại”.

**Bằng chứng:** P13 seed exit 0, tenant root 103 có sẵn, file count 1 → 2; existing file không bị overwrite. Test hiện tại chỉ dựng đúng leaf nên vẫn pass T3.

**Đề nghị:** xác minh/chiếm namespace tenant demo an toàn trước mọi filesystem write, kiểm toàn tenant root và tránh check-then-use race; ưu tiên disk/root riêng cho demo để không chia namespace với DB khác. Thêm ca tenant-root tồn tại nhưng activity leaf mới, không chỉ leaf tồn tại.

### P3A-R5 — HIGH — Seed không ràng buộc disk local, nên không bảo đảm “không mạng” với cấu hình khác

**Vị trí:** `AiAuthoringDemoSeed.php:90–116,171–175`; `config/filesystems.php:77–96`; `MediaService.php:90–104,1085`.

**Tình huống:** môi trường local/testing nhưng `media.disk=media_s3` hoặc driver remote khác. `standIn()` thay provider/approval/queue, **không thay hoặc từ chối media disk**. `directoryExists()` và upload sử dụng disk cấu hình, có đường đi tới S3/remote và credentials từ config. Environment local không chứng minh storage local.

**Bằng chứng:** P12 giữ nguyên sentinel disk sau standIn; tự đọc cấu hình `media_s3` driver s3 và MediaService. Đây là đường cấu hình được source chứng minh, **không thực hiện kết nối remote để thử**. T3/seed probe dùng local fake/local disk, không chứng minh an toàn với remote config. DemoAuthoringProposalProvider tự nó không gọi mạng.

**Đề nghị:** fail closed trước DB/filesystem work nếu disk/adapter/root không thuộc môi trường demo local được xác minh, hoặc cấp disk tạm local riêng. Test sentinel adapter cần chứng minh không bị gọi trong remote-config case; không dùng credential/mạng thật để test.

### P3A-R6 — MEDIUM — Chuyển con trỏ không reset lựa chọn bulk

**Vị trí:** JS:318–335, 366, 395–399; thiết kế §4.5.

**Bằng chứng:** `load_more` gọi `loadList(false)` rồi append items; reset selections chỉ nằm trong `if (reset)`. P4 xác nhận cả selected/reviewed còn. UI không có select-all vô hạn, có cap 100 và guard revision tốt, nhưng vẫn giữ lựa chọn qua cursor trái thiết kế hiện được duyệt.

**Đề nghị:** reset selection theo cursor như contract UX hoặc trình Owner duyệt thay đổi ngữ nghĩa load-more; thêm runtime test filter/cursor. Không tự diễn giải tích luỹ rows là ngoại lệ chưa được duyệt.

### P3A-R7 — MEDIUM — Nhóm lỗi Generate chưa hoàn tất dẫn người dùng tới danh sách thay vì status request

**Vị trí:** JS:745–746, 771–774, 795–799; `resources/lang/vi/lf.php:2652` và khóa EN tương ứng.

**Tình huống:** 409 AI_PROVIDER_CALL_FAILED/AI_QUOTA_COMMIT_FAILED/AI_QUOTA_RESERVATION_EXCEEDED/AI_RUN_*… Thiết kế §4.6 yêu cầu đọc lại **trạng thái yêu cầu**. Code chỉ gán `generationId` ở success, còn nhánh gate_incomplete chỉ cho `reload_list`; translation cũng bảo “xem lại danh sách”. Danh sách trống không chứng minh request failed/completed/running hay mức dùng đã settle.

**Bằng chứng:** source và T3 `test_status_endpoint_and_unconfigured_provider_do_not_expose_raw_inputs`; 10 mã đều có mapping nhưng chưa đúng hành vi phục hồi của nhóm này. Không thấy test UI riêng cho thiếu entitlement, hold refused và actual usage over reservation.

**Đề nghị:** giữ UUID request đã gửi, cung cấp explicit status read cho đúng UUID; xử lý status failed/pending/404 trung thực, không tạo yêu cầu mới ngầm hoặc suy hoàn tiền. Giữ wording quota hiện tại vì nó đúng với hai nguyên nhân gộp.

### P3A-R8 — MEDIUM — Không nhận diện được từng proposal trong bulk results cùng loại

**Vị trí:** JS:403–428, 856–859, 1040–1049.

**Tình huống:** nhiều proposal cùng kind/status/revision; danh sách, tên checkbox/view và bulk result chỉ ghi kind. Với mixed outcomes người dùng không biết dòng nào thất bại để đọc lại; kết quả không có reference/liên kết đối chiếu đến row.

**Bằng chứng:** B6 có hai kết quả cùng “Ánh xạ Node — Thành công”; P3-A seed cố ý có hai mode Node. Đây không phải lỗi xử lý outer 200: code có đọc `http_status` từng item, và HTTP mixed-outcome test pass. Lỗi là traceability và accessible per-row naming.

**Đề nghị:** dùng identifier/ordinal ổn định trong page và accessible label, kèm nút xem đúng proposal từ kết quả; không đưa title/payload vào metadata list trái DTO. Test mixed success/failure với hai proposal cùng loại.

### P3A-R9 — MEDIUM — Chưa theo các primitive List/Index bắt buộc

**Vị trí:** JS:301–311, 403–435; CSS:5476–5618; Admin Standard §25.3–§25.6; thiết kế §9.

Table có action column nhưng class chỉ `table`, không `admin-table-has-actions`; hàng dùng View button trực tiếp, không shared action menu/icon; không có `data-label`; create/count không theo toolbar trái/phải chuẩn. B7 xác nhận mobile còn table hẹp, zero data-label. Không có deviation approval trong thiết kế/brief.

**Đề nghị:** dùng primitive/behavior chuẩn cho vùng list, hoặc xin phê duyệt deviation rõ ràng cho embedded review section. Chưa thấy overflow toàn trang ở 390px nên không ghi “mobile hỏng hoàn toàn”. Dialog/focus cơ bản đã pass B4; 200% zoom và toàn bộ long-text matrix còn thiếu.

### P3A-R10 — MEDIUM — Protocol test không bảo vệ các acceptance có rủi ro cao

**Vị trí:** Protocol:23–29, 33–62, 108–142; ma trận design review §6.

Regex/string scan không chạy state transitions, không kiểm frozen body khi người dùng sửa tiếp, không kiểm deny teardown, bulk cursor/retry và cross-flow late responses. `pagehide` rỗng và computed HTML sink vẫn pass (mutation §3.3); P1–P11 tìm lỗi trong source đang có dù T1 pass.

**Đề nghị:** thêm behavioral tests với controlled deferred responses, detached references, BFCache lifecycle, dirty draft và per-item bulk. Static guard giữ vai trò bổ sung. Không cần hứa static scan chứng minh không XSS; có thể dùng AST/rules phù hợp nhưng vẫn phải có runtime oracle.

### P3A-R11 — MEDIUM — Thiếu backlink D8 và chưa có quyết định hoãn

**Vị trí:** `resources/views/course-templates/partials/learning-mappings.blade.php:1–47`; thiết kế §8.4/§10 D8.

Trang Mapping vẫn có manual select/store/destroy và hiển thị source type/#ID, nhưng không có link HTML về Activity. Không có diff implement backlink và không thấy Owner xác nhận hoãn như §8.4 yêu cầu. Test host trong section không thay cho test backlink flat/section/deleted/revoked.

**Đề nghị:** implement link bằng Course tree đã có, hoặc Owner ghi rõ hoãn D8; không tạo lookup AI UUID/raw query chéo miền hay dùng JSON endpoint làm link. Giữ nguyên manual controls.

## 5. Verdict cho 12 câu hỏi

| # | Verdict | Kết luận và bằng chứng của reviewer |
| --- | --- | --- |
| 1 — Quyền host | **APPROVE** | Controller:552–588 dùng `authoringRole`; Course service:103–136 dùng cùng actorRole như proposalContext:52; active tenant user + primary/assistant/reviewer active. Host T1 phủ creator/no assignment, ended creator, observer/generic role, 3 roles và nested section. HTTP T3 phủ guest/student/unverified/inactive/wrong role, foreign tenant/parent, unassigned và revoked replay. Không thấy mismatch không giải thích được; middleware denial đúng 401/403/404, không ép mọi ca thành 404. Chưa browser teacher riêng. |
| 2 — Lộ nội dung | **REJECT** | Initial AI partial chỉ config/i18n/CSRF, không proposal payload; JS không có explicit browser storage/log/content URL sink. Tuy nhiên R1/R2 vi phạm DOM+memory teardown/late responses; B8 và P1–P10. “Không Media trong HTML” chỉ đúng trong **mục AI**; trang host vốn có attachment name/link của Activity, không phải regression AI. |
| 3 — XSS/free text | **APPROVE WITH CHANGES** | Source `el()` dùng textContent, fields dùng value; mapping criteria JSON stringify thành text, không tạo href từ text. B3 actual canary không execute. Error response không được render markup/raw details. Protocol tĩnh có bypass và thiếu behavioral coverage (R10); không khẳng định có XSS exploit hiện hữu chỉ vì test yếu. |
| 4 — Command/idempotency | **REJECT** | Same-body retry cơ bản giữ UUID; L/R integers từ DTO, CSRF/no-store đúng; backend tests edit/replay/stale pass T3/T4. Nhưng R3 gửi frozen bản cũ khi editor đã đổi và giữ UUID sau reread; R1/R2 làm sai trạng thái sau mất quyền/async. Không xác nhận UI hai tab/lost response end-to-end. |
| 5 — Bulk | **APPROVE WITH CHANGES** | Chưa detail thì checkbox disabled (B1); exact L/R eligibility, unique per-item UUID, cap 100 và per-item status đúng (JS:821–983; HTTP mixed outcomes T3). Retry chỉ unknown giữ item body qua closure. R6 cursor reset sai; R8 không nhận diện từng dòng; R1 còn bulk sau denial. Không coi outer 200 là all-success. |
| 6 — Generate | **APPROVE WITH CHANGES** | Framework pair lấy từ Template và read-only; no framework chặn basis kinds, admin-only link Host T1. 202/manual refresh và failed wording đúng source:777–817; B2 not-ready đúng. 10 AI_* map đủ, quota trung tính đúng; R7 hướng recovery nhầm list, R2 status denial chưa thu hồi. Chưa browser 202/failed và ba quota fault paths. |
| 7 — Contract/honesty | **APPROVE** | Accept competency trong B4 không apply/publish; T3/T4 và count DB 0 application/0 Course Version. B5 confidence/weight riêng, reuse chỉ Reject; bốn loại chữ dừng, propose_new awaiting admin computed. List không title/timestamp; citations không raw media ID hay invented processing revision; review history có dữ liệu thời điểm được DTO cho phép. Không yêu cầu P3-B/C ở verdict này. |
| 8 — Seed | **REJECT** | P12 production/staging/unknown env/force denied trước DB, T3 no persistent approval/allowlist và second-run no-op; fake provider source không credential/network; P13 real ledger=1. Nhưng R4 thật sự ghi vào root tenant đã có; R5 disk remote vẫn được giữ. Chưa chứng minh safety với mọi cấu hình, không chấp nhận khẳng định blanket “không mạng/không ghi nhầm”. |
| 9 — i18n/a11y/standard | **APPROVE WITH CHANGES** | 197 khóa VI/EN bằng nhau, B7 English; semantic headings, labels, status/alert, color+text; B3/B4 focus detail, dialog Cancel, Escape/return focus và Enter đạt. R8/R9 còn per-row identity/standard; 200% zoom, screen reader, long VI/EN đủ bounds và focus mọi error state chưa chạy. |
| 10 — Verification | **REJECT** | Có 35 targeted + 99 MariaDB tests pass, build/lint pass và browser thuận. Nhưng matrix còn thiếu các ca high-risk; mutations cho thấy oracle không đủ; full suite không xanh trong bản sao. SQLite không có Learning/AI schema/CHECK tương đương; MariaDB T3/T4 bổ sung authority/ledger/publication, không thay concurrency/DDL matrix toàn bộ. R10. |
| 11 — Regression | **APPROVE WITH CHANGES** | Diff show chỉ thêm section; controller thêm read quyền, không sửa media/structure owner logic. CSS thêm đều scoped .ai-authoring*, import chỉ init khi có root; không quyền AI thì không gọi API, chỉ thêm query actor (teacher thêm assignment query) và tải bundle chung. T5 phần Activity/publishing pass, T4 manual Mapping/promotion/publication pass; B1 attachment còn. R11 thiếu handoff D8; media binary full-suite chưa pass (T5/T6), chưa đo performance. |
| 12 — Điều chưa kiểm | **APPROVE WITH CHANGES** | Danh sách rõ ở §7; không lấy claim implementer thành PASS. Các khoảng trống high-risk phải được đóng sau sửa, cùng review lại snapshot. “Không tìm thấy evidence” không đồng nghĩa khẳng định mọi người chưa từng thử. |
| **Toàn P3-A** | **REJECT** | **5 HIGH + 6 MEDIUM**; không đóng giai đoạn hoặc dùng waiver thay PASS. |

## 6. Ma trận acceptance §6 ↔ test / browser / khoảng trống

Ma trận dưới phân rã **đủ bảy hàng nhóm** của design review §6; “CHƯA PHỦ” nói về evidence truy vết của lượt review/gói bàn giao, không bịa rằng chưa ai từng thử. Test backend không được tính thay browser behavior.

| Nhóm §6 / acceptance | Test hoặc browser/probe truy vết | Kết quả / dòng chưa phủ |
| --- | --- | --- |
| Authority — admin/3 teacher roles | Host data provider `aiRoles`; HTTP `test_admin_and_all_three_active_assignment_roles_are_supported` (T1/T3) | PASS |
| Authority — creator không assignment, generic role/observer, creator assignment ended | Host `pageOnlyAssignments`, creator/ended tests (T1) | PASS |
| Authority — guest/student/unverified/inactive/foreign tenant/parent, unassigned | HTTP:69–110, 130–159 (T3) | PASS backend; inactive live browser B8 lộ R1 |
| Authority — revoked write/replay | HTTP `test_idempotent_generation_and_decision_replay_recheck_assignment`; ProposalService revoked test (T3/T4) | PASS backend; UI teardown FAIL R1/R2 |
| Authority — admin-only route/buttons/foreign receipt | Host teacher link test; HTTP:284,323 và middleware-stack test:466 (T1/T3) | PASS backend; chưa browser teacher riêng |
| Disclosure — initial AI HTML không payload | Host config whitelist + Blade source | PASS scope AI section; không có canary payload DB trong Host test, nên source evidence bổ sung cần thiết |
| Disclosure — XSS AI/Media/human/criteria/errors | B3 title/body thực; source payload/mapping/citations/text/error; mutation §3.3 | **PARTIAL / CHƯA PHỦ** event-attrs/closing-script ở mọi field/criteria và Media canary qua browser; không phát hiện sink hiện tại |
| Disclosure — audit503/data null, detach/tombstone/stale | HTTP failed-audit/detach tests (T3), ProposalService source/context/prompt tests (T4), P1/P9 | PASS backend; FAIL memory cleanup R1 |
| Disclosure — erase content | Source read deny intact/payload null; HTTP tombstone test | **CHƯA PHỦ** dedicated erasure suite/UI end-to-end lượt này |
| Disclosure — late response, history/cache/session/tab | P3/P7/P8/P10, Protocol mutation; B8 real 403 | FAIL R1/R2; **CHƯA PHỦ** BFCache engine persisted=true, tab switch/two tabs, cache/storage inspection browser thật |
| Review/transport — năm loại/schema/guards/unknown fields | ProposalService validator/edit tests T4, HTTP invalid/bounds T3, B3/B5 | PASS core backend; **PARTIAL** UI five-kind boundary validation |
| Review/transport — confidence và nullable weight | B5 (58%/null, 52%/0.5), source JS:542–603 | PASS |
| Review/transport — no blind bulk | B1/B5/B6; source recordReviewed/selectBox exact L/R | PASS thuận; denial invalidation FAIL R1 |
| Review/transport — client422 vs server422/non-JSON | HTTP invalid input T3; source submitEdit/server generic; P3 | Non-JSON denial FAIL R2; **CHƯA PHỦ** browser server422, rate429, malformed JSON matrix |
| Replay — same UUID/body after response lost, changed body/new command | HTTP replay T3/T4; P5/P11 | Backend PASS; UI FAIL R3; **CHƯA PHỦ** browser actual response-loss oracle |
| Replay — double click/two tabs/concurrency | backend stale guard tests T3/T4; source busy guards | **CHƯA PHỦ** two-browser-tab edit/accept races và concurrent UI submit. P6 direct call không thay browser case |
| Bulk — mixed outcomes/duplicate IDs/max100 | HTTP `test_bulk_reports_each_outcome_and_never_calls_provider`, bounds/envelope test (T3); B6 | PASS backend/all-success browser; **CHƯA PHỦ** browser mixed+unknown retry with actual lost response; R8 identity |
| Bulk — filter/cursor/Activity reset/no global select | source loadList; P4; no select-all control B1 | Cursor FAIL R6; filter reset source confirmed, chưa browser pagination >25 |
| Generate — 202/manual refresh, failed not success | HTTP status T3; JS:777–817 source | **PARTIAL / CHƯA PHỦ** browser 202→pending/failed; response epoch R2 |
| Generate — 10 AI codes; entitlement denied/hold denied/overage | source GATE_MESSAGES, translations; gate tests in T5 incl missing_entitlement/exhausted_quota/settlement; B2 | Mapping source đúng nhóm, recovery R7; **CHƯA PHỦ** riêng UI ba quota scenarios theo design §4.6 |
| Lifecycle — competency/text accept không apply, reuse/new P3-A | B4/B5; T3/T4; DB counts sau browser | PASS |
| Lifecycle (B/C) — operations, target/context, six inheritance conditions, retries/cancel/rebase rollback | T3 có một số backend paths; không chứng nhận UI | **N/A P3-A UI**; không đánh thiếu tính năng B/C |
| Fixtures — production/staging/force before DB | P12 DB mock, Seed production test T3 | PASS |
| Fixtures — tenant/root ownership/local config | Seed existing-leaf test T3; P13 wider-root collision, P12 config probe | FAIL R4/R5 |
| Fixtures — fake provider/no durable settings/real ledger/no fabricated receipts | Seed T3; Demo provider/settings source; P13 ledger1 | PASS default-local case; không blanket no-network với disk khác |
| UX — VI/EN/long text/empty/loading/error | T1 key equality, B1/B2/B7, source hasFilter | PASS keys/basic states; **CHƯA PHỦ** empty vs filtered-empty browser, all long text limits |
| UX — sidebar expanded/collapsed/mobile/200% | B7 desktop1280/mobile390 screenshots + DOM widths | PARTIAL, R9; **CHƯA PHỦ** 200% và tablet riêng |
| UX — dialog/focus/keyboard/aria/status/per-row | B3/B4/B6/B8; source | PASS core focus/dialog, R8 per-row; **CHƯA PHỦ** screen reader và error focus toàn ma trận |
| UX — bounded history/list | source PAGE_SIZE25, backend max100, reviews_limit at100 | PARTIAL; **CHƯA PHỦ** browser >=100 histories/applications và >25 proposal page |
| Regression — manual Mapping, Activity structure/media, Version publish | T4 manual Mapping/promotion/AI publication; T5 ActivityManagement/Publishing; B1 | PASS scopes tested; media runtime 7 failures T5/T6 riêng chưa được làm xanh |
| D8 backlink flat/section/deleted/revoked | Mapping partial source + thiết kế §8.4 | **CHƯA PHỦ / implementation absent**, R11; Host nested-section test không phủ backlink |

## 7. Điều chưa tái lập, rủi ro còn lại và điều kiện đóng

### 7.1. Claim implementer chưa tái lập đầy đủ

- Baseline full suite **1393 passed / 23 skipped / 11904 assertions**: không tái lập, T5/T6 là số của reviewer. Bản sao cố ý không có runtime; không kết nối DB đang dùng hoặc cài runtime để ép pass.
- Browser bulk response unknown rồi retry chỉ ghi một reject: chưa fault-inject qua HTTP/browser lượt này; chỉ đọc closure giữ body, chạy HTTP mixed/replay và B6 thuận. Không xác nhận claim này thay implementer.
- Coverage browser cho 202 pending/failed, quota overage, BFCache persisted, hai tab, mobile+zoom đầy đủ: tài liệu chỉ ghi tổng quát “đã kiểm trình duyệt”, không cung cấp từng case/output đủ để reviewer đối chiếu. Ghi CHƯA PHỦ thay vì suy đã đạt.
- Không tái chạy toàn bộ MariaDB erasure/rebase/successor/schema packet/concurrency suites; những tính năng B/C không là thiếu P3-A nhưng UI disclosure đối với stale/erase vẫn cần regression riêng.

### 7.2. Chưa có chứng cứ truy vết trong gói bàn giao

Không thể biết tuyệt đối “chưa ai kiểm”; những ca sau không tìm thấy evidence test/browser đáp ứng: cleanup detached DOM references; status-403 versus late detail response; Retry sau user sửa thêm draft; tenant root tồn tại nhưng leaf mới; remote media-disk refusal; cùng-kind mixed bulk result identification; 200% long VI/EN; D8 backlink tampering/deleted/revoked. Reviewer đã bổ sung P1–P13/B8 cho các lỗi có thể tái hiện; các mục còn lại vẫn mở trong ma trận.

Không đo performance list lớn (ngoài phạm vi), chất lượng model, activation provider, Qdrant/live network hoặc published Mapping correction (ngoài phạm vi). Không cần các mục ngoài phạm vi này để đóng P3-A.

### 7.3. Điều kiện review lại / đóng P3-A / mở P3-B

1. Implementer xử lý **R1–R5 HIGH**, cung cấp snapshot mới và behavioral regression chứng minh cả negative paths; reviewer độc lập xác nhận lại. Không sửa contract Frozen để hợp thức hoá lỗi UI.
2. Xử lý R6–R11 hoặc đăng ký MEDIUM với owner/hạn/điều kiện chấp nhận rõ; D8 cần implementation hoặc quyết định hoãn đúng thiết kế, không im lặng bỏ. Test tối thiểu cho disclosure/retry không được bỏ qua bằng waiver.
3. Hoàn thành high-risk gaps của ma trận sau sửa: denial/late response/BFCache/draft loss, mixed unknown bulk, quota status recovery, hai tab, responsive/accessibility. Báo chính xác full-suite/runtime limitations và phân loại baseline; không gắn PASS vào skip hoặc claim của implementer.
4. P3-A chưa đóng ở lượt này. P3-B chỉ mở sau P3-A được đóng và Owner duyệt riêng hai amendments `allowed_actions` + DTO tên/ứng viên Node; review này không duyệt trước hai thay đổi đó.

## 8. Lệnh đã chạy, phạm vi an toàn và cleanup

Mọi lệnh có thể ghi runtime/cache/build/test output chạy trong bản sao, trừ `git diff --check`/đọc/hash của repo và ghi báo cáo được phép. Lệnh rút gọn nhóm đường dẫn dài bên dưới dùng đúng các file liệt kê tại T1–T9; shell logs/probe bodies nằm tạm trong `/tmp` và đã xoá, các kết quả quan trọng được chép vào báo cáo này.

| Lệnh / nhóm thao tác thực chạy | Nơi / kết quả |
| --- | --- |
| `pwd`, `cat`, `sed`, `nl`, `rg`, `wc`, `git status --short`, `git diff -- …`, Python hashlib SHA-256 | Repo chỉ đọc; snapshot 19/19 match đầu/cuối; searches forbidden student/login patterns không thấy trong phạm vi UI mới |
| `rsync -a --exclude='.git' --exclude='node_modules' --exclude='runtime' --exclude='storage/*' --exclude='.env*' --exclude='bootstrap/cache/*.php' ./ /tmp/lf-p3a-review-Bhsn1b/app/` | Bản sao code/vendor, **0 symlinks**; không copy environment/secret. Copy build riêng dùng dependencies offline, không dùng vendor symlink |
| `mariadbd --no-defaults --version`; `mariadb-install-db --no-defaults --datadir=…/db --auth-root-authentication-method=normal --skip-test-db` | MariaDB **11.4.12**, bootstrap tạm |
| `mariadbd --no-defaults --skip-networking --datadir=…/db --socket=…/db.sock --pid-file=…/db.pid --log-error=…/db.log` | Chạy instance tạm; lần sandbox đầu bị chặn Unix-socket bind, chạy lại qua auto-review permission thành công; TCP tắt |
| `mariadb --no-defaults --socket=…/db.sock -u root -e 'CREATE DATABASE lf_p3a_review; SELECT @@version, @@skip_networking;'` | Chỉ DB tạm; xác nhận skip_networking=1 |
| `php artisan migrate --force` | App copy + DB tạm trống, tất cả migration DONE; không chạy vào learnforge_db/XAMPP |
| `php vendor/bin/phpunit --do-not-cache-result tests/Unit/AiAuthoringScriptProtocolTest.php` | T2; cùng lệnh chạy 4 mutations; restore SHA sau từng mutation |
| `DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_HOST=invalid DB_PORT=0 DB_SOCKET=…/nonexistent.sock php artisan test --compact [Host Protocol]` | T1; explicit safe config |
| Cùng SQLite env + `php artisan test --compact` | T5 full suite; không inference từ default DB của repo |
| Cùng SQLite env + `php artisan test --compact --filter='real_office_format_upload_extract_and_authorized_read\|faster_whisper_provider_returns_timestamped_units_with_explicit_locale\|faster_whisper_multilingual_profile_runs_one_auto_detect_timeline\|a_corrupt_video_fails_extraction_without_output_or_workspace_residue'` | T6 dùng regex alternatives (shell argument truyền qua subprocess); copy tạm trả 7 tracked P3-A files về HEAD rồi SHA restore; 7 failures tái hiện |
| `DB_CONNECTION=mysql DB_DATABASE=lf_p3a_review DB_SOCKET=…/db.sock DB_HOST=localhost DB_PORT=0 DB_URL= … php vendor/bin/phpunit -c …/mysql.xml --do-not-cache-result [Seed HTTP]` | T3, isolated socket-only env; không mật khẩu thật |
| Cùng MariaDB env/config + PHPUnit bốn test files ở T4 | T4; source assertions nguyên trạng |
| `php artisan route:list --path=ai-authoring --json`; `php vendor/bin/pint --test [8 PHP files/directories trong snapshot]` | T7/T9; không format write |
| `php artisan docs:lint`; `php artisan schema:drift --docs-only`; `git diff --check` | PASS; docs-only không kết nối DB |
| `npm run build`; build lại với VITE Reverb host localhost và key tổng hợp | T8; không npm install/không ra mạng; artifact chỉ trong bản copy |
| `node …/probe.cjs`; `php …/env-probe.php`; `php …/seed-probe.php` | P1–P13; P1–P11 transport mocked, P12 DB mock, P13 MariaDB tạm + Http::preventStrayRequests + local disk |
| `php -S 127.0.0.1:18991 …/server.php` từ copy/public; CUA browser tại ai-demo.localhost | B1–B8; lần đầu cwd sai được dừng/khởi động lại; không sửa router/vendor |
| SQL update đúng synthetic admin tenant103 inactive; cuối query counts | Chỉ lf_p3a_review; tạo denial B8 và counts §3.4 |
| Browser viewport reset + tab.close; `kill 26884`; `mariadb-admin --no-defaults --socket=…/db.sock -u root shutdown` | Web/MariaDB tạm dừng thành công; PID/socket files biến mất, lsof cổng18991 không còn listener |
| Python `shutil.rmtree('/tmp/lf-p3a-review-Bhsn1b')` sau kiểm instance đã tắt | Đã xoá DB, cả app/build copies, vendor copy, media tổng hợp/fake disks/canary, logs/probes/mutations. Không xoá worktree của người dùng |

**Cleanup xác nhận:** tab đã đóng, viewport trả mặc định; server cũ sai cwd PID26827 và server thử thật PID26884 đều dừng; MariaDB PID24872 tắt; socket/pid không còn; thư mục `/tmp/lf-p3a-review-Bhsn1b` đã xoá. Không có media reviewer tạo trong repo gốc. Manifest hash tạm được xoá sau khi ghi/kiểm báo cáo. Không có provider thật, secret thật, remote storage hay kết nối learnforge_db:3307/XAMPP:3306. Chỉ file báo cáo này được tạo trong repo; các thay đổi có sẵn của implementer giữ nguyên.
