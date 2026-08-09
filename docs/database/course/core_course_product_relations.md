# Table: core_course_product_relations

Version: 1.1

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-08-09

Document Path: database/course/core_course_product_relations.md

---

# Purpose

Quản lý quan hệ giữa các Product.

Cho phép:

* Tặng kèm sản phẩm
* Sản phẩm liên quan
* Upsell
* Cross-sell
* Recommended Product

Không lưu trực tiếp trong:

```text
core_course_products
```

để tránh dữ liệu lặp và hỗ trợ quan hệ N-N.

---

# Relationships

core_course_products

1

↓

N

core_course_product_relations

---

core_course_products

1

↓

N

core_course_product_relations

(as related_product)

---

# Business Rules

* Mọi Product Relation phải thuộc customer_id.
* Một Product có thể có nhiều Product Relations.
* Một Product có thể xuất hiện trong nhiều Product Relations.
* Product Relation không ảnh hưởng đến Enrollment.
* Product Relation không ảnh hưởng đến Progress.
* Product Relation chỉ phục vụ Marketing và Sales.
* Product Relation dùng `active`/`inactive` vì đây là trạng thái hiển thị quan
  hệ thương mại, không phải trạng thái publish của Course Template Version.

---

# Relation Types

## gift

Mua Product A được tặng Product B.

Ví dụ:

```text
TOPIK Intermediate

↓

Tặng

TOPIK Mock Test
```

---

## related

Hiển thị sản phẩm liên quan.

Ví dụ:

```text
TOPIK Beginner

↓

Khóa học liên quan

TOPIK Intermediate
```

---

## upsell

Khóa học nâng cấp.

Ví dụ:

```text
TOPIK Beginner

↓

Nâng cấp

TOPIK Master Bundle
```

---

## cross_sell

Sản phẩm bổ sung.

Ví dụ:

```text
TOPIK Beginner

↓

Gợi ý thêm

Speaking Coaching
```

---

## recommended

Sản phẩm được hệ thống đề xuất.

Ví dụ:

```text
Business Korean

↓

Recommended

TOPIK Intermediate
```

---

# Fields

## Identity

### id

BIGINT UNSIGNED

Primary key.

### customer_id

BIGINT UNSIGNED

Tenant sở hữu dữ liệu.

### product_id

BIGINT UNSIGNED

Product nguồn.

### related_product_id

BIGINT UNSIGNED

Product đích.

---

## Relation

### relation_type

VARCHAR(50)

Giá trị:

* gift
* related
* upsell
* cross_sell
* recommended

---

### title_override

VARCHAR(255) NULL

Tiêu đề hiển thị riêng.

Ví dụ:

```text
Khóa học nên học tiếp theo
```

NULL = dùng mặc định.

---

### description_override

VARCHAR(500) NULL

Mô tả riêng.

NULL = dùng mô tả Product gốc.

---

## Display

### sort_order

INT DEFAULT 0

Thứ tự hiển thị.

### is_featured

TINYINT(1) DEFAULT 0

Ưu tiên hiển thị.

---

## Visibility

### starts_at

TIMESTAMP NULL

Bắt đầu hiển thị quan hệ.

NULL = luôn hiển thị.

### ends_at

TIMESTAMP NULL

Kết thúc hiển thị quan hệ.

NULL = không giới hạn.

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

# Business Logic

## Gift

```text
Product A

↓

gift

↓

Product B
```

Khi thanh toán Product A:

```text
Enrollment

↓

Product A

+

Product B
```

---

## Related

```text
Product Detail

↓

Related Products
```

Hiển thị danh sách relation_type = related.

---

## Upsell

```text
Checkout

↓

Bạn muốn nâng cấp?
```

Hiển thị relation_type = upsell.

---

## Cross Sell

```text
Checkout

↓

Khách hàng thường mua thêm
```

Hiển thị relation_type = cross_sell.

---

## Recommended

```text
Homepage

AI Recommendation

Product Detail
```

Hiển thị relation_type = recommended.

---

# Indexes

```sql
(customer_id)

(customer_id, product_id)

(customer_id, related_product_id)

(customer_id, relation_type)

(customer_id, status)

(customer_id, starts_at)

(customer_id, ends_at)

(customer_id, sort_order)
```

---

# Unique Constraints

```sql
UNIQUE(
    customer_id,
    product_id,
    related_product_id,
    relation_type
)
```

---

# Delete / Reference Rules

## Source Product

`product_id` tham chiếu Product nguồn trong cùng `customer_id`.

Không được tạo relation nếu Product nguồn không cùng tenant hoặc không tồn tại.

## Related Product

`related_product_id` tham chiếu Product đích trong cùng `customer_id`.

Không được tạo relation nếu Product đích không cùng tenant hoặc không tồn tại.

## Product Delete / Archive

Khi Product bị archive, Product Relations không bắt buộc bị xóa. Các relation
có thể được giữ để bảo toàn cấu hình marketing/historical intent và có thể được
ẩn bằng Product status hoặc Relation status.

Hard delete Product chỉ được phép khi không còn relation nào tham chiếu Product
đó ở cả hai vai trò:

```text
product_id
related_product_id
```

Nếu hard delete Product được phép theo `core_course_products`, related Product
Relations có thể bị xóa trong cùng transaction trước khi xóa Product.

## Foreign Key Recommendation

Các foreign key từ Product Relation tới Product nên dùng `RESTRICT`.

Không cascade delete Product từ Product Relation.

---

# Sample Data

## Gift Product

```text
id = 1

customer_id = 1

product_id = 10

related_product_id = 20

relation_type = gift

status = active
```

---

## Related Product

```text
id = 2

customer_id = 1

product_id = 10

related_product_id = 30

relation_type = related

status = active
```

---

## Upsell Product

```text
id = 3

customer_id = 1

product_id = 10

related_product_id = 40

relation_type = upsell

status = active
```

---

# Notes

Bảng này chỉ quản lý:

* Marketing
* Recommendation
* Product Linking
* Sales Strategy

Không quản lý:

* Course Structure
* Enrollment
* Learning Progress
* Assessment
* Tracking

Các dữ liệu đó thuộc:

```text
core_course_products
core_course_product_items
core_course_template_versions
core_course_enrollments
track_*
core_assessment_*
```

---

# Future Notes

Trong tương lai AI có thể tự sinh:

```text
relation_type = recommended
```

dựa trên:

```text
track_*
ai_*
```

mà không cần thay đổi schema.

---

End of Document
