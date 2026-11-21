# ADR-0034 — Cohort Membership Period And History

Version: 1.0

Status: Approved

Implementation Status: Implemented

Last Updated: 2026-11-19

Proposal Date: 2026-11-18

Approval Date: 2026-11-18 (nghiệp vụ và thiết kế dữ liệu)

Approved By: LearnForge Business/Architecture Owner

Document Path: adr/ADR-0034-Cohort-Membership-Period-And-History.md

Related ADRs:

* [ADR-0001 — Course Foundation](ADR-0001-Course-Foundation.md): một Enrollment một dòng thành viên lớp
* [ADR-0002 — LiveClass Foundation](ADR-0002-LiveClass-Foundation.md): điểm danh, tự ghi vắng
* [ADR-0022 — Course Type On Course Template](ADR-0022-Course-Type-On-Template.md): Amendment 1.3, học bù và điểm danh theo nội dung

---

# Context

Căn cứ nghiệp vụ: "Nghiệp vụ luồng học tập lớp MK" (16/11/2026) mục 1.2. Hiện trạng đã
kiểm tra trong code ngày 2026-11-18:

* Mỗi Enrollment có đúng một dòng `core_course_cohort_students`. Gỡ khỏi lớp đặt
  `status = removed` và `left_at`.
* Thêm lại vào chính lớp đó ghi đè `joined_at` bằng thời điểm thêm lại và xóa `left_at`:
  mất ngày vào lớp ban đầu và mất khoảng thời gian học viên không thuộc lớp.
* Trang sửa thành viên cho quản trị sửa `joined_at`, không chặn ngày tương lai, không hỏi
  xác nhận, không lưu lịch sử.
* Tự ghi vắng và học bù đã bỏ qua buổi kết thúc trước `joined_at`. Ghi tay, chuyên cần và
  trang của học viên chưa phân biệt buổi đó.
* LF-Core-Course (Foundation P1 Decisions) và tài liệu bảng ghi: không lưu membership
  history, kể cả ở bảng riêng.

# Decision

Owner duyệt nghiệp vụ ngày 2026-11-18.

## 1. Ngày vào lớp

* Ngày vào lớp (`joined_at`) là lúc học viên được gán vào lớp.
* Chỉ `customer_admin` sửa được; giáo viên chỉ xem. Không nhận thời điểm ở tương lai. Giao
  diện hỏi xác nhận trước khi lưu.
* Chuyển sang lớp khác: ngày vào lớp là lúc chuyển, như hiện nay.

## 2. Rời lớp và thêm lại

* Gỡ khỏi lớp ghi `left_at`, như hiện nay.
* Thêm lại vào **cùng lớp** giữ `joined_at` ban đầu. Khoảng từ `left_at` đến lúc thêm lại là
  một khoảng không thuộc lớp, đọc từ lịch sử (mục 4).
* Vẫn một dòng thành viên cho mỗi Enrollment; không thêm cột vào bảng thành viên.

## 3. Buổi ngoài thời gian thuộc lớp

Một buổi nằm ngoài thời gian thuộc lớp của học viên khi giờ kết thúc theo lịch của buổi
rơi vào một trong ba trường hợp: trước `joined_at`; sau `left_at` của thành viên đã rời;
trong một khoảng không thuộc lớp. Với buổi đó:

* Hệ thống không tự ghi vắng và không ghi học bù.
* Giáo viên và quản trị không ghi tay được.
* Buổi không tính vào chuyên cần của học viên.
* Học viên thấy nhãn "Trước ngày bạn vào lớp" (trước `joined_at`) hoặc "Không thuộc lớp"
  (hai trường hợp còn lại) thay cho kết quả điểm danh.
* Kết quả điểm danh đã có ở buổi đó được **giữ nguyên**, không tính vào chuyên cần và không
  hiện cho học viên. Sửa lại ngày vào lớp để buổi trở về trong thời gian thuộc lớp thì kết
  quả được tính lại như cũ (Owner chốt 2026-11-18).
* Quyền học nội dung không đổi: học viên vào lớp muộn vẫn mở được mọi nội dung đã mở
  (ADR-0022 Amendment 1.3, Đợt 2).

## 4. Lịch sử

* Mỗi lần sửa ngày vào lớp, rời lớp, thêm lại hoặc chuyển lớp thêm một dòng lịch sử: ai,
  lúc nào, giá trị trước và sau. Dòng lịch sử chỉ thêm, không sửa, không xóa.
* Cạnh ngày vào lớp có nút xem lịch sử: các lần sửa và các khoảng không thuộc lớp. Quản trị
  và giáo viên của lớp xem được.
* Quy tắc này thay câu "không lưu membership history" của LF-Core-Course (Foundation P1
  Decisions) và của tài liệu bảng `core_course_cohort_students`. Bảng thành viên vẫn không có
  cột lịch sử và không dùng `is_current`.

# Dữ liệu

Owner duyệt ngày 2026-11-18. Tài liệu bảng:
[core_course_cohort_student_changes](../database/course/core_course_cohort_student_changes.md).

Bảng mới `core_course_cohort_student_changes`, chỉ thêm:

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Trung tâm sở hữu. |
| cohort_student_id | BIGINT UNSIGNED NOT NULL | Dòng thành viên lớp. |
| cohort_id | BIGINT UNSIGNED NOT NULL | Lớp tại thời điểm thay đổi, chép từ dòng thành viên. |
| enrollment_id | BIGINT UNSIGNED NOT NULL | Enrollment, chép từ dòng thành viên. |
| change_type | VARCHAR(30) NOT NULL | `joined_at_changed`, `left`, `rejoined` hoặc `transferred`. |
| old_joined_at | DATETIME NULL | Ngày vào lớp trước thay đổi. |
| new_joined_at | DATETIME NULL | Ngày vào lớp sau thay đổi. |
| absent_from | DATETIME NULL | Chỉ với `rejoined`: lúc rời lớp. |
| absent_to | DATETIME NULL | Chỉ với `rejoined`: lúc được thêm lại. |
| changed_by | BIGINT UNSIGNED NULL | Người thực hiện; `NULL` khi hệ thống thực hiện (ví dụ kết thúc lớp). |
| created_at | TIMESTAMP NOT NULL | Thời điểm thay đổi. |

```sql
PRIMARY KEY (id);
INDEX (customer_id, cohort_student_id, created_at);
INDEX (customer_id, cohort_id, enrollment_id);

FOREIGN KEY (customer_id) REFERENCES saas_customers (id) ON UPDATE RESTRICT ON DELETE RESTRICT;
FOREIGN KEY (cohort_student_id, customer_id) REFERENCES core_course_cohort_students (id, customer_id) ON UPDATE RESTRICT ON DELETE RESTRICT;
FOREIGN KEY (changed_by) REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT;

CHECK (change_type IN ('joined_at_changed', 'left', 'rejoined', 'transferred'));
CHECK ((change_type = 'rejoined') = (absent_from IS NOT NULL AND absent_to IS NOT NULL));
CHECK (absent_from IS NULL OR absent_to >= absent_from);
```

* Khoảng không thuộc lớp của một thành viên là các dòng `rejoined` có cùng
  `cohort_student_id` và `cohort_id`.
* Dữ liệu đang có không được dựng lại lịch sử: các lần thêm lại trước ADR này đã ghi đè
  `joined_at` và không còn thông tin để khôi phục.
* `core_course_cohort_students` không đổi cột. `core_liveclass_attendances` không đổi.

# Alternatives rejected

* **Mỗi lần vào lớp một dòng thành viên.** Phá quy tắc "một Enrollment một dòng thành viên"
  của Foundation và mọi chỗ đang đọc dòng duy nhất đó.
* **Cột `first_joined_at` trên bảng thành viên.** Giữ được ngày ban đầu nhưng không ghi được
  nhiều khoảng không thuộc lớp và không có lịch sử sửa.
* **Xóa kết quả điểm danh của buổi ngoài thời gian thuộc lớp.** Không khôi phục được khi
  ngày vào lớp bị sửa nhầm.

# Consequences

* Mọi chỗ ghi `joined_at`, `left_at` của thành viên lớp phải ghi lịch sử trong cùng transaction.
* Chuyên cần, tự ghi vắng, học bù, ghi tay và trang lớp của học viên dùng chung một phép xét
  "buổi có nằm trong thời gian thuộc lớp không".

# Implementation record

Triển khai ngày 2026-11-18:

* Migration `2026_11_18_000400_create_course_cohort_student_changes`; kiểm trên MariaDB 11.4.
* `CourseCohortMembershipHistory`: `record()`, `gaps()`, `outside()`, `forMemberships()`.
* `CourseCohortStudentController`: thêm lại vào cùng lớp giữ `joined_at`; ghi lịch sử khi gỡ,
  thêm lại, chuyển lớp, sửa ngày vào lớp; `joined_at` không nhận thời điểm tương lai.
  `CourseCohortLifecycleService` ghi lịch sử khi lưu trữ lớp nháp gỡ thành viên.
* `LiveClassAttendanceRecorder`: tự ghi vắng, học bù, điểm danh theo nội dung và ghi tay bỏ qua
  buổi ngoài thời gian thuộc lớp; `roster()` trả `outside` cho từng học viên.
* `LearnerMyLearningService`: chuyên cần không tính buổi ngoài thời gian thuộc lớp;
  `membership_outside` trên từng buổi.
* Giao diện: nút Lịch sử cạnh ngày vào lớp ở tab Học viên; form sửa thành viên chặn ngày tương
  lai và hỏi xác nhận; bảng điểm danh và trang lớp của học viên hiện lý do thay cho kết quả.
* Test: `CourseCohortStudentManagementTest::test_the_join_date_is_kept_when_a_learner_is_added_back_and_every_change_is_history`,
  `LiveClassAttendanceCheckInTest::test_a_session_outside_the_learners_time_in_the_class_takes_no_attendance`.
* Tab Học viên của lớp có nút sửa ở mỗi dòng, chỉ cho quản trị khi lớp còn sửa được (Owner
  2026-11-18). Từ 2026-11-19 nút mở popup "Sửa học viên trong lớp" ngay trong tab (lớp cùng Sản
  phẩm, ngày vào lớp, lý do, ghi chú); các trang riêng của phân lớp (danh sách, chi tiết, thêm, sửa)
  chuyển hướng về tab Học viên, route giữ nguyên.
* 2026-11-19, theo mục 1: chọn lớp khác trong popup thì ô ngày vào lớp tự đặt là lúc chuyển
  (vẫn sửa được); chọn lại lớp cũ thì trả ngày đã lưu. Yêu cầu chuyển lớp còn mang ngày vào lớp
  cũ thì máy chủ ghi lúc chuyển, nên buổi trước đó của lớp mới không tự ghi vắng. Test
  `CourseCohortStudentManagementTest::test_a_transfer_that_keeps_the_old_join_date_joins_the_new_class_now`.
* Giới hạn: nút Lịch sử chỉ hiện khi thành viên đã có thay đổi.
