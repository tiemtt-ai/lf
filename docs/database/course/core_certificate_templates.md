# core_certificate_templates

## Purpose

Lưu mẫu chứng chỉ của Tenant.

Template chỉ chịu trách nhiệm định nghĩa:

```text
Layout

Branding

Certificate Content

Rendering Configuration

Verification Display Configuration
```

Template KHÔNG chứa:

```text
Completion Rules

Issue Rules

Approval Rules
```

Các rule này thuộc:

```text
core_certificate_template_products
```

---

## Relationships

```text
saas_customers

1

↓

N

core_certificate_templates
```

```text
core_certificate_templates

1

↓

N

core_certificate_template_products
```

```text
core_certificate_templates

1

↓

N

core_certificate_issued_certificates
```

---

## Business Rules

* Mọi Template phải thuộc `customer_id`.
* Một Tenant có thể có nhiều Template.
* Một Template có thể dùng cho nhiều Product.
* Product liên kết Template thông qua `core_certificate_template_products`.
* Mapping áp dụng trong context của published Course Template Version.
* Product không dùng direct `certificate_template_id` làm nguồn cấu hình.
* Completion, issuance và validity rules theo Product thuộc mapping table.
* Template chỉ lưu cấu hình.
* Certificate đã cấp phải snapshot dữ liệu từ Template.
* Thay đổi Template không được ảnh hưởng certificate đã cấp.
* Một Tenant chỉ nên có một Template mặc định.

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

Tenant sở hữu Template.

---

## Template Information

### template_code

```text
VARCHAR(100)
NOT NULL
```

Ví dụ:

```text
CERT-DEFAULT

CERT-TOPIK

CERT-CORPORATE
```

---

### name

```text
VARCHAR(255)
NOT NULL
```

Tên Template.

Ví dụ:

```text
TOPIK Completion Certificate
```

---

### description

```text
TEXT NULL
```

Mô tả.

---

### template_version

```text
INT UNSIGNED
NOT NULL
DEFAULT 1
```

Version Template.

Ví dụ:

```text
1

2

3
```

---

### language

```text
VARCHAR(10)
NOT NULL
DEFAULT 'en'
```

Ví dụ:

```text
en

vi

ko

ja
```

---

## Media Assets

### background_file_id

```text
BIGINT UNSIGNED
NULL
```

Background chứng chỉ.

Liên kết:

```text
media_files.id
```

---

### logo_file_id

```text
BIGINT UNSIGNED
NULL
```

Logo tổ chức.

---

### signature_file_id

```text
BIGINT UNSIGNED
NULL
```

Ảnh chữ ký.

---

### seal_file_id

```text
BIGINT UNSIGNED
NULL
```

Con dấu.

---

## Certificate Content

### title

```text
VARCHAR(255)
NOT NULL
```

Ví dụ:

```text
Certificate of Completion
```

---

### subtitle

```text
VARCHAR(500)
NULL
```

Ví dụ:

```text
This certificate is proudly presented to
```

---

### content_template

```text
TEXT
NOT NULL
```

Template nội dung.

Ví dụ:

```text
This certifies that {{student_name}}
has successfully completed
{{product_name}}
on {{completion_date}}.
```

---

## Numbering

### certificate_number_prefix

```text
VARCHAR(50)
NULL
```

Ví dụ:

```text
KAHA

TOPIK

LF
```

---

## Validity

### default_validity_days

```text
INT UNSIGNED
NULL
```

Ví dụ:

```text
365

730
```

NULL = không hết hạn.

Có thể override tại:

```text
core_certificate_template_products
```

---

## Rendering

### render_engine

```text
VARCHAR(50)
NOT NULL
DEFAULT 'html_pdf'
```

Allowed values:

```text
html_pdf

pdf

image
```

---

### layout_data

```text
JSON NULL
```

Ví dụ:

```json
{
  "orientation": "landscape",
  "page_size": "A4",
  "student_name_x": 50,
  "student_name_y": 180
}
```

---

## Verification

### qr_code_enabled

```text
TINYINT(1)
NOT NULL
DEFAULT 1
```

---

### verification_enabled

```text
TINYINT(1)
NOT NULL
DEFAULT 1
```

---

## Display

### is_default

```text
TINYINT(1)
NOT NULL
DEFAULT 0
```

Template mặc định của Tenant.

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
  "theme": "gold",
  "font_family": "Montserrat",
  "organization_name": "KAHA"
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
INDEX idx_certificate_templates_customer
(customer_id);
```

```sql
INDEX idx_certificate_templates_status
(customer_id, status);
```

```sql
INDEX idx_certificate_templates_default
(customer_id, is_default);
```

```sql
INDEX idx_certificate_templates_language
(customer_id, language);
```

```sql
INDEX idx_certificate_templates_sort
(customer_id, sort_order);
```

---

# Unique Constraints

```sql
UNIQUE uniq_certificate_template_code
(customer_id, template_code);
```

---

# Sample Data

```text
id = 1

customer_id = 1

template_code = CERT-TOPIK

name = TOPIK Completion Certificate

template_version = 1

language = en

title = Certificate of Completion

certificate_number_prefix = TOPIK

default_validity_days = NULL

render_engine = html_pdf

verification_enabled = 1

qr_code_enabled = 1

is_default = 1

status = active
```

---

# Final Statement

```text
Tenant

↓

Certificate Template

↓

Template Product Mapping

↓

Certificate Issuance

↓

Certificate Verification
```

`core_certificate_templates` chỉ chịu trách nhiệm định nghĩa cách chứng chỉ được hiển thị và render.

Toàn bộ rule cấp chứng chỉ theo Product sẽ được quản lý tại:

```text
core_certificate_template_products
```

Course Product không tham chiếu trực tiếp Certificate Template như nguồn cấu hình.

Certificate issuance rules phải biết Product và Template Version mà Enrollment đã học.
