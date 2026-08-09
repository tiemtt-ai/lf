# Table: core_course_template_teachers

Version: 1.0

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-08-09

Document Path: database/course/core_course_template_teachers.md

---

# Purpose

Liên kết giáo viên với Course Template.

Cho phép:

* Một Template có nhiều giáo viên.
* Một Giáo viên tham gia nhiều Template.

---

# Relationships

core_course_templates

1

↓

N

core_course_template_teachers

---

users (teacher)

1

↓

N

core_course_template_teachers

---

# Business Rules

* Chỉ user role = teacher mới được gán.
* Một teacher có thể tham gia nhiều template.
* Một template có thể có nhiều teacher.
* Một Template có thể không có teacher assignment; thiếu Giáo viên không phải
  publish blocker, nhưng readiness hiển thị cảnh báo không chặn để người dùng có
  thể phân công sau.
* Có thể xác định trách nhiệm của teacher trên working Template: chủ trì nội
  dung, biên soạn hoặc phản biện. Đây không phải vai trò đứng lớp.
* Teacher assignment là cấu hình vận hành mutable của working Template, không
  phải nội dung immutable và không được snapshot vào Course Template Version.
  Thay đổi assignment sau publish không sửa Version, Product Item, Enrollment
  hoặc Progress hiện hữu.
* Teacher assignment không tự động tạo phân công giáo viên cho Product, Cohort
  hoặc LiveClass Session.
* Khi Template đang được Product tham chiếu, thay đổi teacher assignment phải bảo toàn quyền truy cập và lịch sử liên quan.

---

# Fields

## Identity

### id

BIGINT UNSIGNED

Primary key.

### customer_id

BIGINT UNSIGNED

Tenant sở hữu dữ liệu.

### template_id

BIGINT UNSIGNED

Khóa học mẫu.

### teacher_id

BIGINT UNSIGNED

Giáo viên.

---

## Assignment

### role

VARCHAR(50)

Giá trị:

* primary
* assistant
* reviewer

Ý nghĩa hiển thị:

* `primary`: Chủ trì nội dung.
* `assistant`: Người biên soạn.
* `reviewer`: Người phản biện.

Các giá trị này mô tả trách nhiệm trên working Template, không mô tả vai trò
giảng dạy tại Cohort hoặc LiveClass Session.

### sort_order

INT DEFAULT 0

Thứ tự hiển thị.

Ứng dụng tự gán trong phạm vi `customer_id + template_id`: assignment đầu tiên
dùng `0`, assignment tiếp theo dùng `MAX(sort_order) + 1`. Form create/update
không nhận giá trị này; update và remove không đánh lại thứ tự.

### status

VARCHAR(50)

Giá trị:

* active
* inactive

Create đặt `active` ở server. Form update không nhận `status` và bảo toàn giá
trị hiện có; remove là lifecycle action chuyển assignment sang `inactive`.

---

## Audit

### assigned_by

BIGINT UNSIGNED NULL

Người thực hiện gán.

### assigned_at

TIMESTAMP NULL

Ngày gán.

### created_at

TIMESTAMP

### updated_at

TIMESTAMP

---

# Indexes

(customer_id)

(customer_id, template_id)

(customer_id, teacher_id)

(customer_id, status)

---

# Unique Constraints

UNIQUE(template_id, teacher_id)

---

# Sample Data

id = 1

customer_id = 1

template_id = 10

teacher_id = 5

role = primary

status = active

---

End of Document
