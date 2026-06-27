# core_course_progress

## Purpose

Lưu tiến độ tổng hợp của học viên trên một Course Product.

Bảng này trả lời câu hỏi:

```text
Học viên học Product này đến đâu rồi?
```

`core_course_progress` là bảng summary để hiển thị nhanh cho:

```text
Student

Teacher

Customer Admin

Reports

AI
```

Bảng này không lưu log hành vi chi tiết.

Log chi tiết sẽ thuộc nhóm:

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

core_course_progress
```

```text
users

1

↓

N

core_course_progress
```

```text
core_course_products

1

↓

N

core_course_progress
```

```text
core_course_enrollments

1

↓

1

core_course_progress
```

```text
core_course_template_versions

1

↓

N

core_course_progress
```

```text
core_course_progress

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

core_course_activity_progress
```

---

## Business Rules

* Mọi progress phải thuộc `customer_id`.
* Một progress luôn thuộc một `student_id`.
* Một progress luôn thuộc một `product_id`.
* Một progress nên gắn với một `enrollment_id`.
* Một progress luôn thuộc `template_version_id` đã khóa trên Enrollment.
* Progress không tham chiếu working Template Lesson/Activity.
* Mỗi Course Progress thuộc đúng một Learning Cycle qua `enrollment_id`.
* Một enrollment chỉ có một course progress.
* Bảng này lưu kết quả tổng hợp, không lưu từng event học tập.
* Tiến độ lesson chi tiết lưu ở `core_course_lesson_progress`.
* Tiến độ activity chi tiết lưu ở `core_course_activity_progress`.
* Hành vi chi tiết như play, pause, seek, watch duration lưu ở `track_*`.
* Progress có thể được tính lại từ lesson/activity progress.
* Product hoàn thành khi đạt completion rule của Product.
* Sau khi enrollment hết hạn, progress vẫn được giữ lại.

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

Tenant sở hữu dữ liệu progress.

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

### product_id

```text
BIGINT UNSIGNED
NOT NULL
```

Product đang được học.

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

Published Template Version của Enrollment.

Liên kết:

```text
core_course_template_versions.id
```

Phải khớp với `core_course_enrollments.template_version_id`.

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

### progress_percentage

```text
DECIMAL(5,2)
NOT NULL
DEFAULT 0.00
```

Tiến độ tổng của học viên.

Ví dụ:

```text
0.00
25.50
80.00
100.00
```

---

### completed_lessons

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Số lesson đã hoàn thành.

---

### total_lessons

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Tổng số lesson cần học trong Product.

Giá trị này có thể được snapshot từ Product / Product Items để báo cáo ổn định.

---

### completed_activities

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Số activity đã hoàn thành.

---

### total_activities

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Tổng số activity cần học trong Product.

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

Tổng số activity bắt buộc.

---

### assessment_completed

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Số assessment bắt buộc đã hoàn thành.

---

### assessment_total

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Tổng số assessment bắt buộc.

---

### total_learning_seconds

```text
BIGINT UNSIGNED
NOT NULL
DEFAULT 0
```

Tổng thời gian học đã ghi nhận.

Dữ liệu này có thể được tổng hợp từ:

```text
track_video_watch_logs

track_audio_listen_logs

track_document_view_logs

track_user_activity_logs
```

---

### last_version_activity_id

```text
BIGINT UNSIGNED
NULL
```

Version Activity gần nhất học viên đã học.

Liên kết logic tới:

```text
core_course_template_version_activities.id
```

---

### last_version_lesson_id

```text
BIGINT UNSIGNED
NULL
```

Version Lesson gần nhất học viên đã học.

Liên kết logic tới:

```text
core_course_template_version_lessons.id
```

---

### last_accessed_at

```text
TIMESTAMP NULL
```

Thời điểm học viên truy cập Product lần gần nhất.

---

### started_at

```text
TIMESTAMP NULL
```

Thời điểm học viên bắt đầu học Product lần đầu.

NULL = chưa bắt đầu học.

---

### completed_at

```text
TIMESTAMP NULL
```

Thời điểm học viên hoàn thành Product.

NULL = chưa hoàn thành.

---

### status

```text
VARCHAR(50)
NOT NULL
DEFAULT 'not_started'
```

Trạng thái tiến độ.

Allowed values:

```text
not_started

in_progress

completed

expired

paused
```

---

### recalculated_at

```text
TIMESTAMP NULL
```

Thời điểm hệ thống tính lại progress lần cuối.

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
  "progress_source": "activity_progress",
  "certificate_eligible": true
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
INDEX idx_course_progress_customer
(customer_id);
```

```sql
INDEX idx_course_progress_enrollment
(customer_id, enrollment_id);
```

```sql
INDEX idx_course_progress_product
(customer_id, product_id);
```

```sql
INDEX idx_course_progress_template_version
(customer_id, template_version_id);
```

```sql
INDEX idx_course_progress_student
(customer_id, student_id);
```

```sql
INDEX idx_course_progress_student_status
(customer_id, student_id, status);
```

```sql
INDEX idx_course_progress_product_status
(customer_id, product_id, status);
```

```sql
INDEX idx_course_progress_last_accessed
(customer_id, last_accessed_at);
```

```sql
INDEX idx_course_progress_completed_at
(customer_id, completed_at);
```

---

## Unique Constraints

```sql
UNIQUE uniq_course_progress_enrollment
(customer_id, enrollment_id);
```

Không unique theo Student/Product vì Student có thể Enrollment lại cùng Product.

---

## Sample Data

```text
id = 1

customer_id = 1

enrollment_id = 1

product_id = 10

template_version_id = 30

student_id = 100

progress_percentage = 35.00

completed_lessons = 7

total_lessons = 20

completed_activities = 21

total_activities = 60

required_activities_completed = 18

required_activities_total = 50

assessment_completed = 1

assessment_total = 3

total_learning_seconds = 12600

last_version_lesson_id = 501

last_version_activity_id = 9001

last_accessed_at = 2026-06-24 10:30:00

started_at = 2026-06-24 09:15:00

completed_at = NULL

status = in_progress
```

---

## Progress Calculation Example

```text
required_activities_completed = 18

required_activities_total = 50

progress_percentage = 36.00
```

Hoặc theo lesson:

```text
completed_lessons = 7

total_lessons = 20

progress_percentage = 35.00
```

Cách tính thực tế phụ thuộc `completion_rule` của Product.

---

## Final Statement

`core_course_progress` là bảng tổng hợp tiến độ học tập cấp Product.

Nó giúp hệ thống hiển thị nhanh:

```text
My Courses

Continue Learning

Admin Reports

Teacher Reports

Certificate Eligibility

AI Learning Insights
```

Vai trò đúng:

```text
Enrollment

↓

Course Progress Summary

↓

Lesson Progress

↓

Activity Progress

↓

Track Logs

↓

AI Insights
```
