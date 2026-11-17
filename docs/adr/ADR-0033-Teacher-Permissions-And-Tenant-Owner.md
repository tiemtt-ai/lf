# ADR-0033 — Teacher Permissions And Tenant Owner

Version: 1.4

Status: Approved

Implementation Status: Implemented

Last Updated: 2026-11-17

Proposal Date: 2026-11-17

Approval Date: 2026-11-17

Approved By: LearnForge Business/Architecture Owner

Document Path: adr/ADR-0033-Teacher-Permissions-And-Tenant-Owner.md

Related ADRs:

* [ADR-0007 — SaaS Tenant Foundation](ADR-0007-SaaS-Tenant-Foundation.md): tenant boundary, ba vai trò chính thức, `users.customer_id/role` còn là nguồn tạm
* [ADR-0021 — Media Sharing Within Tenant](ADR-0021-Media-Sharing-Within-Tenant.md): quyền theo quan hệ, không theo người tạo
* [ADR-0002 — LiveClass Foundation](ADR-0002-LiveClass-Foundation.md): điểm danh tay và sửa buổi của giáo viên

---

# Context

Hiện trạng đã kiểm tra trong code ngày 2026-11-17:

* Ba vai trò cố định: `customer_admin`, `teacher`, `student`. Mọi giáo viên có cùng một bộ
  quyền viết cứng trong route và controller; thứ khác nhau giữa các giáo viên chỉ là
  phạm vi do phân công (Template, lớp, buổi).
* Mọi `customer_admin` ngang quyền nhau: tạo, sửa, khoá, đổi vai trò mọi tài khoản của
  tenant, kể cả quản trị khác. Chốt chặn duy nhất: không khoá hay hạ vai trò quản trị
  cuối cùng còn hoạt động. `users` không lưu người tạo.

Owner yêu cầu (2026-11-17): quản trị cấp quyền cho giáo viên, và quy tắc rõ ràng giữa
nhiều quản trị với nhau. LF-Core-User § Future RBAC đã dự kiến hướng này.

---

# Decision

## D1. Ba vai trò giữ nguyên

Không thêm, không đổi tên vai trò (Guardrails § Role). Phân quyền là một lớp bên trong
vai trò `teacher`; middleware `role:` vẫn là cổng đầu tiên. `customer_admin` luôn có
toàn quyền nghiệp vụ; `student` không dính tới phân quyền.

## D2. Chủ trung tâm

Mỗi tenant có đúng một **Chủ trung tâm**: một `customer_admin` đang hoạt động, ghi ở
`saas_customers.owner_user_id`.

| Việc | Quản trị | Chủ trung tâm |
| --- | --- | --- |
| Tạo, sửa, khoá, đặt lại mật khẩu giáo viên và học viên | Có, với mọi tài khoản | Có |
| Sửa hồ sơ và mật khẩu của chính mình | Có | Có |
| Xem danh sách và hồ sơ quản trị | Có | Có |
| Tạo tài khoản quản trị, nâng một người dùng lên `customer_admin`, sửa hồ sơ, khoá, đổi vai trò, đặt lại mật khẩu của quản trị khác | Không | Có |
| Phân quyền cho giáo viên, sửa thông tin Tổ chức | Có | Có |
| Chuyển vai trò Chủ trung tâm cho quản trị khác | Không | Có |

* Quyền không phụ thuộc người tạo tài khoản. Phân quyền theo người tạo sinh tài khoản
  mồ côi khi người tạo rời đi (cùng lý do với ADR-0021 và quyết định về người tạo
  Template ngày 2026-11-10).
* Chủ trung tâm không bị khoá và không bị hạ vai trò, kể cả bởi chính mình. Muốn thôi
  thì chuyển cho một `customer_admin` đang hoạt động của cùng tenant; việc chuyển là
  một thao tác, có xác nhận và ghi `saas_audit_logs`.
* Chủ trung tâm ban đầu là `customer_admin` đang hoạt động được tạo sớm nhất của tenant.
  Tenant tạo mới: quản trị đầu tiên là Chủ trung tâm.
  Luồng đăng ký tenant (`CustomerRegisterController`) ghi Chủ trung tâm trong cùng
  transaction tạo tenant.
* Chốt chặn "quản trị cuối cùng còn hoạt động" giữ nguyên.
* Giới hạn: Chủ trung tâm mất quyền truy cập (quên mật khẩu không khôi phục được, nghỉ
  việc không bàn giao) thì trong ứng dụng không ai đổi được. Đội vận hành LF đổi Chủ
  trung tâm ngoài ứng dụng theo yêu cầu có xác minh của tenant và ghi `saas_audit_logs`.

## D3. Quyền của giáo viên

Danh mục quyền cố định trong code. Mỗi quyền trả lời "giáo viên được làm loại việc gì";
làm ở Template, lớp, buổi nào vẫn theo phân công như hiện nay.

| Khoá | Quyền | Mặc định |
| --- | --- | --- |
| `content.template.create` | Tạo Template khoá học | Bật |
| `content.category.manage` | Quản lý danh mục khoá học | Tắt |
| `assessment.authoring` | Soạn và giao đề: câu hỏi, ngữ liệu, tiêu chí chấm, đề, đăng ký làm bài | Bật |
| `assessment.grading` | Chấm bài | Bật |
| `class.manage` | Tạo lớp và tự vận hành lớp mình là giáo viên chính (D7) | Tắt |
| `class.delete` | Xoá lớp nháp chưa có học viên mà mình là giáo viên chính | Tắt |
| `class.operate` | Điểm danh tay và sửa buổi mình dạy (địa điểm, link họp) | Bật |

* Cột "Mặc định" là đúng những gì giáo viên làm được trước ADR này, nên bật tính năng
  không làm ai mất quyền.
* **Luôn có, không tắt được:** xem và dạy lớp, buổi, Template được phân công (kể cả sửa
  Template được phân công, mở bảng điện tử, bắt đầu và kết thúc buổi, hiện mã QR điểm
  danh); đánh giá năng lực học viên của lớp mình dạy; hồ sơ cá nhân. Tắt `class.operate`
  chỉ mất điểm danh tay và sửa buổi.
* Thư viện Media không là quyền riêng: giáo viên dùng Media khi soạn Template và đề như
  hiện nay. Tắt Media mà vẫn cho soạn thì không soạn được nội dung có tệp.
* **Không bao giờ cấp cho giáo viên:** Người dùng, Tổ chức, Phân quyền, Sản phẩm (chỉ được
  chọn trong danh sách Sản phẩm đang hoạt động khi tạo lớp, D7), Ghi danh, Quy tắc điểm danh.
* Thêm, bớt hay đổi nghĩa một quyền là sửa ADR này.

## D4. Nhóm quyền

* Tenant có các **nhóm quyền** có tên (`saas_teacher_permission_groups`) và đúng một nhóm
  mặc định mang các quyền "Bật" ở D3. Mỗi nhóm giữ một tập quyền
  (`saas_teacher_permission_grants`).
* Mỗi giáo viên thuộc một nhóm (`users.teacher_permission_group_id`); để trống là nhóm
  mặc định. Giáo viên hiện có không cần chuyển dữ liệu. Người dùng đổi sang vai trò khác
  thì cột này được xoá về trống trong cùng thao tác.
* Mọi `customer_admin` tạo, sửa, xoá nhóm và gán giáo viên. Nhóm mặc định và nhóm đang có
  giáo viên, kể cả giáo viên đã khoá, không xoá được; nhóm mặc định sửa được tập quyền.
* Đổi tập quyền của nhóm hoặc đổi nhóm của giáo viên có hiệu lực từ request kế tiếp của
  giáo viên đó. Mỗi lần đổi ghi `saas_audit_logs` với giá trị trước và sau.

## D5. Cái gì cấu hình trên giao diện

* Cấu hình được: tập quyền của từng nhóm và nhóm của từng giáo viên, ở màn "Phân quyền"
  trong nhóm menu "Quản trị".
* Cố định, không cấu hình: toàn bộ D2, và hai danh sách "luôn có", "không bao giờ cấp" ở
  D3. Đây là bất biến an toàn; để cấu hình thì một lần tick nhầm có thể khoá cả tenant
  hoặc mở quản lý người dùng cho giáo viên.

## D7. Giáo viên tự vận hành lớp (Amendment 2026-11-17, Owner duyệt 2026-11-17)

Owner chốt 2026-11-17: đã cho giáo viên tạo lớp thì cho tự vận hành trọn lớp đó; giáo viên lo
phần dạy, quản trị lo phần học viên, người và quy tắc chung. Khoá `class.manage` thay khoá
`class.create` của bản 1.0 (khoá cũ chưa từng được cấp, nên không có dữ liệu phải chuyển).

**Phạm vi:** lớp mà giáo viên là `primary_teacher` đang `active`
(`core_course_cohort_teachers`). Phạm vi theo giáo viên chính, không theo người tạo: người
tạo lớp tự thành giáo viên chính; quản trị đổi giáo viên chính thì người mới quản lý lớp.
Giáo viên khác của lớp (`teacher`, `assistant`) vẫn chỉ dạy như hiện nay.

| Việc trên lớp | Giáo viên chính có `class.manage` | Chỉ quản trị |
| --- | --- | --- |
| Tạo lớp: chọn Sản phẩm đang hoạt động, tên, mã, ngày bắt đầu và kết thúc (bắt buộc, vì giáo viên chính cần đủ hai mốc) | Có | |
| Sửa thông tin lớp | Có | |
| Lịch học, buổi học (thêm, sửa, đổi giờ, huỷ, vắng giáo viên), thứ tự nội dung của lớp | Có | |
| Kích hoạt, kết thúc lớp | Có | |
| Xem học viên, xuất danh sách và điểm danh | Có | |
| Ghi danh, chuyển, rút học viên | | Có |
| Chọn giáo viên dạy từng buổi, trong số giáo viên đã phân công vào lớp | Có | |
| Phân công, đổi, gỡ giáo viên của lớp (kể cả giáo viên chính) | | Có |
| Chọn bộ quy tắc điểm danh (lớp giáo viên tạo dùng bộ mặc định) | | Có |
| Lưu trữ lớp | | Có |

* Xoá lớp là quyền riêng `class.delete`, cùng phạm vi, và vẫn chỉ cho lớp nháp chưa có học
  viên.
* Điểm danh tay vẫn thuộc `class.operate`.
* Giáo viên làm trên cùng các màn Lớp học của quản trị (đường dẫn trong khu giáo viên); các
  nút của cột "Chỉ quản trị" không hiện, và máy chủ từ chối dù gọi thẳng.
* Mất `class.manage` hay thôi là giáo viên chính thì lớp vẫn còn; quản trị vận hành tiếp.
* Không đổi schema: dùng `core_course_cohorts` và `core_course_cohort_teachers` hiện có.

## D6. Thực thi

* Một điểm kiểm tra duy nhất ở máy chủ (`teacherMay(user, key)`), dùng cho route,
  controller và việc ẩn hiện nút. Ẩn nút không thay cho kiểm tra ở máy chủ.
* Thiếu quyền: 403 với request ghi, và mục menu hoặc nút tương ứng không hiện.
* Mỗi quyền có test cho cả hai chiều (có quyền, không quyền) và test cô lập tenant.

---

# Implementation Order

1. Chủ trung tâm (D2, gồm luồng đăng ký tenant) và nền phân quyền (D4–D6) với bốn quyền
   không cần màn hay route mới: `content.template.create`, `assessment.authoring`,
   `assessment.grading`, `class.operate`.
2. `content.category.manage`: mở route danh mục cho giáo viên có quyền.
3. `class.manage` và `class.delete` (D7), chia hai bước:
   * 3a: tạo, sửa, xoá lớp; lịch học, buổi học, thứ tự nội dung; xem học viên. "Lớp của
     tôi" có nút "Tạo lớp" và "Quản lý" trên lớp mình là giáo viên chính.
   * 3b: kích hoạt, kết thúc lớp.
   Mức rủi ro HIGH theo LF-Regression-Audit (phân quyền): mỗi thao tác của màn Lớp học có
   test hai chiều và test giáo viên không phải giáo viên chính.

Owner duyệt tài liệu Database dưới đây ngày 2026-11-17 và cho bắt đầu code đợt 1 cùng ngày.

## Implementation Notes — đợt 1 (2026-11-17)

* Migration `2026_11_17_000400_create_teacher_permissions`: hai bảng nhóm quyền, cột
  `users.teacher_permission_group_id`, cột `saas_customers.owner_user_id`; điền Chủ trung
  tâm và nhóm mặc định "Mặc định" (bốn quyền "Bật") cho mọi tenant đang có.
* Chủ trung tâm: `App\Services\TenantOwner`; `Admin\UserController` chặn tạo, nâng, sửa,
  khoá, đặt lại mật khẩu quản trị khác (403 hoặc lỗi ở ô Vai trò) và thêm thao tác
  `admin.users.transfer-ownership`; `CustomerRegisterController` ghi Chủ trung tâm.
* Điểm kiểm tra: `App\Services\TeacherPermissions::may()`, dùng qua middleware
  `teacher.may:<khoá>[,<khoá>]` (đủ một khoá là qua) và Blade `@teacherMay`.
  `content.template.create`: `course-templates.create/store`. `assessment.authoring`: câu
  hỏi, ngữ liệu, tiêu chí chấm, đề (kể cả Giao chấm), ghi danh. Kết quả: đọc với
  `assessment.authoring` hoặc `assessment.grading`; nhận chấm và chấm với
  `assessment.grading`; chỉ giáo viên có `assessment.grading` được chọn làm người chấm.
  `class.operate`: `teacher.cohorts.attendance.save`, `teacher.live-sessions.update`;
  trang điểm danh của giáo viên chỉ xem khi thiếu quyền.
* Màn "Phân quyền" (`admin.teacher-permissions.*`): nhóm quyền, quyền của nhóm, giáo viên
  của nhóm. Chỉ bốn quyền của đợt 1 hiện trên màn; khoá của đợt sau đã lưu thì được giữ
  khi lưu nhóm.
* Test: `tests/Feature/TeacherPermissionsTest.php`, `tests/Integration/TeacherPermissionMariaDbTest.php`.

## Implementation Notes — đợt 2 (2026-11-17)

* `content.category.manage`: các route quản lý danh mục khoá học (thêm, sửa, bật tắt, xoá)
  mở trong khu giáo viên sau `teacher.may:content.category.manage`; controller kiểm tra
  lại cùng điểm đó. Giáo viên có quyền quản lý mọi danh mục của trung tâm, như quản trị;
  không có quyền thì vẫn chỉ xem danh sách và chọn danh mục trong form Template.
* Quyền này hiện trên màn "Phân quyền"; nhóm mặc định vẫn tắt.

## Implementation Notes — đợt 3a (2026-11-17)

* Khoá `class.create` đổi thành `class.manage` trong danh mục; `class.manage` và
  `class.delete` hiện trên màn "Phân quyền", nhóm mặc định tắt cả hai.
* Các route Lớp học dùng chung tách ra `routes/modules/course-cohort-manage.php`: tạo, xem,
  sửa lớp; xuất học viên và điểm danh; lịch học; thứ tự nội dung; thêm, sửa, đổi giờ, đổi
  link, chọn giáo viên, huỷ, vắng giáo viên của buổi. File được nạp trong khu quản trị và
  trong khu giáo viên (`teacher.course-cohorts.*`) sau `teacher.may:class.manage`; xoá lớp
  (`teacher.course-cohorts.bulk-destroy`) sau `teacher.may:class.delete`.
* Phạm vi: `TeacherPermissions::managesCohort()` (giáo viên chính đang hoạt động), dùng qua
  `AuthorizesCourseCohortAdmin::authorizeCohortManager()` trong mọi controller trên và
  trong `LiveClassScheduleRequest`. Thao tác chỉ quản trị không có route giáo viên.
* Giáo viên tạo lớp được ghi `primary_teacher` cùng transaction, khoảng phụ trách bằng thời
  gian vận hành. Giáo viên xoá lớp nháp của mình: phân công giáo viên chính của chính họ
  không tính là dữ liệu của lớp và bị xoá theo; lịch, buổi, học viên, giáo viên khác vẫn
  chặn.
* Màn của giáo viên ẩn: kích hoạt, kết thúc, lưu trữ; quản lý học viên và link Ghi danh;
  thêm, sửa, gỡ giáo viên của lớp; bộ quy tắc điểm danh (gửi thẳng thì lỗi "managed");
  lưu điểm danh (giáo viên điểm danh ở "Lớp của tôi" với `class.operate`); bắt đầu, kết
  thúc buổi và mở bảng; tab Bản ghi; link Sản phẩm và Phiên bản của quản trị.
* "Lớp của tôi" có "Tạo lớp", "Quản lý lớp" (lớp mình là giáo viên chính) và "Xoá lớp"
  (lớp nháp). Menu "Lớp của tôi" sáng khi ở các trang lớp này.
* Link xem tài liệu của nội dung lớp: `MediaService::generateVersionActivitySignedUrls()`
  nhận cờ người gọi đã kiểm tra phạm vi; chỉ `CourseCohortContentTree` dùng cờ này.
* Test: `CourseCohortManagementTest` (giáo viên chính tạo và vận hành lớp; giáo viên khác
  bị chặn; xoá chỉ lớp nháp của mình). Đã thử trên trình duyệt với `teacher1` (lớp đang
  hoạt động có tài liệu Media).

## Implementation Notes — đợt 3b (2026-11-17)

* `course-cohorts.activate` và `course-cohorts.complete` chuyển vào
  `routes/modules/course-cohort-manage.php`; controller kiểm tra phạm vi giáo viên chính.
  Điều kiện kích hoạt và cảnh báo khi kết thúc giữ nguyên như của quản trị.
* Màn của giáo viên có nút "Kích hoạt lớp" (lớp nháp) và "Kết thúc lớp" (lớp đang hoạt
  động); nút "Lưu trữ" và route lưu trữ vẫn chỉ ở khu quản trị.
* Test: `CourseCohortManagementTest::test_a_primary_teacher_activates_and_completes_their_class_but_never_archives_it`.

# Database Documents

* [saas_teacher_permission_groups](../database/saas/saas_teacher_permission_groups.md) (bảng mới)
* [saas_teacher_permission_grants](../database/saas/saas_teacher_permission_grants.md) (bảng mới)
* [saas_customers](../database/saas/saas_customers.md) § Tenant Owner Amendment (cột `owner_user_id`)
* `users.teacher_permission_group_id`: mô tả trong tài liệu bảng nhóm quyền, vì `users` chưa
  có tài liệu bảng riêng.

---

# Consequences

* ADR-0007 đang Frozen: ADR này không đổi tenant boundary, vai trò hay
  `users.customer_id/role`; chỉ thêm một cột vào `saas_customers` và hai bảng `saas_`.
  Khi `saas_customer_members` được triển khai, Chủ trung tâm và nhóm quyền phải được
  chuyển sang đọc Membership cùng lúc với vai trò.
* Màn quản lý người dùng phải phân biệt quản trị thường với Chủ trung tâm; các thao tác
  trên tài khoản quản trị khác hiện mở cho mọi quản trị sẽ bị thu hẹp.
* Các chỗ đang kiểm tra `role === 'teacher'` cho bảy việc ở D3 phải chuyển sang điểm kiểm
  tra chung.
* D7 mở các màn Lớp học cho giáo viên: mọi controller của module lớp phải kiểm tra phạm vi
  giáo viên chính, không chỉ `role:customer_admin` như hiện nay.

# Not In Scope

Quyền theo từng lớp hay từng Template; quyền cho học viên; vai trò mới; quyền vượt tenant.
