# Admin/Teacher Layout Adoption Audit

Version: 1.3

Document Status: Review

Implementation Status: Partial

Last Updated: 2026-10-17

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

Trình duyệt IAB; tài khoản QA riêng trên SQLite `/tmp`, qua login thật, không
sửa dữ liệu tenant ứng dụng. Reference và LF desktop: 1280×800, DPR 1, Arial.
Ảnh chụp, log và JSON kiểm thử tạm đã được xóa theo yêu cầu Owner sau rà soát.
Các kết quả dưới đây là ghi nhận lịch sử của lần kiểm tra trước; không còn
artifact thô để đối chiếu lại. Cần chạy lại kiểm thử nếu cần bằng chứng mới.

| Hạng mục | Kết quả |
| --- | --- |
| Sidebar desktop expanded/collapsed | PASS: 248px / 68px; logo đầy đủ / chữ V |
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
