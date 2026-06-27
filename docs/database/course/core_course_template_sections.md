# core_course_template_sections

## Purpose

Lưu working Section (Module / Chapter / Unit) của Course Template draft.

Section là lớp trung gian giữa:

```text
Course Template

↓

Template Section

↓

Template Lesson

↓

Template Activity
```

Giúp tổ chức khóa học thành các nhóm nội dung lớn, dễ quản lý, dễ học và dễ mở rộng.

---

## Relationships

```text
saas_customers

1

↓

N

core_course_template_sections
```

```text
core_course_templates

1

↓

N

core_course_template_sections
```

```text
core_course_template_sections

1

↓

N

core_course_template_lessons
```

---

## Business Rules

* Mọi Section phải thuộc một `customer_id`.
* Mọi Section phải thuộc một Template.
* Template Section phải có cùng `customer_id` với Course Template.
* Không được query Template Section ngoài tenant hiện hành.
* Mọi Template phải có ít nhất một Section.
* Course nhỏ nhất vẫn phải có `Section 1`.
* Một Template có thể có nhiều Section.
* Section dùng để nhóm Lesson.
* Lesson chỉ thuộc một Section.
* Thứ tự hiển thị được quản lý bằng sort_order.
* Có thể ẩn hoặc hiển thị từng Section.
* Có thể khóa Section cho đến khi học viên hoàn thành Section trước.
* Không chứa Activity trực tiếp.
* Không chứa Media trực tiếp.
* Activity luôn nằm trong Lesson.
* Khi publish Template, Section được snapshot sang `core_course_template_version_sections`.
* Student/Progress không tham chiếu working Template Section.

---

## Fields

### id

```text
BIGINT UNSIGNED
PRIMARY KEY
AUTO_INCREMENT
```

Khóa chính.

---

### customer_id

```text
BIGINT UNSIGNED
NOT NULL
```

Tenant sở hữu Template Section.

Liên kết:

```text
saas_customers.id
```

Giá trị phải khớp với `customer_id` của Course Template.

---

### template_id

```text
BIGINT UNSIGNED
NOT NULL
```

Template sở hữu Section.

Liên kết:

```text
core_course_templates.id
```

---

### parent_section_id

```text
BIGINT UNSIGNED
NULL
```

Cho phép Section lồng nhau.

Ví dụ:

```text
Module 1

└─ Unit 1
└─ Unit 2
```

V1 có thể để NULL toàn bộ.

Chuẩn bị cho mở rộng tương lai.

---

### code

```text
VARCHAR(100)
NULL
```

Mã nội bộ.

Ví dụ:

```text
M01

CH01

UNIT01
```

---

### title

```text
VARCHAR(255)
NOT NULL
```

Tên Section.

Ví dụ:

```text
Introduction

Basic Grammar

Listening Practice
```

---

### short_title

```text
VARCHAR(100)
NULL
```

Tên rút gọn.

Ví dụ:

```text
Grammar

Speaking
```

---

### description

```text
TEXT
NULL
```

Mô tả Section.

---

### thumbnail_file_id

```text
BIGINT UNSIGNED
NULL
```

Ảnh đại diện Section.

Liên kết:

```text
media_files.id
```

---

### sort_order

```text
INT UNSIGNED
NOT NULL
DEFAULT 1
```

Thứ tự hiển thị.

Ví dụ:

```text
1
2
3
4
```

---

### is_required

```text
TINYINT(1)
NOT NULL
DEFAULT 1
```

Section bắt buộc hay không.

```text
1 = Required

0 = Optional
```

---

### unlock_rule

```text
VARCHAR(50)
NOT NULL
DEFAULT 'immediate'
```

Quy tắc mở khóa.

Allowed values:

```text
immediate

after_previous_section

manual
```

Ví dụ:

```text
Section 2 chỉ mở khi hoàn thành Section 1
```

---

### estimated_duration_minutes

```text
INT UNSIGNED
NULL
```

Tổng thời lượng ước tính.

Ví dụ:

```text
120

300

600
```

---

### total_lessons

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Cache số lượng Lesson.

Đây là read-model/cache field phục vụ hiển thị và reporting.

Source of truth:

```text
COUNT(core_course_template_lessons.id)

WHERE template_section_id = core_course_template_sections.id
```

Phải recalculation khi Template Lesson được thêm, chuyển Section, archive hoặc xóa.

Không dùng field này thay cho truy vấn/audit chi tiết khi cần số liệu chính xác lịch sử.

---

### status

```text
VARCHAR(50)
NOT NULL
DEFAULT 'active'
```

Allowed values:

```text
active

inactive

archived
```

---

### metadata

```text
JSON NULL
```

Thông tin mở rộng.

Ví dụ:

```json
{
  "color": "#0EA5E9",
  "icon": "book-open"
}
```

---

### created_at

```text
TIMESTAMP NULL
```

---

### updated_at

```text
TIMESTAMP NULL
```

---

## Indexes

```sql
INDEX idx_template_sections_customer
(customer_id);
```

```sql
INDEX idx_template_sections_template
(customer_id, template_id);
```

```sql
INDEX idx_template_sections_parent
(customer_id, parent_section_id);
```

```sql
INDEX idx_template_sections_sort
(customer_id, template_id, parent_section_id, sort_order);
```

```sql
INDEX idx_template_sections_status
(customer_id, template_id, status);
```

---

## Unique Constraints

```sql
UNIQUE uniq_template_section_code
(customer_id, template_id, code);
```

```sql
UNIQUE uniq_template_section_sort
(customer_id, template_id, parent_section_id, sort_order);
```

Với root Section có `parent_section_id = NULL`, application/service layer phải
bảo đảm `sort_order` không trùng vì MySQL cho phép nhiều giá trị NULL trong
unique index.

---

## Sample Data

### Section 1

```text
id = 1

customer_id = 1

template_id = 1

code = M01

title = Hangul Fundamentals

sort_order = 1

is_required = 1

unlock_rule = immediate

estimated_duration_minutes = 240

total_lessons = 8

status = active
```

---

### Section 2

```text
id = 2

customer_id = 1

template_id = 1

code = M02

title = Basic Grammar

sort_order = 2

is_required = 1

unlock_rule = after_previous_section

estimated_duration_minutes = 360

total_lessons = 12

status = active
```

---

## Sample Structure

```text
TOPIK Beginner

├─ Section 1: Hangul Fundamentals
│
├─ Lesson 1
├─ Lesson 2
├─ Lesson 3
│
└─ Lesson 8

├─ Section 2: Basic Grammar
│
├─ Lesson 9
├─ Lesson 10
├─ Lesson 11
│
└─ Lesson 20
```

---

## Final Statement

Section là lớp tổ chức nội dung cấp cao của Template.

```text
Course Template

↓

Template Section

↓

Template Lesson

↓

Template Activity
```

Giúp khóa học có cấu trúc rõ ràng, dễ quản lý, dễ học và sẵn sàng mở rộng cho các chương trình đào tạo lớn trong tương lai.
