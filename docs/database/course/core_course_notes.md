# Table: core_course_notes

## Purpose

Lưu ghi chú cá nhân của học viên trong quá trình học Course Product.

Note có thể gắn với:

```text
Product
Version Lesson
Version Activity
Video timestamp
Document page
```

Bảng này giúp Student học chủ động hơn và tạo dữ liệu tốt cho AI personalization.

---

## Relationships

```text
saas_customers
1
↓
N
core_course_notes
```

```text
users
1
↓
N
core_course_notes
```

Quan hệ này dùng `user_id` tham chiếu `users.id`.

```text
core_course_products
1
↓
N
core_course_notes
```

```text
core_course_enrollments
1
↓
N
core_course_notes
```

```text
core_course_template_version_lessons
1
↓
N
core_course_notes
```

```text
core_course_template_version_activities
1
↓
N
core_course_notes
```

---

## Business Rules

* Mọi Note phải thuộc `customer_id`.
* Note luôn thuộc một `user_id`.
* `user_id` tham chiếu `users.id`.
* Student là role của User, không phải identity field của Note.
* Dùng `user_id` để thống nhất identity với Course Reviews.
* Note luôn thuộc một `product_id`.
* Note luôn thuộc một `enrollment_id`.
* Chỉ Enrollment có `status = active` mới được Create/Update Note.
* Không hỗ trợ Preview Notes, Guest Notes hoặc Anonymous Notes.
* Note phải lưu `version_id` của learning content.
* `product_id` phải khớp với `core_course_enrollments.product_id`.
* `version_id` phải khớp với `core_course_enrollments.version_id`.
* Runtime code phải derive `product_id` và `version_id` từ Enrollment context,
  không nhận độc lập từ user input.
* Runtime context gồm `customer_id`, `enrollment_id`, `user_id`,
  `product_id`, `version_id`, `version_lesson_id` và optional
  `version_activity_id`.
* Note có thể gắn với Version Lesson hoặc Version Activity.
* Working Template references chỉ dùng trace/reporting, không phải learning source.
* Note có thể gắn với timestamp video/audio.
* Note mặc định là private cho học viên.
* Teacher/Admin không xem note cá nhân nếu chưa có permission rõ ràng.
* Note là personal learning data, không phải Progress.
* Note không ảnh hưởng Progress.
* Note có thể dùng làm dữ liệu AI personalization nếu tenant cho phép.

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

Tenant sở hữu note.

---

### product_id

```text
BIGINT UNSIGNED
NOT NULL
```

Product mà note thuộc về.

---

### version_id

```text
BIGINT UNSIGNED
NOT NULL
```

Published Template Version chứa learning content được ghi chú.

Liên kết `core_course_template_versions.id`.

---

### enrollment_id

```text
BIGINT UNSIGNED
NOT NULL
```

Enrollment liên quan.

Enrollment phải active khi tạo hoặc cập nhật Note.

---

### user_id

```text
BIGINT UNSIGNED
NOT NULL
```

User sở hữu/tạo note.

Liên kết `users.id`.

Student là role của User, không phải tên field identity.

---

### version_section_id

```text
BIGINT UNSIGNED
NULL
```

Section liên quan.

---

### version_lesson_id

```text
BIGINT UNSIGNED
NULL
```

Lesson liên quan.

---

### version_activity_id

```text
BIGINT UNSIGNED
NULL
```

Activity liên quan.

---

### note_type

```text
VARCHAR(50)
NOT NULL
DEFAULT 'text'
```

Allowed values:

```text
text
highlight
question
reminder
ai_generated
```

---

### title

```text
VARCHAR(255)
NULL
```

Tiêu đề ghi chú.

---

### content

```text
TEXT
NOT NULL
```

Nội dung ghi chú.

---

### position_seconds

```text
BIGINT UNSIGNED
NULL
```

Vị trí video/audio nếu note gắn với timestamp.

Ví dụ:

```text
420
```

---

### page_number

```text
INT UNSIGNED
NULL
```

Số trang nếu note gắn với tài liệu/PDF.

---

### visibility

```text
VARCHAR(50)
NOT NULL
DEFAULT 'private'
```

Allowed values:

```text
private
teacher_visible
shared
```

V1 nên dùng `private`.

---

### pinned

```text
TINYINT(1)
NOT NULL
DEFAULT 0
```

Đánh dấu note quan trọng.

---

### status

```text
VARCHAR(50)
NOT NULL
DEFAULT 'active'
```

Allowed values:

```text
active
archived
deleted
```

---

### metadata

```text
JSON NULL
```

Ví dụ:

```json
{
  "source": "video_player",
  "selected_text": "grammar pattern explanation"
}
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

## Indexes

```sql
INDEX idx_course_notes_customer
(customer_id);
```

```sql
INDEX idx_course_notes_user
(customer_id, user_id);
```

```sql
INDEX idx_course_notes_product
(customer_id, product_id);
```

```sql
INDEX idx_course_notes_version
(customer_id, version_id);
```

```sql
INDEX idx_course_notes_enrollment
(customer_id, enrollment_id);
```

```sql
INDEX idx_course_notes_lesson
(customer_id, version_lesson_id);
```

```sql
INDEX idx_course_notes_activity
(customer_id, version_activity_id);
```

```sql
INDEX idx_course_notes_status
(customer_id, status);
```

```sql
INDEX idx_course_notes_created
(customer_id, created_at);
```

---

## Unique Constraints

Không cần unique constraint.

Một user có thể tạo nhiều note trong cùng một lesson/activity.

---

## Sample Data

```text
id = 1

customer_id = 1

product_id = 10

version_id = 30

enrollment_id = 100

user_id = 200

version_section_id = 101

version_lesson_id = 501

version_activity_id = 9001

note_type = text

title = Cấu trúc ngữ pháp quan trọng

content = Cần nhớ mẫu câu này để dùng trong bài speaking.

position_seconds = 420

page_number = NULL

visibility = private

pinned = 1

status = active
```

---

## Final Statement

`core_course_notes` là bảng ghi chú học tập cá nhân của User trong Enrollment context.

Vai trò đúng:

```text
User (student role)
↓
Enrollment
↓
Product / Template Version / Version Lesson / Version Activity
↓
Personal Note
↓
Review / AI Personalization
```
