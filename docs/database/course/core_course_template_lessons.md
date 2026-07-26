# Table: core_course_template_lessons

Version: 1.3

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
* `estimated_lesson_count` của Template là estimate, không phải hard limit;
  authoring không bị chặn khi số Lesson vượt estimate.
* Product/Enrollment/Progress chỉ sử dụng Version Lesson.
* Sửa working Template Lesson không ảnh hưởng Version Lesson đã publish.
* Lesson là primary authoring unit trong Course Template editing tree.
* Lesson không có lifecycle/status riêng; lifecycle thuộc Course Template draft
  và published Version.
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

### lesson_type

VARCHAR(50) NOT NULL DEFAULT `regular`.

Canonical values: `regular`, `review`, `midterm_exam`, `final_exam`,
`other_exam`. Đây chỉ là semantic classification; không điều khiển scheduling,
grading, completion, unlock, Assessment, Activity hoặc Cohort.

---

## Learning Metadata

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

## ADR-0015 Amendment

[ADR-0015](../../adr/ADR-0015-Course-Lesson-Multiple-Prerequisites.md),
defines these canonical values:

* `none`
* `all_previous_lessons_completed`
* `selected_lessons_completed`
* `date_based`

The Lesson adds nullable `prerequisite_match VARCHAR(10)` with allowed values
`all` and `any`; it is required only for `selected_lessons_completed`.
Normalized selected edges belong to
`core_course_template_lesson_prerequisites`.

Legacy `previous_lesson_completed` and `unlock_after_lesson_id` remain only for
the additive migration/backfill window. New application writes stop using the
single-reference column after the ADR is approved and implemented.

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

(customer_id, template_section_id, sort_order)

(customer_id, created_by)

---

# Unique Constraints

---

# Sample Data

id = 1

customer_id = 1

template_id = 10

template_section_id = 2

title = Lesson 1 - Korean Alphabet

sort_order = 1

is_preview = 1

duration_seconds = 3600

activity_count = 5

unlock_rule = none

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
