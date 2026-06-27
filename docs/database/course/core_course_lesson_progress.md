# core_course_lesson_progress

## Purpose

Lưu tiến độ học tập của học viên theo từng Lesson trong một Course Product.

Bảng này trả lời câu hỏi:

```text
Học viên đã học Lesson này đến đâu?
```

`core_course_lesson_progress` là bảng summary cấp Lesson.

Nó không lưu log hành vi chi tiết.

Log chi tiết như xem video, mở tài liệu, làm quiz, pause, seek, replay sẽ nằm ở:

```text
track_*
```

---

## Relationships

```text
saas_customers

1

↓

N

core_course_lesson_progress
```

```text
core_course_enrollments

1

↓

N

core_course_lesson_progress
```

```text
core_course_progress

1

↓

N

core_course_lesson_progress
```

```text
core_course_products

1

↓

N

core_course_lesson_progress
```

```text
users

1

↓

N

core_course_lesson_progress
```

```text
core_course_template_version_lessons

1

↓

N

core_course_lesson_progress
```

```text
core_course_lesson_progress

1

↓

N

core_course_activity_progress
```

---

## Business Rules

* Mọi lesson progress phải thuộc `customer_id`.
* Một lesson progress thuộc một `student_id`.
* Một lesson progress thuộc một `product_id`.
* Một lesson progress nên gắn với một `enrollment_id`.
* Một lesson progress nên gắn với một `course_progress_id`.
* Một lesson progress luôn thuộc `template_version_id` của Enrollment.
* Một lesson progress luôn tham chiếu tới một `version_lesson_id`.
* Không tham chiếu working Template Lesson làm progress source.
* Progress được tách theo Learning Cycle bằng `enrollment_id`.
* Một student chỉ có một progress record cho một lesson trong cùng một product.
* Lesson progress là dữ liệu tổng hợp.
* Activity progress là nguồn chính để tính lesson progress.
* Nếu tất cả required activities trong lesson hoàn thành, lesson được xem là completed.
* Nếu học viên bắt đầu bất kỳ activity nào trong lesson, lesson chuyển sang in_progress.
* Nếu lesson bị khóa theo Product/Template rule, status có thể là locked.
* Sau khi enrollment hết hạn, lesson progress vẫn được giữ lại.

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

Tenant sở hữu progress.

---

### enrollment_id

```text
BIGINT UNSIGNED
NOT NULL
```

Enrollment liên quan.

Liên kết:

```text
core_course_enrollments.id
```

---

### course_progress_id

```text
BIGINT UNSIGNED
NOT NULL
```

Progress tổng cấp Product.

Liên kết:

```text
core_course_progress.id
```

---

### product_id

```text
BIGINT UNSIGNED
NOT NULL
```

Course Product đang học.

Liên kết:

```text
core_course_products.id
```

---

### template_version_id

```text
BIGINT UNSIGNED
NOT NULL
```

Published Template Version đã khóa trên Enrollment.

Liên kết `core_course_template_versions.id`.

---

### student_id

```text
BIGINT UNSIGNED
NOT NULL
```

Học viên sở hữu progress.

Liên kết:

```text
users.id
```

---

### version_section_id

```text
BIGINT UNSIGNED
NOT NULL
```

Version Section chứa Version Lesson.

Liên kết logic tới:

```text
core_course_template_version_sections.id
```

Section là bắt buộc trong Course architecture.

---

### version_lesson_id

```text
BIGINT UNSIGNED
NOT NULL
```

Version Lesson immutable được học viên học.

Liên kết:

```text
core_course_template_version_lessons.id
```

---

### sort_order

```text
INT UNSIGNED
NOT NULL
DEFAULT 1
```

Thứ tự Lesson tại thời điểm học.

Có thể snapshot từ Template/Product để báo cáo ổn định.

---

### progress_percentage

```text
DECIMAL(5,2)
NOT NULL
DEFAULT 0.00
```

Tiến độ của Lesson.

Ví dụ:

```text
0.00
50.00
100.00
```

---

### completed_activities

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Số activity đã hoàn thành trong Lesson.

---

### total_activities

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Tổng số activity trong Lesson.

---

### required_activities_completed

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Số activity bắt buộc đã hoàn thành.

---

### required_activities_total

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Tổng số activity bắt buộc trong Lesson.

---

### total_learning_seconds

```text
BIGINT UNSIGNED
NOT NULL
DEFAULT 0
```

Tổng thời gian học trong Lesson.

Giá trị này có thể tổng hợp từ `core_course_activity_progress` hoặc `track_*`.

---

### first_accessed_at

```text
TIMESTAMP NULL
```

Thời điểm học viên mở Lesson lần đầu.

---

### last_accessed_at

```text
TIMESTAMP NULL
```

Thời điểm học viên truy cập Lesson gần nhất.

---

### started_at

```text
TIMESTAMP NULL
```

Thời điểm Lesson chuyển sang trạng thái `in_progress`.

---

### completed_at

```text
TIMESTAMP NULL
```

Thời điểm Lesson hoàn thành.

---

### status

```text
VARCHAR(50)
NOT NULL
DEFAULT 'not_started'
```

Trạng thái tiến độ Lesson.

Allowed values:

```text
locked

not_started

in_progress

completed

skipped
```

---

### recalculated_at

```text
TIMESTAMP NULL
```

Thời điểm hệ thống tính lại lesson progress lần cuối.

---

### metadata

```text
JSON NULL
```

Dữ liệu mở rộng.

Ví dụ:

```json
{
  "completion_rule": "required_activities",
  "section_title_snapshot": "Hangul Fundamentals",
  "lesson_title_snapshot": "Lesson 1: Korean Vowels"
}
```

---

### created_at

```text
TIMESTAMP NULL
```

Thời điểm tạo.

---

### updated_at

```text
TIMESTAMP NULL
```

Thời điểm cập nhật.

---

## Indexes

```sql
INDEX idx_course_lesson_progress_customer
(customer_id);
```

```sql
INDEX idx_course_lesson_progress_enrollment
(customer_id, enrollment_id);
```

```sql
INDEX idx_course_lesson_progress_course_progress
(customer_id, course_progress_id);
```

```sql
INDEX idx_course_lesson_progress_product
(customer_id, product_id);
```

```sql
INDEX idx_course_lesson_progress_template_version
(customer_id, template_version_id);
```

```sql
INDEX idx_course_lesson_progress_student
(customer_id, student_id);
```

```sql
INDEX idx_course_lesson_progress_lesson
(customer_id, version_lesson_id);
```

```sql
INDEX idx_course_lesson_progress_section
(customer_id, version_section_id);
```

```sql
INDEX idx_course_lesson_progress_status
(customer_id, status);
```

```sql
INDEX idx_course_lesson_progress_last_accessed
(customer_id, last_accessed_at);
```

```sql
INDEX idx_course_lesson_progress_completed_at
(customer_id, completed_at);
```

---

## Unique Constraints

```sql
UNIQUE uniq_course_lesson_progress_enrollment_lesson
(customer_id, enrollment_id, version_lesson_id);
```

Đảm bảo một Enrollment cycle chỉ có một progress record cho một Version Lesson.

---

## Sample Data

```text
id = 1

customer_id = 1

enrollment_id = 1

course_progress_id = 1

product_id = 10

template_version_id = 30

student_id = 100

version_section_id = 101

version_lesson_id = 501

sort_order = 5

progress_percentage = 75.00

completed_activities = 3

total_activities = 4

required_activities_completed = 3

required_activities_total = 4

total_learning_seconds = 2400

first_accessed_at = 2026-06-24 09:10:00

last_accessed_at = 2026-06-24 10:20:00

started_at = 2026-06-24 09:10:00

completed_at = NULL

status = in_progress
```

---

## Progress Calculation Example

```text
required_activities_completed = 3

required_activities_total = 4

progress_percentage = 75.00
```

Nếu:

```text
required_activities_completed = required_activities_total
```

thì:

```text
status = completed

completed_at = current timestamp
```

---

## Final Statement

`core_course_lesson_progress` là bảng tổng hợp tiến độ học tập cấp Lesson.

Vai trò đúng:

```text
Product Progress

↓

Lesson Progress

↓

Activity Progress

↓

Track Logs
```

Bảng này giúp LF hiển thị nhanh:

```text
Lesson completed chưa?

Lesson học đến đâu?

Continue Learning nên mở Lesson nào?

Teacher/Admin thấy học viên kẹt ở Lesson nào?

AI biết học viên yếu hoặc bỏ dở ở phần nào?
```
