# AI Authoring Review UI — Independent Design Review (Phần 3)

Version: 1.0

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-09-29

Document Path: quality/LF-AI-Authoring-Review-UI-Design-Review.md

Reviewer: Codex — reviewer K3 và closure Phần 2, độc lập với implementer Bước 7 và tác giả UI

Reviewed Design: LF-AI-Authoring-Review-UI-Design v0.2

Design Verdict: **APPROVE WITH CHANGES**

P3-A Implementation Gate: **CHƯA PASS — chưa bắt đầu code theo v0.2 hiện tại**

Initial / Final Audit Level: **HIGH / HIGH** (impact của implementation dự kiến; không phải chứng nhận audit implementation đã hoàn thành)

Findings By Severity: **0 BLOCKER, 4 HIGH, 4 MEDIUM, 0 LOW**

---

## 1. Kết luận, độc lập và phạm vi

Kiến trúc chính phù hợp: một bề mặt review ở working Activity, dùng web/session và owner services hiện có, giữ proposal/review/application/publication riêng, không thêm schema hoặc domain. Có thể tiếp tục hoàn thiện thiết kế theo D1–D8 với các thay đổi dưới đây. Tuy nhiên v0.2 có mô tả lifecycle sai và phụ thuộc dữ liệu chưa khai báo; **không dùng verdict có điều kiện này làm Architecture Review PASS để bắt đầu P3-A**.

Reviewer chưa từng viết/sửa code hoặc tài liệu canonical của AI Authoring/Bước 7 hay thiết kế này. Những lượt K3/closure trước chỉ tạo báo cáo review. Lượt này chỉ tạo báo cáo được giao; script probe và bản sao nằm trong /tmp. Không sửa backend, test, migration, contract Frozen, ADR hoặc tài liệu thiết kế. Owner duyệt D1–D8 là authorization hướng thiết kế, không thay chứng cứ độc lập.

Đã đọc brief và bảng khẳng định cần tự kiểm; docs/README, LF-INDEX routing, Guardrails, Development Standards, Regression Audit, Architecture Review Checklist, ADR-0017, chuẩn Admin Form/List/Confirmation, contract Bước 7 và source liên quan. Đây là review thiết kế dựa trên source và probe hẹp, **không** là review implementation UI chưa tồn tại, không tái mở closure Phần 2.

## 2. Snapshot và phương pháp

**7/7 hash đầu/cuối khớp bàn giao**, kể cả brief. Bảng ghi chung giá trị ở hai thời điểm; manifest riêng nằm trong evidence. Nếu snapshot lệch, review không được chuyển sang kết luận trên cây mới.

| File | SHA-256 đầu = cuối |
| --- | --- |
| `docs/platform/LF-AI-Authoring-Review-UI-Design.md` | `81d26268c61f528d0a5d25f1ac71eda6d4074d3cc63da70ad9411793aa371a9f` |
| `docs/platform/LF-AI-Authoring-Proposal-Contract.md` | `fd23a83289e499015f46b66f1afff152130423742aa1f773f3be690bb59cd18f` |
| `routes/modules/ai-authoring.php` | `61ef30963f09a99305ccf01edb6c7eb06f1d544cf9f1459a2d274c539c4b2d86` |
| `app/Http/Controllers/AiAuthoringController.php` | `4d2f380d4a807916e396ce6bd84e1a412a47bc9e694b67650024ed5e7f193f82` |
| `app/Services/AiAuthoringProposalService.php` | `8e5ede418a0b557368e03e858f6bf82970a8f815d2068504c00cfcd445b83bd5` |
| `app/Services/AiAuthoringHttpReadService.php` | `70b347bfa0701832907f6d53d59803b2871d21081dc337bfa8453e766e8ca01c` |
| `docs/quality/LF-AI-Authoring-Review-UI-Design-Reviewer-Brief.md` | `4b13696515c81b44cc4f49edf069487c1106d097be0be562c85d7434107200af` |

Code chạy trong bản sao `/tmp/lf-p3-ui-review-09368uu2/app`, vendor copy thật, **0 symlink**, `.env` testing tổng hợp, DB_CONNECTION=sqlite/DB_DATABASE=:memory:, DB_URL rỗng, DB_PORT=0 và socket không tồn tại. Không dựng hoặc kết nối MariaDB; không kết nối learnforge_db/XAMPP, không gọi provider, không thêm secret. Các probe không gọi generation hay mutation domain, không lưu proposal vào database.

### 2.1. Chứng cứ source chính

| ID | Source đã tự đọc | Điều xác nhận |
| --- | --- | --- |
| E1 | `routes/modules/ai-authoring.php:6–52`, `routes/web.php:119–201`, `routes/modules/course.php:22` | 18 endpoint chung, 5 admin-only; middleware role/tenant/session; cờ đăng ký ở hai prefix |
| E2 | `AiAuthoringProposalService.php:62–111,328–388,394–556` | Basis, unconfigured refusal, show/allowed_actions, edit/decision/replay guards |
| E3 | `AiAuthoringHttpReadService.php:25–81,115–171` | Parent scope, list DTO, context preview, detail applications, commandVersions |
| E4 | `AiAuthoringController.php:31–145`; `AiAuthoringInput.php:16–118` | JSON transport/strict inputs, per-item bulk, status/error allowlist và no-store |
| E5 | `CourseAuthoringContextService.php:27–118`; `CourseTemplateActivityController.php:500–535,1410–1473` | Quyền AI assignment hẹp hơn quyền vào trang Activity; shared show view; hai route-prefix khác nhau |
| E6 | `AuthoringPayloadValidator.php:28–128`; `AiAuthoringApplicationService.php:50–110,114–209,214–281,353–424,555–588` | Chỉ node_mapping có mapping; Node/Intent states, retry actor, target rejection, context confirmation |
| E7 | `AuthoringProposalRecords.php:46–53,121–193`; `AiAuthoringSuccessorService.php:46–120` và `inheritedProjection()` | Audit trước disclosure, source/context/prompt stale, điều kiện kế thừa hẹp |
| E8 | `CourseTemplateController.php:181–185`; `CourseTemplateLearningMappingController.php:17–65`; `LearningFrameworkReadService::customerAdminTenantId`; `routes/modules/learning.php` | Mapping tab/chọn Framework/Learning authoring reads hiện admin-only; không có candidate enumeration cho teacher trong 41 endpoint |
| E9 | `CourseTemplateLearningMappingIntentService.php:16–42`; partial `learning-mappings.blade.php`; `course-template-activities/show.blade.php` | Intent state có internal proposal ID, không UUID; manual controls hiện còn; Activity dùng chung view |
| E10 | `config/ai.php:35,210–217`; `AppServiceProvider.php:69`; `UnavailableAuthoringProposalProvider.php`; `AiProviderExecutionGate.php:64–109,146–252` | Default chưa activation; AI_* bao gồm cả runtime/settlement errors, không chỉ ba refusal |
| E11 | `tests/Integration/AiAuthoringHttpMariaDbTest.php:69–110,239–254` | Đọc test hiện có: guest 401, role/inactive 403, object scope 404; failed audit kỳ vọng 503/no payload. **Không chạy lại các test này lượt này** |

Các đường service/controller trong bảng thuộc `app/Services` hoặc `app/Http`; line range chỉ để xác định đoạn đã đọc. Contract v0.8 § HTTP endpoint/authorization, § State transitions, § Restricted decision inheritance và ADR-0017 A3–A11 là nguồn quy tắc; UI không được nới quyền dựa trên gợi ý hành động.

### 2.2. Bảng khẳng định của brief — kết quả độc lập

| Khẳng định | Kết quả |
| --- | --- |
| 41 route Activity-scoped | **Đúng**: route:list trong bản sao trả 23 admin + 18 teacher; không có tenant-global proposal route |
| allowed_actions chỉ pending_review | **Đúng nhưng có điều kiện visible**: list/detail còn yêu cầu nguồn/payload hợp lệ. Status pending_review đơn lẻ không đủ để bật nút |
| Chỉ competency/node_mapping bắt buộc Framework, phải khớp Template | **Đúng** ở generate. Basis còn phải được Learning xác nhận published/active; có IDs không bảo đảm eligible. Learning objective vẫn cần Course/Activity context |
| Provider refusal 409, details rỗng, blocked_at không lộ | **Đúng** qua source và response reflection; no-store được Symfony chuẩn hóa thành `no-store, private`. Không suy mọi AI_* đều là refusal/chưa activation |
| List metadata-only, không title/snippet | **Đúng**. DTO thực tế **không có thời điểm** mà §4.1 dự kiến hiển thị |
| resources/views không dùng Livewire; partial có chữ Việt cứng | `rg` không thấy Livewire/livewire/wire: trong views; partial có các chuỗi Việt cứng. JS hiện dùng Alpine. Đây là kiểm source, không chứng nhận package Livewire chưa được cài |

## 3. Findings cần giao implementer/tác giả thiết kế

### P3-UI-R1 — HIGH — Bảng sau accept sai loại và nghĩa application

**Vị trí:** thiết kế §4.5 (dòng 149–156), D7; E6; contract § Human approval and publication/State transitions.

`competency` không có mapping branch: validator chỉ thêm `mapping` cho node_mapping; target() trả `not_a_mapping` cho mọi loại khác. Probe độc lập chấp nhận competency thường nhưng từ chối competency+mapping. Vì vậy hàng “competency, node_mapping — reuse_existing” dẫn tới thao tác không tồn tại. Ngoài ra `target-rejections` chỉ dành cho **Intent đã applied**; receipt chưa applied phải Cancel, không đi theo “confirm hoặc reject rồi apply” chung. Timeline một chuỗi chưa phân biệt hai operation.

**Đề nghị:** summary/concept/learning_objective/**competency** sau accept chỉ ghi nhận; chỉ node_mapping tiếp tục. Tách create_node (Learning ghi Node trong draft, receipt applied ngay) và apply_intent (Course ghi working Intent). `awaiting_publication` của receipt đợi **Framework Version**; `applied` của apply_intent là đã ghi Intent, **chưa** canonical Mapping hoặc Course publish. Sau admin tạo Node còn target confirmation, Framework publish và lựa chọn/rebase Template đúng Version trước Apply Intent. Các owner publish vẫn ngoài UI này. Chỉ applied Intent mới dùng reject_target để chặn publish tương lai; không undo Node/Mapping/receipt. Ghi rõ retry create_node admin-only và approved_by gốc không đổi.

### P3-UI-R2 — HIGH — Gộp stale/context/target thành một đường phục hồi

**Vị trí:** §3.5, §4.6 (160–165); E2/E3/E7; contract § P1-2 và Restricted decision inheritance.

Pending context drift, source drift hoặc generated-prompt drift thành stale; stale không trở lại pending/accepted bằng context confirmation. contentPreview() gọi show() và từ chối content_denied bằng proposal_stale; reconfirmContext() chỉ nhận accepted. Accepted Course-only context drift, khi nguồn còn đúng, mới được xem/xác nhận context hiện tại. Target drift là nhánh riêng, không nhất thiết proposal.status=stale. Không phải stale pending nào cũng có predecessor từng được accept để làm successor.

**Đề nghị:** bảng trạng thái/phục hồi riêng cho pending stale, accepted source drift, accepted context_changed, target_changed và access denied. Không hứa banner biết nguyên nhân chi tiết khi DTO chỉ có unavailable/stale. Kế thừa successor chỉ source_revision_changed và mọi điều kiện của Frozen contract: actor/source hiện còn quyền, cùng file/fingerprint, payload chưa erase, **đã có accept lịch sử**, Node result eligible, nguồn hiện tại >0. Preview bỏ rationale/citations/**confidence**, source_refs bắt đầu rỗng; tạo pending mới, chọn references mới và accept mới. Reason khác phải payload người dùng tự viết, không tự copy. Không nguồn/quyền thì không dựng successor từ dữ liệu cũ; đưa về manual owner authoring phù hợp. Không dùng context confirmation để vượt source/prompt stale.

### P3-UI-R3 — HIGH — P3-A “không cần đổi backend” thiếu dependency của editor và quyền host

**Vị trí:** §2, §4.2–4.3, §6.1, §8; E5/E8/E9.

P3-A bao gồm chọn Node/mode nhưng 41 endpoint không trả candidate list hoặc đề xuất basis IDs trong detail. Learning basis service có candidates nội bộ cho provider; đó không phải teacher-facing endpoint. Target preview chỉ nhận accepted và chỉ trả một target, không giải quyết pending editor. Framework IDs có thể lấy từ Template mà host Course view đã cung cấp; **không** cần tưởng tượng endpoint mới cho việc đó. Nhưng khi Template chưa chọn Framework, teacher không có mapping tab và select controller từ chối teacher; link “để chọn” không đúng cho vai trò này.

Host Activity còn cho teacher là created_by hoặc mọi assignment active, trong khi AI port đòi user active và assignment primary/assistant/reviewer hiện hành. Route registration flag và việc thấy trang Activity không đủ để bật AI section/actions.

**Đề nghị chốt trước code:** hoặc giới hạn P3-A editor giữ mapping mode/Node/Definition hiện có, chỉ sửa các field thực sự được duyệt (và sửa D2/phạm vi rõ); hoặc thiết kế read DTO qua Learning/Course owner ports, authority và test rồi duyệt bổ sung contract/backend trước UI. Không đọc chéo core_learning_* trong JS/controller AI, không nới Learning admin guard, không yêu cầu teacher đoán numeric Node/Definition ID. Teacher chưa basis thấy “cần admin chọn Framework”; link/admin action chỉ admin. AI section dùng quyền AI hiện hành, không quyền host rộng hơn. UI data wiring trong host controller phải được kê scope; “không đổi schema/domain” không đồng nghĩa “không có dependency read model”.

### P3-UI-R4 — HIGH — Chưa quy định loại bỏ nội dung đã tải khi disclosure không còn hợp lệ

**Vị trí:** §3.3, §5, §7; E2/E3/E4/E7. Đây là **thiếu điều kiện thiết kế**, không khẳng định có bug UI đã triển khai.

Backend audit fail trả 503 với data=null; no-store không tự xóa DOM, draft memory, cache JS hoặc nội dung quay lại qua history. Nếu UI giữ detail trước đó khi refresh lỗi audit/401/403/404/content_denied, lời hứa mất quyền = mất nội dung không được bảo đảm. Response cũ đến muộn còn có thể nạp lại detail sau khi đã nhận denial.

**Đề nghị:** state machine loading/authorized/denied/unavailable; chỉ render payload sau GET hợp lệ đã audit. Khi đã nhận denial/session loss/audit-infrastructure failure của content read, gỡ nội dung và disable mutations; không fallback sang payload cũ để tiếp tục review. Reset theo proposal/tenant/actor, chặn response đến muộn, xóa memory khi đóng/chuyển scope; pageshow/history restore phải revalidate trước khi mở nội dung. Không lưu payload vào localStorage/**sessionStorage/IndexedDB/Cache API/service-worker cache** hoặc telemetry; tránh innerHTML/unsafe HTML và URL tự sinh từ nội dung. Chốt UX draft rõ: lỗi mạng write với quyền còn hợp lệ có thể giữ tạm trong memory để retry; mất phiên/quyền không hứa khôi phục qua login bằng storage. Báo trước nguy cơ mất draft; không hi sinh disclosure để autosave. Không hứa thu hồi realtime trong browser khi chưa có tín hiệu server.

### P3-UI-R5 — MEDIUM — UI hứa dữ liệu/điểm vào mà DTO chưa cung cấp

**Vị trí:** §4.1, §4.3, §4.6, §5 và D8; E2/E3/E5/E9.

List không created_at; citations chỉ ordinal/media_file_id/usage_type/content_type/locale/locator, **không processing_version/fingerprint/excerpt**. context-preview chỉ current Course DTO/hash, không old DTO để diff. Application summary chỉ UUID/operation/status/approved_by (bounded 100), không timeline timestamps/error detail. Confidence/rationale nằm trong payload, không top-level. Mapping state có internal ai_proposal_id/revision ID, chưa có UUID cho link trực tiếp. Route prefix trên Activity là `…lessons.activities` hoặc `…sections.lessons.activities`; không phải prefix AI. Probe route-name xác nhận dùng nguyên activity `$routePrefix` để nối `.ai-authoring` sẽ không tồn tại.

**Đề nghị:** lập field/endpoint/availability matrix; bỏ thời điểm, version citation, old/new diff hoặc timeline lịch sử mà không có data; nếu giữ phải amendment allowlisted DTO qua owner service, không lấy raw rows/historical snapshots. Giới hạn lịch sử 100 phải hiển thị trung thực, không coi là toàn bộ. D8 link direct cần AI-owned lookup UUID tenant/parent scoped hoặc chỉ link về Activity; không fan-out tìm UUID không giới hạn và không query AI tables từ Course để lấy nội dung. Dùng `templateRoutePrefix + '.activities.ai-authoring.…'`, IDs template/activity riêng, không lesson/section ID. D8 chỉ read-only cho review AI; giữ select/store/destroy manual hiện hữu theo authority, không biến cả Learning page thành read-only.

### P3-UI-R6 — MEDIUM — Thiếu protocol request_id, guards và bulk review có nội dung

**Vị trí:** §3.6, §4.4, §7; E2/E4; contract § Validation/pagination/replay.

Thiết kế chỉ nói UUID/409, chưa nói đầy đủ như brief yêu cầu. Guard là operation-specific; successor/inherit/rebase không nhận expected_lock_version giả. Probe Input từ chối guard lạ trên decision và successor; nhận UUID/selection hợp lệ khi successor không có lock field. Server trả details={} cho 422, không đủ tự gán lỗi server vào một field. List chỉ metadata nên bulk chọn dòng rồi accept ngay không tạo một bước review nội dung có nghĩa.

**Đề nghị:** đóng băng command body/UUID/guards trước gửi; network timeout/abort là outcome unknown, retry cùng UUID và **cùng toàn bộ body**, kể cả expected versions/reason. Thay nội dung hoặc sau refresh để ra quyết định mới phải UUID mới; không thay guards dưới UUID cũ, không tự regenerate. 202 chỉ manual status refresh, không lời hứa worker; status GET có thể HTTP 200 nhưng request_status=failed, không được toast “đã tạo thành công” hoặc invent nguyên nhân không có trong DTO. 409 giữ thao tác chưa áp dụng, re-fetch current authorized detail để người quyết định; không tin replay historic status thay current read. Bulk có outer UUID correlation, distinct item UUIDs và proposal IDs, tối đa 100; snapshot selection/version từng dòng, xem detail hợp lệ trước bulk decision, không “chọn tất cả” xuyên trang vô hạn; retry chỉ item outcome unknown với body gốc, giữ kết quả từng dòng. Filter/cursor/parent đổi phải reset selection phù hợp. Lỗi client validation có thể cạnh field; 422 backend details rỗng phải form-level message, không chế field code.

### P3-UI-R7 — MEDIUM — Denial/error matrix không khớp và AI_ không phải phân loại hành vi

**Vị trí:** §2 bảng vai trò, §4.2, §5, §7; E1/E4/E10/E11.

Guest JSON nhận 401, student/wrong role/unverified/inactive thường 403; foreign object hoặc unassigned teacher đúng prefix mới non-disclosing 404. Sai tenant của user có thể bị middleware 403. “Khách/học viên/chéo tenant đều 404” không đúng framework semantics và tự mâu thuẫn mục lỗi §5. AI_RUN_ALREADY_EXECUTED, AI_PROVIDER_CALL_FAILED, AI_QUOTA_COMMIT_FAILED… là runtime/uncertain settlement, không thể gộp thành “chưa bật/hết quota/chính sách” chỉ nhờ prefix. AI_APPROVAL_REQUIRED cũng không xác định deployment chưa bật vì blocked_at bị loại.

**Đề nghị:** giữ middleware chuẩn, ghi riêng 401/403/419/429 và object 404, không yêu cầu backend đổi mọi denial sang 404. Fetch xử lý response framework/non-JSON/login redirect mà không render HTML response. Bảng translation explicit cho codes đã kiểm: approval/availability neutral, quota/entitlement neutral, policy neutral; runtime/provider/settlement có nhóm thất bại/không xác định riêng, không tự chạy generation mới. Unknown AI_* hoặc domain code có thông báo fallback an toàn. Không lộ raw details, SQL, stage/config, hay chỉ dẫn vượt chính sách.

### P3-UI-R8 — MEDIUM — HIGH verification và fake fixture chưa có acceptance cụ thể

**Vị trí:** §6.2, §7, §8; D5; Regression Audit § HIGH; Admin Standard §§21,25,26.

Hướng test đúng nhưng thiếu traceability cho các nhánh R1–R7, production refusal của seed command, BFCache/late response, request identity và accessibility động. “Theo chuẩn” chưa chỉ rõ shared confirmation/action menu/sidebar matrix. Chưa có fake seed command để xác nhận no-network hay environment guard.

**Đề nghị:** bổ sung acceptance matrix ở §6 báo cáo này trước P3-A. Fixture chỉ synthetic tenant/Media, provider injectable fake **chỉ** local/testing với guard trước mọi write; production/staging từ chối kể cả option force, không persistent allow-list/real credentials/network binding. Đi qua workflow/owner ports và ledger thật khi thử quota, không sửa schema/Run provenance để giả trạng thái. Chỉ seed trạng thái reachable; ca fault/concurrency dùng test fixture, không giả production receipts. Code/lệnh phải có test zero actual network và production denial trước dùng. Review UI high-risk cần baseline/module/full tests, build/formatter/diff và browser acceptance; không chỉ render feature test.

## 4. Trả lời 12 câu hỏi của brief

### Q1 — Vị trí/phạm vi D1/D8: APPROVE WITH CHANGES

Activity working draft là đúng authority/provenance của API (E1/E5). Không bắt buộc thêm tenant/Template-wide queue vào v1; 41 routes không hỗ trợ queue đó. Cần nêu discoverability khi Template có nhiều Activity và deep-link từ Mapping về Activity/proposal. Không tạo tổng pending bằng crawl không giới hạn các Activity. D8 tránh hai bề mặt quyết định là hợp lý, nhưng teacher không có mapping tab; scope D8 cho admin hiện có và xử lý link UUID/role đúng R3/R5. Preserve manual fallback và admin manual controls; không tự tạo global route hoặc thêm menu.

### Q2 — Hợp đồng và sáu bất biến: REJECT đối với mô tả §4.5–4.6 hiện tại

Accept≠publish, confidence≠weight, Media authorization và owner boundary đúng. Nhưng competency mapping, reject_target trước apply, nghĩa awaiting_publication/applied và stale→context confirmation sai E6/E7 (R1/R2). Audit-before-disclosure có thật: appendAuthoring không được catch để tiếp tục show; Controller Throwable trả sanitized 503/data=null. UI phải báo tạm không xem được, không render detail cũ (R4). Successor chỉ decision projection dưới sáu điều kiện, không copy confidence/rationale/citations, cần nguồn mới và accept mới. Retry đúng actor/quyền operation, không đổi approved_by. Sửa flow để phù hợp Frozen contract; không sửa backend lifecycle cho giống bảng sai.

### Q3 — Vai trò/phân quyền: APPROVE WITH CHANGES

Ma trận AI teacher active assignment primary/assistant/reviewer và active tenant admin đúng CourseAuthoringContextService. Admin-only node/inherit/rebase và retry create_node đúng. Tuy nhiên host page authority rộng hơn, mapping selection admin-only và denial statuses khác (R3/R7). UI phải hỏi/nhận authority hiện hành của AI để render section; route prefix/registration flag chỉ quyết định URL nào tồn tại. allowed_actions chỉ dùng UX, service kiểm lại POST/replay/handoff. Một action stale/forbidden không được UI “sửa” bằng đổi actor/target hoặc chạy admin URL.

### Q4 — allowed_actions D3: APPROVE WITH CHANGES

Khoảng trống đã kiểm ở cả list/detail; mở rộng phía backend tốt hơn nhân state machine/quyền trong JS. Phải định nghĩa vocabulary và eligibility theo actor, source/content visibility, kind, target/context, receipt operation/status; application retry/cancel cần chỉ rõ receipt nào, không một nút proposal-global mơ hồ. Không serialize receipt snapshots hay thêm quyền. Luôn tính mới, không lưu danh sách action để làm capability token; POST giữ checks/locks, UI chịu 409/403/404 dù action từng được gợi ý.

Contract Frozen cho allowed_actions là advisory, chưa đóng enum chỉ ba tên. Thêm tên có thể additive/backward compatible về transport, nhưng là thay đổi DTO observable: **cần amendment/addendum được Owner duyệt cho vocabulary/semantics, regression old/new consumer rồi code**. Không tự chỉnh Frozen text hoặc chỉ nói “sửa code nhỏ”; không cần ADR/schema mới nếu domain/quyền không đổi. D3 không tự giải quyết candidate data và lifecycle R1–R3.

### Q5 — An toàn UI: APPROVE WITH CHANGES

Plain text/escape, CSRF/session, no-store, không nội dung trong URL/log đúng hướng (E4). Bao gồm AI, Media, human rationale, criteria, Node labels và error text; dùng Blade escaping/textContent, không nội suy vào HTML/JS attribute không an toàn. Thiếu browser memory/draft/late-response/history/session protocol R4. Nhiều tab không thể được button-disable giải quyết: version guards/server authority vẫn quyết định; tab nhận xung đột phải re-read, không tự overwrite. Bản nháp không có autosave endpoint được duyệt; chọn memory-only và UX mất draft minh bạch, không thêm storage bền để “tiện” khi hết phiên.

### Q6 — Idempotency/xung đột/bulk: APPROVE WITH CHANGES

UUID trong browser đúng nếu giữ nguyên canonical command cho retry; cần R6. Guards: Generate chỉ request_id; Edit/Decision/Node approval L+R; target/context L+hash; Apply/Retry/Cancel L (Cancel thêm reason_code); successor dùng reason/payload/current anchor selection; inherit/rebase dùng sealed preview hashes/plan, không invented L/R. JSON IDs/versions là integer, hash lowercase hex; form không gửi numeric string bừa. Bulk outer request_id chỉ correlation, item UUID quyết định replay; envelope invalid 422, item outcomes trong outer 200 phải đọc từng http_status/error, không coi HTTP200 là all-success. Người review phải có detail hợp lệ của revision đã chọn, không accept mù từ metadata-only rows.

### Q7 — Provider chưa bật D5/D6: APPROVE WITH CHANGES

Default config providers=[] và binding Unavailable thật; generate không có provider/model trả AI_APPROVAL_REQUIRED trước Media/ledger. Đây là **default source**, không xác minh mọi deployment “luôn rỗng”; dữ liệu lịch sử/fake/successor có thể tồn tại mà provider hiện tắt. Có thể hiện nút tạo và kết quả chặn trung tính, không tiết lộ configuration/stage hoặc gợi ý bypass. Prefix AI_ chỉ là fallback family, không đủ phân loại R7. Fake fixtures phải guard/no-network/bounded/synthetic như R8; hiện mới là proposal thiết kế, chưa chứng minh command an toàn vì command chưa tồn tại.

### Q8 — P3-A/B/C D2: REJECT đối với lời hứa P3-A độc lập backend hiện tại

Chia phase và review từng phase hợp lý. Nhưng P3-A node editor đầy đủ cần candidate read model; D8 backlinks chưa có UUID, teacher basis-selection link sai quyền. Chốt phạm vi hẹp hoặc duyệt dependency trước P3-A (R3/R5), không đẩy vào cuối implementation. P3-B cần cả D3 advisory DTO và state/owner prerequisites R1/R2; P3-C cần preview complete/dispositions cho mọi Intent, exact hashes, published target, xử lý map/remove_explicit/cancel_rebase atomically. Admin new-Node flow còn phụ thuộc Framework publish/select/rebase; nếu cần P3-C với Template đã có Intents, P3-B phải ghi rõ handoff/deferred scenario, không hứa end-to-end tất cả propose_new trước P3-C.

### Q9 — Chuẩn UI/i18n/truy cập: APPROVE WITH CHANGES

Reuse LF Admin/Teacher primitives, VI/EN lf.LF_* keys, progressive disclosure, keyboard/aria-live/zoom đúng. Shared show view và Alpine đã tự xác nhận; không copy các chuỗi Việt cứng của Mapping partial. Bổ sung R8: canonical LFConfirm cho thao tác cần confirmation, action menu chuẩn khi list có row actions, sidebar expanded/collapsed, mobile/long VI/EN, loaded count rõ thay global total không tồn tại, empty vs no-filter-result. Loading/error/disabled/bulk results phải có accessible name, aria-busy/status/alert, focus summary/field/trigger hợp lý; lựa chọn bulk có label và feedback số dòng. Preview/hash-only state không được thông báo là success/published. Mockup/browser còn là gate delivery, không PASS từ tài liệu.

### Q10 — Verification/audit: APPROVE WITH CHANGES

**HIGH bắt buộc**, không chỉ đề xuất: sensitive disclosure, authority, idempotency/concurrency, cross-domain writes/immutable published identity. Không đòi tests của UI chưa viết ở review thiết kế. Nhưng trước code cần acceptance/impact graph và baseline plan R8; khi implementation phải chạy targeted + module/shared + full suite khi môi trường cho phép, Pint --test, npm run build, diff check và browser QA. Route middleware, test real ledger/fake adapter, source/audit failures, human-only zero provider, tampering/replay và regression manual paths phải có traceability. Closure Phần 2 đã PASS WITH DOCUMENTED RISKS không thay baseline/verdict UI tương lai.

### Q11 — Blade + JS D4: APPROVE

Không có lý do kỹ thuật cần đổi sang Livewire/framework mới. Existing views Blade, resources/js/app.js dùng Alpine, JSON/session APIs hợp luồng hiện có. Scoped JS module/state management và shared components đủ cho workflow; không dùng lý do stack để hạ guards/audit. Không claim technology lựa chọn tự chứng minh accessibility/security.

### Q12 — Đã kiểm/chưa kiểm: APPROVE với giới hạn tại §7

Đã kiểm source/middleware/DTO/lifecycle và 17 probe transport/validation/routes trong bản sao. Chưa có UI/mockup, seed command, allowed_actions expansion, candidate DTO hoặc browser implementation để xác nhận. Không chứng nhận hình dáng/responsiveness/performance/source revocation trong browser, real provider quality/activation, live Qdrant, data migration hoặc dev deployment. Việc nhận diện các giới hạn không miễn các thay đổi R1–R8.

## 5. Quyết định D1–D8

| Quyết định | Đồng ý / đề nghị đổi | Verdict | Điều kiện / diễn giải |
| --- | --- | --- | --- |
| D1 — Activity, không menu | **Đồng ý** | APPROVE WITH CHANGES | Activity-first; AI authority riêng với host, discoverability/deep-link scope rõ; không thêm global queue giả |
| D2 — P3-A/B/C | **Đề nghị đổi ranh giới/phụ thuộc** | APPROVE WITH CHANGES | Chốt narrowed P3-A hoặc duyệt candidate DTO trước; P3-B khai báo Framework publish/select/rebase handoff/P3-C dependency |
| D3 — expand allowed_actions trước B | **Đồng ý, thêm amendment và receipt scope** | APPROVE WITH CHANGES | Advisory vocabulary/semantics approved, current actor checks, per-application actions; không capability token |
| D4 — Blade + JS | **Đồng ý** | APPROVE | Reuse Alpine/shared LF components; không framework mới |
| D5 — fake provider local/test | **Đồng ý, bổ sung guard bắt buộc** | APPROVE WITH CHANGES | Synthetic fixtures, production refusal trước write, zero network/secret, không persistent activation hoặc bypass owner/quota |
| D6 — vẫn hiện Create khi chưa bật | **Đồng ý, đổi cách map lỗi** | APPROVE WITH CHANGES | Explicit code groups + safe fallback; không suy deployment state từ AI_ hoặc AI_APPROVAL_REQUIRED |
| D7 — accepted textual kinds không update Course | **Đề nghị bổ sung competency** | APPROVE WITH CHANGES | Cả bốn loại summary/concept/learning_objective/competency dừng tại reviewed content; chỉ node_mapping có target/application |
| D8 — Mapping read-only + backlink | **Đồng ý trong scope review AI** | APPROVE WITH CHANGES | Không duyệt ở Mapping tab, không xóa manual controls; admin hiện có; backlink có authorized UUID lookup hoặc Activity fallback |

Owner đã duyệt hướng D1–D8; báo cáo này đề nghị Owner duyệt lại **phần điều chỉnh** D2/D7 và các chi tiết contract/authority mới nếu chọn mở read DTO. Không tự mở backend vì Owner duyệt hướng UI.

## 6. Điều kiện trước P3-A và acceptance cho implementation

Trước bắt đầu code cần một bản thiết kế sửa đã được đối chiếu và reviewer xác nhận:

1. Sửa lifecycle R1/R2 và D7; không thay Frozen backend để hợp thức hóa bảng sai. Bảng kind/status/operation phải phân biệt review, Node receipt, Intent receipt, Framework publication và Course publication.
2. Chốt lựa chọn R3 cho P3-A editor, nguồn dữ liệu của mọi control, authority AI tại host, admin-only Framework selection/handoff. Nếu cần mở DTO/contract thì duyệt amendment trước code tương ứng; không dùng nhãn “UI only” để bỏ qua gate.
3. Chốt field/route/backlink matrix R5; không invent timestamps/version/old context/history hoặc raw SQL reads để lấp khoảng trống.
4. Chốt security/draft/session/BFCache/late-response protocol R4 và command/bulk protocol R6; bảng lỗi đúng R7 và acceptance R8.
5. Owner chấp thuận thay đổi D2/D7 và phạm vi chọn thêm backend/read-model; reviewer kiểm lại snapshot sửa và xác nhận các điều kiện trước P3-A đã đóng. **Hiện chưa có xác nhận đó.**

Acceptance tối thiểu cần trace vào test/browser case:

| Nhóm | Ca cần kiểm khi triển khai |
| --- | --- |
| Authority/host | Admin, teacher cả ba assignment; created_by nhưng không assignment; role assignment khác; inactive/revoked giữa render/GET/write/replay; foreign tenant/parent/receipt; admin-only URLs/nút vắng teacher; guest 401, middleware 403, object 404 |
| Disclosure/XSS | HTML/script/event attrs/closing script trong AI/Media/criteria/human text không execute; audit fail 503 data=null; source deny/stale/erase không payload/rationale/citations; response cũ, tab switch, history restore, cache/storage/log/URL và session expiry |
| Review/transport | Năm loại có schema/field bounds; confidence và nullable weight riêng; unknown fields; integer guards/payload_schema_version; no detail→bulk blind accept; 422 client field vs generic server error; non-JSON framework failures |
| Replay/concurrency/bulk | Same UUID/body retry sau response lost; changed body conflict; new action UUID; double click/two tabs stale guard; mixed ordered outcomes; duplicate item IDs; max100; cursor/filter resets; no global select; 202 manual refresh/no worker claim |
| Owner lifecycle (B/C) | competency không apply; node reuse/new, both operations, awaiting_admin computed; applied≠canonical Mapping; pending stale không reconfirm; six inheritance conditions/confidence NULL/fresh refs; rejected target chỉ applied; retry/cancel permissions/zero provider; full rebase plan hash/map/remove/cancel rollback |
| Fixtures | Production/staging refusal kể cả force, local synthetic tenant/sources, no real HTTP/provider credentials, no durable allow-list changes; real Commercial ledger oracle; unreachable trạng thái dùng fault test, không giả production audit |
| UX/a11y/regression | VI/EN keys và long text; sidebar expanded/collapsed/mobile/200% zoom; focus/dialog/keyboard/aria-live/aria-busy/per-row results; empty/no-result/loading/error; bounded history/list; preserve manual Mapping, Activity structure/media and existing Version publish flows |

P3-A không phải gate cho provider activation, model quality, Qdrant live hay published-Mapping correction UI. Manual fallback tồn tại trong owner surfaces và phải giữ discoverability đúng quyền; không thêm domain/authority thay thế.

## 7. Lệnh, giới hạn và cleanup

| Lệnh / phương pháp đã chạy | Kết quả và phạm vi |
| --- | --- |
| Python SHA-256 đầu/cuối 7 file | **7/7 khớp**; không phát hiện snapshot drift |
| `rg` và đọc trực tiếp docs/routes/controller/service/request/middleware/views/tests liên quan | Evidence E1–E11; không dùng grep result thay nội dung đã đối chiếu |
| `php artisan route:list --path=ai-authoring --json` trong bản sao testing | **exit 0, 41 routes**, 23 admin/18 teacher; full manifest lưu evidence |
| `php probe.php` (reflection response; strict Input; payload validator; route URLs) | **exit 0, 17/17 checks**. Không gọi endpoint/service có DB/provider side effects |
| Kiểm tên route/Livewire/source assertions | AI name đúng dưới template prefix; activity nested prefix không có AI route; không thấy Livewire markers trong views |

Probe đầu tiên exit 1 do harness đòi Cache-Control **bằng đúng** `no-store`; Symfony thực tế thêm `private`, vẫn có directive no-store. Sửa **chỉ script trong /tmp** sang kiểm directive; 17/17 PASS. Evidence giữ lượt đầu và lượt đúng, không gọi đó là failure sản phẩm hoặc lặng lẽ bỏ qua. Response checks không phải test HTTP middleware end-to-end, không chứng minh audit transaction hoặc real-ledger behavior lần mới.

Chưa chạy full PHPUnit/MariaDB suites, frontend build/Pint/browser QA, docs:lint hoặc schema drift ở lượt này: không có code/schema/UI mới, chỉ review thiết kế. Tests HTTP MariaDB hiện hữu được **đọc**, không gắn PASS mới; kết quả closure trước giữ là lịch sử. Không có mockup/screenshot, measured large-list performance, draft recovery implementation hoặc production-safe seeder. Current max100/page không tự chứng minh performance: listing vẫn có per-proposal freshness/source checks; cần đo và giới hạn request fan-out thực tế khi delivery.

Không dựng MariaDB hoặc server mạng. Sau export evidence đã xóa toàn root `/tmp/lf-p3-ui-review-09368uu2`, gồm app/vendor/cache/script; kiểm root không còn. Hash nguồn kiểm lại sau cleanup và sau ghi báo cáo vẫn 7/7 khớp. Chỉ file báo cáo được thêm trong workspace; không sửa canonical/code/test/migration.

Evidence ngoài repository: [gói review UI](/Users/amin/.codex/visualizations/2026/09/28/01a0e662-4d45-7dc0-a759-42fc721f2633/lf-ai-authoring-ui-design-review-20260929-evidence.tar.gz), SHA-256 `04de5a9bd7a0facd7b799bf533b9a3c1d2c23b35ce76c6b6817436b49e76b48b`. Gồm snapshot source đã đọc, manifests, route JSON, probe/script và cleanup record; không vendor/datadir/.env/secret hoặc symlink.

**Kết luận: APPROVE WITH CHANGES cho hướng thiết kế; v0.2 chưa đạt gate bắt đầu P3-A. Cần sửa và xác nhận các điều kiện §6 trước implementation.**

## Owner

Architecture Team

## Primary Consumers

* Architecture Owner
* UI/backend implementer
* Independent reviewer

---

## 8. Lượt 2 — v0.3, bàn giao lại snapshot đúng (2026-09-29)

### 8.1. Verdict hiện hành và độc lập

**Design Verdict: APPROVE WITH CHANGES. P3-A Implementation Gate: CHƯA PASS.**

Sáu finding đã đóng ở mức thiết kế; R5 và R7 còn hai phần MEDIUM cần làm rõ. Không còn HIGH mở, không có finding ID mới. Ngoài sửa hai phần này, các bổ sung D1, D3, D5, D6, D8 còn chờ Owner xác nhận theo §10 v0.3 và lời bàn giao mới. Không dùng báo cáo này để bắt đầu P3-A ngay. Header và §§1–7 phía trên là kết quả lịch sử lượt 1; mục này là kết luận mới cho v0.3, không sửa lại lịch sử đó.

Reviewer vẫn độc lập: chưa viết/sửa code hoặc tài liệu canonical AI Authoring/Bước 7 hay thiết kế UI. Chỉ thêm mục này vào báo cáo. Đã tự đọc source liên quan, không dựa vào bảng tự đối chiếu §11 hoặc kết luận tác giả. Không kết nối database, khởi động MariaDB, gọi provider, thêm secret hay sửa backend/test/migration/contract/design.

Lượt bị dừng trước đó không có verdict: hash thiết kế `64d5abf6…` không khớp. Lượt này dùng bản đúng `f9185baa…` được người giao việc xác nhận. Brief cuối tài liệu còn ghi Owner chưa duyệt D2/D7/D9; lời bàn giao mới và §10 bản đúng xác nhận đã duyệt ngày 2026-09-29, nên dùng thông tin bàn giao mới cho trạng thái phê duyệt. Đây là bằng chứng do Owner/người giao việc cung cấp, không phải reviewer tự kiểm chữ ký hay hồ sơ phê duyệt bên ngoài repo.

### 8.2. Snapshot đầu/cuối

Kiểm đầu lượt và ngay trước append: **8/8 khớp**. Bảy tài liệu/source bảo vệ giữ nguyên hash sau append; riêng báo cáo thay đổi có chủ đích do thêm mục này. Phần byte của báo cáo lượt 1 giữ nguyên, SHA-256 vẫn `7ebd1026…`; không coi hash toàn báo cáo sau append là snapshot drift.

| File | SHA-256 đầu = cuối của đầu vào |
| --- | --- |
| `docs/platform/LF-AI-Authoring-Review-UI-Design.md` | `f9185baa2a5deeb52f4cfb402f3b70a6728cdd8c49d470917fe3e1e75d938434` |
| `docs/quality/LF-AI-Authoring-Review-UI-Design-Review.md` — phần lượt 1 | `7ebd102660fc653ba824bec7b632d458adbd3a5e1e3ee1cd3c84a30482539fa2` |
| `docs/quality/LF-AI-Authoring-Review-UI-Design-Reviewer-Brief.md` | `d1053be4647e7d89bf9dd6888de77b012c5f504dd85f18dca4bc65c62684e440` |
| `docs/platform/LF-AI-Authoring-Proposal-Contract.md` | `fd23a83289e499015f46b66f1afff152130423742aa1f773f3be690bb59cd18f` |
| `routes/modules/ai-authoring.php` | `61ef30963f09a99305ccf01edb6c7eb06f1d544cf9f1459a2d274c539c4b2d86` |
| `app/Http/Controllers/AiAuthoringController.php` | `4d2f380d4a807916e396ce6bd84e1a412a47bc9e694b67650024ed5e7f193f82` |
| `app/Services/AiAuthoringProposalService.php` | `8e5ede418a0b557368e03e858f6bf82970a8f815d2068504c00cfcd445b83bd5` |
| `app/Services/AiAuthoringHttpReadService.php` | `70b347bfa0701832907f6d53d59803b2871d21081dc337bfa8453e766e8ca01c` |

### 8.3. R1–R8: trạng thái sau kiểm lại

Đóng ở đây nghĩa là yêu cầu đã được mô tả đúng trong thiết kế, không phải UI chưa viết đã vượt test/browser QA.

| Finding | Trạng thái | Verdict đối với remediation | Đối chiếu độc lập |
| --- | --- | --- | --- |
| R1 — HIGH, lifecycle/kind | **ĐÓNG** | APPROVE | §5 tách bốn loại chữ, Node receipt, Intent receipt và publication; validator chỉ cho mapping ở node_mapping; target/rejectTarget kiểm loại và receipt applied. D7 đã bổ sung competency |
| R2 — HIGH, stale/context/target | **ĐÓNG** | APPROVE | §6 khớp `AuthoringProposalRecords::applyStaleness`, `reconfirmContext` và `AiAuthoringSuccessorService::inheritedProjection`: pending drift không reconfirm; accepted source drift khác context-only; projection bỏ rationale/confidence/citations, fresh anchors và accept lại |
| R3 — HIGH, dependency/authority | **ĐÓNG** | APPROVE | §§2.1,7.2,8.1 cấm dùng quyền host thay quyền AI, khoá Framework theo Template, teacher không có đường chọn; hoãn duyệt reuse_existing/bộ chọn Node tới DTO P3-B. Controller host đã có Template/selected IDs; không cần API candidate mới cho P3-A hẹp |
| R4 — HIGH, disclosure/browser memory | **ĐÓNG** | APPROVE | §§4.1–4.3 quy định GET/audit trước render, gỡ DOM/memory trên denial hoặc lỗi đọc, bỏ response cũ theo generation, revalidate BFCache, draft chỉ memory, không persistent storage/HTML/telemetry. Khớp show/audit và Controller 503/data=null; browser implementation vẫn phải chứng minh |
| R5 — MEDIUM, DTO/route/backlink | **ĐÓNG MỘT PHẦN; MỞ MEDIUM** | APPROVE WITH CHANGES | §4.4 sửa field/history/context/citation đúng DTO; §§7.3,8.4 bỏ hứa UUID chưa có và giữ manual controls. Còn chưa phân biệt route HTML của backlink với route JSON AI, xem §8.4 dưới đây |
| R6 — MEDIUM, request/bulk | **ĐÓNG** | APPROVE | §4.5 đóng băng UUID/body/guards, unknown outcome, reread sau conflict, 202 không hứa worker, individual bulk outcomes và giới hạn100; khớp strict Input, Controller bulk/response. Node approval P3-B phải dùng L+R và cặp Framework IDs như `proposals.approve-node`, không tái dùng guards của target |
| R7 — MEDIUM, error/AI_* | **ĐÓNG MỘT PHẦN; MỞ MEDIUM** | APPROVE WITH CHANGES | §2.1 sửa HTTP denial; §4.6 đủ10 codes, safe fallback và runtime group đúng hướng. Dòng quota vẫn suy sai nguyên nhân/kết quả, xem §8.5 |
| R8 — MEDIUM, acceptance/fake fixtures | **ĐÓNG** | APPROVE | §9 nhận toàn acceptance matrix §6 lượt1, HIGH audit, module/full/build/browser, production/staging refusal trước write kể cả force, synthetic sources, real ledger và zero-network. Chưa có seeder/test/UI nên đây là yêu cầu delivery, không PASS implementation |

Không mở lại findings HIGH chỉ vì controller UI, mockup, DTO P3-B hay seeder chưa được viết: phase dependency và acceptance hiện đã được khai báo. Các điều đó phải được kiểm ở review implementation tương ứng.

### 8.4. Phần còn mở của R5 — MEDIUM — URL HTML của backlink khác URL JSON AI

**Vị trí:** thiết kế §8.4, dòng376–380. Đoạn này quyết định chỉ liên kết về trang Hoạt động, nhưng liền sau đó chỉ định tiền tố `…course-templates.activities.ai-authoring.…` và chỉ hai IDs, đồng thời nói không dùng route có lesson/section. Đó là quy tắc đúng cho **fetch API AI**, không đủ để dựng **href trang Hoạt động**. Nếu dùng nguyên mô tả cho backlink, người dùng đi vào endpoint JSON hoặc route không tồn tại, thay vì màn hình review.

Source tự kiểm:

* `routes/modules/ai-authoring.php:6–52`: prefix AI chỉ có các endpoint JSON qua `AiAuthoringController`; không có HTML Activity/show route.
* `routes/modules/course.php:343–346`: HTML direct Activity là `{role}.course-templates.lessons.activities.show`, cần `templateId`, `lessonId`, `activityId`.
* `routes/modules/course.php:443–446`: HTML Activity trong section là `{role}.course-templates.sections.lessons.activities.show`, thêm `sectionId`.
* `CourseTemplateActivityController::showView/routePrefix`: trả Blade show, route hierarchy và `templateRoutePrefix` riêng. `CourseTemplateController.php:207–209` cùng `activitiesByLesson()` đã cấp dữ liệu Course hierarchy cho trang Template; không cần tra cứu proposal UUID hoặc quét API AI để mở Activity.

**Đề nghị:** ghi rõ hai đường: backlink dùng route HTML Course và hierarchy hiện được Course cấp theo tenant; sau khi mở Activity, JS dùng `templateRoutePrefix + '.activities.ai-authoring.…'` với hai IDs để gọi API. Nếu chưa giải quyết link HTML, hoãn D8 khỏi P3-A một cách rõ ràng và Owner xác nhận phạm vi đó; không dùng URL JSON làm fallback. Thêm acceptance cho cả Activity direct và trong section, source không còn tồn tại và quyền bị thu hồi. Phần DTO khác của R5 đã đóng; đây là điểm route còn sót, không yêu cầu backend Frozen thêm endpoint.

### 8.5. Phần còn mở của R7 — MEDIUM — Hai mã quota không chứng minh “Đã hết hạn mức”

**Vị trí:** thiết kế §4.6, dòng205, gộp `AI_QUOTA_EXCEEDED` và `AI_QUOTA_RESERVATION_EXCEEDED` vào câu “Đã hết hạn mức”.

`AiProviderExecutionGate.php:78–99` trả `AI_QUOTA_EXCEEDED` cả khi **không có entitlement active** lẫn khi reserve không được. DTO bỏ `blocked_at`, nên browser không biết là feature chưa được cấp, quota thiếu hay ledger chưa sẵn sàng; không thể kết luận đã dùng hết quota.

`AiProviderExecutionGate.php:228–258` trả `AI_QUOTA_RESERVATION_EXCEEDED` **sau khi provider đã gọi và commit actual usage**, vì actual lớn hơn reservation. `DatabaseUsageQuotaReserver::commit` ghi `committed_over_limit` theo `actualQuantity > reserved_quantity`, không theo một phép xác nhận mọi quota của tenant bằng0. Vì vậy mã này còn khác với refusal trước provider. `tests/Feature/AiProviderExecutionGateTest.php:617–645` thể hiện actual2 vượt hold1, đã commit và không refund; test được đọc, không chạy lại lượt này.

Giới hạn bằng chứng: adapter Authoring hiện trả `quota_quantity=1.0` (`AuthoringProposalAdapter.php:88`), Generate cũng giữ1.0, nên over-reservation không phải nhánh bình thường reachable của chính adapter này hiện tại. Dù vậy §4.6 chủ động nhận toàn vocab shared gate nên câu dành cho mã đó vẫn phải đúng. Nhánh thiếu entitlement của `AI_QUOTA_EXCEEDED` có thể gặp trong Generate P3-A.

**Đề nghị:** dùng câu trung tính cho `AI_QUOTA_EXCEEDED`, ví dụ “Hiện chưa thể sử dụng AI trong hạn mức hoặc quyền lợi của tổ chức”; không suy config/stage từ code. Chuyển `AI_QUOTA_RESERVATION_EXCEEDED` sang nhóm request failed/usage đã vượt phần giữ chỗ, đọc lại trạng thái, **không tự tạo request mới hoặc hứa refund/chưa gọi provider**. Không cần lộ code/stage/SQL cho người dùng và không cần đổi backend. Test mapping lỗi phải cover thiếu entitlement, reserve refusal và actual-over-reservation riêng.

### 8.6. Ba khẳng định backend và đủ mã AI_*

| Khẳng định | Kết quả tự xác minh |
| --- | --- |
| Competency không có nhánh mapping | **ĐÚNG.** `AuthoringPayloadValidator.php:43–65` chỉ thêm key mapping cho node_mapping; mọi unknown key bị từ chối. `AiAuthoringApplicationService::target` trả `not_a_mapping` khi kind khác node_mapping. Competency vẫn cần basis Framework khi generate; cần basis không có nghĩa có application |
| reject-target chỉ khi Intent applied | **ĐÚNG**, cụ thể receipt `apply_intent` của accepted revision phải tồn tại và status applied (`AiAuthoringApplicationService.php:391–416`). Unapplied trả invalid_proposal/use_cancel_application ở service; HTTP chỉ lộ code422 và details rỗng, nên UI không được đợi chuỗi detail đó để phân nhánh. Applied receipt/Mapping không bị undo; quyết định chặn publication sau đó |
| Chọn Framework và tab Mapping chỉ admin | **ĐÚNG ở code hiện tại.** `CourseTemplateController.php:181–185` chỉ cấp learningMappingState cho customer_admin; `resources/views/course-templates/edit.blade.php:12,172–174` chỉ dựng tab/partial khi có state. `CourseTemplateLearningMappingController::select/store/destroy` gọi admin(), từ chối role khác403 và redirect admin. Teacher có quyền AI không đồng nghĩa được vào tab/chọn Framework |

Đã quét PHP tokens của `app`, `routes`, `config`, `bootstrap`, `database`, lấy string literal dạng `AI_[A-Z0-9_]+`, tách runtime sites trong app khỏi env identifiers/comments. Đối chiếu trực tiếp các nơi gate/recorder/recovery phát mã với §4.6: **10/10 mã có tên trong bảng, không thiếu mã error runtime hiện tại**.

| Mã | Source phát/giữ mã chính | §4.6 |
| --- | --- | --- |
| AI_APPROVAL_REQUIRED | Gate64/68/75; ProposalService108 | Có |
| AI_QUOTA_EXCEEDED | Gate79/99 | Có; còn R7 về thông điệp |
| AI_QUOTA_RESERVATION_EXCEEDED | Gate241/243/249 | Có; còn R7 về thông điệp |
| AI_SAFETY_BLOCKED | Gate109 | Có |
| AI_PROVIDER_CALL_FAILED | Gate216; RequestRecoveryService80 | Có |
| AI_QUOTA_COMMIT_FAILED | Gate251/252 | Có |
| AI_ADAPTER_MISMATCH | Gate193/214/215 | Có |
| AI_RUN_ALREADY_EXECUTED | Gate152/180 | Có |
| AI_RUN_TRANSITION_CONFLICT | AiModelRunRecorder208; ControlledEmbeddingRecovery41 | Có |
| AI_RUN_PROVENANCE_CONFLICT | Gate146; AiModelRunRecorder138 | Có |

Gate là `app/Services/AiProviderExecutionGate.php`; recorder/recovery phụ ở `app/Services/Ai`. Các tên `AI_AUTHORING_PROVIDER`, `AI_QDRANT_HOST`, v.v. là env keys, không phải mã lỗi cần thông điệp. Controller response() mặc định ánh xạ các mã gate sang409, `data=null`, details object rỗng và no-store. Đủ tên không đóng được R7 về ngữ nghĩa.

### 8.7. D2/D9 và verdict các quyết định

**Đồng ý thu hẹp P3-A và để DTO Node sang P3-B.** P3-A vẫn có giá trị sử dụng: đọc/review bốn loại chữ và propose_new với fields tự do; reuse_existing không thể accept/bulk-accept hay chỉnh Node khi thiếu label/candidates. Khả năng reject sau đọc hợp lệ không biến metadata IDs thành quyết định mapping được phê duyệt. Generate yêu cầu node_mapping có thể trả cả hai mode; UI phải nhận và defer reuse_existing, không giả có tham số chỉ sinh propose_new.

Không cần duyệt DTO tên/ứng viên trước P3-A hẹp. Duyệt DTO sớm chỉ cần nếu Owner muốn P3-A đã review/switch reuse_existing có ý nghĩa. P3-B bắt buộc có approved amendment và owner read port cho labels/candidates/teacher eligibility, cùng vocabulary/receipt-scoped allowed_actions; D9 duyệt **hướng và phase**, chưa phải duyệt shape/endpoint/semantics của DTO chưa thiết kế. P3-B cũng phải khai báo draft Framework selection/publication/rebase handoff cho create_node. Không lấy read permission admin của Learning để nới quyền teacher.

“Không cần đổi backend” ở §8.1 được hiểu là giữ service/API/contract AI hiện có, không phải cấm thay web controller adapter: host controller cần gọi Course authority service và cấp selected IDs/AI visibility cho Blade như §2.1. Không thêm direct cross-domain SQL hoặc endpoint mới để lấp dữ liệu.

| Quyết định | Ý kiến / verdict kỹ thuật lượt2 | Phê duyệt/phụ thuộc |
| --- | --- | --- |
| D1 | Đồng ý — APPROVE | Bổ sung AI authority còn Owner xác nhận |
| D2 | Đồng ý ranh giới mới — APPROVE | Owner đã duyệt theo bàn giao mới |
| D3 | Đồng ý — APPROVE về kế hoạch amendment trước B | Bổ sung chờ Owner; amendment cụ thể là gate riêng trước code B |
| D4 | Giữ Blade/JS/Alpine — APPROVE | Không đổi verdict |
| D5 | Đồng ý guards/fake fixtures — APPROVE ở mức thiết kế | Bổ sung chờ Owner; command/test chưa có |
| D6 | APPROVE WITH CHANGES | Còn R7 và Owner xác nhận bảng thông điệp sửa |
| D7 | Đồng ý thêm competency — APPROVE | Owner đã duyệt theo bàn giao mới |
| D8 | APPROVE WITH CHANGES | Còn R5 URL HTML/API; Owner xác nhận phạm vi/làm rõ |
| D9 | Đồng ý defer Node DTO tới B — APPROVE | Owner đã duyệt hướng; không thay approved amendment DTO trước B |

Verdict câu hỏi lượt1 cập nhật theo v0.3: Q1 **APPROVE WITH CHANGES** (backlink R5); Q2 **APPROVE** (lifecycle/contract); Q3 **APPROVE** (authority design); Q4 **APPROVE** (D3 future gate); Q5 **APPROVE** (security protocol, chưa chứng nhận UI); Q6 **APPROVE** (command/bulk); Q7 **APPROVE WITH CHANGES** (R7); Q8 **APPROVE** (P3-A hẹp/D9); Q9 **APPROVE** (chuẩn/acceptance); Q10 **APPROVE** (HIGH plan); Q11 **APPROVE**; Q12 **APPROVE** với giới hạn §8.9. Mọi APPROVE này là review thiết kế, không phải waiver test hay Owner approval.

### 8.8. Điều kiện trước P3-A của §6 lượt1

| Điều kiện | Trạng thái lượt2 |
| --- | --- |
| 1 — Lifecycle R1/R2/D7 | **ĐÓNG** ở thiết kế; không sửa Frozen backend |
| 2 — Scope/dependency R3, authority, Framework handoff | **ĐÓNG** với P3-A hẹp; D2/D9 được Owner duyệt; amendment Node/allowed_actions hoãn đúng gate P3-B |
| 3 — Fields/routes/backlink R5 | **ĐÓNG MỘT PHẦN**: DTO matrix đúng; cần tách HTML backlink/API theo §8.4 hoặc explicit defer D8 khỏi A |
| 4 — Browser/commands/errors/acceptance R4/R6/R7/R8 | **ĐÓNG MỘT PHẦN**: R4/R6/R8 đóng; sửa thông điệp quota R7 |
| 5 — Owner approval + reviewer recheck | D2/D7/D9 đã có bàn giao phê duyệt; reviewer đã kiểm snapshot đúng. **CHƯA ĐÓNG TOÀN BỘ**: hai phần thiết kế trên còn mở và §10 còn D1/D3/D5/D6/D8 chờ xác nhận riêng |

Để mở gate: tác giả sửa/làm rõ R5 và R7, Owner xác nhận các bổ sung còn chờ cùng phạm vi cuối, rồi reviewer đối chiếu snapshot sửa để đóng các phần còn mở. Đây là hai sửa nhỏ ở thiết kế, không yêu cầu tạo migration, mở provider hay làm DTO P3-B trước P3-A. Reviewer không tự sửa canonical hoặc suy phê duyệt từ thời gian chờ.

### 8.9. Lệnh, giới hạn và kiểm cuối

| Phương pháp đã chạy lượt2 | Kết quả/phạm vi |
| --- | --- |
| Python hashlib kiểm8 đầu vào đầu lượt, trước append và cuối lượt | Đầu vào khớp; cuối kiểm7 protected hashes và hash phần byte lượt1, báo cáo chỉ append |
| `nl`, `sed`, `rg` đọc v0.3/brief/contract/report và source liên quan | Tự đối chiếu §§4–9, R1–R8, ba khẳng định; không coi bảng tác giả là bằng chứng |
| PHP `token_get_all` scan string literals, không bootstrap Laravel | exit0;10 runtime AI_* error codes,10/10 covered; không DB/provider/file write |
| Đọc routes Course/AI, controller/view Template/Activity/Mapping, Course authority, payload validator, records, successor, gate/adapter/quota và test overage | Xác nhận HTML/API khác, entitlement/refusal khác settlement; source test được đọc, không ghi PASS mới |

Một tìm kiếm ban đầu dùng tên file quota/routes không tồn tại và glob zsh không match; đã tìm lại bằng `rg --files`/`rg` trong thư mục thật và đọc đúng source. Không dùng lần tìm lỗi đó làm kết quả kiểm chứng sản phẩm.

Không chạy PHPUnit, MariaDB, route:list mới, build/Pint, docs:lint, schema drift hay browser QA: không có implementation UI mới và các kết luận lần này dựa trên source/DTO/error inventory. Không gắn lại PASS của17 probes lượt1 cho v0.3; source backend vẫn giữ hash nhưng đó là evidence lịch sử. Mockup, measured performance, browser erase/BFCache/draft/concurrency, fixture environment guard/no-network và DTO P3-B chưa được chứng minh; acceptance §6/§9 vẫn bắt buộc khi delivery.

Mức audit implementation dự kiến giữ **HIGH/HIGH**. Không có database/server/process cần cleanup; file manifest tạm trong /tmp được xoá sau kiểm cuối. Chỉ báo cáo được append trong workspace; các source/canonical bảo vệ giữ nguyên.

**Kết luận lượt2: APPROVE WITH CHANGES; 6 findings đóng, R5/R7 còn phần MEDIUM; gate bắt đầu P3-A chưa pass.**

---

## 9. Lượt 3 — kiểm phần còn mở của R5/R7 trên v0.4 (2026-09-29)

### 9.1. Verdict hiện hành

**Verdict kỹ thuật thiết kế v0.4: APPROVE. Architecture Review kỹ thuật: PASS cho phạm vi P3-A hẹp đã nêu. Quyền bắt đầu P3-A: CHƯA MỞ — chờ Owner xác nhận riêng các bổ sung D1/D3/D5/D6/D8.**

R5 và R7 đã đóng ở mức thiết kế. R1–R4, R6, R8 giữ trạng thái đóng của lượt2; không phát hiện finding mới trong phạm vi hẹp lượt3. Tổng finding thiết kế còn mở: **0 BLOCKER, 0 HIGH, 0 MEDIUM, 0 LOW**. APPROVE không thay Owner approval, không chứng nhận implementation/UI chưa tồn tại và không mở gate code P3-B/C.

Reviewer vẫn độc lập với code/tài liệu canonical AI Authoring và tác giả thiết kế. Chỉ append mục này, giữ nguyên byte nội dung lượt1–2. Tự đọc §4.6/§8.4 và ba bổ sung với source, không dùng kết luận tác giả làm bằng chứng. Không sửa canonical/code/test/migration, không kết nối learnforge_db/XAMPP hoặc database khác, không gọi provider hay thêm secret.

### 9.2. Snapshot

Đầu lượt và ngay trước append: **8/8 khớp**. Cuối lượt: bảy đầu vào bảo vệ giữ nguyên hash; phần byte báo cáo trước mục9 cũng giữ hash bàn giao. Toàn báo cáo thay hash có chủ đích bởi append được giao, không phải drift đầu vào.

| File | SHA-256 đầu = cuối của đầu vào |
| --- | --- |
| `docs/platform/LF-AI-Authoring-Review-UI-Design.md` | `d5f734990a0da005fe444a78c63d24af8a7bb129e5d53c084fbca098ee938def` |
| `docs/quality/LF-AI-Authoring-Review-UI-Design-Review.md` — byte lượt1–2 | `f63c64722bb9608af0500a7328b64ffd53f25caec75a1cf2a0546d35bfdef6a9` |
| `docs/quality/LF-AI-Authoring-Review-UI-Design-Reviewer-Brief.md` | `878dc03f52bfbf8831aae80832505ae9ca81dfaeb2b644945e11642c0802d4e7` |
| `docs/platform/LF-AI-Authoring-Proposal-Contract.md` | `fd23a83289e499015f46b66f1afff152130423742aa1f773f3be690bb59cd18f` |
| `routes/modules/ai-authoring.php` | `61ef30963f09a99305ccf01edb6c7eb06f1d544cf9f1459a2d274c539c4b2d86` |
| `app/Http/Controllers/AiAuthoringController.php` | `4d2f380d4a807916e396ce6bd84e1a412a47bc9e694b67650024ed5e7f193f82` |
| `app/Services/AiAuthoringProposalService.php` | `8e5ede418a0b557368e03e858f6bf82970a8f815d2068504c00cfcd445b83bd5` |
| `app/Services/AiAuthoringHttpReadService.php` | `70b347bfa0701832907f6d53d59803b2871d21081dc337bfa8453e766e8ca01c` |

### 9.3. R5 — URL HTML/JSON: ĐÓNG, APPROVE

§8.4 v0.4 đã phân biệt đúng:

* Backlink từ Mapping mở route HTML `{role}.course-templates.lessons.activities.show` với Template/Lesson/Activity, hoặc `.sections.lessons.activities.show` khi có Section. `routes/modules/course.php:343–346,443–446` đăng ký chính xác hai route đó qua `showDirect`/`show` của Course controller.
* `CourseTemplateActivityController::showView()` trả Blade `course-template-activities.show`; `routePrefix()` và `activityRouteParameters()` chọn đúng hierarchy. `CourseTemplateController.php:198–209` đã cấp sections/directLessons/lessonsBySection/activitiesByLesson để dựng link theo Course hierarchy thuộc tenant. Không cần AI proposal UUID lookup hoặc quét AI API.
* Fetch JSON sau khi vào Activity dùng `templateRoutePrefix + '.activities.ai-authoring.…'` với hai IDs, khớp `routes/modules/ai-authoring.php:6–9`. Không dùng JSON endpoint làm href hay fallback HTML.

Thiết kế còn quy định nếu chưa dựng backlink HTML thì hoãn D8 khỏi A và Owner xác nhận phạm vi, đồng thời acceptance có direct/section Activity, object mất và quyền bị thu hồi. Như vậy lỗi route còn sót đã được giải quyết, không cần endpoint/DTO mới để mở trang Activity. D8 vẫn chỉ dành cho admin ở Mapping tab hiện có, giữ manual controls và không tạo bề mặt duyệt thứ hai. Việc có triển khai D8 trong A hay hoãn theo điều kiện trên phải được thể hiện trong phạm vi được Owner xác nhận.

### 9.4. R7 — quota messages: ĐÓNG, APPROVE

§4.6 v0.4 đã dùng thông điệp trung tính cho `AI_QUOTA_EXCEEDED`, nêu mã gộp thiếu entitlement active và reserve refusal; không tự kết luận quota đã dùng hết. Source `AiProviderExecutionGate.php:78–99` có đúng hai nhánh này. Controller response() bỏ details và không lộ blocked_at (`AiAuthoringController.php:117–136`), nên frontend không thể phân biệt nguyên nhân bằng thông tin ngoài DTO.

`AI_QUOTA_RESERVATION_EXCEEDED` đã chuyển sang nhóm không hoàn tất/chưa rõ kết quả: đọc lại request status, không tự tạo request mới, không hứa refund hoặc chưa gọi provider. Source Gate228–258 thực hiện commit actual usage sau provider rồi mới phát mã khi actual vượt hold. Thiết kế không còn nhầm over-reservation với refusal trước provider hay quota tenant bằng0. Lời giải thích provider đã gọi chỉ áp dụng cho mã over-reservation, không suy mọi mã khác cùng nhóm đều đã gọi provider.

Yêu cầu test riêng thiếu entitlement, reserve refusal, actual-over-reservation đã có trong v0.4. Adapter Authoring hiện báo usage1.0 và Generate giữ1.0 như đã ghi §8.5; không gán nhánh overage thành đường bình thường của adapter hiện tại. Bộ10 tên lỗi đã kiểm ở lượt2 không đổi trong bảng v0.4; lượt này kiểm sự sửa thông điệp, không chạy lại toàn inventory hoặc tests gate.

### 9.5. Ba bổ sung: đều đúng source, APPROVE

| Bổ sung v0.4 | Chứng cứ tự kiểm | Kết luận |
| --- | --- | --- |
| Duyệt Node dùng L+R và cặp Framework IDs | `AiAuthoringInput.php:56–60`: approve-node dùng `$review` (L+R) cộng framework_id/framework_version_id. `AiAuthoringApplicationService::approveNode` kiểm actor admin, kind/mode propose_new, Framework identity, lock/revision và gọi Learning owner port với draft Version | Đúng. Không dùng expected_target_hash thay guards. Đây là thao tác P3-B; nhận IDs không thay eligibility/draft checks của Learning |
| UI rẽ nhánh theo receipt, không đợi use_cancel_application | `AiAuthoringApplicationService::rejectTarget` kiểm receipt operation apply_intent/status applied; unapplied trả invalid_proposal với service detail. Controller chỉ trả422/details rỗng. `AiAuthoringHttpReadService::detail` có application_uuid/operation/status/approved_by | Đúng. Chỉ dùng receipt phù hợp của lần đọc hiện tại để gợi ý nút; không coi create_node applied là Intent applied, không coi status là capability. Eligibility đầy đủ vẫn thuộc service và amendment allowed_actions P3-B; POST có thể từ chối khi quyền/trạng thái đổi |
| Generate node_mapping có thể trả reuse_existing | Strict Input chỉ nhận requested_kinds và optional Framework pair, không có tham số mapping mode. `AiAuthoringProposalService::generate` nhận kinds/basis; `AuthoringProposalAdapter.php:60–80` kiểm kind rồi gọi validator; `AuthoringPayloadValidator::mapping` chấp nhận cả reuse_existing và propose_new | Đúng. P3-A phải nhận/defer reuse_existing ở cả detail và bulk, không hứa chỉ sinh propose_new hoặc tự chuyển mode để accept thiếu Node labels |

Các bổ sung làm rõ giao thức sẵn có, không đề nghị thay Frozen backend. Web controller adapter được thay để hỏi Course authority/cấp selected IDs như §8.1, không mở read-model Node trước A.

### 9.6. Điều kiện trước P3-A và phê duyệt còn lại

| Điều kiện §6 lượt1 | Kết quả lượt3 |
| --- | --- |
| 1 — Lifecycle R1/R2/D7 | Đã đóng từ lượt2; giữ nguyên |
| 2 — Scope R3/authority/Framework; D2/D9 | Đã đóng với P3-A hẹp; không cần DTO Node trước A |
| 3 — DTO/routes/backlink R5 | **ĐÓNG** ở thiết kế v0.4 |
| 4 — Browser/commands/errors/acceptance | **ĐÓNG** ở thiết kế; R7 đã đóng, các ca kiểm delivery vẫn bắt buộc |
| 5 — Owner approval và reviewer recheck | Recheck kỹ thuật **ĐÃ PASS**; **Owner confirmation CHƯA ĐỦ** cho các bổ sung D1/D3/D5/D6/D8 |

Không còn yêu cầu sửa kỹ thuật thiết kế được phát hiện trong phạm vi review. D6/D8 chuyển verdict kỹ thuật từ APPROVE WITH CHANGES ở lượt2 sang **APPROVE**; D1–D5/D7/D9 giữ ý kiến kỹ thuật ở §8.7. Các câu Q1/Q7 còn điều kiện kỹ thuật ở lượt2 nay **APPROVE**; kết luận các câu khác không đổi trong phạm vi này. Không dùng sự thay đổi verdict để suy Owner đã phê duyệt.

Điều kiện còn lại trước code A là **Owner xác nhận riêng D1/D3/D5/D6/D8 theo v0.4**, bao gồm phạm vi backlink D8 nếu hoãn. Theo §10 và lời bàn giao, hiện vẫn CHỜ. Khi có xác nhận và snapshot/phase scope không đổi, không còn finding kỹ thuật nào từ review này cần remediation để bắt đầu P3-A; thay đổi thiết kế/phạm vi mới cần review tương ứng.

Sự xác nhận hướng D3/D9 không thay approved amendment DTO/vocabulary cụ thể, implementation và regression trước P3-B. Tests/browser QA/HIGH audit, fixtures local/testing guard và zero-network vẫn là nghĩa vụ delivery; không yêu cầu UI chưa viết phải chạy tests để đóng review thiết kế.

### 9.7. Phương pháp, giới hạn và kiểm cuối

Đã chạy Python SHA-256 đầu/trước append/cuối; đọc bằng sed/rg các đoạn thiết kế/brief/source nêu trên; `git diff --check` giới hạn báo cáo. Chỉ đọc source, không bootstrap Laravel hoặc chạy code domain. Không chạy PHPUnit/route:list/build/Pint/docs:lint/schema drift/browser/MariaDB ở lượt hẹp này. Không gán PASS thực thi cho test cases chưa chạy; không tái mở R1–R4/R6/R8 hay closure Phần2. Mức audit implementation dự kiến vẫn HIGH/HIGH.

Đã kiểm bảy hash bảo vệ cuối lượt và hash byte báo cáo lượt1–2, giữ nguyên lịch sử; manifest tạm trong /tmp được xoá sau kiểm. Không có database, provider connection hoặc server cần cleanup. Chỉ báo cáo có append được giao.

**Kết luận lượt3: APPROVE thiết kế v0.4; R1–R8 đã đóng ở mức thiết kế, review kỹ thuật PASS. P3-A vẫn CHỜ Owner xác nhận riêng D1/D3/D5/D6/D8, chưa được bắt đầu code.**
