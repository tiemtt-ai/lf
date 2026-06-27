# core_course_cohorts

## Purpose

Lưu nhóm học / lớp học / batch triển khai thực tế của một Course Product.

Cohort dùng cho các mô hình:

```text
Batch-based Learning

Live Online

Hybrid

Offline Class

Corporate Training

Private Group

Mentoring
```

Ví dụ:

```text
Product:
TOPIK Beginner

Cohorts:
- TOPIK Beginner July 2026
- TOPIK Beginner August 2026
- Samsung Corporate TOPIK 2026
- TOPIK 1:1 Private Coaching
```

---

## Relationships

```text
saas_customers
1
↓
N
core_course_cohorts
```

```text
core_course_products
1
↓
N
core_course_cohorts
```

```text
users
1
↓
N
core_course_cohorts
```

Teacher chính phụ trách cohort.

```text
core_course_cohorts
1
↓
N
core_course_cohort_students
N
↓
1
core_course_enrollments
```

---

## Business Rules

* Mọi Cohort phải thuộc `customer_id`.
* Một Cohort luôn thuộc một `product_id`.
* Một Product có thể có nhiều Cohort.
* Cohort đại diện cho một nhóm học viên học cùng Product.
* Cohort có thể có teacher chính.
* Cohort có thể có lịch học riêng.
* Cohort có thể có ngày bắt đầu/kết thúc riêng.
* Cohort có thể dùng cho lớp batch, corporate, private group, mentoring hoặc custom.
* `cohort_type` mô tả mục đích / mô hình tổ chức lớp.
* `delivery_mode` mô tả hình thức học.
* Student phải có enrollment trước hoặc cùng lúc khi được đưa vào Cohort.
* Một Enrollment chỉ có một active Cohort Membership.
* Chuyển lớp cập nhật record `core_course_cohort_students` hiện tại.
* Không lưu Cohort Membership History.
* Cohort không thay thế Product.
* Cohort không thay thế Enrollment.
* Cohort chỉ nhóm các Enrollment/Student lại để quản lý lớp học thực tế.

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

Tenant sở hữu Cohort.

---

### product_id

```text
BIGINT UNSIGNED
NOT NULL
```

Product mà Cohort triển khai.

Liên kết:

```text
core_course_products.id
```

---

### teacher_id

```text
BIGINT UNSIGNED
NULL
```

Giáo viên chính của Cohort.

Liên kết:

```text
users.id
```

User này thường có role:

```text
teacher
```

---

### cohort_code

```text
VARCHAR(100)
NOT NULL
```

Mã Cohort để vận hành và tra cứu.

Ví dụ:

```text
TOPIK-BEG-JUL-2026

TOPIK-BEG-AUG-2026

SAMSUNG-TOPIK-2026
```

---

### name

```text
VARCHAR(255)
NOT NULL
```

Tên Cohort hiển thị.

Ví dụ:

```text
TOPIK Beginner - July 2026

Samsung TOPIK Corporate Class
```

---

### description

```text
TEXT
NULL
```

Mô tả Cohort.

---

### cohort_type

```text
VARCHAR(50)
NOT NULL
DEFAULT 'batch'
```

Mục đích / mô hình tổ chức lớp.

Allowed values:

```text
batch

corporate

private_group

exam_prep

bootcamp

mentoring

custom
```

Ví dụ:

```text
batch
```

Lớp học theo đợt khai giảng.

```text
corporate
```

Lớp đào tạo cho doanh nghiệp.

```text
mentoring
```

Lớp coaching / hướng dẫn 1:1 hoặc nhóm nhỏ.

---

### delivery_mode

```text
VARCHAR(50)
NOT NULL
DEFAULT 'self_paced'
```

Hình thức học chính.

Allowed values:

```text
self_paced

live_online

offline

hybrid
```

Ví dụ:

```text
live_online
```

Học trực tuyến theo lịch.

```text
hybrid
```

Kết hợp online, offline, replay hoặc self-learning.

---

### max_students

```text
INT UNSIGNED
NULL
```

Số học viên tối đa.

NULL = không giới hạn.

---

### current_students

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Số học viên hiện tại.

Có thể được cập nhật từ:

```text
core_course_cohort_students
```

---

### starts_at

```text
TIMESTAMP NULL
```

Thời điểm bắt đầu Cohort.

---

### ends_at

```text
TIMESTAMP NULL
```

Thời điểm kết thúc Cohort.

---

### enrollment_starts_at

```text
TIMESTAMP NULL
```

Thời điểm bắt đầu cho phép ghi danh vào Cohort.

---

### enrollment_ends_at

```text
TIMESTAMP NULL
```

Thời điểm kết thúc ghi danh vào Cohort.

---

### timezone

```text
VARCHAR(100)
NULL
```

Timezone của Cohort.

Ví dụ:

```text
Asia/Ho_Chi_Minh

Asia/Seoul
```

NULL = dùng timezone của tenant.

---

### schedule_summary

```text
VARCHAR(500)
NULL
```

Tóm tắt lịch học để hiển thị nhanh.

Ví dụ:

```text
Tue/Thu 19:00 - 20:30

Sat/Sun 09:00 - 11:00
```

Chi tiết lịch học nên thuộc LiveClass/Schedule domain riêng.

---

### meeting_provider

```text
VARCHAR(50)
NULL
```

Nhà cung cấp học online mặc định.

Allowed examples:

```text
zoom

google_meet

teams

custom
```

---

### meeting_url

```text
TEXT
NULL
```

Link phòng học mặc định nếu Cohort dùng một phòng cố định.

Nếu mỗi buổi học có link riêng, lưu ở LiveClass Session.

---

### corporate_customer_name

```text
VARCHAR(255)
NULL
```

Tên doanh nghiệp nếu Cohort là lớp corporate.

Ví dụ:

```text
Samsung Vietnam

LG Electronics

Hyundai
```

---

### status

```text
VARCHAR(50)
NOT NULL
DEFAULT 'draft'
```

Trạng thái Cohort.

Allowed values:

```text
draft

open

full

in_progress

completed

cancelled

archived
```

---

### metadata

```text
JSON NULL
```

Dữ liệu mở rộng.

Ví dụ:

```json
{
  "corporate_contract_id": 123,
  "default_language": "vi",
  "attendance_required": true,
  "replay_enabled": true
}
```

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
INDEX idx_course_cohorts_customer
(customer_id);
```

```sql
INDEX idx_course_cohorts_product
(customer_id, product_id);
```

```sql
INDEX idx_course_cohorts_teacher
(customer_id, teacher_id);
```

```sql
INDEX idx_course_cohorts_status
(customer_id, status);
```

```sql
INDEX idx_course_cohorts_type
(customer_id, cohort_type);
```

```sql
INDEX idx_course_cohorts_delivery_mode
(customer_id, delivery_mode);
```

```sql
INDEX idx_course_cohorts_starts
(customer_id, starts_at);
```

```sql
INDEX idx_course_cohorts_enrollment_dates
(customer_id, enrollment_starts_at, enrollment_ends_at);
```

---

## Unique Constraints

```sql
UNIQUE uniq_course_cohorts_code
(customer_id, cohort_code);
```

Đảm bảo mã Cohort không trùng trong cùng tenant.

---

## Sample Data

### Batch Live Online

```text
id = 1

customer_id = 1

product_id = 10

teacher_id = 25

cohort_code = TOPIK-BEG-JUL-2026

name = TOPIK Beginner - July 2026

cohort_type = batch

delivery_mode = live_online

max_students = 30

current_students = 18

starts_at = 2026-07-01 19:00:00

ends_at = 2026-09-30 20:30:00

enrollment_starts_at = 2026-06-01 00:00:00

enrollment_ends_at = 2026-06-30 23:59:59

timezone = Asia/Ho_Chi_Minh

schedule_summary = Tue/Thu 19:00 - 20:30

meeting_provider = zoom

status = open
```

---

### Corporate Hybrid

```text
id = 2

customer_id = 1

product_id = 10

teacher_id = 30

cohort_code = SAMSUNG-TOPIK-2026

name = Samsung TOPIK Corporate Class

cohort_type = corporate

delivery_mode = hybrid

max_students = 50

current_students = 42

starts_at = 2026-08-01 09:00:00

ends_at = 2026-10-30 18:00:00

timezone = Asia/Ho_Chi_Minh

schedule_summary = Offline workshop + weekly online class

corporate_customer_name = Samsung Vietnam

status = open
```

---

## Final Statement

`core_course_cohorts` là bảng quản lý lớp học / batch triển khai thực tế của Product.

Vai trò đúng:

```text
Product

↓

Cohort / Batch / Class

↓

Cohort Students

↓

Live Class / Hybrid / Corporate Learning
```

Trong đó:

```text
cohort_type = mục đích / mô hình tổ chức lớp

delivery_mode = hình thức học
```

Cohort giúp LF hỗ trợ tốt:

```text
Live Online

Hybrid

Offline Class

Corporate Training

Batch-based Learning

Private Group

Mentoring
```

mà không làm Product hoặc Enrollment bị quá tải nghiệp vụ.
