# core_course_template_version_lessons

Version: 1.1

Status: Official Foundation

Last Updated: 2026-07

---

## Purpose

Lưu snapshot Lesson thuộc một Course Template Version.

Learning Progress phải tham chiếu Version Lesson, không tham chiếu working Template Lesson.

---

## Relationships

```text
Customer 1 → N Version Lessons

Course Template Version 1 → N Version Lessons

Version Section 0..1 → N Version Lessons

Source Template Lesson 1 → N Version Lessons

Version Lesson 1 → N Version Activities
```

---

## Business Rules

* Version Lesson phải thuộc `customer_id`.
* `version_section_id = NULL` nghĩa là Version Lesson thuộc trực tiếp Template Version.
* Nếu có `version_section_id`, Version Section phải cùng Template Version và `customer_id`.
* Snapshot từ `core_course_template_lessons`.
* Immutable sau khi Template Version được publish.
* `source_template_lesson_id` chỉ dùng trace/reporting.
* Publish phải giữ nguyên cấu trúc flat hoặc sectioned của working Lesson và
  không tạo hidden/default Version Section.
* Progress, Notes và Bookmarks dùng `version_lesson_id`.
* Duration và Activity count là published read-model snapshots.

---

## Fields

### id

```text
BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT
```

### customer_id

```text
BIGINT UNSIGNED NOT NULL
```

### template_version_id

```text
BIGINT UNSIGNED NOT NULL
```

### version_section_id

```text
BIGINT UNSIGNED NULL
```

Liên kết tùy chọn tới `core_course_template_version_sections.id`. NULL nghĩa là
Lesson thuộc trực tiếp Template Version.

### source_template_lesson_id

```text
BIGINT UNSIGNED NULL
```

Trace tới working Template Lesson.

### title_snapshot

```text
VARCHAR(255) NOT NULL
```

### description_snapshot

```text
TEXT NULL
```

### lesson_type

```text
VARCHAR(50) NULL
```

### sort_order

```text
INT UNSIGNED NOT NULL DEFAULT 1
```

### duration_seconds

```text
INT UNSIGNED NOT NULL DEFAULT 0
```

Published aggregate snapshot từ Version Activities/Media.

### activity_count

```text
INT UNSIGNED NOT NULL DEFAULT 0
```

Published count snapshot từ Version Activities.

### unlock_rule_snapshot

```text
VARCHAR(50) NOT NULL DEFAULT 'none'
```

### unlock_after_version_lesson_id

```text
BIGINT UNSIGNED NULL
```

Predecessor Version Lesson trong cùng Template Version.

### unlock_at_snapshot

```text
TIMESTAMP NULL
```

### metadata

```text
JSON NULL
```

### created_at

```text
TIMESTAMP NULL
```

### updated_at

```text
TIMESTAMP NULL
```

---

## Indexes

```sql
(customer_id)

(customer_id, template_version_id)

(customer_id, version_section_id)

(customer_id, source_template_lesson_id)

(customer_id, template_version_id, version_section_id, sort_order)
```

---

## Unique Constraints

```sql
UNIQUE(customer_id, template_version_id, version_section_id, sort_order)
```

Với `version_section_id = NULL`, application/service layer phải bảo đảm
`sort_order` không trùng vì MySQL cho phép nhiều giá trị NULL trong unique index.

---

## Sample Data

```text
id = 501
customer_id = 1
template_version_id = 30
version_section_id = 101
source_template_lesson_id = 5
title_snapshot = Korean Alphabet
sort_order = 1
duration_seconds = 1800
activity_count = 4
```

---

## Final Statement

Version Lesson là learning source ổn định cho Enrollment và Lesson Progress.
