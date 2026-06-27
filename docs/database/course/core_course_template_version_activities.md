# core_course_template_version_activities

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

---

## Purpose

Lưu snapshot Activity thuộc một Course Template Version.

Version Activity là đơn vị learning content nhỏ nhất mà Activity Progress tham chiếu.

---

## Relationships

```text
Customer 1 → N Version Activities

Course Template Version 1 → N Version Activities

Version Lesson 1 → N Version Activities

Source Template Activity 1 → N Version Activities
```

---

## Business Rules

* Version Activity phải thuộc `customer_id`.
* Phải có cùng tenant với Template Version và Version Lesson.
* Snapshot từ `core_course_template_activities`.
* Immutable sau khi Template Version được publish.
* Progress tham chiếu `version_activity_id`.
* `source_template_activity_id` chỉ dùng trace/reporting.
* Media/Assessment/LiveClass reference phải được snapshot hoặc trỏ tới immutable/versioned asset.
* Completion rule và threshold được đóng băng theo Version.

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

### version_lesson_id

```text
BIGINT UNSIGNED NOT NULL
```

Liên kết `core_course_template_version_lessons.id`.

### source_template_activity_id

```text
BIGINT UNSIGNED NULL
```

Trace tới working Template Activity.

### activity_type

```text
VARCHAR(50) NOT NULL
```

### title_snapshot

```text
VARCHAR(255) NOT NULL
```

### description_snapshot

```text
TEXT NULL
```

### activity_ref_type

```text
VARCHAR(100) NULL
```

Snapshot loại Media/Assessment/LiveClass reference.

### activity_ref_id

```text
BIGINT UNSIGNED NULL
```

Phải trỏ tới immutable/versioned domain asset nếu nội dung nguồn có thể thay đổi.

### sort_order

```text
INT UNSIGNED NOT NULL DEFAULT 1
```

### is_required

```text
TINYINT(1) NOT NULL DEFAULT 1
```

### completion_rule

```text
VARCHAR(50) NOT NULL
```

### completion_threshold

```text
DECIMAL(8,2) NULL
```

### duration_seconds

```text
INT UNSIGNED NOT NULL DEFAULT 0
```

### unlock_rule_snapshot

```text
VARCHAR(50) NOT NULL DEFAULT 'none'
```

### unlock_after_version_activity_id

```text
BIGINT UNSIGNED NULL
```

Predecessor Version Activity trong cùng Version Lesson.

### metadata

```text
JSON NULL
```

Snapshot external URL, embed configuration hoặc domain-specific immutable context nếu cần.

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

(customer_id, version_lesson_id)

(customer_id, source_template_activity_id)

(customer_id, activity_type)

(customer_id, version_lesson_id, sort_order)
```

---

## Unique Constraints

```sql
UNIQUE(customer_id, version_lesson_id, sort_order)
```

---

## Sample Data

```text
id = 9001
customer_id = 1
template_version_id = 30
version_lesson_id = 501
source_template_activity_id = 20
activity_type = video
title_snapshot = Hangul Introduction
activity_ref_type = media_videos
activity_ref_id = 700
sort_order = 1
is_required = 1
completion_rule = watch_percent
completion_threshold = 80.00
duration_seconds = 900
```

---

## Final Statement

Version Activity là frozen learning unit cho Activity Progress, Notes, Bookmarks,
Tracking và AI Context.
