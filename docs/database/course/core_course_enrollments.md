# Table: core_course_enrollments

## Purpose

Lưu quan hệ giữa học viên và Course Product.

Enrollment xác định học viên nào đã được cấp quyền học sản phẩm nào.

Trong LF hiện tại:

```text
Template = working draft

Template Version = published immutable learning content

Product = sản phẩm bán một Template Version

Enrollment = quyền học của Student với Product
```

---

## Relationships

```text
saas_customers

1

↓

N

core_course_enrollments
```

```text
users

1

↓

N

core_course_enrollments
```

```text
core_course_products

1

↓

N

core_course_enrollments
```

```text
core_course_template_versions

1

↓

N

core_course_enrollments
```

```text
core_course_enrollments

1

↓

1

core_course_progress
```

---

## Business Rules

* Mọi enrollment phải thuộc `customer_id`.
* Một enrollment luôn thuộc một `student_id`.
* Một enrollment luôn gắn với một `product_id`.
* Một enrollment luôn khóa một `version_id` tại thời điểm ghi danh.
* `version_id` được resolve từ active/current Product Item của Product tại
  thời điểm tạo Enrollment.
* User/Admin không được chọn thủ công `version_id`.
* Version phải là published Version mà Product bán tại thời điểm Enrollment.
* Enrollment và Version phải thuộc cùng `customer_id`.
* `version_id` frozen sau khi tạo Enrollment.
* `product_id` và `version_id` bất biến ở mọi Enrollment status, kể cả
  `pending`; activation không phải thời điểm khóa binding.
* Product đổi Version không được âm thầm thay đổi historical Enrollments.
* Enrollment không được tham chiếu editable Course Template làm learning source.
* Một Enrollment là một Learning Cycle.
* Student có thể hoàn thành Product rồi Enrollment lại để bắt đầu cycle mới.
* Re-enrollment được phép; không tạo unique vĩnh viễn theo Student/Product hoặc
  `(customer_id, student_id, product_id)`.
* Existing `pending`, `active` hoặc `suspended` Enrollment chặn cycle mới cho
  cùng tenant, Student và Product. Existing `completed`, `expired` hoặc
  `cancelled` cho phép tạo một Enrollment mới và không bị sửa/reset.
* Mọi creation source phải dùng chung backend creation policy, eligibility
  policy và Product Course Version resolver. Bulk giữ một atomic transaction
  cho toàn submission; shared pair core không tự commit.
* Resolver phải kiểm tra Product Item, Product, Template và Version cùng tenant,
  `product_item.template_id = version.template_id`, đúng một active Product Item
  và Version status `published`.
* Application writers phải whitelist update field và từ chối thay đổi binding.
  MySQL persistence guard chặn UPDATE thực sự làm đổi `product_id` hoặc
  `version_id`; guard này không thay thế validation khi INSERT.
* Mỗi Progress, Completion và Product-based Certificate phải tham chiếu `enrollment_id`.
* Enrollment có thể được tạo từ admin, teacher, self_registration, purchase,
  promotion, import hoặc api.
* Enrollment quyết định quyền truy cập học tập.
* Product quyết định nội dung, giá, thời hạn học và quyền truy cập.
* Enrollment mới snapshot `access_duration_days` và
  `review_duration_days` từ Product tại thời điểm tạo.
* Product đổi duration không được cập nhật duration snapshot hoặc timestamp
  của historical Enrollment.
* Enrollment legacy có duration snapshot `NULL` giữ nguyên timestamp và không
  được đổi `enrolled_at`.
* Enrollment không lưu tiến độ học chi tiết.
* Tiến độ học lưu ở nhóm Learning Progress.
* Nếu product có thời hạn học, enrollment cần lưu ngày bắt đầu và ngày hết hạn riêng.
* Sau khi enrollment hết hạn, dữ liệu học tập vẫn được giữ lại.

---

## Fields

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

Tenant sở hữu enrollment.

---

### product_id

```text
BIGINT UNSIGNED
NOT NULL
```

Course Product mà học viên được cấp quyền học.

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

Published Template Version được khóa tại thời điểm mua/ghi danh.

Liên kết:

```text
core_course_template_versions.id
```

Đây là source of truth của learning content cho Enrollment.

`version_id` luôn tham chiếu immutable published Course Version và không bao giờ
tham chiếu editable Course Template.

`version_id` được resolve từ active/current Product Item của Product tại thời
điểm tạo Enrollment.

User/Admin không được chọn thủ công `version_id`.

`version_id` không thay đổi sau khi Enrollment được tạo.

---

### student_id

```text
BIGINT UNSIGNED
NOT NULL
```

Học viên được cấp quyền học.

Liên kết:

```text
users.id
```

User này phải có role:

```text
student
```

---

### source

```text
VARCHAR(50)
NOT NULL
DEFAULT 'admin'
```

Nguồn tạo enrollment.

Allowed values:

```text
admin

teacher

self_registration

purchase

promotion

import

api
```

---

### source_id

```text
BIGINT UNSIGNED
NULL
```

ID tham chiếu đến nguồn tạo enrollment.

Ví dụ:

```text
order_id

payment_id

campaign_id

api_request_id

import_batch_id
```

NULL = không có nguồn cụ thể.

---

### enrolled_by

```text
BIGINT UNSIGNED
NULL
```

User tạo enrollment.

Ví dụ:

```text
customer_admin

teacher

system
```

NULL = hệ thống tự tạo.

---

### enrolled_at

```text
TIMESTAMP
NOT NULL
```

Thời điểm học viên được cấp quyền học.

---

### access_duration_days

```text
INTEGER UNSIGNED
NULL
```

Snapshot thời hạn truy cập của Product tại thời điểm tạo Enrollment.

Enrollment mới bắt buộc có giá trị lớn hơn `0`. `NULL` chỉ dùng để tương thích
Enrollment legacy và không được backfill từ Product hiện tại.

Giá trị không thay đổi khi Product thay đổi. Khi một workflow được phép đổi
`enrolled_at`, hệ thống dùng snapshot này để tính lại `access_ends_at`.

---

### review_duration_days

```text
INTEGER UNSIGNED
NULL
```

Snapshot thời hạn ôn tập của Product tại thời điểm tạo Enrollment.

Giá trị hợp lệ là `NULL`, `0` hoặc số nguyên dương theo Product contract.
`NULL` trên Enrollment legacy không được suy diễn từ Product hiện tại.

Giá trị không thay đổi khi Product thay đổi. Khi một workflow được phép đổi
`enrolled_at`, hệ thống dùng snapshot này để tính lại chuỗi thời gian ôn tập.

---

### access_starts_at

```text
TIMESTAMP NULL
```

Thời điểm bắt đầu được học.

NULL = được học ngay sau khi enrolled.

---

### access_ends_at

```text
TIMESTAMP NULL
```

Thời điểm hết quyền học.

NULL = không giới hạn.

Ví dụ:

```text
Product access_duration_days = 90

enrolled_at = 2026-06-24

access_ends_at = 2026-09-22
```

---

### review_starts_at

```text
TIMESTAMP NULL
```

Thời điểm bắt đầu giai đoạn ôn tập.

Thường là sau khi hết thời gian học chính.

---

### review_ends_at

```text
TIMESTAMP NULL
```

Thời điểm kết thúc giai đoạn ôn tập.

NULL = không có giới hạn ôn tập hoặc không áp dụng.

---

### status

```text
VARCHAR(50)
NOT NULL
DEFAULT 'active'
```

Trạng thái enrollment.

Allowed values:

```text
pending

active

suspended

completed

expired

cancelled
```

---

### notes

```text
TEXT
NULL
```

Optional internal tenant/admin/teacher notes for operational comments about the
Enrollment learning cycle.

`notes` is not public-facing by default.

`metadata` must not be used as a replacement for Enrollment notes.

---

### completed_at

```text
TIMESTAMP NULL
```

Thời điểm học viên hoàn thành product.

---

### cancelled_at

```text
TIMESTAMP NULL
```

Thời điểm enrollment bị hủy.

---

### expired_at

```text
TIMESTAMP NULL
```

Thời điểm enrollment hết hạn.

Có thể trùng với `access_ends_at`, nhưng tách riêng để lưu trạng thái thực tế.

---

### metadata

```text
JSON NULL
```

System/internal dữ liệu mở rộng.

Ví dụ:

```json
{
  "campaign_code": "SUMMER2026",
  "original_price": 1500000,
  "paid_amount": 1200000
}
```

Metadata không được expose như raw user-editable notes field.

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

## Indexes

```sql
INDEX idx_course_enrollments_customer
(customer_id);
```

```sql
INDEX idx_course_enrollments_product
(customer_id, product_id);
```

```sql
INDEX idx_course_enrollments_version
(customer_id, version_id);
```

```sql
INDEX idx_course_enrollments_student
(customer_id, student_id);
```

```sql
INDEX idx_course_enrollments_student_status
(customer_id, student_id, status);
```

```sql
INDEX idx_course_enrollments_product_status
(customer_id, product_id, status);
```

```sql
INDEX idx_course_enrollments_access_ends
(customer_id, access_ends_at);
```

```sql
INDEX idx_course_enrollments_enrolled_at
(customer_id, enrolled_at);
```

---

## Unique Constraints

Không tạo unique:

```text
(customer_id, student_id, product_id)
```

Mỗi row là một Learning Cycle độc lập.

Nếu chỉ cho một active cycle tại một thời điểm, rule đó được kiểm soát bằng
transaction/service logic theo `status = active`, không chặn historical cycles.

---

## Sample Data

```text
id = 1

customer_id = 1

product_id = 10

version_id = 30

student_id = 100

source = purchase

source_id = 5001

enrolled_by = NULL

enrolled_at = 2026-06-24 09:00:00

access_duration_days = 90

review_duration_days = 30

access_starts_at = 2026-06-24 09:00:00

access_ends_at = 2026-09-22 23:59:59

review_starts_at = 2026-09-23 00:00:00

review_ends_at = 2026-10-22 23:59:59

status = active

notes = Assigned by admin for corporate batch

completed_at = NULL
```

---

## Final Statement

`core_course_enrollments` là bảng cấp quyền học tập cho Student.

Nó không phải bảng bán hàng và không phải bảng tiến độ.

Vai trò đúng là:

```text
Student

↓

Enrollment

↓

Product

↓

Template Version Content

↓

Learning Progress
```

Enrollment trả lời câu hỏi:

```text
Học viên nào được học Product nào, từ khi nào, đến khi nào, và trạng thái quyền học hiện tại là gì?
```
