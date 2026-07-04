# core_course_activity_progress

## Purpose

Lưu tiến độ học tập của học viên theo từng Activity trong một Course Product.

Activity là đơn vị học tập nhỏ nhất trong Course Domain.

Ví dụ:

```text
Video

Audio

Document

Quiz

Assignment

Live Session

External Link
```

Bảng này trả lời câu hỏi:

```text
Học viên đã hoàn thành Activity này chưa, hoàn thành bao nhiêu phần trăm, và trạng thái hiện tại là gì?
```

---

## Relationships

```text
saas_customers

1

↓

N

core_course_activity_progress
```

```text
core_course_enrollments

1

↓

N

core_course_activity_progress
```

```text
core_course_progress

1

↓

N

core_course_activity_progress
```

```text
core_course_lesson_progress

1

↓

N

core_course_activity_progress
```

```text
core_course_products

1

↓

N

core_course_activity_progress
```

```text
users

1

↓

N

core_course_activity_progress
```

```text
core_course_template_version_activities

1

↓

N

core_course_activity_progress
```

---

## Business Rules

* Mọi activity progress phải thuộc `customer_id`.
* Một activity progress thuộc một `student_id`.
* Một activity progress thuộc một `product_id`.
* Một activity progress nên gắn với một `enrollment_id`.
* Một activity progress luôn thuộc `version_id` của Enrollment.
* Progress tham chiếu Version Lesson/Activity, không tham chiếu working Template content.
* Progress được tách theo Learning Cycle bằng `enrollment_id`.
* Một activity progress nên gắn với một `course_progress_id`.
* Một activity progress nên gắn với một `lesson_progress_id`.
* Một Enrollment cycle chỉ có một progress record cho một Version Activity.
* `product_id` và `version_id` phải được lấy từ Enrollment/Course Progress/Lesson Progress context, không nhận độc lập từ user input.
* Activity progress là dữ liệu tổng hợp cấp activity.
* Log chi tiết vẫn nằm ở `track_*`.
* Activity có thể được hoàn thành theo nhiều rule khác nhau:

  * xem video đủ %
  * mở tài liệu
  * hoàn thành quiz
  * nộp assignment
  * tham gia live class
  * admin/teacher đánh dấu hoàn thành
* Nếu activity là assessment, kết quả chi tiết nằm ở `core_assessment_*`.
* Nếu activity là media, hành vi chi tiết nằm ở `media_*` và `track_*`.
* Sau khi enrollment hết hạn, activity progress vẫn được giữ lại.

---

## Runtime Invariants

```text
core_course_activity_progress.product_id
=
core_course_enrollments.product_id
```

```text
core_course_activity_progress.version_id
=
core_course_enrollments.version_id
```

```text
core_course_activity_progress.product_id
=
core_course_progress.product_id
```

```text
core_course_activity_progress.version_id
=
core_course_progress.version_id
```

`core_course_activity_progress.lesson_progress_id` phải thuộc cùng context:

```text
enrollment_id

product_id

version_id

version_lesson_id
```

Runtime code phải tạo Activity Progress từ Enrollment/Course Progress/Lesson Progress context.

Không nhận `product_id` hoặc `version_id` độc lập từ user input khi tạo hoặc cập nhật runtime progress.

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

### lesson_progress_id

```text
BIGINT UNSIGNED
NOT NULL
```

Progress cấp Lesson.

Liên kết:

```text
core_course_lesson_progress.id
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

### version_id

```text
BIGINT UNSIGNED
NOT NULL
```

Published Course Version đã khóa trên Enrollment.

Liên kết:

```text
core_course_template_versions.id
```

Giá trị này phải khớp với:

```text
core_course_enrollments.version_id

core_course_progress.version_id

core_course_lesson_progress.version_id
```

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
NULL
```

Version Section tùy chọn chứa Activity.

Liên kết logic tới:

```text
core_course_template_version_sections.id
```

NULL khi Version Lesson chứa Activity thuộc trực tiếp Template Version.

---

### version_lesson_id

```text
BIGINT UNSIGNED
NOT NULL
```

Version Lesson chứa Activity.

Liên kết:

```text
core_course_template_version_lessons.id
```

---

### version_activity_id

```text
BIGINT UNSIGNED
NOT NULL
```

Version Activity immutable được học viên học.

Liên kết:

```text
core_course_template_version_activities.id
```

---

### activity_type

```text
VARCHAR(50)
NOT NULL
```

Loại activity.

Allowed values:

```text
video

audio

document

quiz

assignment

live_session

external_link

text

html

scorm

survey
```

Giá trị này snapshot từ `core_course_template_version_activities`.

---

### sort_order

```text
INT UNSIGNED
NOT NULL
DEFAULT 1
```

Thứ tự Activity trong Lesson tại thời điểm học.

---

### is_required

```text
TINYINT(1)
NOT NULL
DEFAULT 1
```

Activity có bắt buộc để hoàn thành Lesson/Product hay không.

```text
1 = Required

0 = Optional
```

---

### progress_percentage

```text
DECIMAL(5,2)
NOT NULL
DEFAULT 0.00
```

Tiến độ hoàn thành Activity.

Ví dụ:

```text
0.00

30.00

80.00

100.00
```

---

### completion_rule

```text
VARCHAR(50)
NOT NULL
DEFAULT 'manual'
```

Quy tắc hoàn thành Activity.

Allowed values:

```text
manual

viewed

watch_percentage

listen_percentage

submitted

passed

attended

completed
```

Ví dụ:

```text
watch_percentage
```

Video phải xem đủ ngưỡng.

---

### completion_threshold

```text
DECIMAL(5,2)
NULL
```

Ngưỡng hoàn thành.

Ví dụ:

```text
30.00

80.00

100.00
```

Nếu activity là video, có thể dùng:

```text
30.00
```

nghĩa là xem từ 30% trở lên thì hoàn thành.

---

### score

```text
DECIMAL(8,2)
NULL
```

Điểm số đạt được nếu Activity có chấm điểm.

Ví dụ:

```text
85.00
```

NULL = không áp dụng.

---

### max_score

```text
DECIMAL(8,2)
NULL
```

Điểm tối đa.

Ví dụ:

```text
100.00
```

---

### passed

```text
TINYINT(1)
NULL
```

Kết quả đạt/không đạt.

```text
1 = Passed

0 = Failed

NULL = Not applicable / Not graded
```

---

### attempt_count

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Số lần học viên thực hiện Activity.

Ví dụ:

```text
Số lần mở quiz

Số lần nộp assignment

Số lần xem video
```

---

### total_learning_seconds

```text
BIGINT UNSIGNED
NOT NULL
DEFAULT 0
```

Tổng thời gian học Activity.

Có thể tổng hợp từ:

```text
track_events

track_activity_summaries
```

---

### last_position_seconds

```text
BIGINT UNSIGNED
NULL
```

Vị trí học gần nhất.

Dùng nhiều cho:

```text
video

audio

replay
```

Ví dụ:

```text
420
```

nghĩa là học viên đang dừng ở giây thứ 420.

---

### first_accessed_at

```text
TIMESTAMP NULL
```

Thời điểm học viên mở Activity lần đầu.

---

### last_accessed_at

```text
TIMESTAMP NULL
```

Thời điểm học viên truy cập Activity gần nhất.

---

### started_at

```text
TIMESTAMP NULL
```

Thời điểm Activity chuyển sang `in_progress`.

---

### completed_at

```text
TIMESTAMP NULL
```

Thời điểm Activity hoàn thành.

---

### submitted_at

```text
TIMESTAMP NULL
```

Thời điểm học viên nộp bài.

Dùng cho:

```text
quiz

assignment

survey
```

---

### graded_at

```text
TIMESTAMP NULL
```

Thời điểm Activity được chấm điểm.

Dùng cho:

```text
quiz

assignment

speaking

writing
```

---

### status

```text
VARCHAR(50)
NOT NULL
DEFAULT 'not_started'
```

Trạng thái Activity Progress.

Allowed values:

```text
locked

not_started

in_progress

submitted

completed

passed

failed

skipped
```

---

### recalculated_at

```text
TIMESTAMP NULL
```

Thời điểm hệ thống tính lại activity progress lần cuối.

---

### metadata

```text
JSON NULL
```

Dữ liệu mở rộng.

Ví dụ:

```json
{
  "activity_title_snapshot": "Lesson 1 Video",
  "media_file_id": 15,
  "quiz_id": 3,
  "provider": "s3",
  "progress_source": "video_watch"
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
INDEX idx_course_activity_progress_customer
(customer_id);
```

```sql
INDEX idx_course_activity_progress_enrollment
(customer_id, enrollment_id);
```

```sql
INDEX idx_course_activity_progress_course_progress
(customer_id, course_progress_id);
```

```sql
INDEX idx_course_activity_progress_lesson_progress
(customer_id, lesson_progress_id);
```

```sql
INDEX idx_course_activity_progress_product
(customer_id, product_id);
```

```sql
INDEX idx_course_activity_progress_version
(customer_id, version_id);
```

```sql
INDEX idx_course_activity_progress_student
(customer_id, student_id);
```

```sql
INDEX idx_course_activity_progress_lesson
(customer_id, version_lesson_id);
```

```sql
INDEX idx_course_activity_progress_activity
(customer_id, version_activity_id);
```

```sql
INDEX idx_course_activity_progress_type
(customer_id, activity_type);
```

```sql
INDEX idx_course_activity_progress_status
(customer_id, status);
```

```sql
INDEX idx_course_activity_progress_last_accessed
(customer_id, last_accessed_at);
```

```sql
INDEX idx_course_activity_progress_completed_at
(customer_id, completed_at);
```

---

## Unique Constraints

```sql
UNIQUE uniq_course_activity_progress_enrollment_activity
(customer_id, enrollment_id, version_activity_id);
```

Đảm bảo một Enrollment cycle chỉ có một progress record cho một Version Activity.

Rule này cho phép re-enrollment vì mỗi Enrollment mới là một Learning Cycle mới.

---

## Sample Data

### Video Activity

```text
id = 1

customer_id = 1

enrollment_id = 1

course_progress_id = 1

lesson_progress_id = 1

product_id = 10

version_id = 30

student_id = 100

version_section_id = 101

version_lesson_id = 501

version_activity_id = 9001

activity_type = video

sort_order = 1

is_required = 1

progress_percentage = 85.00

completion_rule = watch_percentage

completion_threshold = 80.00

score = NULL

max_score = NULL

passed = NULL

attempt_count = 3

total_learning_seconds = 1800

last_position_seconds = 1200

first_accessed_at = 2026-06-24 09:10:00

last_accessed_at = 2026-06-24 10:20:00

started_at = 2026-06-24 09:10:00

completed_at = 2026-06-24 10:20:00

status = completed
```

---

### Quiz Activity

```text
id = 2

customer_id = 1

enrollment_id = 1

course_progress_id = 1

lesson_progress_id = 1

product_id = 10

version_id = 30

student_id = 100

version_section_id = 101

version_lesson_id = 501

version_activity_id = 9002

activity_type = quiz

sort_order = 2

is_required = 1

progress_percentage = 100.00

completion_rule = passed

completion_threshold = NULL

score = 85.00

max_score = 100.00

passed = 1

attempt_count = 1

total_learning_seconds = 900

submitted_at = 2026-06-24 10:40:00

graded_at = 2026-06-24 10:40:00

completed_at = 2026-06-24 10:40:00

status = passed
```

---

## Progress Calculation Example

### Video

```text
progress_percentage = 85.00

completion_threshold = 80.00

↓

status = completed
```

---

### Quiz

```text
score = 85.00

passed = 1

↓

status = passed

progress_percentage = 100.00
```

---

### Document

```text
completion_rule = viewed

first_accessed_at is not null

↓

status = completed
```

---

## Final Statement

`core_course_activity_progress` là bảng tiến độ chi tiết nhất trong Course Domain.

Vai trò đúng:

```text
Course Progress

↓

Lesson Progress

↓

Activity Progress

↓

Track Logs
```

Bảng này giúp LF biết chính xác:

```text
Học viên đã xem video chưa?

Đã xem bao nhiêu phần trăm?

Đã mở tài liệu chưa?

Đã làm quiz chưa?

Đã pass chưa?

Đã hoàn thành activity bắt buộc chưa?
```

Từ đó hệ thống có thể tính:

```text
Lesson Progress

Course Progress

Certificate Eligibility

Teacher Report

AI Learning Insight
```
