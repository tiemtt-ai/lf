# Table: core_course_template_lessons

Version: 1.2

Status: Official Foundation

Last Updated: 2026-07

---

# Purpose

Lưu danh sách bài học thuộc Course Template.

Template Lesson là blueprint của bài học.

Template Lesson là working draft để giáo viên chỉnh sửa.

Khi publish Template, Lesson được snapshot sang
`core_course_template_version_lessons`; Student không học trực tiếp working Lesson.

---

# Relationships

core_course_templates

1

↓

N

core_course_template_lessons

---

core_course_template_sections

0..1

↓

N

core_course_template_lessons

---

core_course_template_lessons

1

↓

N

core_course_template_activities

---

# Business Rules

* Mọi Template Lesson phải thuộc customer_id.
* Mọi Template Lesson phải thuộc một Course Template.
* `template_section_id = NULL` nghĩa là Lesson thuộc trực tiếp Template.
* Nếu có `template_section_id`, Section phải cùng Template và `customer_id`.
* Template Lesson không chứa Enrollment.
* Template Lesson không chứa Learning Progress.
* Template Lesson chỉ định nghĩa cấu trúc bài học.
* Thứ tự lesson được xác định bằng sort_order.
* Không được tạo số lượng lesson vượt quá max_lessons của Template nếu max_lessons có giá trị.
* Product/Enrollment/Progress chỉ sử dụng Version Lesson.
* Sửa working Template Lesson không ảnh hưởng Version Lesson đã publish.
* Lesson là primary authoring unit trong Course Template editing tree.
* Authoring tree chỉ hiển thị Lesson title cùng Edit/Delete; không hiển thị
  Lesson status hoặc Draft/Active label.
* Activities được liệt kê trực tiếp trong cùng Lesson card.

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

### template_section_id

BIGINT UNSIGNED
NULL

Section tùy chọn chứa Lesson. NULL nghĩa là Lesson thuộc trực tiếp Template.

---

## Basic Information

### title

VARCHAR(255)

Tên bài học.

### slug

VARCHAR(255) NULL

Slug bài học.

### short_description

VARCHAR(500) NULL

Mô tả ngắn.

### description

TEXT NULL

Mô tả chi tiết.

---

## Display

### sort_order

INT DEFAULT 0

Thứ tự hiển thị trong nhóm trực tiếp của Template hoặc trong Section.

### is_preview

TINYINT(1) DEFAULT 0

Cho phép học thử.

---

## Learning Metadata

### learning_objective

TEXT NULL

Mục tiêu học tập.

### duration_seconds

INT UNSIGNED DEFAULT 0

Tổng thời lượng bài học.

System generated.

Tự động tính từ:

```text
Template Activities

↓

Media

↓

Duration
```

Không cho phép nhập tay.

### activity_count

INT UNSIGNED DEFAULT 0

Số lượng Activity hiện có.

System generated.

---

## Unlock Rules

### unlock_rule

VARCHAR(50) DEFAULT 'none'

Giá trị:

* none
* previous_lesson_completed
* date_based

### unlock_after_lesson_id

BIGINT UNSIGNED NULL

Lesson phải hoàn thành trước.

### unlock_at

TIMESTAMP NULL

Ngày mở khóa nếu dùng date_based.

---

## Business

### status

VARCHAR(50)

Giá trị:

* draft
* active
* inactive
* archived

---

## Audit

### created_by

BIGINT UNSIGNED NULL

Người tạo.

### created_at

TIMESTAMP

### updated_at

TIMESTAMP

---

# Indexes

(customer_id)

(customer_id, template_id)

(customer_id, template_section_id)

(customer_id, template_id, status)

(customer_id, template_section_id, sort_order)

(customer_id, slug)

(customer_id, created_by)

---

# Unique Constraints

UNIQUE(customer_id, template_id, slug)

---

# Sample Data

id = 1

customer_id = 1

template_id = 10

template_section_id = 2

title = Lesson 1 - Korean Alphabet

slug = lesson-1-korean-alphabet

sort_order = 1

is_preview = 1

duration_seconds = 3600

activity_count = 5

unlock_rule = none

status = active

---

# Notes

Lesson là đơn vị học tập.

Không chứa:

* Video
* PDF
* Quiz
* Assignment

Các nội dung đó thuộc:

```text
core_course_template_activities
```

Lesson chỉ là container tổ chức Activity.

---

End of Document
