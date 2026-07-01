# core_certificate_template_products

Version: 1.0

Status: Foundation Approved and Frozen

Last Updated: 2026-06

---

## Purpose

Mapping Certificate Template với Course Product và lưu rule cấp chứng chỉ theo từng Product.

Bảng này là source of truth cho:

* Certificate Template áp dụng cho Product.
* Completion requirement.
* Minimum score.
* Issuance mode.
* Certificate validity theo Product.
* Mapping được dùng cho automatic issuance.

Course Product không lưu trực tiếp Certificate Template hoặc issuance rules.

---

## Relationships

```text
Customer

1

↓

N

Certificate Template Product Mappings
```

```text
Certificate Template

1

↓

N

Certificate Template Product Mappings
```

```text
Course Product

1

↓

N

Certificate Template Product Mappings
```

```text
Course Template Version

1

↓

N

Certificate Template Product Mappings
```

Database relationships:

```text
saas_customers.id

↓

core_certificate_template_products.customer_id
```

```text
core_certificate_templates.id

↓

core_certificate_template_products.certificate_template_id
```

```text
core_course_products.id

↓

core_certificate_template_products.product_id
```

```text
core_course_template_versions.id

↓

core_certificate_template_products.template_version_id
```

---

## Business Rules

* Mọi mapping phải thuộc `customer_id`.
* Certificate Template và Course Product phải thuộc cùng `customer_id` với mapping.
* Mapping phải tham chiếu published `template_version_id` mà Product bán.
* Product, Template Version và mapping phải thuộc cùng `customer_id`.
* Foundation: một Product chỉ có một active Certificate Mapping.
* Phase sau có thể mở rộng một Product có nhiều active mappings.
* Cùng một Certificate Template không được map trùng vào cùng một Product.
* Chỉ một mapping active được dùng cho automatic issuance của một Product.
* Completion, score, issuance và validity rules theo Product phải đọc từ mapping này.
* Course Product không được dùng direct `certificate_template_id` làm nguồn cấu hình.
* Certificate Template chỉ định nghĩa layout/rendering; mapping định nghĩa rule theo Product.
* Issued Certificate phải lưu `certificate_template_product_id`.
* Issued Certificate phải snapshot rules tại thời điểm cấp để giữ historical consistency.
* Thay đổi mapping không được làm thay đổi certificate đã cấp.
* Mapping inactive/archived không được dùng cho certificate issuance mới.
* Mọi truy vấn mapping phải được giới hạn theo `customer_id`.

---

## Fields

### id

```text
BIGINT UNSIGNED
PRIMARY KEY
AUTO_INCREMENT
```

Khóa chính của mapping.

---

### customer_id

```text
BIGINT UNSIGNED
NOT NULL
```

Tenant sở hữu mapping.

Liên kết:

```text
saas_customers.id
```

---

### certificate_template_id

```text
BIGINT UNSIGNED
NOT NULL
```

Certificate Template được sử dụng.

Liên kết:

```text
core_certificate_templates.id
```

---

### product_id

```text
BIGINT UNSIGNED
NOT NULL
```

Course Product áp dụng Certificate Template và issuance rules.

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

Published Course Template Version mà certificate rules áp dụng.

Liên kết:

```text
core_course_template_versions.id
```

Mapping không áp dụng trực tiếp lên working Template draft.

---

### completion_required_percentage

```text
DECIMAL(5,2)
NOT NULL
DEFAULT 100.00
```

Phần trăm tiến độ tối thiểu để đủ điều kiện cấp chứng chỉ.

Allowed range:

```text
0.00 - 100.00
```

Source khi đánh giá eligibility:

```text
core_course_completions.final_progress_percentage
```

---

### minimum_score_percentage

```text
DECIMAL(5,2)
NULL
```

Phần trăm điểm tối thiểu để đủ điều kiện cấp chứng chỉ.

```text
NULL = không yêu cầu điểm tối thiểu

Allowed range = 0.00 - 100.00
```

Giá trị được chuẩn hóa:

```text
(final_score / max_score) * 100
```

Không dùng absolute score.

---

### issue_mode

```text
VARCHAR(50)
NOT NULL
DEFAULT 'automatic'
```

Allowed values:

```text
automatic

manual

approval_required
```

Ý nghĩa:

* `automatic`: hệ thống tự cấp khi đủ điều kiện.
* `manual`: Customer Admin/Teacher cấp thủ công.
* `approval_required`: đủ điều kiện nhưng cần phê duyệt trước khi cấp.

---

### validity_days

```text
INT UNSIGNED
NULL
```

Số ngày hiệu lực của certificate tính từ `issued_at`.

```text
NULL = dùng core_certificate_templates.default_validity_days
```

Nếu cả hai đều NULL, certificate không hết hạn.

---

### is_active

```text
TINYINT(1)
NOT NULL
DEFAULT 1
```

Read-model flag cho truy vấn nhanh mapping có thể dùng cho issuance.

Source of truth:

```text
status = active
```

Field này phải được cập nhật cùng transaction với `status` và không được chỉnh độc lập.

---

### status

```text
VARCHAR(50)
NOT NULL
DEFAULT 'active'
```

Lifecycle status của mapping.

Allowed values:

```text
draft

active

inactive

archived
```

---

### metadata

```text
JSON NULL
```

Dữ liệu mở rộng không thuộc core issuance rule.

Ví dụ:

```json
{
  "certificate_type": "completion",
  "approval_role": "customer_admin"
}
```

---

### created_at

```text
TIMESTAMP NULL
```

Thời điểm tạo mapping.

---

### updated_at

```text
TIMESTAMP NULL
```

Thời điểm cập nhật mapping.

---

## Indexes

```sql
INDEX idx_certificate_template_products_customer
(customer_id);
```

```sql
INDEX idx_certificate_template_products_template
(customer_id, certificate_template_id);
```

```sql
INDEX idx_certificate_template_products_product
(customer_id, product_id);
```

```sql
INDEX idx_certificate_template_products_template_version
(customer_id, template_version_id);
```

```sql
INDEX idx_certificate_template_products_status
(customer_id, status);
```

```sql
INDEX idx_certificate_template_products_active
(customer_id, product_id, is_active);
```

---

## Unique Constraints

```sql
UNIQUE uniq_certificate_template_products_mapping
(customer_id, certificate_template_id, product_id, template_version_id);
```

Đảm bảo cùng một Template không bị map trùng vào cùng một Product.

Rule “một active mapping cho mỗi Product” phải được thực thi bằng
transaction/service validation hoặc generated nullable active-slot.

Không dùng unique boolean đơn giản làm chặn nhiều inactive historical mappings.

---

## Sample Data

```text
id = 1

customer_id = 1

certificate_template_id = 3

product_id = 12

template_version_id = 30

completion_required_percentage = 100.00

minimum_score_percentage = 70.00

issue_mode = automatic

validity_days = 365

is_active = 1

status = active

metadata = {"certificate_type":"completion"}

created_at = 2026-06-27 10:00:00

updated_at = 2026-06-27 10:00:00
```

---

## Issuance Flow

```text
Course Completion

↓

Find active core_certificate_template_products by Product + Template Version

↓

Evaluate completion percentage and minimum score percentage

↓

Apply issue_mode and validity_days

↓

Snapshot Mapping / Product / Template / Completion rules

↓

Create core_certificate_issued_certificates
```

---

## Design Notes

`core_certificate_template_products` là nguồn cấu hình Certificate theo Product.

Rules được đánh giá trong context của Template Version mà Enrollment học.

`core_certificate_templates` chỉ định nghĩa layout, branding và rendering.

`core_course_products` không lưu direct Certificate Template relationship.

`core_certificate_issued_certificates` lưu mapping reference và snapshots để
certificate đã cấp không thay đổi khi mapping hiện tại được cập nhật.

---

## Final Statement

```text
Certificate Template

↓

Certificate Template Product Mapping

↓

Course Product Completion

↓

Issued Certificate
```
