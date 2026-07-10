# Table: core_course_template_activities

Version: 1.0

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

* text
* video
* audio
* document
* quiz
* assignment
* liveclass
* external_link

---

## Activity Reference

### activity_ref_type

VARCHAR(100) NULL

Domain/table mà Activity tham chiếu.

Ví dụ:

* media_videos
* media_audios
* media_documents
* core_assessment_quizzes
* core_liveclass_rooms

### activity_ref_id

BIGINT UNSIGNED NULL

ID của dữ liệu được tham chiếu.

---

## External Content

### external_url

VARCHAR(1000) NULL

URL ngoài hệ thống.

Dùng khi activity_type = external_link.

### embed_code

LONGTEXT NULL

Mã nhúng ngoài hệ thống nếu cần.

Ví dụ:

* YouTube embed
* Vimeo embed
* Third-party learning tool embed

---

## Learning Metadata

### duration_seconds

INT UNSIGNED DEFAULT 0

Thời lượng Activity.

System generated nếu Activity tham chiếu Media.

Có thể manual cho:

* text
* external_link
* assignment

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
* attend
* manual

### completion_threshold

INT UNSIGNED NULL

Ngưỡng hoàn thành.

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
* previous_lesson_completed
* date_based

### unlock_after_activity_id

BIGINT UNSIGNED NULL

Activity cần hoàn thành trước.

### unlock_at

TIMESTAMP NULL

Ngày mở khóa nếu unlock_rule = date_based.

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

(customer_id, activity_ref_type, activity_ref_id)

(customer_id, template_lesson_id, sort_order)

(customer_id, status)

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

activity_ref_type = media_videos

activity_ref_id = 101

duration_seconds = 1200

is_required = 1

completion_rule = watch_percent

completion_threshold = 80

sort_order = 1

status = active

---

## Quiz Activity

id = 2

customer_id = 1

template_id = 10

template_lesson_id = 5

title = Quiz - Korean Alphabet Practice

activity_type = quiz

activity_ref_type = core_assessment_quizzes

activity_ref_id = 88

duration_seconds = 600

is_required = 1

completion_rule = pass

completion_threshold = 70

sort_order = 2

status = active

---

## External Link Activity

id = 3

customer_id = 1

template_id = 10

template_lesson_id = 5

title = Extra Practice Link

activity_type = external_link

external_url = https://example.com/practice

duration_seconds = 300

is_required = 0

completion_rule = view

sort_order = 3

status = active

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
