# core_course_cohort_students

## Purpose

Lưu danh sách học viên thuộc một Cohort / Batch / Class.

Bảng này là cầu nối giữa:

```text
Cohort

↓

Student

↓

Enrollment
```

Nó trả lời câu hỏi:

```text
Học viên nào đang thuộc lớp/batch nào?
```

---

## Relationships

```text
saas_customers
1
↓
N
core_course_cohort_students
```

```text
core_course_cohorts
1
↓
N
core_course_cohort_students
```

```text
users
1
↓
N
core_course_cohort_students
```

```text
core_course_enrollments
1
↓
N
core_course_cohort_students
```

---

## Business Rules

* Mọi cohort student phải thuộc `customer_id`.
* Một record luôn thuộc một `cohort_id`.
* Một record luôn thuộc một `student_id`.
* Một record luôn thuộc một `enrollment_id`.
* Student phải có enrollment với Product của Cohort trước hoặc cùng lúc khi được thêm vào Cohort.
* Cohort Student không cấp quyền học.
* Quyền học vẫn nằm ở `core_course_enrollments`.
* Cohort Student chỉ quản lý việc học viên thuộc lớp/batch nào.
* Mỗi Enrollment chỉ có một Cohort Membership record.
* Mỗi Enrollment chỉ có một Active Cohort.
* Khi chuyển Cohort, UPDATE record hiện tại; không tạo record mới.
* Không lưu Membership History trong bảng này hoặc bảng history riêng.
* Không dùng field `is_current`.
* Nếu Cohort đầy, không cho thêm student mới. Foundation không có admin override.
* Nếu student bị remove khỏi Cohort, enrollment vẫn có thể còn active.
* Chỉ Enrollment `active` được thêm hoặc chuyển vào Cohort `active`.
* Capacity được kiểm tra trong transaction sau khi lock Cohort parent row.

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

Tenant sở hữu dữ liệu.

---

### cohort_id

```text
BIGINT UNSIGNED
NOT NULL
```

Cohort mà học viên thuộc về.

Liên kết:

```text
core_course_cohorts.id
```

---

### enrollment_id

```text
BIGINT UNSIGNED
NOT NULL
```

Enrollment cấp quyền học Product cho Student.

Liên kết:

```text
core_course_enrollments.id
```

---

### product_id

```text
BIGINT UNSIGNED
NOT NULL
```

Product liên quan.

Snapshot từ Cohort / Enrollment để query nhanh.

Liên kết:

```text
core_course_products.id
```

---

### student_id

```text
BIGINT UNSIGNED
NOT NULL
```

Học viên thuộc Cohort.

Liên kết:

```text
users.id
```

User này phải có role:

```text
student
```

---

### assigned_by

```text
BIGINT UNSIGNED
NULL
```

User thêm học viên vào Cohort.

Có thể là:

```text
customer_admin

teacher

system
```

NULL = hệ thống tự gán.

---

### joined_at

```text
TIMESTAMP
NOT NULL
```

Thời điểm học viên được thêm vào Cohort hiện tại.

Khi chuyển Cohort, cập nhật lại `joined_at`.

---

### left_at

```text
TIMESTAMP NULL
```

Thời điểm học viên rời Cohort.

NULL = vẫn đang thuộc Cohort.

---

### status

```text
VARCHAR(50)
NOT NULL
DEFAULT 'active'
```

Trạng thái học viên trong Cohort.

Allowed values:

```text
active

removed

completed

cancelled
```

---

### transfer_from_cohort_id

```text
BIGINT UNSIGNED
NULL
```

Snapshot Cohort ngay trước lần chuyển gần nhất.

Liên kết:

```text
core_course_cohorts.id
```

---

### transfer_reason

```text
VARCHAR(500)
NULL
```

Lý do chuyển Cohort.

Ví dụ:

```text
Student requested schedule change

Admin moved student to corporate class
```

---

### note

```text
TEXT NULL
```

Ghi chú nội bộ.

---

### metadata

```text
JSON NULL
```

Dữ liệu mở rộng.

Ví dụ:

```json
{
  "import_batch_id": 12,
  "corporate_department": "HR",
  "seat_type": "paid"
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
INDEX idx_course_cohort_students_customer
(customer_id);
```

```sql
INDEX idx_course_cohort_students_cohort
(customer_id, cohort_id);
```

```sql
INDEX idx_course_cohort_students_enrollment
(customer_id, enrollment_id);
```

```sql
INDEX idx_course_cohort_students_product
(customer_id, product_id);
```

```sql
INDEX idx_course_cohort_students_student
(customer_id, student_id);
```

```sql
INDEX idx_course_cohort_students_status
(customer_id, status);
```

```sql
INDEX idx_course_cohort_students_joined
(customer_id, joined_at);
```

---

## Unique Constraints

```sql
UNIQUE uniq_course_cohort_students_enrollment
(customer_id, enrollment_id);
```

Đảm bảo:

```text
Một Enrollment chỉ có một Cohort Membership record.
```

Không dùng `is_current` và không tạo membership history.

---

## Sample Data

```text
id = 1

customer_id = 1

cohort_id = 10

enrollment_id = 100

product_id = 5

student_id = 200

assigned_by = 2

joined_at = 2026-07-01 09:00:00

left_at = NULL

status = active

transfer_from_cohort_id = NULL

transfer_reason = NULL
```

---

## Transfer Example

```text
Student A

↓

TOPIK Beginner July 2026

↓

transfer

↓

TOPIK Beginner August 2026
```

Update existing record:

```text
cohort_id = 11

status = active

transfer_from_cohort_id = 10

joined_at = 2026-07-15 10:00:00

left_at = NULL

updated_at = 2026-07-15 10:00:00
```

---

## Final Statement

`core_course_cohort_students` không cấp quyền học.

Quyền học thuộc:

```text
core_course_enrollments
```

`core_course_cohort_students` chỉ quản lý việc phân học viên vào lớp / batch / cohort.

Vai trò đúng:

```text
Product

↓

Enrollment

↓

Cohort Assignment

↓

Live / Hybrid / Corporate Class Management
```

Bảng này giúp LF hỗ trợ:

```text
chuyển lớp

quản lý batch

quản lý lớp corporate

quản lý sĩ số

quản lý học viên theo teacher/lịch học
```
