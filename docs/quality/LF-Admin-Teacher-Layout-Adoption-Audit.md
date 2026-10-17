# Admin/Teacher Layout Adoption Audit

Version: 1.22

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
