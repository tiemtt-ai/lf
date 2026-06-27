# core_course_bookmarks

## Purpose

Lưu các vị trí hoặc nội dung học tập mà học viên đánh dấu để quay lại sau.

Bookmark khác Note:

```text
Note = học viên ghi nội dung

Bookmark = học viên đánh dấu vị trí/nội dung
```

Bookmark có thể gắn với:

```text
Product
Version Lesson
Version Activity
Video timestamp
Document page
```

---

## Relationships

```text
saas_customers
1
↓
N
core_course_bookmarks
```

```text
users
1
↓
N
core_course_bookmarks
```

```text
core_course_products
1
↓
N
core_course_bookmarks
```

```text
core_course_enrollments
1
↓
N
core_course_bookmarks
```

```text
core_course_template_version_lessons
1
↓
N
core_course_bookmarks
```

```text
core_course_template_version_activities
1
↓
N
core_course_bookmarks
```

---

## Business Rules

* Mọi Bookmark phải thuộc `customer_id`.
* Bookmark luôn thuộc một `student_id`.
* Bookmark luôn thuộc một `product_id`.
* Bookmark luôn thuộc một `enrollment_id`.
* Chỉ Enrollment có `status = active` mới được Create/Update Bookmark.
* Không hỗ trợ Preview Bookmarks, Guest Bookmarks hoặc Anonymous Bookmarks.
* Bookmark phải lưu `template_version_id` của learning content.
* Bookmark có thể gắn với Version Lesson hoặc Version Activity.
* Position/page/anchor thuộc frozen Version Activity.
* Bookmark có thể lưu timestamp video/audio.
* Bookmark có thể lưu page number của document.
* Bookmark không cấp quyền học.
* Bookmark không ảnh hưởng progress.
* Một học viên có thể bookmark nhiều vị trí trong cùng một activity.
* Bookmark dùng cho “Save for later”, “Continue review”, AI recommendation.

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

Tenant sở hữu bookmark.

---

### product_id

```text
BIGINT UNSIGNED
NOT NULL
```

Product mà bookmark thuộc về.

---

### template_version_id

```text
BIGINT UNSIGNED
NOT NULL
```

Published Template Version chứa learning content được bookmark.

Liên kết `core_course_template_versions.id`.

---

### enrollment_id

```text
BIGINT UNSIGNED
NOT NULL
```

Enrollment liên quan.

Enrollment phải active khi tạo hoặc cập nhật Bookmark.

---

### student_id

```text
BIGINT UNSIGNED
NOT NULL
```

Học viên tạo bookmark.

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

### bookmark_type

```text
VARCHAR(50)
NOT NULL
DEFAULT 'activity'
```

Allowed values:

```text
product
lesson
activity
video_position
audio_position
document_page
```

---

### title

```text
VARCHAR(255)
NULL
```

Tên bookmark do học viên đặt hoặc hệ thống tự tạo.

---

### position_seconds

```text
BIGINT UNSIGNED
NULL
```

Vị trí video/audio.

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

Số trang nếu bookmark gắn với tài liệu.

---

### note

```text
VARCHAR(500)
NULL
```

Ghi chú ngắn cho bookmark.

Nếu cần ghi chú dài, dùng `core_course_notes`.

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
  "auto_title": true
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
INDEX idx_course_bookmarks_customer
(customer_id);
```

```sql
INDEX idx_course_bookmarks_student
(customer_id, student_id);
```

```sql
INDEX idx_course_bookmarks_product
(customer_id, product_id);
```

```sql
INDEX idx_course_bookmarks_template_version
(customer_id, template_version_id);
```

```sql
INDEX idx_course_bookmarks_enrollment
(customer_id, enrollment_id);
```

```sql
INDEX idx_course_bookmarks_lesson
(customer_id, version_lesson_id);
```

```sql
INDEX idx_course_bookmarks_activity
(customer_id, version_activity_id);
```

```sql
INDEX idx_course_bookmarks_type
(customer_id, bookmark_type);
```

```sql
INDEX idx_course_bookmarks_status
(customer_id, status);
```

```sql
INDEX idx_course_bookmarks_created
(customer_id, created_at);
```

---

## Unique Constraints

Không nên bắt buộc unique toàn bảng.

Một học viên có thể bookmark nhiều vị trí trong cùng một activity.

Nếu muốn tránh duplicate cùng vị trí, có thể dùng application logic theo:

```text
student_id
product_id
version_activity_id
position_seconds
page_number
```

---

## Sample Data

### Video Bookmark

```text
id = 1

customer_id = 1

product_id = 10

template_version_id = 30

enrollment_id = 100

student_id = 200

version_section_id = 101

version_lesson_id = 501

version_activity_id = 9001

bookmark_type = video_position

title = Phần giải thích ngữ pháp

position_seconds = 420

page_number = NULL

note = Xem lại trước khi làm quiz

status = active
```

---

### Document Bookmark

```text
id = 2

customer_id = 1

product_id = 10

template_version_id = 30

enrollment_id = 100

student_id = 200

version_section_id = 101

version_lesson_id = 501

version_activity_id = 9002

bookmark_type = document_page

title = Bảng tổng hợp từ vựng

position_seconds = NULL

page_number = 12

note = Ôn lại cuối tuần

status = active
```

---

## Final Statement

`core_course_bookmarks` là bảng đánh dấu nội dung học tập để học viên quay lại sau.

Vai trò đúng:

```text
Student
↓
Product / Template Version / Version Lesson / Version Activity / Position
↓
Bookmark
↓
Review Later / AI Recommendation
```
