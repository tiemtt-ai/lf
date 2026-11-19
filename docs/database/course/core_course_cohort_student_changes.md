# Table: core_course_cohort_student_changes

Version: 1.0

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-11-18

Document Path: database/course/core_course_cohort_student_changes.md

## Purpose

Lịch sử thành viên lớp, chỉ thêm: sửa ngày vào lớp, rời lớp, thêm lại và chuyển lớp. Nghiệp vụ
nằm ở [ADR-0034](../../adr/ADR-0034-Cohort-Membership-Period-And-History.md) (Owner duyệt nghiệp
vụ và thiết kế dữ liệu ngày 2026-11-18).

Migration: `2026_11_18_000400_create_course_cohort_student_changes`; kiểm trên MariaDB 11.4.

## Relationships

* `Cohort Student 1 → N Changes`.
* `cohort_id` và `enrollment_id` được chép từ dòng thành viên để đọc các khoảng không thuộc
  lớp của một Enrollment trong một lớp mà không cần join.

## Business Rules

* Một dòng được thêm trong cùng transaction với thay đổi của `core_course_cohort_students`:
  - `joined_at_changed`: quản trị sửa ngày vào lớp; ghi giá trị trước và sau;
  - `left`: thành viên rời lớp;
  - `rejoined`: thành viên được thêm lại vào cùng lớp; `absent_from` là lúc rời, `absent_to`
    là lúc thêm lại; `joined_at` của thành viên không đổi;
  - `transferred`: thành viên chuyển sang lớp khác; `cohort_id` là lớp mới.
* Lần vào lớp đầu tiên không tạo dòng: chính dòng thành viên là bản ghi đó.
* Lưu mà không đổi ngày vào lớp thì không tạo dòng.
* Dòng không bao giờ bị sửa hay xóa.
* `changed_by` là `NULL` khi hệ thống thực hiện (ví dụ lưu trữ một lớp nháp).
* Khoảng không thuộc lớp của một Enrollment trong một lớp là các dòng `rejoined` có cùng
  `cohort_id` và `enrollment_id`. `CourseCohortMembershipHistory::outside()` là phép xét duy
  nhất cho "buổi có nằm trong thời gian thuộc lớp không".
* Thời điểm được lưu theo giờ ứng dụng, cùng quy ước với `joined_at` và `left_at`.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Trung tâm sở hữu. |
| cohort_student_id | BIGINT UNSIGNED NOT NULL | Dòng thành viên lớp. |
| cohort_id | BIGINT UNSIGNED NOT NULL | Lớp của thay đổi; với `transferred` là lớp mới. |
| enrollment_id | BIGINT UNSIGNED NOT NULL | Enrollment, chép từ dòng thành viên. |
| change_type | VARCHAR(30) NOT NULL | `joined_at_changed`, `left`, `rejoined` hoặc `transferred`. |
| old_joined_at | DATETIME NULL | Ngày vào lớp trước thay đổi. |
| new_joined_at | DATETIME NULL | Ngày vào lớp sau thay đổi. |
| absent_from | DATETIME NULL | Chỉ với `rejoined`: lúc rời lớp. |
| absent_to | DATETIME NULL | Chỉ với `rejoined`: lúc được thêm lại. |
| changed_by | BIGINT UNSIGNED NULL | Người thực hiện. |
| created_at | TIMESTAMP NOT NULL | Thời điểm thay đổi. |

Không có `updated_at`: dòng không bao giờ được sửa.

## Constraints

```sql
PRIMARY KEY (id);
INDEX idx_cccsc_member (customer_id, cohort_student_id, created_at);
INDEX idx_cccsc_cohort_enrollment (customer_id, cohort_id, enrollment_id);

FOREIGN KEY (customer_id) REFERENCES saas_customers (id) ON UPDATE RESTRICT ON DELETE RESTRICT;
FOREIGN KEY (cohort_student_id, customer_id) REFERENCES core_course_cohort_students (id, customer_id) ON UPDATE RESTRICT ON DELETE RESTRICT;
FOREIGN KEY (changed_by) REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT;

CHECK (change_type IN ('joined_at_changed', 'left', 'rejoined', 'transferred'));
CHECK ((change_type = 'rejoined') = (absent_from IS NOT NULL AND absent_to IS NOT NULL));
CHECK (absent_from IS NULL OR absent_to >= absent_from);
```

SQLite bỏ qua `CHECK`; các ràng buộc này phải được kiểm trên MariaDB 11.4.

## Sample Data

`id=1, customer_id=1, cohort_student_id=40, cohort_id=17, enrollment_id=32, change_type='rejoined', absent_from='2026-07-10 08:00:00', absent_to='2026-07-20 08:00:00', changed_by=12, created_at='2026-07-20 08:00:00'`

## Design Notes

* Lịch sử chỉ có từ lúc triển khai: các lần thêm lại trước đó đã ghi đè `joined_at` và không
  khôi phục được.
* Không lưu `left_at` riêng ở dòng `left`: thời điểm rời lớp là `created_at` của dòng đó và
  `left_at` của dòng thành viên.
