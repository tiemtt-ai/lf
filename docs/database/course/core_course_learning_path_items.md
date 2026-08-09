# Table: core_course_learning_path_items

Document Path: database/course/core_course_learning_path_items.md

## Purpose

Lưu danh sách Course Products nằm trong một Learning Path.

Bảng này xác định:

```text
Product nào thuộc Learning Path?

Thứ tự học là gì?

Product này bắt buộc hay tùy chọn?

Có cần hoàn thành Product trước đó không?

Có mở khóa Product tiếp theo không?
```

Ví dụ:

```text
TOPIK Full Learning Path

↓

1. TOPIK Beginner

2. TOPIK Intermediate

3. TOPIK Advanced
```

---

## Relationships

```text
saas_customers

1

↓

N

core_course_learning_path_items
```

```text
core_course_learning_paths

1

↓

N

core_course_learning_path_items
```

```text
core_course_products

1

↓

N

core_course_learning_path_items
```

---

## Business Rules

* Mọi Learning Path Item phải thuộc `customer_id`.
* Mỗi item phải thuộc một `learning_path_id`.
* Mỗi item phải trỏ tới một `product_id`.
* Một Learning Path có thể có nhiều Products.
* Một Product có thể xuất hiện trong nhiều Learning Paths.
* Trong cùng một Learning Path, một Product không nên bị trùng.
* Thứ tự học được xác định bằng `sort_order`.
* Item có thể là bắt buộc hoặc tùy chọn.
* Item có thể bị khóa cho đến khi học viên hoàn thành item trước đó.
* Bảng này không tạo Enrollment.
* Enrollment vẫn được quản lý bởi `core_course_enrollments`.

---

# Fields

## Identity Fields

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

Tenant sở hữu dữ liệu.

---

## Relationship Fields

### learning_path_id

```text
BIGINT UNSIGNED
NOT NULL
```

Learning Path chứa item này.

Liên kết:

```text
core_course_learning_paths.id
```

---

### product_id

```text
BIGINT UNSIGNED
NOT NULL
```

Course Product thuộc Learning Path.

Liên kết:

```text
core_course_products.id
```

---

### prerequisite_product_id

```text
BIGINT UNSIGNED
NULL
```

Product cần hoàn thành trước khi học Product này.

Liên kết:

```text
core_course_products.id
```

Ví dụ:

```text
TOPIK Intermediate

requires

TOPIK Beginner
```

NULL = không yêu cầu Product trước đó.

---

## Learning Rules

### item_type

```text
VARCHAR(50)
NOT NULL
DEFAULT 'course_product'
```

Loại item trong Path.

Allowed values:

```text
course_product

assessment

certificate_checkpoint

external_activity
```

V1 chủ yếu dùng:

```text
course_product
```

---

### is_required

```text
TINYINT(1)
NOT NULL
DEFAULT 1
```

```text
1 = bắt buộc

0 = tùy chọn
```

---

### unlock_rule

```text
VARCHAR(50)
NOT NULL
DEFAULT 'always_available'
```

Quy tắc mở khóa item.

Allowed values:

```text
always_available

after_previous_completed

after_prerequisite_completed

manual_unlock
```

---

### completion_required

```text
TINYINT(1)
NOT NULL
DEFAULT 1
```

Item này có cần hoàn thành để tính hoàn thành Learning Path không.

---

## Display

### title_override

```text
VARCHAR(255)
NULL
```

Tên hiển thị riêng trong Learning Path.

NULL = dùng tên Product.

---

### description_override

```text
TEXT NULL
```

Mô tả riêng trong Learning Path.

NULL = dùng mô tả Product.

---

### sort_order

```text
INT UNSIGNED
NOT NULL
DEFAULT 1
```

Thứ tự học trong Learning Path.

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
  "recommended_weeks": 8,
  "ai_recommendation_weight": 0.9
}
```

---

## Audit Fields

### created_by

```text
BIGINT UNSIGNED
NULL
```

Người tạo item.

---

### updated_by

```text
BIGINT UNSIGNED
NULL
```

Người cập nhật cuối.

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

# Indexes

```sql
INDEX idx_learning_path_items_customer
(customer_id);
```

```sql
INDEX idx_learning_path_items_path
(customer_id, learning_path_id);
```

```sql
INDEX idx_learning_path_items_product
(customer_id, product_id);
```

```sql
INDEX idx_learning_path_items_prerequisite
(customer_id, prerequisite_product_id);
```

```sql
INDEX idx_learning_path_items_sort
(customer_id, learning_path_id, sort_order);
```

```sql
INDEX idx_learning_path_items_status
(customer_id, status);
```

---

# Unique Constraints

```sql
UNIQUE uniq_learning_path_item_product
(customer_id, learning_path_id, product_id);
```

Một Product không nên bị thêm trùng trong cùng một Learning Path.

---

# Sample Data

```text
id = 1

customer_id = 1

learning_path_id = 1

product_id = 10

prerequisite_product_id = NULL

item_type = course_product

is_required = 1

unlock_rule = always_available

completion_required = 1

title_override = TOPIK Beginner

sort_order = 1

status = active
```

```text
id = 2

customer_id = 1

learning_path_id = 1

product_id = 11

prerequisite_product_id = 10

item_type = course_product

is_required = 1

unlock_rule = after_prerequisite_completed

completion_required = 1

title_override = TOPIK Intermediate

sort_order = 2

status = active
```

---

# Learning Path Flow

```text
Student views Learning Path

↓

System loads Learning Path Items

↓

Check Enrollment / Completion

↓

Show Available / Locked / Completed

↓

Recommend Next Product
```

---

# AI Use Cases

AI có thể dùng bảng này để:

```text
Recommend next course

Detect missing prerequisite

Build learning roadmap

Suggest review before advanced course

Personalize learning path
```

---

# Final Statement

`core_course_learning_path_items` là bảng map giữa Learning Path và Course Product.

Vai trò:

```text
Learning Path

↓

Learning Path Items

↓

Course Products

↓

Prerequisite / Unlock Rules

↓

AI Recommendation
```

Bảng này giúp LearnForge quản lý lộ trình học tập không chỉ như một bundle thương mại, mà như một cấu trúc học thuật có thứ tự, điều kiện và khả năng cá nhân hóa.
