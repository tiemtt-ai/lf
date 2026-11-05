# ADR-0024 — Cohort Owned Content

Version: 1.1

Status: Archived

Implementation Status: Not Applicable

Last Updated: 2026-11-05

Proposal Date: 2026-11-05

Approval Date: 2026-11-05

Document Path: adr/ADR-0024-Cohort-Owned-Content.md

Related ADRs:

* [ADR-0012 — Course Template Published Version Snapshot](ADR-0012-Course-Template-Published-Version-Snapshot.md) — Version đã xuất bản vẫn bất biến.
* [ADR-0015 — Course Lesson Multiple Prerequisites](ADR-0015-Course-Lesson-Multiple-Prerequisites.md) — quy tắc mở khóa được chép cùng nội dung.
* [ADR-0022 — Course Type On Template](ADR-0022-Course-Type-On-Template.md) — loại khóa học giới hạn hoạt động được thêm.
* [ADR-0023 — Cohort Content Order](ADR-0023-Cohort-Content-Order.md) — ADR này thay phần lưu thứ tự và phần "chỉ xem" của ADR đó.

---

# Withdrawn

Owner rút quyết định này ngày 2026-11-05 trước khi có migration hay code: lớp đọc thẳng phiên bản đã chọn của Template, không sinh bản chép; nội dung ở lớp chỉ xem. Phần riêng của lớp lưu theo [ADR-0023](ADR-0023-Cohort-Content-Order.md). Tài liệu giữ lại để biết vì sao không chọn cách này.

---

# Context

Owner chốt ngày 2026-11-05: tab Buổi học của lớp giống hoàn toàn tab Nội dung của Template, kể cả
nút thêm mới, form, sửa, xóa và kéo thả. Mỗi lớp có nội dung riêng, thay đổi không ảnh hưởng Template,
Version hay lớp khác. So với Template chỉ thêm ngày giờ, giáo viên và liên hệ giáo viên cho từng buổi.

Hiện trạng đã kiểm tra trong code:

* Version đã xuất bản bất biến; Enrollment, Product, Completion và Certificate gắn với Version đó.
  `version_id` của Enrollment được trigger bảo vệ.
* Session, Attendance, Recording, Progress, kiểm tra quyền và Media đều trỏ tới các bảng
  `core_course_template_version_*`. Viết bộ bảng mới phải sửa toàn bộ các điểm này.
* `core_course_activity_progress` và `core_course_lesson_progress` chưa có dòng nào, và chưa có màn hình
  học cho học viên, nên chưa có tiến độ thật cần chuyển.
* DB dev có 7 lớp đã gắn Version và 14 Session, đều là dữ liệu nháp.

---

# Decision

## 1. Bản nội dung của lớp là một Version do lớp sở hữu

Không thêm bảng nội dung mới. Bản của lớp nằm trong chính các bảng Version hiện có:

* `core_course_template_versions` có thêm trạng thái `cohort` và cột `owner_cohort_id`.
* `core_course_cohorts` có thêm `content_version_id`, trỏ tới bản của lớp.
* `core_course_cohorts.version_id` giữ nguyên nghĩa: Version đã xuất bản mà lớp được tạo từ đó. Enrollment,
  Product và chuyển lớp vẫn đối chiếu theo cột này.

Bản `cohort` không phải Version đã xuất bản, nên ADR-0012 không đổi: Version `published` vẫn bất biến. Bản
`cohort` sửa được, không bao giờ là `is_current`, và không xuất hiện ở danh sách Version, bộ chọn Product hay
luồng nhân bản. Mọi truy vấn Version hiện có phải được rà lại theo điều này.

## 2. Thời điểm chép

* Chép khi lớp nhận `version_id`, trong cùng transaction, gồm Section, Lesson, Activity và dòng điều kiện
  Lesson.
* Không chép cho các lớp đang có. Owner chốt 2026-11-05: đó là dữ liệu nháp, có thể bỏ qua hoặc xóa, nên
  migration không gắn lại Session cũ.
* Template xuất bản bản mới không ảnh hưởng lớp đã chép.

## 3. Giao diện giống Template

Tab Buổi học dùng lại đúng view và form của tab Nội dung Template, không dựng bản khác:

* Thêm, sửa, xóa Section, Lesson và Activity; form và quy tắc kiểm tra giống Template, gồm tải Media.
* Kéo thả và phạm vi kéo như Template, ghi trực tiếp vào `sort_order` của bản lớp.
* Form của Activity `live_class` thêm ngày giờ, chọn giáo viên, liên hệ giáo viên; offline có phòng và địa chỉ.
* Tab Buổi học đổi route, không đổi view: các view đã nhận tiền tố route qua biến.

Ngoài phạm vi vì thuộc Template chứ không thuộc nội dung lớp: xuất bản, lịch sử phiên bản, cài đặt khóa học.
Soạn bằng AI (`ai-authoring`) chưa làm ở bước này vì phải rà riêng quyền và hạn mức.

## 4. Ngày thuộc về vị trí

Giữ quy tắc của Owner: ngày hiện lần lượt theo danh sách Activity `live_class` của lớp; kéo không đổi ngày tại
vị trí. Thay mục 3 của ADR-0023 ở chỗ: cả box đi theo bài. Giáo viên, phòng, địa chỉ, tên và link đi theo nội
dung; chỉ ngày giờ đứng yên.

* Bài đã lưu sang vị trí chưa lưu: tự lưu buổi ở vị trí đó, dùng ngày của vị trí, để không mất thông tin đã nhập.
* Giáo viên không dạy được ở ngày mới: từ chối lần kéo và nêu tên giáo viên cùng ngày.
* Chọn giáo viên ở tab Giáo viên: tự gán vào các buổi đã lưu, sắp diễn ra, chưa có giáo viên.

## 5. Những gì đã qua vẫn khóa

Giữ mục 4 của ADR-0023: buổi đã đến giờ bắt đầu, ở mọi trạng thái, hoặc đã có điểm danh, bản ghi thì không kéo
được, không đổi ngày được, và tooltip nêu lý do.

Thêm cho sửa và xóa nội dung:

* Bài hoặc hoạt động bị khóa không xóa được và không đổi được loại hoạt động; nội dung khác vẫn sửa được.
* Activity có buổi đã lưu, sắp diễn ra thì chưa xóa được; tooltip nêu lý do, như các nút xóa bị chặn ở Template.

Thêm Activity `live_class` làm các ngày chưa lưu phía sau tính lại; buổi đã lưu không tự đổi ngày.

## 6. Quy tắc mở khóa

Quy tắc mở khóa thuộc nội dung nên sửa được theo từng lớp. Kéo thả dùng cùng kiểm tra thứ tự mở khóa như Template,
đọc từ bản của lớp. Mục 5 của ADR-0023 được thay bằng mục này.

## 7. Học viên

* Học viên có thành viên `active` trong lớp đang hoạt động hoặc đã hoàn thành thấy bản của lớp. Enrollment không có
  lớp thấy Version đã xuất bản. Mọi màn hình học phải chọn nội dung qua một dịch vụ duy nhất.
* Tiến độ trỏ tới Lesson và Activity của nội dung học viên đang học.
* Chuyển lớp: tiến độ được ghép theo mục gốc chung; mục chỉ có ở lớp cũ không tính sang lớp mới. Chi tiết làm khi xây
  màn hình học.
* Hoàn thành và chứng chỉ tính theo các hoạt động bắt buộc của nội dung học viên đang học.

## 8. Tenant

Mọi đọc và ghi theo `customer_id`. `owner_cohort_id` dùng khóa kép với `customer_id`. Thao tác trên nội dung lớp phải qua
`CourseCohortMutationPolicy::canMutate`. Backend kiểm tra mục thuộc đúng bản của lớp, không tin dữ liệu từ client.

---

# Consequences

* Lớp có đủ khả năng của Template mà không đụng Version đã xuất bản, và tái dùng được Session, Media, kiểm tra quyền.
* Hai bảng `core_course_cohort_*_orders` của ADR-0023 không còn cần và sẽ bị bỏ trong migration của ADR này.
* Sửa Template sau khi lớp đã chép không tự sang lớp. Đây là chủ đích.
* Báo cáo so sánh giữa các lớp không còn cùng một bộ Activity, nên phải ghép theo mục gốc.
* Dung lượng dữ liệu tăng theo số lớp.
* `version_number`, `source_template_*_id` và ràng buộc của Media cần được thiết kế lại cho bản lớp ở bước Database,
  vì mục thêm ở lớp không có nguồn Template.

---

# Implementation Gate

Owner duyệt ngày 2026-11-05. Thứ tự còn lại: tài liệu Database, Architecture Review, Freeze, rồi mới Migration và Implementation.
Chưa tạo migration. Code hiện tại của ADR-0023 giữ nguyên đến khi ADR này được triển khai.
