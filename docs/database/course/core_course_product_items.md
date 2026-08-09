# Table: core_course_product_items

Version: 1.2

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-08-09

Document Path: database/course/core_course_product_items.md

---

# Purpose

Liên kết nội dung học tập với Product.

Cho phép một Product chứa:

* Một published Course Version
* Nhiều published Course Versions

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
* Một published Course Version có thể xuất hiện trong nhiều Product.
* Mọi Product Item phải tham chiếu một published Course Version bằng `version_id`.
* `version_id` tham chiếu `core_course_template_versions.id`.
* Product Item phải có cùng `customer_id` với Product và published Course Version.
* Một Product Bundle có thể chứa nhiều published Course Versions.
* Product không sao chép Course Version hoặc cấu trúc snapshot.
* Product Item liên kết Course Product với published Course Version.
* Product Item chỉ tạo quyền truy cập tới published Course Version thông qua Product/Enrollment.
* Enrollment derive `version_id` từ active Product Item `version_id` tại thời điểm tạo Enrollment.
* Không được tham chiếu working Template draft làm learning content.
* Product Item không lưu giá bán.
* Giá bán thuộc Product.
* Product Item chỉ được quản lý thông qua Product workflow, không phải một
  module Product độc lập trong Phase 3 first-table implementation.
* Product Item dùng `active`/`inactive` vì đây là trạng thái liên kết nội dung
  trong Product runtime, không phải trạng thái publish của Course Template Version.

---

# Product Examples

## Single Course

```text
Product

TOPIK Beginner

↓

Items

Version #30
```

---

## Bundle

```text
Product

TOPIK Master Bundle

↓

Items

Version #30

Version #31

Version #32
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

### version_id

```text
BIGINT UNSIGNED
NOT NULL
```

Published Course Version được Product tham chiếu.

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
```

Lý do:

* Product Item không còn polymorphic.
* Product chỉ tham chiếu Course Template Version.
* `version_id` là foreign key learning content chính thức.

Không tạo migration hoặc implementation mới dựa trên `item_type/item_id`.

`template_id` **re-introduced** bởi ADR-0014 / Product v2 phase-one contract
(2026-07-15) làm Draft selection provenance — không phải learning runtime
authority; `version_id` vẫn là runtime content authority. Xem
[core_course_product_items_v2.md](core_course_product_items_v2.md).

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

Enrollment tạo mới phải lấy `version_id` từ active Product Item tại thời điểm
cấp quyền học. User/Admin không chọn `version_id` độc lập khi tạo Enrollment.

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

(customer_id, version_id)

(customer_id, status)

(customer_id, product_id, sort_order)

(customer_id, template_id)  -- idx_ccpi_v2_template

---

# Unique Constraints

UNIQUE(customer_id, product_id, version_id)

UNIQUE(customer_id, product_id, template_id)  -- uk_ccpi_v2_product_template (Product v2 phase-one; xem core_course_product_items_v2.md)

---

# Delete / Reference Rules

## Parent Product

Product Item phụ thuộc vào:

```text
core_course_products.id
```

Product Item phải có cùng `customer_id` với Product.

Khi Product bị archive, Product Items không tự động bị xóa. Product Items có
thể giữ nguyên để bảo toàn cấu hình commerce/content của Product.

Nếu Product chưa từng có tham chiếu lịch sử và hard delete được phép theo
`core_course_products`, Product Items có thể bị xóa trong cùng transaction trước
khi xóa Product.

## Published Course Version

Product Item tham chiếu:

```text
core_course_template_versions.id
```

Published Course Template Version là immutable historical content. Không được
cascade delete hoặc update Version từ Product Item.

Không được xóa Template Version nếu còn Product Item đang tham chiếu.

## Foreign Key Recommendation

Các foreign key từ Product Item tới Product và Template Version nên dùng
`RESTRICT`.

Không dùng cascade delete từ Product Item sang Product, Template Version,
Version Section, Version Lesson hoặc Version Activity.

---

# Sample Data

## Single Course Product

id = 1

customer_id = 1

product_id = 5

version_id = 30

sort_order = 1

status = active

---

## Bundle Product

id = 2

customer_id = 1

product_id = 6

version_id = 30

sort_order = 1

status = active

---

id = 3

customer_id = 1

product_id = 6

version_id = 31

sort_order = 2

status = active

---

id = 4

customer_id = 1

product_id = 6

version_id = 32

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

Product Item luôn liên kết Course Product với published Course Version và
không tạo bản sao nội dung.

---

# Changelog

## v1.2 (2026-07-22)

* "Legacy / Removed Fields": loại `template_id` khỏi danh sách field bị cấm.
  `template_id` đã được re-introduced bởi ADR-0014 / Product v2 phase-one
  contract (2026-07-15). Xem
  [core_course_product_items_v2.md](core_course_product_items_v2.md).

---

End of Document
