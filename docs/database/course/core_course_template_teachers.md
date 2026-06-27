# Table: core_course_template_teachers

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

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
* Có thể xác định teacher chính và teacher phụ.
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

### sort_order

INT DEFAULT 0

Thứ tự hiển thị.

### status

VARCHAR(50)

Giá trị:

* active
* inactive

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
