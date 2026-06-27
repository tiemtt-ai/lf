# core_course_template_versions

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

---

## Purpose

Lưu published snapshot immutable của một Course Template.

Course Template là working draft. Course Template Version là nội dung đã publish
để Product bán và Enrollment học ổn định theo thời gian.

---

## Relationships

```text
Customer 1 → N Course Template Versions

Course Template 1 → N Course Template Versions

Course Template Version 1 → N Course Product Items

Course Template Version 1 → N Enrollments

Course Template Version 1 → N Version Sections
```

---

## Business Rules

* Mọi Version phải thuộc `customer_id`.
* Version phải có cùng `customer_id` với Course Template nguồn.
* `version_number` tăng dần trong phạm vi Template.
* Chỉ Version có `status = published` mới được gắn vào Product.
* Published Version là immutable.
* Nếu cần thay đổi nội dung đã publish, phải tạo Version mới.
* `deprecated` Version không được dùng cho Product sale mới.
* Enrollment hiện có trên deprecated Version vẫn tiếp tục học.
* `archived` Version không được Product mới sử dụng và chỉ được giữ để lưu trữ/audit.
* Enrollment luôn giữ Version cũ bất kể Version chuyển deprecated/archived.
* Product, Enrollment, Progress, Certificate và AI Context dùng Version, không dùng draft content.
* Archive Version không làm thay đổi Enrollment hoặc certificate đang tham chiếu Version đó.
* Publish phải snapshot Template, Sections, Lessons và Activities trong cùng transaction/workflow.

---

## Fields

### id

```text
BIGINT UNSIGNED
PRIMARY KEY
AUTO_INCREMENT
```

### customer_id

```text
BIGINT UNSIGNED
NOT NULL
```

Tenant sở hữu Version.

### template_id

```text
BIGINT UNSIGNED
NOT NULL
```

Working Course Template nguồn.

Liên kết `core_course_templates.id`.

### version_number

```text
INT UNSIGNED
NOT NULL
```

Số version tăng dần trong phạm vi `template_id`.

### version_code

```text
VARCHAR(100)
NOT NULL
```

Mã version duy nhất trong tenant, ví dụ `TOPIK-BEGINNER-V3`.

### title_snapshot

```text
VARCHAR(255)
NOT NULL
```

Snapshot từ `core_course_templates.title`.

### description_snapshot

```text
LONGTEXT NULL
```

Snapshot mô tả Course Template tại thời điểm publish.

### status

```text
VARCHAR(50)
NOT NULL
DEFAULT 'draft_snapshot'
```

Allowed values:

```text
draft_snapshot
published
deprecated
archived
```

Meaning:

* `draft_snapshot`: đang tạo và validate snapshot.
* `published`: có thể gắn vào Product cho sale mới.
* `deprecated`: không bán mới; Enrollment hiện có vẫn tiếp tục học.
* `archived`: không còn Product mới sử dụng; chỉ lưu trữ/audit.

### published_at

```text
TIMESTAMP NULL
```

Thời điểm Version trở thành immutable published snapshot.

### published_by

```text
BIGINT UNSIGNED NULL
```

User publish Version.

### source_template_updated_at

```text
TIMESTAMP NULL
```

Snapshot `updated_at` của working Template để audit nguồn publish.

### metadata

```text
JSON NULL
```

Snapshot metadata mở rộng và thông tin publish pipeline.

### created_at

```text
TIMESTAMP NULL
```

### updated_at

```text
TIMESTAMP NULL
```

Chỉ được thay đổi trước publish hoặc cho metadata vận hành không làm đổi learning content.

---

## Indexes

```sql
(customer_id)

(customer_id, template_id)

(customer_id, template_id, version_number)

(customer_id, status)

(customer_id, published_at)
```

---

## Unique Constraints

```sql
UNIQUE(customer_id, template_id, version_number)

UNIQUE(customer_id, version_code)
```

---

## Sample Data

```text
id = 30
customer_id = 1
template_id = 10
version_number = 3
version_code = TOPIK-BEGINNER-V3
title_snapshot = TOPIK Beginner
status = published
published_at = 2026-06-27 09:00:00
published_by = 5
source_template_updated_at = 2026-06-27 08:45:00
```

---

## Final Statement

`core_course_template_versions` là source of truth của published Course content
được Product, Enrollment và Learning Progress sử dụng.
