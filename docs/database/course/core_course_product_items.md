# Table: core_course_product_items

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

---

# Purpose

Liên kết nội dung học tập với Product.

Cho phép một Product chứa:

* Một Course Template Version
* Nhiều Course Template Versions

Bảng này giúp Product hoạt động như:

* Single Course
* Bundle

mà không cần thay đổi kiến trúc.

---

# Relationships

core_course_products

1

↓

N

core_course_product_items

---

core_course_template_versions

1

↓

N

core_course_product_items

---

# Business Rules

* Mọi Product Item phải thuộc customer_id.
* Mọi Product Item phải thuộc một Product.
* Một Product có thể có nhiều Product Items.
* Một Template Version có thể xuất hiện trong nhiều Product.
* Mọi Product Item phải tham chiếu một published Course Template Version.
* Product Item phải có cùng `customer_id` với Product và Template Version.
* Một Product Bundle có thể chứa nhiều Template Version.
* Product không sao chép Template Version hoặc cấu trúc snapshot.
* Product Item chỉ tạo quyền truy cập tới Template Version thông qua Product/Enrollment.
* Không được tham chiếu working Template draft làm learning content.
* Product Item không lưu giá bán.
* Giá bán thuộc Product.

---

# Product Examples

## Single Course

```text
Product

TOPIK Beginner

↓

Items

Template Version #30
```

---

## Bundle

```text
Product

TOPIK Master Bundle

↓

Items

Template Version #30

Template Version #31

Template Version #32
```

---

# Fields

## Identity

### id

BIGINT UNSIGNED

Primary key.

### customer_id

```text
BIGINT UNSIGNED
NOT NULL
```

Tenant sở hữu dữ liệu.

### product_id

```text
BIGINT UNSIGNED
NOT NULL
```

Product chứa Item.

Liên kết:

```text
core_course_products.id
```

---

### template_version_id

```text
BIGINT UNSIGNED
NOT NULL
```

Published Course Template Version được Product tham chiếu.

Liên kết:

```text
core_course_template_versions.id
```

Product Item không sao chép dữ liệu Version.

---

## Legacy / Removed Fields

Các field sau đã bị loại khỏi thiết kế chính thức:

```text
item_type

item_id

template_id
```

Lý do:

* Product Item không còn polymorphic.
* Product chỉ tham chiếu Course Template Version.
* `template_version_id` là foreign key learning content chính thức.

Không tạo migration hoặc implementation mới dựa trên `item_type/item_id`.

---

## Display

### title_override

VARCHAR(255) NULL

Tên hiển thị riêng trong Product.

NULL = sử dụng tên gốc.

### short_description_override

VARCHAR(500) NULL

Mô tả riêng trong Product.

NULL = sử dụng mô tả gốc.

### sort_order

INT DEFAULT 0

Thứ tự hiển thị trong Product.

---

## Enrollment

### is_required

TINYINT(1) DEFAULT 1

Bắt buộc hoàn thành Item này hay không.

Ví dụ:

```text
Bundle 10 khóa

8 khóa bắt buộc

2 khóa tùy chọn
```

---

## Business

### status

VARCHAR(50)

Giá trị:

* active
* inactive

---

## Audit

### created_by

BIGINT UNSIGNED NULL

Người tạo.

### created_at

TIMESTAMP

### updated_at

TIMESTAMP

---

# Indexes

(customer_id)

(customer_id, product_id)

(customer_id, template_version_id)

(customer_id, status)

(customer_id, product_id, sort_order)

---

# Unique Constraints

UNIQUE(customer_id, product_id, template_version_id)

---

# Sample Data

## Single Course Product

id = 1

customer_id = 1

product_id = 5

template_version_id = 30

sort_order = 1

status = active

---

## Bundle Product

id = 2

customer_id = 1

product_id = 6

template_version_id = 30

sort_order = 1

status = active

---

id = 3

customer_id = 1

product_id = 6

template_version_id = 31

sort_order = 2

status = active

---

id = 4

customer_id = 1

product_id = 6

template_version_id = 32

sort_order = 3

status = active

---

# Notes

Product Item là lớp liên kết giữa:

```text
Commerce

↓

Learning Content
```

Bảng này cho phép LearnForge mở rộng từ:

* Single Course
* Bundle Course

Product luôn tham chiếu published Course Template Version và không tạo bản sao nội dung.

---

End of Document
