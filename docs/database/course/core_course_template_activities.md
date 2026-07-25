# Table: core_course_template_activities

Version: 1.1

Status: Official Foundation

Last Updated: 2026-06

---

# Purpose

Lưu các hoạt động học tập thuộc Template Lesson.

Template Activity là blueprint của nội dung học tập cụ thể trong bài học.

Một Lesson có thể có nhiều Activity như:

* Video
* Audio
* Document
* Quiz
* Assignment
* Live Class
* Text Content

Template Activity là working draft để giáo viên chỉnh sửa.

Khi publish Template, Activity được snapshot sang
`core_course_template_version_activities`; Student không học trực tiếp working Activity.

---

# Relationships

core_course_templates

1

↓

N

core_course_template_activities

---

core_course_template_lessons

1

↓

N

core_course_template_activities

---

Media / Assessment / LiveClass

1

↓

N

core_course_template_activities

---

# Business Rules

* Mọi Template Activity phải thuộc customer_id.
* Mọi Template Activity phải thuộc một Template.
* Mọi Template Activity phải thuộc một Template Lesson.
* Activity không lưu progress học tập.
* Activity không lưu tracking logs.
* Activity chỉ tham chiếu nội dung từ Domain tương ứng.
* Video, Audio, Document thuộc Media Domain.
* Quiz, Assignment thuộc Assessment Domain.
* LiveClass thuộc LiveClass Domain.
* Thời lượng Activity nên được tự động tính từ nội dung gốc nếu có.
* Thứ tự Activity trong Lesson được xác định bằng sort_order.
* Product/Enrollment/Progress chỉ sử dụng Version Activity.
* Sửa working Template Activity không ảnh hưởng Version Activity đã publish.
* Trong authoring tree, mỗi Activity là một row phẳng trực tiếp dưới Lesson.
* Activity row hiển thị icon, title text, View, Edit và Delete.
* Title là text thuần, không phải link. View và Edit là hai action riêng.
* Media Activity có active same-tenant Media usage mở signed URL trong tab mới.
* External-link Activity chỉ mở HTTP(S) URL hợp lệ trong tab mới.
* Activity khác dùng tenant-scoped readonly detail route; detail không update
  dữ liệu và không render editable form controls.
* Khi Media/external target không hợp lệ hoặc không tồn tại, View mở readonly
  Activity detail.
* Không hiển thị Activity status hoặc type/status badge trong authoring tree.
* Empty state chỉ hiển thị `Chưa có hoạt động.`.
* Mỗi Activity chọn `anytime`, hoặc một hay nhiều thời điểm
  `before_session`, `during_session`, `after_session`.
* `anytime` không được kết hợp với các thời điểm theo buổi học.
* Activity hiện có mặc định `anytime`.
* Không tham chiếu Live Class Activity; Cohort Session thực tế gắn Lesson là
  mốc thời gian ở runtime.
* Các lựa chọn này độc lập với sort order, completion, unlock và Progress.

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

Course Template chứa Activity.

### template_lesson_id

BIGINT UNSIGNED

Template Lesson chứa Activity.

---

## Basic Information

### title

VARCHAR(255)

Tên Activity.

### description

TEXT NULL

Mô tả Activity.

### sort_order

INT DEFAULT 0

Thứ tự hiển thị trong Lesson.

---

## Activity Type

### activity_type

VARCHAR(50)

Loại Activity.

Giá trị:

* video
* embedded_video
* audio
* document
* quiz
* live_class

---

## Type-specific Content

### external_video_url

VARCHAR(1000) NULL

URL ngoài hệ thống.

Dùng khi activity_type = embedded_video. Chỉ canonical HTTPS YouTube/Vimeo URL
được normalize bởi `TrustedVideoUrlService`.

### live_class_url

VARCHAR(1000) NULL

Dùng khi activity_type = live_class. Chỉ URL HTTPS.

### assessment_quiz_id

BIGINT UNSIGNED NULL

Provisional positive integer Assessment Quiz reference khi
`activity_type = quiz`. Có thể lưu ở working Activity, nhưng Template chứa Quiz
bị chặn publish cho đến khi Assessment Phase 2 định nghĩa ownership và
immutable binding.

---

## Learning Metadata

### available_anytime

TINYINT(1) DEFAULT 1

Có thể học bất kỳ lúc nào. Khi bằng `1`, ba field theo buổi học phải bằng `0`.

### available_before_session

TINYINT(1) DEFAULT 0

Có thể học trước Cohort Session thực tế gắn với Lesson.

### available_during_session

TINYINT(1) DEFAULT 0

Có thể học trong Cohort Session thực tế gắn với Lesson.

### available_after_session

TINYINT(1) DEFAULT 0

Có thể học sau Cohort Session thực tế gắn với Lesson.

Ít nhất một trong bốn field availability phải bằng `1`.

### duration_seconds

INT UNSIGNED DEFAULT 0

Thời lượng Activity.

System generated nếu Activity tham chiếu Media.

### estimated_duration_seconds

INT UNSIGNED NULL. Estimated learner completion time. UI whole minutes are
stored as `minutes * 60`; `NULL` means unknown. Lesson `duration_seconds` is the
sum of non-null Activity estimates. The current non-null Lesson column uses `0`
when every estimate is unknown.


### is_required

TINYINT(1) DEFAULT 1

Activity bắt buộc để hoàn thành Lesson.

### completion_rule

VARCHAR(50) DEFAULT 'view'

Giá trị:

* view
* watch_percent
* submit
* pass
* manual

`live_class` chỉ hỗ trợ `manual`.

Uploaded `video`, `audio` và `document` media được publish validation bằng
canonical `MediaService` type/MIME/extension policy. `media_files.status =
ready` là source of truth; publish không gọi storage HEAD để kiểm tra lại object
vật lý.

### completion_threshold

INT UNSIGNED NULL

Ngưỡng hoàn thành.

Khi có giá trị, threshold là integer từ `1` đến `100`.

Ví dụ:

* video watch_percent = 80
* quiz pass score = 70

---

## Preview / Access

### is_preview

TINYINT(1) DEFAULT 0

Cho phép xem thử Activity.

### unlock_rule

VARCHAR(50) DEFAULT 'none'

Giá trị:

* none
* previous_activity_completed

### unlock_after_activity_id

BIGINT UNSIGNED NULL

Activity cần hoàn thành trước.

### unlock_at

TIMESTAMP NULL

Legacy compatibility column. Current Activity authoring and publish readiness
require this field to remain `NULL`; date-based unlock belongs outside the
current Activity runtime contract.

---

## Audit

### created_by

BIGINT UNSIGNED NULL

User tạo Activity.

### created_at

TIMESTAMP

### updated_at

TIMESTAMP

---

# Indexes

(customer_id)

(customer_id, template_id)

(customer_id, template_lesson_id)

(customer_id, activity_type)

(customer_id, template_lesson_id, sort_order)

(customer_id, created_by)

---

# Unique Constraints

Không cần unique mặc định.

Một Lesson có thể chứa nhiều Activity cùng loại và cùng nội dung nếu nghiệp vụ cho phép.

---

# Sample Data

## Video Activity

id = 1

customer_id = 1

template_id = 10

template_lesson_id = 5

title = Video - Korean Alphabet Introduction

activity_type = video

external_video_url = null

duration_seconds = 1200

is_required = 1

completion_rule = watch_percent

completion_threshold = 80

sort_order = 1

---

## Quiz Activity

id = 2

customer_id = 1

template_id = 10

template_lesson_id = 5

title = Quiz - Korean Alphabet Practice

activity_type = quiz

assessment_quiz_id = 88

duration_seconds = 600

is_required = 1

completion_rule = pass

completion_threshold = 70

sort_order = 2

---

## Embedded Video Activity

id = 3

customer_id = 1

template_id = 10

template_lesson_id = 5

title = Extra Practice Video

activity_type = embedded_video

external_video_url = https://www.youtube.com/watch?v=example

duration_seconds = 300

is_required = 0

completion_rule = view

sort_order = 3

---

# Notes

Activity là nơi liên kết Course Domain với:

* Media Domain
* Assessment Domain
* LiveClass Domain
* External Content

Activity không lưu:

* Progress
* Watch Logs
* Attempts
* Scores

Các dữ liệu đó thuộc:

```text
track_*
core_assessment_*
```

---

End of Document
