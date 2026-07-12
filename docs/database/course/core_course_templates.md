# Table: core_course_templates

Version: 2.0

Status: Official Foundation

Last Updated: 2026-07-12

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
* Template là aggregate authoring nội bộ, được định danh bằng `id`; Template
  không có slug và route authoring phải resolve bằng ID.
* Course Product tiếp tục là aggregate public/catalog/SEO và giữ nguyên slug.
* Category và publisher bắt buộc nhưng không có default selection. Difficulty
  và video source là optional, không có default selection. Status mặc định
  `draft`. Placeholder UI không phải stored value hợp lệ.

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

BIGINT UNSIGNED

Danh mục khóa học bắt buộc; không có giá trị mặc định.

---

## Basic Information

### title

VARCHAR(255)

Tên khóa học.

### short_description

VARCHAR(500) NULL

Mô tả ngắn.

### description

LONGTEXT NULL

Mô tả chi tiết.

### publisher_name

VARCHAR(255)

Đơn vị phát hành nội dung bắt buộc; không có giá trị mặc định.

---

## Introduction Media

Ba introduction item độc lập và optional; image, video và document có thể tồn
tại đồng thời. Chỉ hai nguồn video loại trừ lẫn nhau.

### intro_image_media_file_id

BIGINT UNSIGNED NULL

FK → `media_files.id`. Một introduction image, tenant-owned.

### intro_video_source

VARCHAR(50) NULL

Giá trị `upload`, `embed`, hoặc `NULL`.

### intro_video_media_file_id

BIGINT UNSIGNED NULL

FK → `media_files.id`; required only when source is `upload`.

### intro_video_embed_url

VARCHAR(2048) NULL

Normalized HTTPS YouTube/Vimeo URL; raw iframe/HTML is forbidden.

### intro_video_provider

VARCHAR(50) NULL

Normalized provider `youtube` or `vimeo`; required only when source is
`embed`. Rendering derives a trusted embed URL from provider and normalized
URL.

### intro_document_media_file_id

BIGINT UNSIGNED NULL

FK → `media_files.id`. Private document access uses authorization and signed
URLs.

Video invariant:

| `intro_video_source` | Media ID | Embed URL/provider |
| --- | --- | --- |
| `NULL` | `NULL` | `NULL` / `NULL` |
| `upload` | required | `NULL` / `NULL` |
| `embed` | `NULL` | required / required |

---

## Course Metadata

### difficulty_level

VARCHAR(50) NULL

Giá trị:

* beginner
* intermediate
* advanced

Optional; không có default selection. Đây là learning-content metadata của
Template, không phải Product level.

### estimated_minutes_per_lesson

INT UNSIGNED NULL

Số phút dự kiến cho mỗi Lesson; là estimate, không phải duration aggregate.

### estimated_lesson_count

INT UNSIGNED NULL

Số Lesson dự kiến; không phải giới hạn authoring.

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

Required, default `draft`.

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
`NULL`. Required constraint failures roll back the operation without replacing
the current draft.

No `duplicated_from_version_id`, `duplicated_by` or `duplicated_at` columns are
introduced. Duplicate provenance belongs to the append-only tenant audit trail.

---

# Indexes

(customer_id)

(customer_id, category_id)

(customer_id, status)

(customer_id, created_by)

# Sample Data

id = 1

customer_id = 1

category_id = 2

title = TOPIK Beginner 1

publisher_name = Visang

intro_image_media_file_id = 650

intro_video_source = upload

intro_video_media_file_id = 700

intro_document_media_file_id = 710

difficulty_level = beginner

estimated_minutes_per_lesson = 75

estimated_lesson_count = 40

lesson_count = 32

working_revision = 12

status = active

---

End of Document
