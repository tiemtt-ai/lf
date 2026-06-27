# core_course_reviews

Version: 1.0

Status: Official Foundation

Last Updated: 2026-06

---

## Purpose

Lưu đánh giá và xếp hạng của học viên đối với Course Product sau khi học viên đã được ghi danh.

Review phục vụ:

* Product Rating
* Product Detail Page
* Search & Ranking
* AI Recommendation
* Quality Analytics

Review không thuộc Course Template.

Review thuộc:

```text
Enrollment

↓

Course Product
```

Quan hệ này đảm bảo học viên chỉ đánh giá Product mà mình đã được cấp quyền học.

---

## Relationships

```text
Customer

1

↓

N

Course Reviews
```

```text
Course Product

1

↓

N

Course Reviews
```

```text
Enrollment

1

↓

0..1

Course Review
```

```text
User

1

↓

N

Course Reviews
```

Database relationships:

```text
saas_customers.id

↓

core_course_reviews.customer_id
```

```text
core_course_products.id

↓

core_course_reviews.product_id
```

```text
core_course_enrollments.id

↓

core_course_reviews.enrollment_id
```

```text
core_course_template_versions.id

↓

core_course_reviews.template_version_id
```

```text
users.id

↓

core_course_reviews.user_id
```

---

## Business Rules

* Mọi Review phải thuộc một Customer thông qua `customer_id`.
* Mỗi Enrollment chỉ được tạo tối đa một Review.
* Chỉ User có Enrollment hợp lệ mới được tạo Review.
* `product_id` phải khớp với Product của Enrollment.
* `template_version_id` phải khớp với Version đã khóa trên Enrollment.
* Review dùng `user_id`, không dùng `student_id`.
* Với learner Review, `user_id` phải khớp với User của Enrollment.
* User-based naming cho phép mở rộng Teacher, QA và Internal Review.
* Customer của Review, Product, Enrollment và User phải giống nhau.
* Review gắn với Course Product, không gắn với Course Template.
* Không lưu `template_id` trong Review.
* Rating chỉ nhận giá trị nguyên từ 1 đến 5.
* Title và Comment là tùy chọn.
* Học viên có thể cập nhật Review của Enrollment.
* Có thể ẩn Review mà không xóa vật lý dữ liệu.
* Review có thể được hiển thị công khai hoặc chỉ sử dụng nội bộ.
* `is_verified_purchase` chỉ phản ánh trạng thái xác thực giao dịch tại thời điểm Review được tạo hoặc cập nhật.
* Product Rating công khai chỉ tổng hợp các Review có `status = active` và `is_public = 1`.
* Mọi truy vấn Review phải được giới hạn theo `customer_id`.

---

## Fields

### id

```text
BIGINT UNSIGNED
PRIMARY KEY
AUTO_INCREMENT
```

Khóa chính của Review.

---

### customer_id

```text
BIGINT UNSIGNED
NOT NULL
```

Tenant sở hữu Review.

Liên kết:

```text
saas_customers.id
```

---

### product_id

```text
BIGINT UNSIGNED
NOT NULL
```

Course Product được đánh giá.

Liên kết:

```text
core_course_products.id
```

Giá trị này phải khớp với `product_id` của Enrollment.

---

### enrollment_id

```text
BIGINT UNSIGNED
NOT NULL
```

Enrollment cho phép User đánh giá Product.

Liên kết:

```text
core_course_enrollments.id
```

Mỗi Enrollment chỉ có tối đa một Review.

---

### template_version_id

```text
BIGINT UNSIGNED
NOT NULL
```

Template Version mà học viên đã học và đang đánh giá.

Liên kết `core_course_template_versions.id`.

Field này phục vụ analytics theo version; Review vẫn thuộc Product/Enrollment.

---

### user_id

```text
BIGINT UNSIGNED
NOT NULL
```

User tạo Review.

Liên kết:

```text
users.id
```

Với learner Review, giá trị này phải khớp với User của Enrollment.

Không tạo field `student_id`; Student chỉ là Role.

---

### rating

```text
TINYINT UNSIGNED
NOT NULL
```

Điểm đánh giá Course Product.

Allowed values:

```text
1
2
3
4
5
```

---

### title

```text
VARCHAR(255)
NULL
```

Tiêu đề ngắn của Review.

---

### comment

```text
TEXT
NULL
```

Nội dung đánh giá chi tiết.

---

### is_public

```text
TINYINT(1)
NOT NULL
DEFAULT 1
```

Quy định Review có được hiển thị công khai hay không.

```text
1 = public

0 = internal only
```

---

### is_verified_purchase

```text
TINYINT(1)
NOT NULL
DEFAULT 0
```

Cho biết Enrollment có được xác thực từ giao dịch mua Product hay không.

```text
1 = verified purchase

0 = enrollment không phát sinh từ giao dịch mua đã xác thực
```

Review vẫn có thể hợp lệ khi `is_verified_purchase = 0`, ví dụ Admin Enrollment hoặc Free Enrollment.

---

### status

```text
VARCHAR(50)
NOT NULL
DEFAULT 'active'
```

Trạng thái nghiệp vụ của Review.

Allowed values:

```text
active

hidden

reported

deleted
```

Ý nghĩa:

* `active`: Review đang hoạt động.
* `hidden`: Review bị ẩn khỏi khu vực công khai.
* `reported`: Review đang chờ xử lý moderation.
* `deleted`: Review được xóa logic nhưng vẫn giữ dữ liệu audit.

---

### created_at

```text
TIMESTAMP
NULL
```

Thời điểm Review được tạo.

---

### updated_at

```text
TIMESTAMP
NULL
```

Thời điểm Review được cập nhật gần nhất.

---

## Indexes

```sql
INDEX idx_course_reviews_customer
(customer_id);
```

```sql
INDEX idx_course_reviews_product
(customer_id, product_id);
```

```sql
INDEX idx_course_reviews_user
(customer_id, user_id);
```

```sql
INDEX idx_course_reviews_enrollment
(customer_id, enrollment_id);
```

```sql
INDEX idx_course_reviews_template_version
(customer_id, template_version_id);
```

```sql
INDEX idx_course_reviews_status
(customer_id, status);
```

```sql
INDEX idx_course_reviews_rating
(customer_id, rating);
```

---

## Unique Constraints

```sql
UNIQUE uniq_course_reviews_enrollment
(customer_id, enrollment_id);
```

Đảm bảo mỗi Enrollment chỉ có tối đa một Review.

---

## Sample Data

```text
id = 1

customer_id = 1

product_id = 12

enrollment_id = 301

template_version_id = 30

user_id = 88

rating = 5

title = Excellent Course

comment = Very useful and well structured.

is_public = 1

is_verified_purchase = 1

status = active

created_at = 2026-06-27 10:00:00

updated_at = 2026-06-27 10:00:00
```

---

## Design Notes

Review thuộc Course Product, không thuộc Course Template.

Một Product có thể có nhiều Review.

Một Enrollment chỉ có tối đa một Review.

Review là dữ liệu thương mại thuộc Commerce Layer, không phải Learning Content.

Review được sử dụng cho:

* Product Rating
* Recommendation
* Search Ranking
* AI Analytics
* Product Quality Dashboard

Review không cấp quyền học và không thay đổi Enrollment hoặc Learning Progress.

---

## Final Statement

`core_course_reviews` lưu đánh giá của học viên trong ngữ cảnh Enrollment và Course Product.

Vai trò đúng:

```text
Student

↓

Enrollment

↓

Course Product

↓

Review & Rating
```

Không gắn Review với Course Template.
