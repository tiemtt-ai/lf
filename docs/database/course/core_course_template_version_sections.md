# core_course_template_version_sections

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

---

## Purpose

Lưu snapshot Section thuộc một Course Template Version.

Version Section không thay đổi khi working Template Section được chỉnh sửa.

---

## Relationships

```text
Customer 1 → N Version Sections

Course Template Version 1 → N Version Sections

Source Template Section 1 → N Version Sections

Parent Version Section 1 → N Child Version Sections
```

---

## Business Rules

* Version Section phải thuộc `customer_id`.
* Phải có cùng tenant với Template Version.
* Snapshot từ `core_course_template_sections`.
* Immutable sau khi Template Version được publish.
* Mỗi Template Version phải có ít nhất một Version Section.
* Course nhỏ nhất vẫn snapshot `Section 1`.
* `source_template_section_id` chỉ dùng trace/audit, không phải learning source.
* Parent phải thuộc cùng Template Version.
* `total_lessons` là snapshot/read-model lấy từ Version Lessons tại thời điểm publish.

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

Liên kết `core_course_template_versions.id`.

### source_template_section_id

```text
BIGINT UNSIGNED NULL
```

Trace tới `core_course_template_sections.id`.

### parent_version_section_id

```text
BIGINT UNSIGNED NULL
```

Parent Version Section trong cùng Version.

### code_snapshot

```text
VARCHAR(100) NULL
```

### title_snapshot

```text
VARCHAR(255) NOT NULL
```

### description_snapshot

```text
TEXT NULL
```

### sort_order

```text
INT UNSIGNED NOT NULL DEFAULT 1
```

### is_required

```text
TINYINT(1) NOT NULL DEFAULT 1
```

### unlock_rule_snapshot

```text
VARCHAR(50) NOT NULL DEFAULT 'immediate'
```

### estimated_duration_minutes

```text
INT UNSIGNED NULL
```

### total_lessons

```text
INT UNSIGNED NOT NULL DEFAULT 0
```

Published read-model snapshot từ Version Lessons; không recalculation sau publish.

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

Không cập nhật learning content sau khi parent Version published.

---

## Indexes

```sql
(customer_id)

(customer_id, template_version_id)

(customer_id, source_template_section_id)

(customer_id, parent_version_section_id)

(customer_id, template_version_id, sort_order)
```

---

## Unique Constraints

```sql
UNIQUE(customer_id, template_version_id, parent_version_section_id, sort_order)
```

Root sort uniqueness với NULL phải được publish service validate.

---

## Sample Data

```text
id = 101
customer_id = 1
template_version_id = 30
source_template_section_id = 1
parent_version_section_id = NULL
code_snapshot = M01
title_snapshot = Hangul Fundamentals
sort_order = 1
is_required = 1
total_lessons = 8
```

---

## Final Statement

Version Section là frozen organization layer của published Course content.
