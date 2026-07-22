# Table: core_course_learning_paths

## Purpose

Quản lý lộ trình học tập (Learning Path).

Learning Path là tập hợp các Course Products được sắp xếp theo thứ tự học tập khuyến nghị hoặc bắt buộc.

Ví dụ:

```text
TOPIK Learning Path

↓

TOPIK Beginner

↓

TOPIK Intermediate

↓

TOPIK Advanced
```

Learning Path KHÔNG phải Product.

Learning Path KHÔNG phải Bundle.

Learning Path là cấu trúc học thuật (Academic Structure).

---

## Relationships

```text
saas_customers

1

↓

N

core_course_learning_paths
```

```text
core_course_learning_paths

1

↓

N

core_course_learning_path_items
```

---

## Business Rules

* Mọi Learning Path phải thuộc một Tenant.
* Một Tenant có thể có nhiều Learning Paths.
* Một Product có thể thuộc nhiều Learning Paths.
* Learning Path không tạo Enrollment.
* Learning Path không bán trực tiếp.
* Learning Path dùng cho Curriculum, AI Recommendation và Certification Programs.
* Có thể Public hoặc Internal.

---

# Fields

## Identity

### id

```text
BIGINT UNSIGNED
PRIMARY KEY
AUTO_INCREMENT
```

---

### customer_id

```text
BIGINT UNSIGNED
NOT NULL
```

Tenant sở hữu.

---

## Basic Information

### path_code

```text
VARCHAR(100)
NOT NULL
```

Ví dụ:

```text
TOPIK-PATH

BUSINESS-KOREAN

AI-ENGINEER
```

---

### name

```text
VARCHAR(255)
NOT NULL
```

Ví dụ:

```text
TOPIK Full Learning Path
```

---

### description

```text
TEXT NULL
```

Mô tả lộ trình.

---

### thumbnail_file_id

```text
BIGINT UNSIGNED
NULL
```

Ảnh đại diện.

Liên kết:

```text
media_files.id
```

---

## Learning Configuration

### difficulty_level

```text
VARCHAR(50)
NULL
```

Ví dụ:

```text
beginner

intermediate

advanced

mixed
```

---

### estimated_duration_days

```text
INT UNSIGNED
NULL
```

Ví dụ:

```text
90

180

365
```

---

### certificate_available

```text
TINYINT(1)
NOT NULL
DEFAULT 0
```

Path có chương trình chứng nhận hay không.

---

## Display

### visibility

```text
VARCHAR(50)
NOT NULL
DEFAULT 'public'
```

Allowed values:

```text
public

private

internal
```

---

### sort_order

```text
INT UNSIGNED
NOT NULL
DEFAULT 1
```

---

## Status

### status

```text
VARCHAR(50)
NOT NULL
DEFAULT 'active'
```

Allowed values:

```text
draft

active

inactive

archived
```

---

## Metadata

### metadata

```text
JSON NULL
```

Ví dụ:

```json
{
  "target_audience": "Students",
  "language": "Korean"
}
```

---

## Audit

### created_by

```text
BIGINT UNSIGNED
NULL
```

---

### updated_by

```text
BIGINT UNSIGNED
NULL
```

---

### created_at

```text
TIMESTAMP NULL
```

---

### updated_at

```text
TIMESTAMP NULL
```

---

# Indexes

```sql
INDEX idx_learning_paths_customer
(customer_id);
```

```sql
INDEX idx_learning_paths_status
(customer_id, status);
```

```sql
INDEX idx_learning_paths_visibility
(customer_id, visibility);
```

```sql
INDEX idx_learning_paths_sort
(customer_id, sort_order);
```

---

# Unique Constraints

```sql
UNIQUE uniq_learning_path_code
(customer_id, path_code);
```

---

# Sample Data

```text
id = 1

customer_id = 1

path_code = TOPIK-PATH

name = TOPIK Full Learning Path

difficulty_level = mixed

estimated_duration_days = 365

certificate_available = 1

visibility = public

status = active
```

---

# Learning Path Structure

```text
TOPIK Full Learning Path

↓

TOPIK Beginner

↓

TOPIK Intermediate

↓

TOPIK Advanced
```

Lưu ý:

```text
Danh sách Product thuộc Path
không lưu trong bảng này.

Sẽ được quản lý bởi:

core_course_learning_path_items
```

---

# Final Statement

core_course_learning_paths là bảng định nghĩa lộ trình học tập cấp cao.

Vai trò:

```text
Learning Path

↓

Learning Path Items

↓

Course Products

↓

Student Progress

↓

Certification Program
```
