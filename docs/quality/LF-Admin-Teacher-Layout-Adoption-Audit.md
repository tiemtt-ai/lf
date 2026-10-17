# Admin/Teacher Layout Adoption Audit

Version: 1.27

Document Status: Review

Implementation Status: Partial

Last Updated: 2026-10-18

Document Path: quality/LF-Admin-Teacher-Layout-Adoption-Audit.md

## Scope and authority

Owner yêu cầu áp dụng thiết kế `docs/ui-demo/admin-layout-developer` cho Admin
và Teacher, cho phép tái cấu trúc, xóa layout cũ không còn dùng và cập nhật
chính sách để phù hợp thiết kế mới. Source demo giữ nguyên.

Classification: Existing-Feature Change. Initial/Final Audit Level: HIGH.
Rationale: shared layout có nhiều consumer. Escalation: None.

Documents Reviewed: docs/README.md, LF-INDEX.md routing, Architecture Guardrails,
LF-Development-Standards.md, LF-Regression-Audit.md, LF-Tech-CSS.md,
LF-Admin-Form-Design-Standard.md, LF-Navigation.md, demo README/HTML/CSS/JS.

Source Of Truth: demo cho visual shell; route, middleware và quyền hiện hành
cho navigation; dữ liệu server cho user/locale. Invariants: không đổi tenant,
role, authentication, CSRF, route contracts, database hoặc nghiệp vụ.

## Implementation and cleanup

* Thay markup trong `layouts/backend.blade.php`, tách sidebar/header thành hai
  partial; tái sử dụng `backend-icon` và `backend-breadcrumbs`, không tạo thư
  viện component khác.
* Thay CSS layout cũ trong `resources/css/admin/admin-layout.css` bằng shell
  của reference. Giữ CSS variables còn được form nghiệp vụ sử dụng. Các rule
  CRUD demo không được đưa vào shared layout.
* Xóa header đối tác/menu ngang nhiều tầng, footer lớn, floating mock actions,
  markup menu cũ, CSS heading/divider không còn consumer, state group thừa.
* `backendSidebar()` giữ preference key cũ. Mobile dùng native dialog, di
  chuyển cùng một sidebar, Escape/backdrop đóng và trả focus; resize khôi phục
  sidebar. Mobile không ghi đè preference desktop.
* Menu locale POST vào route thật; logout POST + CSRF; menu tài khoản giữ
  quyền Admin/Teacher. Không có thông báo giả: bell hiển thị trạng thái chức
  năng thông báo chưa triển khai, không hiển thị số chưa đọc giả.
* Logo demo giống asset hiện hành, không cần thêm bản sao. Asset footer/auth/
  public còn được sử dụng được giữ nguyên.
* Nội dung business views vẫn là dữ liệu/chức năng LF. Không thay chúng bằng
  danh mục, CSV hoặc dữ liệu trong bộ nhớ của demo.

Dependencies and consumers: backend.css → admin-layout.css; app.js → Alpine
backendSidebar; backend layout → all Admin/Teacher views. Public/student không
sử dụng backend layout; controller, middleware, route và migration không đổi.

## Browser evidence

Trình duyệt IAB; tài khoản QA riêng trên SQLite QA tạm (đã dọn), qua login thật, không
sửa dữ liệu tenant ứng dụng. Reference và LF desktop: 1280×800, DPR 1, Arial.
Ảnh chụp, log và JSON kiểm thử tạm đã được xóa theo yêu cầu Owner sau rà soát.
Các kết quả dưới đây là ghi nhận lịch sử của lần kiểm tra trước; không còn
artifact thô để đối chiếu lại. Cần chạy lại kiểm thử nếu cần bằng chứng mới.

| Hạng mục | Kết quả |
| --- | --- |
| Sidebar desktop expanded/collapsed | PASS: 248px / 68px; logo đầy đủ / chữ V ở lần kiểm tra ban đầu; đã thay bằng icon Owner bên dưới |
| Header và typography shell | PASS: 66px; Arial 14px/1.45; màu/nav khớp measured styles |
| Logo + brand region | PASS: pixel diff 0/16,368 pixel trong vùng 248×66 |
| Footer trên Teacher dashboard ngắn | PASS: đáy footer ở 800px khi viewport cao 800px |
| Tablet 768×1024 | PASS: expanded/collapsed, không document overflow |
| Mobile 390×844 | PASS: drawer 280px, không duplicate sidebar, không document overflow |
| Keyboard | PASS: Escape đóng drawer và trả focus; Escape đóng top menu |
| Resize khi drawer mở | PASS: dialog đóng, sidebar trở lại shell |
| Admin VI/EN, Teacher VI/EN | PASS: locale thật, menu đúng role |
| Navigation | PASS: Teacher categories/templates và create form; active route đúng |
| Form hiện hành trên mobile | PASS: create template không overflow, 1 h1, IDs duy nhất |
| JavaScript console | Không thấy error trong các luồng đã kiểm tra |
| Touch trên thiết bị thật, screen reader | Chưa kiểm chứng; CSS coarse-pointer giữ target 44px |

Không tuyên bố pixel parity toàn bộ ứng dụng: nội dung, tên người dùng,
permissions/menu và dữ liệu LF khác demo; business forms/tables chưa được
migrate sang trang CRUD minh họa. Border color của phần tử border-width=0 khác
bởi Tailwind reset nhưng không tạo khác biệt pixel. Nút icon đã khôi phục
padding native 1px 6px của reference.

## Tests and regression

Baseline trước sửa: 28 failed, 23 skipped, 1430 passed; model/provider không
được override so với lệnh mặc định `php artisan test`.

| Nhóm baseline | Số lỗi |
| --- | ---: |
| AiAuthoringHostSectionTest | 11 |
| AudioProcessingLocalReviewTest | 3 |
| DocumentProcessingLocalReviewTest | 4 |
| VideoTranscriptCaptionLocalReviewTest | 3 |
| CourseCohortManagementTest | 3 |
| BackendLayoutNavigationTest (table action contract) | 1 |
| CourseCategoryManagementTest | 1 |
| CourseMediaIntegrationTest | 1 |
| MediaThumbnailComponentTest | 1 |

Các lỗi trên đã tái hiện trước layout change, không tự sửa nghiệp vụ hoặc
bỏ assertion để làm xanh. Lỗi runtime audio/video/document được ghi nhận là
baseline; báo cáo này không kết luận nguyên nhân môi trường hay nghiệp vụ
khi chưa audit riêng từng pipeline.

Test class/markup cũ của sidebar/header/account được cập nhật theo contract
mới; giữ assertions về route, role, CSRF, profile và public/student.
Node tests mới kiểm preference, storage failure, mobile move/focus/restore,
resize/cleanup, backdrop và group toggle.

Commands and Results:

* `php artisan test`: **28 failed, 23 skipped, 1429 passed (11693 assertions)**,
  135.20s. Danh sách 28 failure trùng baseline; không có failure mới. Số test
  pass giảm 1 vì hai test CSS layout cũ được thay bằng một test geometry/drawer.
* Targeted auth/tenant/navigation/profile: **51 passed, 1 baseline failure**
  (table action contract). Shell-only rerun sau cleanup CSS: 22 passed,
  cùng 1 baseline failure.
* `node --test tests/js/*.test.mjs`: **154 passed**, gồm 5 test shell mới.
* `npm run build`: **PASS**.
* Pint cho 5 PHP files thay đổi: **PASS**. Pint toàn repo: **10 file fail**,
  đều không được thay đổi trong task này.
* `php artisan docs:lint`: **58 issue(s)**, không tăng so với trước task;
  94 legacy metadata entries trong allowlist vẫn được báo riêng.
* `git diff --check`: **PASS**.

Không đổi model mặc định, không bỏ test, không sửa lỗi pipeline AI/media ngoài
phạm vi để hợp thức hóa kết quả.

## Remaining risks and verdict

Không có thay đổi business logic, auth/tenant, schema hoặc production data.
Full-suite/Pint/docs debt có sẵn cần xử lý riêng. Không cấp blanket PASS cho
repository khi các command toàn repo còn fail. Layout được triển khai trên
Admin/Teacher; nghiệm thu 100% toàn bộ màn hình/thiết bị chưa được xác nhận.

## Changed files and traceability

* Presentation: `resources/css/admin/admin-layout.css` (1032 → 291 lines),
  `resources/css/admin/admin-components.css` (retired title/divider rules only).
* Markup: `resources/views/layouts/backend.blade.php` (427 → 164 lines),
  `resources/views/components/backend-breadcrumbs.blade.php`,
  `resources/views/components/backend-icon.blade.php`.
* Behavior/i18n: `resources/js/app.js`, `resources/lang/{vi,en}/lf.php`.
* Existing tests updated: `BackendLayoutNavigationTest`, `AdminProfileTest`,
  `RoleProfileTest` — replace retired class assertions, preserve access checks.
* Policies/catalog: LF-Tech-CSS, LF-Admin-Form-Design-Standard, LF-INDEX,
  quality/README and documentation manifest.
* New Files: `resources/views/partials/backend/{header,sidebar}.blade.php`,
  `tests/js/backend-layout.test.mjs`, this audit. Temporary browser evidence was subsequently removed at Owner request.

| Requirement | Verification |
| --- | --- |
| Shared Admin/Teacher shell | BackendLayoutNavigationTest, AdminProfileTest, RoleProfileTest; browser measurements recorded above (raw artifacts removed) |
| Role/tenant boundaries unchanged | AuthFlowTest, TenantAuthenticationSecurityTest, TenantHostResolutionTest |
| Mobile drawer and persisted preference | backend-layout.test.mjs + real browser open/Escape/resize/navigation |
| Reference visual values | Historical browser measurements and pixel comparison recorded above; raw artifacts removed |
| No old unused shell | repo consumer searches; final diff, retired HTML/CSS removed |
| No business/data changes | git diff: no app/, routes/, database/ changes |

Findings By Severity for newly implemented shell: BLOCKER 0 | HIGH 0.
Unverified: true touch hardware, screen readers, all business-module viewports.
Full repository acceptance remains BLOCKED by existing failing gates; this
report does not certify blanket production readiness or 100% whole-app parity.


## Follow-up responsive review — 2026-10-17

Rà soát bổ sung shell Admin/Teacher; không thay business behavior hoặc source demo.

* Thay các SVG nét vẽ cũ bằng đúng nodes từ Lucide đi kèm reference; icon
  đầu ra dùng book-marked, tài khoản dùng user. So sánh DOM xác nhận 8/8
  sidebar icons Admin khớp SVG reference, không thêm dependency runtime.
* Chặn close event đến muộn khôi phục sidebar khi drawer đã được mở lại.
  Regression test mô phỏng close event bất đồng bộ và kiểm focus/location.
* Teacher và reference: 320×740, 768×1024, 1280×800, cùng IAB, DPR 1.
  Header height/padding, sidebar width, content padding, toggle geometry,
  font và màu shell khớp. Chiều cao content khác theo dữ liệu thật.
* Admin: kiểm thêm 681×844 và 680×844 tại breakpoint, cùng 320/768/1280.
  Không document overflow; sidebar expanded 248px, collapsed 68px;
  drawer mobile hiển thị đủ menu, Escape đóng và trả focus về toggle.
  Scrollbar của trang dài làm giảm content width 15px, không phải lệch CSS.
* Mobile 320px: dropdown thông báo nằm trong viewport. Console Admin không
  ghi nhận error trong luồng kiểm tra.

Ảnh, JSON measurements và log follow-up đã được dọn theo yêu cầu Owner.
Kết quả tổng hợp được giữ trong báo cáo này; không còn liên kết tới artifact tạm.

Validation lần này: build PASS; Node 155/155 PASS; Pint ba Blade files vừa
sửa PASS; diff check PASS. PHP BackendLayoutNavigationTest, AdminProfileTest,
RoleProfileTest: 33 PASS, 1 baseline failure tại table action contract
(BackendLayoutNavigationTest:596), không có lỗi mới trong nhóm chạy lại.
Không chạy lại full suite trong lượt sửa icon/drawer này; kết quả 28 baseline
failures ở trên thuộc lần full-suite trước, không phải kết quả chạy mới.

Phạm vi bằng chứng là shell và các màn hình đã liệt kê, không chứng nhận
pixel parity mọi form/table nghiệp vụ. Touch thiết bị thật và screen reader
vẫn chưa kiểm chứng. Không đổi CSS chỉ để tạo diff khi geometry đã khớp mẫu.


## Owner follow-up — collapsed brand and cleanup

Owner yêu cầu thay chữ V khi thu menu bằng icon đính kèm. Asset production
`public/assets/admin/brand-symbol.png` giữ nguyên ảnh Owner, hiển thị rộng 40px
và giữ tỷ lệ. Desktop collapsed và trạng thái khởi tạo dùng cùng icon; expanded
và mobile drawer giữ logo đầy đủ. UI Kit gốc không sửa.

Dọn các script, log và database QA tạm riêng của đợt layout trong `/tmp`;
không xóa artifacts của công việc khác. Báo cáo giữ kết quả lịch sử, không
liên kết tới thư mục bằng chứng đã xóa. Đây là chỉnh presentation trong shared shell,
không thay auth, tenant, route hoặc nghiệp vụ.

Kiểm tra follow-up: browser xác nhận icon 40px đã tải, không còn chữ V;
expanded/mobile drawer giữ logo đầy đủ. Build PASS; local Markdown links trong
5 tài liệu UI đã chỉnh không có đường dẫn thiếu. Không tạo lại artifacts trong repo.

## Owner follow-up — account dropdown

Theo yêu cầu chỉnh UI/UX submenu: tài khoản dùng user, người dùng dùng users,
tổ chức dùng building-2, đăng xuất giữ log-out từ cùng bộ Lucide reference.
Tách phần thông tin người dùng và đăng xuất bằng border nhẹ; tăng gap icon/label,
đồng bộ focus-visible với hover. Giữ route, role filtering, POST logout và CSRF.

Build, Pint các Blade thay đổi và diff check PASS; AdminProfileTest +
RoleProfileTest: 11 passed, 214 assertions. Chưa xác minh browser dropdown mới
vì phiên LF hiện tại đang ở trang đăng nhập; không tuyên bố visual parity.
Không sửa UI Kit gốc hoặc tạo thêm file kiểm thử tạm.

## Account and language browser follow-up

Owner yêu cầu selector cờ + VI/EN theo ảnh: dùng SVG local, nút hiện locale
hiện tại và dropdown hiện locale còn lại; giữ POST/CSRF đổi ngôn ngữ.
Browser QA qua login thật: account dropdown không tràn tại PC 1280×800,
mobile 390×844 và 320×640. Mobile 320px: account bounds x=33..293,
language bounds x=114..226, không overflow bên trong. Đổi VI → EN → VI
thành công; Escape đóng dropdown và trả focus đúng trigger.
Database QA được dọn sau kiểm tra; không lưu log hoặc artifacts kiểm thử trong repo.

## Owner follow-up — topmenu simplification

Thay initials avatar trên topmenu bằng icon user (User hiện chưa có trường ảnh
đại diện); bỏ trigger/dropdown thông báo chưa triển khai. Giữ alignment phải
của cụm topmenu. VI/EN dùng weight 400, nền mặc định trong suốt và nền nhấn nhẹ
khi hover/focus/mở. Bỏ mobile offset riêng của notification để language dropdown
không bị áp nhầm khi trở thành phần tử đầu tiên. Không đổi nghiệp vụ/UI Kit.

Validation: build, Pint Blade và diff check PASS; 12 tests/234 assertions PASS
(AdminProfileTest, RoleProfileTest và backend layout render cho Admin/Teacher).
Không chạy lại browser trong lượt này; không tạo file kiểm thử tạm.

## Owner follow-up — visible account role

Topmenu PC hiển thị icon user, tên thật, badge Quản trị viên/Giáo viên theo
role hiện hành và mũi tên. Mobile ≤680px ẩn tên/badge trong trigger; dropdown
luôn hiện tên, badge vai trò và email. Badge có bản dịch EN; không thay role
hoặc quyền. Tên dài được ellipsis, cụm account được phép co khi header hẹp.
UI Kit giữ nguyên. Build, Pint và 12 tests/234 assertions PASS; diff check PASS.
Chưa kiểm tra browser riêng trong lượt này; không tạo file kiểm thử tạm.

## Owner follow-up — dropdown-only role (2026-10-18)

Theo quyết định mới, bỏ badge role khỏi topmenu; dropdown đặt tên và badge
Admin/Giáo viên cùng hàng, email ở dưới. Admin giữ label Admin ở VI/EN;
Teacher giữ bản dịch Teacher khi EN. Identity row dùng flex-wrap và tên
cho phép ngắt từ dài để tránh tràn trên mobile. Rule ẩn role trong trigger
đã bỏ vì không còn markup tương ứng. Không đổi nghiệp vụ/UI Kit gốc.

Build, Pint các Blade/translations và diff check PASS; 12 tests/234 assertions
PASS. Chưa kiểm tra browser riêng trong lượt này; không tạo file kiểm thử tạm.

## Topmenu visual verification — 2026-10-18

Owner yêu cầu hiện chuông trở lại: dùng icon có accessible name, không handler,
không popup, không tab stop; hover dùng chung rule admin-top-button.

Browser QA Admin/Teacher qua login thật: 1280×800, 768×1024, 681×844,
390×844, 320×640. Tên/badge nằm trong dropdown, tên topmenu ẩn trên mobile;
tên dài không dấu cách được wrap trong dropdown và ellipsis ở trigger.
Không overflow dropdown; Escape đóng và trả focus. Console không có error.
Sửa max-width top-actions từ trừ 44px thành 56px để giữ đủ lề phải 20px
tại viewport 681px khi tên dài; xác minh right=646 trong header right=666.

Build, Pint và 12 tests/234 assertions PASS; diff check PASS. Database QA
đã dọn, server QA đã dừng; không lưu file kiểm thử tạm trong repo.

Owner follow-up: badge vai trò căn phải đối diện tên trong dropdown desktop;
mobile ≤680px xuống dòng căn trái, tên dài vẫn wrap. Build và diff check PASS.
Chưa kiểm tra browser riêng cho thay đổi căn lề này.

## Badge alignment browser acceptance — 2026-10-18

Browser kiểm tài khoản Admin thực tế ở 1280×800, 768×1024, 390×844,
320×640; dropdown rộng 260px, không overflow. PC badge right khớp identity
right; mobile name chiếm hàng riêng và badge căn trái, email bên dưới.
Kiểm Teacher QA với chuỗi tên dài không dấu cách ở PC/mobile: tên trong
dropdown wrap, badge xuống dòng khi thiếu chỗ; trigger PC dùng ellipsis,
mobile ẩn tên. Không phát hiện lệch/tràn cần sửa trong dropdown.
Escape đóng và trả focus về trigger. Không sửa code hoặc UI Kit trong lượt
nghiệm thu này; không cần chạy lại build/tests vì chỉ quan sát browser.
Database QA đã xóa, server QA đã dừng; không lưu file kiểm thử tạm trong repo.

## Login visual adoption — 2026-10-18

Owner chọn ảnh login mới: nền xám nhạt, logo Master Korean, form centered,
icon user/lock, password toggle, nhớ tài khoản, nút primary và footer copyright.
Bỏ header/nav public, logo đối tác góc trên, tabs đăng ký và social placeholder
trên login; không thêm dòng login Trung tâm/Giáo viên. Login view dùng app layout
và CSS lf-login-* trong auth/backoffice-login.css; auth layout/CSS cũ vẫn có
consumer ở forgot/reset/verify, nên giữ. UI Kit gốc không đổi.

Theo Core Auth, /login là endpoint chung cho mọi role, bao gồm student; không
được đoán role khi guest hoặc tạo endpoint role riêng. Chỉ presentation của
login chung thay đổi; controller, request, route, CSRF, throttle và tenant
resolution giữ nguyên. Forgot/reset-password không thay. Các input vẫn là
email/password, có autocomplete, label ẩn accessible, invalid/error association.

Browser QA 1440×900, 390×844, 320×640: không overflow, footer không che form;
password toggle hoạt động, sai credentials hiện lỗi cạnh input và aria-invalid;
Admin/Teacher đăng nhập đúng vào /admin và /teacher. Console không ghi nhận error.
AuthFlowTest, TenantAuthenticationSecurityTest, TenantHostResolutionTest:
18 passed/127 assertions. Build, Pint, diff check PASS. Không chạy full suite
trong lượt presentation này. Database QA đã xóa, server/tab QA đã đóng; không
lưu scripts/logs/screenshots kiểm thử trong repository. Không tuyên bố pixel
parity tuyệt đối với ảnh tĩnh trên mọi thiết bị.

## Login card/header revision — 2026-10-18

Owner chọn ảnh bố cục mới. Header dùng primary LF #485cc7, logo Master Korean
nguyên màu trên nền trắng, label ADMIN/Quản trị hệ thống bên phải và VI–EN
góc phải trên. Card trắng desktop max-width 480px, padding 28px; labels hiện,
controls full width/44px và radius 9px. Mobile header dùng grid để language
vẫn ở hàng trên cùng; chữ quản trị căn phải hàng sau. Giữ nhớ tài khoản,
email/password contracts, CSRF và password toggle; không đổi auth/tenant.
Login vẫn dùng chung theo Core Auth; nội dung card dùng tên Đăng nhập hệ thống.

Browser 1440×900, 768×1024, 390×844, 320×640 không tràn ngang. VI → EN → VI
qua POST route thật thành công, đổi label/title/placeholder. Form/input co theo
viewport; trang thấp cuộn dọc tự nhiên, footer không phủ controls.
18 tests/127 assertions PASS; build, Pint, diff check PASS. Database QA đã xóa,
server/tab QA đã đóng. Không sửa UI Kit hoặc lưu file kiểm thử tạm trong repo.

## Login navy header and dropdown — 2026-10-18

Owner yêu cầu label bên trái, nền header theo ảnh 2, logo trắng trong suốt
và selector cờ VI/EN như back office. Header #182f4b lấy từ màu phổ biến của
ảnh Owner; logo PNG có alpha được chuyển trắng bằng CSS filter, không sửa
asset/UI Kit. Desktop logo giữa, label trái, locale phải; mobile logo hàng
sau, dropdown locale căn phải. Các rule header/selector cũ được thay thế.

Browser 1440×900 và 320×640 xác nhận màu rgb(24,47,75), logo không nền,
locale dropdown trong viewport, VI → EN → VI hoạt động qua POST thật;
Escape đóng/trả focus. Auth/tenant 18 tests/127 assertions PASS; Pint và
diff check PASS, build PASS. QA database đã dọn, server/tab đã đóng.

Owner follow-up: header login dùng lại primary #485cc7 cùng nút Đăng nhập;
nút ngôn ngữ nền trong suốt, chữ/caret trắng, hover trắng mờ. Dropdown vẫn
nền trắng để dễ đọc. CSS-only, build/diff check PASS; chưa browser rerun.

Owner follow-up: input login PC giảm 44px → 40px; mobile ≤600px và coarse
pointer giữ 44px. Password toggle dùng inset/grid center theo input hiện hành.
CSS-only, build/diff check PASS; chưa browser rerun, không tạo file QA tạm.

Owner follow-up: logo trắng login tăng desktop 180px → 260px, mobile
160px → 220px, bỏ opacity .85 để trắng rõ hơn. Header desktop min-height
136px để giữ khoảng thở. Asset gốc không sửa. Build/diff check PASS; chưa
browser rerun riêng cho kích thước này, không tạo file QA tạm.

Owner correction: giữ chiều cao header desktop ban đầu 112px, logo giới hạn
72px (mobile 60px) và object-fit contain; giữ trắng không opacity, không tăng
chiều cao khối để chứa logo. CSS-only, build/diff check PASS.

## Exact SVG logo adoption — 2026-10-18

Theo Owner: lấy SVG từ DOM .login-brand-logo của trang local
http://localhost:3000/login_business. Asset public/assets/admin/brand-logo.svg
giữ nguyên 3 path vector và viewBox 0 0 3507 1024; root dimensions chuẩn hóa
theo viewBox, bỏ style React không cần. Không script/external references.
Login dùng asset SVG thay PNG, CSS filter hiện trắng; PNG giữ cho các consumer
khác. Không sửa UI Kit hoặc business code.

Browser PC 1440×900 và tablet 768×1024: logo tải đúng SVG, header 112px, input
40px. Mobile 390×844/320×640: logo SVG trắng, không overflow; responsive hiện
hành giữ header hai hàng 169px và input touch 44px. Console không có error.
Build, Pint Blade và diff check PASS. Không chạy lại auth tests vì chỉ đổi asset
URL và intrinsic dimensions. Database QA đã xóa, server/tab QA đã đóng.
Không để lại file kiểm thử tạm trong repo.

Owner correction: logo login dùng đúng box 150×54px đã đo ở trang mẫu,
áp dụng PC/mobile, bỏ override 220px trên mobile. Giữ SVG trắng và header
PC 112px. Build/diff check PASS; chưa browser rerun cho lần thu nhỏ này.

## Logo 150×54 visual acceptance — 2026-10-18

Đối chiếu cùng IAB: reference PC 1440×900 có header 112px, logo box 150×54
tại x645/y29; LF có cùng kích thước/vị trí logo, header 112px, input 40px.
Reference mobile 390px giảm logo còn 100×42; LF giữ 150×54 theo yêu cầu Owner.
Phát hiện LF header mobile còn cao hơn 112px do hai hàng: đã chỉnh grid mobile
112px gồm hàng label/language 48px và hàng logo 54px, padding 5px mỗi cạnh dọc.
Browser LF 390×844 và 320×640 xác nhận header 112px, logo 150×54, input 44px
theo touch policy, không tràn ngang. VI/EN mobile không chồng chữ/logo;
đổi locale thành công. Không thay form/auth behavior hoặc UI Kit.

Build/diff check PASS; không chạy lại auth tests cho thay đổi CSS geometry.
Database QA đã xóa, server/tab QA đóng, không lưu file kiểm thử tạm trong repo.

## Login closeout and retired CSS cleanup — 2026-10-18

Đã rà class consumers trong views/JS/app và xóa 10 rule thuộc 6 nhóm không
còn dùng: auth-login-tabs, auth-login-tab, auth-remember, auth-forgot-row,
auth-social-list, auth-social-button. Giữ auth-login-card, inputs, submit,
forgot-link, auth layout/footer vì forgot/reset/verify/register còn sử dụng.
Không đổi logo 150×54, header 112px, input PC 40px/touch 44px; không đổi
nghiệp vụ, UI Kit, routes/controllers hoặc schema.

Rerun AuthFlowTest + TenantAuthenticationSecurityTest + TenantHostResolutionTest:
18 passed, 127 assertions (3.28s). Build PASS (4.98s), diff check PASS.
Pint login Blade và VI/EN translations PASS. Pint toàn repo FAIL trên cùng
10 file baseline đã ghi nhận trước (migrations, media preview controllers,
TrustedVideoUrlService/tests, Phase4C harness và ReadingOrder probe); không
file nào trong nhóm này thay đổi. Không tuyên bố Pint toàn repo đã PASS.

Chốt phần login trong phạm vi UI đã nghiệm thu; baseline formatting debt
cần xử lý riêng. Không tạo file kiểm thử tạm; ảnh/log cũ đã dọn ở lượt trước.

## Pint baseline debt resolved — 2026-10-18

Theo Owner, Pint chỉ chạy sửa trên đúng 10 file baseline đã liệt kê ở trên.
Pint toàn repo chạy lại: PASS. Đối chiếu AST của cả 10 PHP files với HEAD,
resolve tên lớp và bỏ metadata định dạng/import declarations: giống nhau,
bao gồm giá trị string và array entries. Parser không báo lỗi cú pháp.
Diff check PASS, UI Kit không đổi; không chạy migrations hoặc sửa nghiệp vụ.
Các kết quả Pint FAIL trước đây là lịch sử, không phải trạng thái hiện tại.
Không tạo script, log hoặc file kiểm thử tạm.

## Final Admin/Teacher UI and login verification — 2026-10-18

Chạy mặc định php artisan test, không override model/provider. Lần đầu:
29 failed, 23 skipped, 1428 passed (11672 assertions), 173.01s.
Phát hiện 1 test lỗi thời do chỉnh dropdown: account navigation yêu cầu exact
class="admin-top-dropdown", trong khi class list có thêm admin-account-dropdown.
Đã đổi assertion sang kiểm CSS class token trên đúng #top-account; giữ toàn bộ
assertions route/role/sidebar. Targeted test PASS (24 assertions). Không sửa
production/business behavior để làm xanh test.

Chạy lại toàn bộ PHP sau sửa: **28 failed, 23 skipped, 1429 passed,
11693 assertions, 157.79s**. So sánh hai lần: chỉ mất failure account navigation,
không xuất hiện failure mới. Các nhóm/số lỗi còn lại trùng baseline đã ghi
nhận trước khi triển khai UI. Không có failure mới còn mở từ lượt UI/login.

| Nhóm baseline còn lỗi | Số lỗi | Biểu hiện hiện tại |
| --- | ---: | --- |
| AiAuthoringHostSectionTest | 11 | Assertions section/config markup AI authoring |
| AudioProcessingLocalReviewTest | 3 | Pipeline ready/failed và error code không khớp |
| DocumentProcessingLocalReviewTest | 4 | Office extraction processing_failed |
| VideoTranscriptCaptionLocalReviewTest | 3 | Pipeline/error code và thiếu record |
| CourseCohortManagementTest | 3 | Locked tab, edit URL và attendance copy assertions |
| BackendLayoutNavigationTest | 1 | Table action contract cũ |
| CourseCategoryManagementTest | 1 | Table sequence header assertion |
| CourseMediaIntegrationTest | 1 | Current-file copy assertion |
| MediaThumbnailComponentTest | 1 | Preview CSS variable assertion |

Phân loại baseline dựa trên lịch sử và failure groups; không kết luận mọi
assertion cũ đều lỗi thời hoặc mọi pipeline failure đều là lỗi môi trường
khi chưa audit nghiệp vụ tương ứng. Các lỗi này không được sửa trong nhiệm vụ.

| Gate cuối | Kết quả |
| --- | --- |
| PHP full suite | 28 baseline FAIL, 1429 PASS, 23 SKIP |
| Node tests/js/*.test.mjs | 155/155 PASS |
| Pint toàn repository sau sửa test | PASS |
| npm run build | PASS (4.87s) |
| git diff --check | PASS |
| UI Kit gốc | Không thay đổi |

Kết luận: phần UI/login không có regression mới còn được phát hiện bởi
các gate này; repository chưa được chứng nhận full-suite PASS do 28 baseline
failures. Browser acceptance/logo 150×54/header 112 ghi ở lượt trước, không
chạy lại browser trong lượt kiểm tra tự động này. Logs full-suite/Node tạm
đã xóa sau tổng kết; không tạo thêm file QA/report riêng.


## Baseline failure audit and outdated-test corrections — 2026-10-18

Scope: Owner yêu cầu phân loại 28 baseline failures và chỉ sửa test lỗi thời.
Lượt này chỉnh bốn test files bên dưới và báo cáo này; không chỉnh production
code, nghiệp vụ, cấu hình model, Approved policy hoặc UI Kit. Các diff định
dạng/Pint, login CSS và account-navigation test từ lượt trước vẫn được giữ.

| Nhóm baseline | Số lỗi | Phân loại và xử lý |
| --- | ---: | --- |
| AiAuthoringHostSectionTest | 11 | Test lỗi thời: suite kiểm UI enabled nhưng không bật rollout flag; config contract thiếu generationConfigured. Bật flag trong fixture, kiểm boolean/config whitelist; giữ role/tenant/secret checks. |
| CourseCohortManagementTest | 3 | Test lỗi thời: deferred tabs đã ẩn, list đi qua show trước khi edit. Kiểm metadata inaccessible, fallback overview, show URLs; giữ kiểm forbidden, lifecycle và database. |
| CourseCategoryManagementTest | 1 | Test lỗi thời: exact class string không cho phép thêm class hợp lệ. Kiểm CSS class token, giữ số cột và nội dung. |
| CourseMediaIntegrationTest | 1 | Test lỗi thời: assertDontSeeText quét cả bản dịch lỗi trong JS config. Kiểm duplicate label trong markup current-file row bằng XPath. |
| AudioProcessingLocalReviewTest | 3 | Môi trường đã xác nhận: say trong sandbox sinh WAV im lặng dài 0,005s; ngoài sandbox fixture dài 17,5785s và cả class PASS, giữ model mặc định. Không sửa hoặc skip test. |
| VideoTranscriptCaptionLocalReviewTest | 3 | Môi trường đã xác nhận: cả class ngoài sandbox PASS, bao gồm transcript, corrupt-video error code và tenant read. Không sửa hoặc skip test. |
| DocumentProcessingLocalReviewTest | 4 | BLOCKED xác nhận pipeline Office: LibreOffice nhận SIGABRT (signal 6) trong sandbox. Chẩn đoán trực tiếp ngoài sandbox có doc/pptx chuyển đổi thành công, ppt/xls quá timeout 30s. Suite bình thường ngoài sandbox tiếp tục treo ở source.doc, đã dừng chủ động trước timeout cấu hình 900s. Có bằng chứng runtime không ổn định, chưa đủ để loại trừ lỗi tích hợp; giữ nguyên test. |
| BackendLayoutNavigationTest | 1 | Lỗi UI/canonical thực tế: test tại tests/Feature/BackendLayoutNavigationTest.php:577; ví dụ resources/views/course-categories/index.blade.php:77 thiếu admin-table-has-actions và dùng managed-list. LF-Tech-CSS.md:384 và LF-Admin-Form-Design-Standard.md:886 vẫn yêu cầu shared action contract. Không nới assertion. |
| MediaThumbnailComponentTest | 1 | Lỗi UI/canonical thực tế: test tại tests/Feature/MediaThumbnailComponentTest.php:91 yêu cầu 1280px theo docs/platform/LF-Media.md:616; resources/css/admin/admin-pages.css:503 đang 800px. Giữ test, không đổi CSS/policy trong nhiệm vụ này. |

Tổng: **16 lỗi thời đã sửa; 6 lỗi môi trường đã xác nhận; 2 lỗi UI thực tế;
4 lỗi Office còn BLOCKED xác nhận**. Không coi lỗi baseline là test lỗi thời
chỉ vì implementation đang khác assertion. AI rollout được đối chiếu amendment
Single Entry và test flag-off riêng trong AiAuthoringActivityEntryTest.

### Verification evidence

- Chạy lại AiAuthoringHostSectionTest, AiAuthoringActivityEntryTest,
  CourseCategoryManagementTest, CourseMediaIntegrationTest,
  CourseCohortManagementTest: **147 PASS, 1803 assertions, 15,19s**.
- Audio class ngoài sandbox, cùng provider/model mặc định: toàn bộ 24 cases
  PASS (bao gồm ba cases baseline). Lượt audio/document/video chung bị dừng
  ở LibreOffice nên không có aggregate summary; không tuyên bố cả lượt PASS.
- Video class chạy riêng ngoài sandbox: **6 PASS, 115 assertions, 41,58s**.
- Hai test canonical UI giữ nguyên: **2 FAIL, 11 assertions** như dự kiến.
- Diagnostic media trong sandbox có TMPDIR riêng: 15 FAIL / 74 PASS,
  439 assertions; gồm 10 baseline media và 5 PDF/Docling failures phát sinh
  trong môi trường chẩn đoán. Không dùng lượt này làm full-suite baseline
  hoặc quy những failures đó thành regression của thay đổi test.
- Pint toàn repository: **PASS**. git diff --check: **PASS**.
- docs:lint: **58 issues** ở các tài liệu/index/manifest khác; không có
  diagnostic nào cho báo cáo này. Giữ nguyên ngoài phạm vi sửa test.
- Đây là targeted verification, chưa chạy lại full suite sau bốn test edits;
  không suy diễn repository hiện chỉ còn hai failures. Build trước đó PASS;
  lượt test-only này không thay đổi assets nên không chạy build lại.

Không thêm skip/mock để che lỗi pipeline; không chỉnh model hoặc runtime để
làm xanh assertions. Logs, fixtures và profiles chẩn đoán tạm được dọn sau
khi đọc kết quả; không giữ đường dẫn tới file tạm làm evidence link.


## Canonical UI fixes and Office boundary review — 2026-10-18

Classification: Existing-Feature Change. Initial/Final Audit Level: HIGH
(shared presentation across five module consumers). Audit Level Escalation:
None. Source of truth: LF-Tech-CSS table action contract,
LF-Admin-Form-Design-Standard §25.4, LF-Media shared preview geometry and
LF-Tech-Runtime-Requirements Office runtime/limits. Architecture Guardrails,
Development Standards, Regression Audit, Processing Contract and conflict
register reviewed. No architecture boundary change or conflicting policy
resolution is introduced by this implementation.

### UI implementation and invariants

- Five list consumers (course-categories, course-cohorts, course-enrollments,
  course-products, course-templates) now opt into admin-table-has-actions and
  reuse x-admin-action-menu. Existing management URLs, labels, authorization
  conditions, queries, lifecycle and route destinations are unchanged. No new
  action, backend route, component, business rule or database write is added.
- Shared image/video preview max-width restored from 800px to 1280px; mobile
  viewport gutter remains 24px and max-height accounts for that spacing.
  Existing compact audio presentation remains outside this geometry fix.
- Removed the late generic managed-actions width/alignment override so the
  shared action column rule applies. No UI Kit source changed.

Requirement traceability: the two unchanged canonical tests now PASS
(46 assertions). Related UI/module group: 247 PASS, 3849 assertions, 18.29s.
Pint PASS; frontend build PASS (4.47s); Node 155 PASS.

Browser QA used an isolated page rendering the existing Blade action-menu and
media-preview-modal with the actual built candidate CSS/JS, not copied styles.
At 1440×900, image panel measured 1280px, left/right 80/1360. At 390×844,
panel measured 342px, left/right 24/366, computed gutter 24px; no document
horizontal overflow. The mobile action panel was inside viewport
(left/right 136.52/272.52; top/bottom 124/172) and visible after click.
Desktop menu screenshot showed the panel below the trigger without clipping.
These are shared-component checks, not screenshots of all populated business
pages or real-device touch certification. QA tab/server and viewport override
are removed/reset after inspection.

### Office: runtime versus LF integration

The four native-conversion cases are DOC, PPTX, PPT and XLS; DOCX and XLSX
use native LF readers and do not depend on this LibreOffice conversion path.
The configured soffice resolves to the Homebrew wrapper executing
/Applications/LibreOffice.app/Contents/MacOS/soffice.

Repeated direct conversion outside Laravel and outside the sandbox, each with
an isolated disposable UserInstallation profile, timed out after 25s for all
four formats without a converted output. A second diagnostic using the app
binary directly and temporary SAL_USE_VCLPLUGIN=svp also timed out after 15s
per format, with empty stdout/stderr and no output. Processes were bounded;
no permanent environment setting, binary upgrade or timeout change was made.
Together with prior sandbox SIGABRT evidence this confirms an external runtime
failure independently of the LF orchestration. Short diagnostic timeouts do
not certify that the configured 900s production conversion would always fail;
they establish that normal local conversion is currently not verified.

A separate four-case converter-boundary test was added to the existing
DocumentProcessingLocalReviewTest. It substitutes only the external soffice
conversion output with existing PDF/XLSX fixtures. It checks unchanged source
bytes, profile/output paths, target format and bounded timeout, then exercises
the actual LF upload, usage dispatch, processing, reader, persistence and
authorized-read path, including temporary-directory cleanup. This verifies the
integration when the converter supplies valid output; it does not prove real
Office conversion or replace/skip the six original native-format cases.

Native Office acceptance remains BLOCKED on a working local LibreOffice
runtime. The direct conversion failures establish a runtime problem, but a separate
integration defect was also reproduced: Symfony Process::run throws
ProcessSignaledException before DocumentProcessRunner reaches its nonzero-exit
classification branch. Consequently signal evidence was lost and mapped to
processing_failed instead of the provider's existing provider_unavailable
mapping. DocumentProcessRunner now wraps this exception as
DocumentCommandFailure retaining exit code and signal. No input/output,
provider-selection, retry rule, model, data or business lifecycle was changed.
Other runner consumers (audio/video, frame OCR, Docling, PDF validation) were
reviewed; the exception remains a RuntimeException and existing normalization
paths continue to own public error codes.

Verification: a real child PHP process terminated by SIGTERM now retains its
signal in DocumentCommandFailure; provider classification additionally covers
SIGABRT (6). The converter-boundary cases pass all four formats (60 assertions).
Combined targeted document/PDF/spreadsheet tests: 17 PASS, 107 assertions, 2.98s.
The new POSIX test ran locally; its platform guard only covers systems without
posix_kill, not LibreOffice availability. Original real Office tests remain
unchanged and must pass against a working runtime for native acceptance.


Changed files for this follow-up: resources/css/admin/admin-pages.css; the
five resources/views/{course-categories,course-cohorts,course-enrollments,
course-products,course-templates}/index.blade.php files;
app/Services/DocumentProcessRunner.php;
tests/Feature/DocumentProcessingLocalReviewTest.php;
tests/Feature/LocalDocumentMixedPdfTest.php; this existing audit. No new
repository file, migration, route, component or dependency.


### Final verification and disposition

| Gate | Final result |
| --- | --- |
| PHP full suite after UI fixes, before signal normalization | 10 FAIL / 23 SKIP / 1447 PASS, 11911 assertions, 165.60s |
| PHP full suite after all changes including five new cases | **10 FAIL / 23 SKIP / 1452 PASS**, 11975 assertions, 150.01s |
| Original two UI contract tests | **2 PASS**, unchanged assertions |
| Related UI/module group | **247 PASS**, 3849 assertions |
| Document/PDF/spreadsheet integration group | **17 PASS**, 107 assertions |
| Node | **155 PASS** |
| Pint entire repository after final PHP changes | **PASS** |
| Frontend build | **PASS**, 4.47s |
| git diff --check | **PASS** |
| docs:lint | **58 existing/unrelated issues**, none naming this audit; not expanded into docs cleanup |
| UI Kit original | No diff |

Final suite failures are the same ten media cases as the preceding run:
audio 3, video 3, Office 4. There is no new failure identity. In particular,
all four Office failures now carry provider_unavailable rather than the prior
processing_failed, demonstrating that SIGABRT reaches the existing provider
classification. Ready assertions still fail because native conversion has not
succeeded. The video corrupt-input case remains a sandbox failure as before;
this document does not claim it was fixed by the document signal correction.

Disposition:
- Two canonical UI findings: resolved and verified.
- Office integration: converter handoff and authorized read pass controlled
  boundary tests; lost-signal classification defect resolved with regression
  coverage. These checks do not certify native Office output fidelity.
- Office environment: direct native conversion remains blocked by local
  LibreOffice behavior. A working/qualified runtime must be demonstrated before
  real Office acceptance; do not change business logic to bypass conversion.
- Existing audio/video environment findings remain as previously recorded;
  no additional model change or new outside-sandbox audio/video run here.

Invariants retained: tenant/customer ownership, auth/permissions, existing
queries and action URLs, lifecycle, processing output semantics, model/default
configuration, original UI Kit, no migration. No production database was used
for test fixtures. Native tools only read repository fixtures into disposable
profiles. All new diagnostic logs/JSON/HTML/router/profiles and generated LF
fixtures are deleted after results are summarized. No temporary evidence links
are retained; no QA server or tab remains.

Findings By Severity: MEDIUM — unresolved native Office runtime acceptance;
LOW — unrelated docs-lint debt. Final Verdict: **BLOCKED** for full/native
Office acceptance; requested UI corrections and integration correction are
verified. No full-suite PASS or production readiness claim.
