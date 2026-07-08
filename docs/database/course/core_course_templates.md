# Table: core_course_templates

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

Related ADR:
[ADR-0013 — Course Template Version Duplicate to Draft](../../adr/ADR-0013-Course-Template-Version-Duplicate-to-Draft.md)

---

# Purpose

Lưu working blueprint/draft của khóa học để giáo viên chỉnh sửa.

Template là nguồn dữ liệu gốc duy nhất của Course Definition.

Khi publish, hệ thống tạo Course Template Version immutable.

Product tham chiếu Template Version, không tham chiếu working Template.

Template không chứa:

* Enrollment
* Progress
* Learning History

Template chỉ định nghĩa cấu trúc học tập.

---

# Relationships

core_course_categories

1

↓

N

core_course_templates

---

core_course_templates

1

↓

N

core_course_template_teachers

---

core_course_templates

1

↓

N

core_course_template_lessons

---

core_course_templates

1

↓

N

core_course_template_versions

---

# Business Rules

* Mọi template phải thuộc customer_id.
* Một template có thể có nhiều giáo viên.
* Template là working draft và có thể tiếp tục chỉnh sửa.
* Publish Template phải tạo một Template Version snapshot mới.
* Product chỉ được tham chiếu published Template Version.
* Sửa Template không làm thay đổi Version, Product, Enrollment hoặc Progress hiện có.
* Một Template chỉ có một working draft.
* Duplicate một Version về draft cập nhật chính Template hiện có và thay thế
  working Sections, Lessons và Activities trong một transaction.
* Sau duplicate, `status = draft` và `working_revision` tăng từ revision hiện
  tại; không reset về source revision của Version.
* Duplicate không thay đổi `last_version_published_at`, published Version,
  Product, Enrollment, Progress hoặc Completion.
* Template không chứa học viên.
* Không tồn tại các bảng Course Runtime legacy.

---

# Fields

## Identity

### id

BIGINT UNSIGNED

Primary key.

### customer_id

BIGINT UNSIGNED

Tenant sở hữu template.

### category_id

BIGINT UNSIGNED NULL

Danh mục khóa học.

---

## Basic Information

### title

VARCHAR(255)

Tên khóa học.

### slug

VARCHAR(255)

Slug URL.

### short_description

VARCHAR(500) NULL

Mô tả ngắn.

### description

LONGTEXT NULL

Mô tả chi tiết.

### publisher_name

VARCHAR(255) NULL

Đơn vị phát hành nội dung.

---

## Thumbnail / Trailer

### thumbnail_type

VARCHAR(50)

Giá trị:

* image
* video

### thumbnail_image

VARCHAR(500) NULL

Ảnh thumbnail.

### thumbnail_video_source

VARCHAR(50) NULL

Giá trị:

* youtube
* aws

### thumbnail_video_url

VARCHAR(1000) NULL

Youtube URL hoặc AWS URL.

### thumbnail_video_media_id

BIGINT UNSIGNED NULL

Liên kết media_files hoặc media_videos.

---

## Course Metadata

### difficulty_level

VARCHAR(50) NULL

Giá trị:

* beginner
* intermediate
* advanced

### estimated_duration_minutes

INT UNSIGNED DEFAULT 0

Tổng thời lượng học dự kiến.

### max_lessons

INT UNSIGNED NULL

Số lesson tối đa cho phép.

### lesson_count

INT UNSIGNED DEFAULT 0

Số lesson hiện tại.

---

## SEO

### meta_title

VARCHAR(255) NULL

### meta_description

VARCHAR(500) NULL

### meta_keywords

VARCHAR(500) NULL

---

## Business

### working_revision

INT UNSIGNED DEFAULT 1

Revision counter của working draft để hỗ trợ optimistic editing/audit.

Không phải published Template Version.

### status

VARCHAR(50)

Giá trị:

* draft
* active
* archived

---

## Audit

### created_by

BIGINT UNSIGNED NULL

User tạo.

### last_version_published_at

TIMESTAMP NULL

Read-model timestamp của Template Version được publish gần nhất.

Source of truth:

```text
MAX(core_course_template_versions.published_at)
```

### created_at

TIMESTAMP

### updated_at

TIMESTAMP

---

# Duplicate Version To Draft Rules

The canonical workflow is defined by
[ADR-0013](../../adr/ADR-0013-Course-Template-Version-Duplicate-to-Draft.md).

Template snapshot fields are copied back to their documented editable
counterparts. `status` becomes `draft`, `working_revision` increments by one,
and `updated_at` records the operation. `created_by`, `created_at` and
`last_version_published_at` remain unchanged.

If `source_category_id` no longer identifies a same-tenant Category,
`category_id` becomes `NULL`. Missing optional Media identifiers also become
`NULL`; supported snapshot URL/text fields remain available. Required
constraint failures roll back the operation without replacing the current
draft.

No `duplicated_from_version_id`, `duplicated_by` or `duplicated_at` columns are
introduced. Duplicate provenance belongs to the append-only tenant audit trail.

---

# Indexes

(customer_id)

(customer_id, category_id)

(customer_id, status)

(customer_id, created_by)

(customer_id, slug)

---

# Unique Constraints

UNIQUE(customer_id, slug)

---

# Sample Data

id = 1

customer_id = 1

category_id = 2

title = TOPIK Beginner 1

slug = topik-beginner-1

publisher_name = Visang

thumbnail_type = video

thumbnail_video_source = youtube

difficulty_level = beginner

estimated_duration_minutes = 2400

max_lessons = 40

lesson_count = 32

working_revision = 12

status = active

---

End of Document
