# ADR-0033 — Teacher Permissions And Tenant Owner

Version: 1.1

Status: Approved

Implementation Status: Not Implemented

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
| `class.create` | Tạo lớp | Tắt |
| `class.delete` | Xoá lớp (chỉ lớp nháp, chưa có học viên) | Tắt |
| `class.operate` | Điểm danh tay và sửa buổi mình dạy (địa điểm, link họp) | Bật |

* Cột "Mặc định" là đúng những gì giáo viên làm được trước ADR này, nên bật tính năng
  không làm ai mất quyền.
* **Luôn có, không tắt được:** xem và dạy lớp, buổi, Template được phân công (kể cả sửa
  Template được phân công, mở bảng điện tử, bắt đầu và kết thúc buổi, hiện mã QR điểm
  danh); đánh giá năng lực học viên của lớp mình dạy; hồ sơ cá nhân. Tắt `class.operate`
  chỉ mất điểm danh tay và sửa buổi.
* Thư viện Media không là quyền riêng: giáo viên dùng Media khi soạn Template và đề như
  hiện nay. Tắt Media mà vẫn cho soạn thì không soạn được nội dung có tệp.
* **Không bao giờ cấp cho giáo viên:** Người dùng, Tổ chức, Phân quyền, Sản phẩm, Ghi
  danh, Quy tắc điểm danh.
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
3. `class.create` và `class.delete`: màn tạo lớp trong khu giáo viên; giáo viên tạo lớp
   tự được phân công vào lớp đó. Tạo lớp phải chọn Sản phẩm: giáo viên chỉ chọn trong
   danh sách Sản phẩm đang hoạt động, không xem và không sửa Sản phẩm.

Owner duyệt tài liệu Database dưới đây ngày 2026-11-17. Owner được hỏi trước khi bắt đầu
code.

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

# Not In Scope

Quyền theo từng lớp hay từng Template; quyền cho học viên; vai trò mới; quyền vượt tenant.
