# core_course_completions

## Purpose

Lưu kết quả hoàn thành Course Product của học viên.

Bảng này trả lời câu hỏi:

```text
Học viên đã hoàn thành Product này chưa?

Hoàn thành khi nào?

Hoàn thành theo rule nào?

Có đủ điều kiện cấp certificate không?
```

`core_course_completions` là bảng ghi nhận completion chính thức, khác với `core_course_progress`.

```text
core_course_progress
= tiến độ hiện tại / summary có thể thay đổi

core_course_completions
= sự kiện hoàn thành chính thức / kết quả cuối
```

---

## Relationships

```text
saas_customers

1

↓

N

core_course_completions
```

```text
users

1

↓

N

core_course_completions
```

```text
core_course_products

1

↓

N

core_course_completions
```

```text
core_course_template_versions

1

↓

N

core_course_completions
```

```text
core_course_enrollments

1

↓

0..1

core_course_completions
```

```text
core_course_progress

1

↓

0..1

core_course_completions
```

Future relationship:

```text
core_course_completions

1

↓

0..1

core_certificate_issued_certificates
```

---

## Business Rules

* Mọi completion phải thuộc `customer_id`.
* Completion luôn thuộc một `student_id`.
* Completion luôn thuộc một `product_id`.
* Completion luôn thuộc `template_version_id` đã khóa trên Enrollment.
* Mỗi Completion thuộc đúng một Learning Cycle qua `enrollment_id`.
* Student có thể có nhiều Completion cho cùng Product nếu có nhiều Enrollments.
* Completion nên gắn với `enrollment_id`.
* Completion nên gắn với `course_progress_id`.
* Một enrollment chỉ nên có một completion chính thức.
* Completion được tạo khi học viên đạt điều kiện hoàn thành Product.
* Completion không thay thế progress.
* Progress có thể thay đổi, completion là bản ghi kết quả hoàn thành.
* Completion có thể được tạo tự động hoặc được admin/teacher xác nhận thủ công.
* Nếu Product có certificate, completion có thể dùng để xét điều kiện cấp certificate.
* Completion nên giữ lại kể cả khi enrollment hết hạn.
* Không xóa completion nếu đã dùng để cấp certificate.

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

Tenant sở hữu completion.

---

### enrollment_id

```text
BIGINT UNSIGNED
NOT NULL
```

Enrollment liên quan.

Liên kết:

```text
core_course_enrollments.id
```

---

### course_progress_id

```text
BIGINT UNSIGNED
NULL
```

Progress summary dùng để tạo completion.

Liên kết:

```text
core_course_progress.id
```

---

### product_id

```text
BIGINT UNSIGNED
NOT NULL
```

Course Product được hoàn thành.

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

Published Template Version mà học viên hoàn thành.

Liên kết `core_course_template_versions.id`.

Phải khớp với Enrollment và Course Progress.

---

### student_id

```text
BIGINT UNSIGNED
NOT NULL
```

Học viên hoàn thành Product.

Liên kết:

```text
users.id
```

---

### completion_rule

```text
VARCHAR(50)
NOT NULL
```

Rule dùng để xác định hoàn thành.

Allowed values:

```text
all_required_activities

all_lessons

progress_percentage

lesson_and_assessment

assessment_passed

manual

custom
```

---

### required_progress_percentage

```text
DECIMAL(5,2)
NULL
```

Phần trăm yêu cầu để hoàn thành.

Ví dụ:

```text
80.00

100.00
```

NULL nếu rule không dùng phần trăm.

---

### final_progress_percentage

```text
DECIMAL(5,2)
NOT NULL
DEFAULT 100.00
```

Tiến độ cuối cùng tại thời điểm completion.

---

### completed_lessons

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Số lesson đã hoàn thành tại thời điểm completion.

---

### total_lessons

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Tổng lesson tại thời điểm completion.

---

### completed_activities

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Số activity đã hoàn thành tại thời điểm completion.

---

### total_activities

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Tổng activity tại thời điểm completion.

---

### required_activities_completed

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Số activity bắt buộc đã hoàn thành.

---

### required_activities_total

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Tổng activity bắt buộc.

---

### assessment_completed

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Số assessment bắt buộc đã hoàn thành.

---

### assessment_total

```text
INT UNSIGNED
NOT NULL
DEFAULT 0
```

Tổng assessment bắt buộc.

---

### final_score

```text
DECIMAL(8,2)
NULL
```

Điểm tổng kết nếu Product có scoring.

NULL = không áp dụng.

---

### max_score

```text
DECIMAL(8,2)
NULL
```

Điểm tối đa.

---

### passed

```text
TINYINT(1)
NULL
```

Kết quả đạt/không đạt.

```text
1 = Passed

0 = Failed

NULL = Not applicable
```

---

### completed_at

```text
TIMESTAMP
NOT NULL
```

Thời điểm học viên hoàn thành Product.

---

### completed_by

```text
BIGINT UNSIGNED
NULL
```

Người xác nhận completion.

NULL = system auto-completed.

Nếu manual completion:

```text
teacher_id

customer_admin_id
```

---

### completion_source

```text
VARCHAR(50)
NOT NULL
DEFAULT 'system'
```

Nguồn tạo completion.

Allowed values:

```text
system

teacher

admin

migration

api
```

---

### certificate_eligible

```text
TINYINT(1)
NOT NULL
DEFAULT 0
```

Học viên có đủ điều kiện cấp certificate hay không.

---

### certificate_issued

```text
TINYINT(1)
NOT NULL
DEFAULT 0
```

Đã cấp certificate hay chưa.

---

### certificate_issued_at

```text
TIMESTAMP NULL
```

Thời điểm certificate được cấp.

NULL = chưa cấp.

---

### status

```text
VARCHAR(50)
NOT NULL
DEFAULT 'completed'
```

Trạng thái completion.

Allowed values:

```text
completed

revoked

corrected
```

---

### revoked_at

```text
TIMESTAMP NULL
```

Thời điểm thu hồi completion.

Chỉ dùng khi completion bị hủy do sai dữ liệu hoặc gian lận.

---

### revoked_by

```text
BIGINT UNSIGNED
NULL
```

User thu hồi completion.

---

### revoked_reason

```text
VARCHAR(500)
NULL
```

Lý do thu hồi.

---

### metadata

```text
JSON NULL
```

Dữ liệu mở rộng.

Ví dụ:

```json
{
  "product_title_snapshot": "TOPIK Beginner - July 2026",
  "completion_rule_snapshot": "lesson_and_assessment",
  "certificate_template_id": 3
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
INDEX idx_course_completions_customer
(customer_id);
```

```sql
INDEX idx_course_completions_enrollment
(customer_id, enrollment_id);
```

```sql
INDEX idx_course_completions_progress
(customer_id, course_progress_id);
```

```sql
INDEX idx_course_completions_product
(customer_id, product_id);
```

```sql
INDEX idx_course_completions_template_version
(customer_id, template_version_id);
```

```sql
INDEX idx_course_completions_student
(customer_id, student_id);
```

```sql
INDEX idx_course_completions_completed_at
(customer_id, completed_at);
```

```sql
INDEX idx_course_completions_certificate_eligible
(customer_id, certificate_eligible);
```

```sql
INDEX idx_course_completions_certificate_issued
(customer_id, certificate_issued);
```

```sql
INDEX idx_course_completions_status
(customer_id, status);
```

---

## Unique Constraints

```sql
UNIQUE uniq_course_completions_enrollment
(customer_id, enrollment_id);
```

Đảm bảo một enrollment chỉ có một completion chính thức.

Không unique theo Student/Product.

Một Student có thể hoàn thành cùng Product ở nhiều Enrollment cycles.

---

## Sample Data

```text
id = 1

customer_id = 1

enrollment_id = 100

course_progress_id = 50

product_id = 10

template_version_id = 30

student_id = 200

completion_rule = lesson_and_assessment

required_progress_percentage = 100.00

final_progress_percentage = 100.00

completed_lessons = 20

total_lessons = 20

completed_activities = 60

total_activities = 60

required_activities_completed = 50

required_activities_total = 50

assessment_completed = 3

assessment_total = 3

final_score = 88.50

max_score = 100.00

passed = 1

completed_at = 2026-09-30 20:30:00

completed_by = NULL

completion_source = system

certificate_eligible = 1

certificate_issued = 0

certificate_issued_at = NULL

status = completed
```

---

## Completion Flow

```text
Student học Activity

↓

core_course_activity_progress updated

↓

core_course_lesson_progress recalculated

↓

core_course_progress recalculated

↓

Completion Rule matched

↓

core_course_completions created

↓

Certificate eligibility checked
```

---

## Final Statement

`core_course_completions` là bảng ghi nhận kết quả hoàn thành chính thức của học viên đối với Course Product.

Vai trò đúng:

```text
Progress

↓

Completion

↓

Certificate Eligibility

↓

Certificate Issuance
```

Bảng này giúp LF phân biệt rõ:

```text
Progress = đang học đến đâu

Completion = đã hoàn thành chính thức chưa

Certificate = đã được cấp chứng chỉ chưa
```
