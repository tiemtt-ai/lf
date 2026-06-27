# core_course_favorites

## Purpose

Lưu danh sách Course Product mà học viên yêu thích hoặc muốn xem lại sau.

Tính năng này tương tự:

```text
Wishlist

Saved Courses

Favorites
```

trên các nền tảng LMS, E-Commerce và Learning Marketplace.

Favorite không cấp quyền học.

Favorite chỉ thể hiện sự quan tâm của học viên đối với Product.

---

## Relationships

```text
saas_customers

1

↓

N

core_course_favorites
```

```text
users

1

↓

N

core_course_favorites
```

```text
core_course_products

1

↓

N

core_course_favorites
```

---

## Business Rules

* Mọi Favorite phải thuộc một Customer.
* Favorite luôn thuộc một Student.
* Favorite luôn gắn với một Product.
* Một Student chỉ được Favorite một Product một lần.
* Favorite không tạo Enrollment.
* Favorite không ảnh hưởng quyền học.
* Xóa Product không tự động xóa Favorite (soft delete Product vẫn giữ lịch sử).
* Favorite dùng để:

  * Danh sách yêu thích.
  * Gợi ý khóa học.
  * Marketing automation.
  * Recommendation engine.
  * AI personalization.

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

Tenant sở hữu Favorite.

---

### product_id

```text
BIGINT UNSIGNED
NOT NULL
```

Product được lưu yêu thích.

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

Học viên thực hiện Favorite.

Liên kết:

```text
users.id
```

---

### source

```text
VARCHAR(50)
NOT NULL
DEFAULT 'manual'
```

Nguồn tạo Favorite.

Allowed values:

```text
manual

website

mobile_app

recommendation

campaign
```

Ví dụ:

```text
website
```

Student click nút:

```text
♡ Add To Favorites
```

---

### note

```text
VARCHAR(500)
NULL
```

Ghi chú cá nhân của học viên.

Ví dụ:

```text
Học sau khi hoàn thành TOPIK 1
```

V1 có thể chưa sử dụng.

---

### metadata

```text
JSON NULL
```

Dữ liệu mở rộng.

Ví dụ:

```json
{
  "campaign_code": "TOPIK2026",
  "source_page": "course_detail"
}
```

---

### created_at

```text
TIMESTAMP NULL
```

Thời điểm thêm yêu thích.

---

### updated_at

```text
TIMESTAMP NULL
```

Thời điểm cập nhật.

---

## Indexes

```sql
INDEX idx_course_favorites_customer
(customer_id);
```

```sql
INDEX idx_course_favorites_student
(customer_id, student_id);
```

```sql
INDEX idx_course_favorites_product
(customer_id, product_id);
```

```sql
INDEX idx_course_favorites_created
(customer_id, created_at);
```

---

## Unique Constraints

```sql
UNIQUE uniq_course_favorites_student_product
(customer_id, student_id, product_id);
```

Đảm bảo:

```text
Một học viên chỉ Favorite một Product một lần.
```

---

## Sample Data

```text
id = 1

customer_id = 1

student_id = 100

product_id = 25

source = website

note = Học sau khi hoàn thành TOPIK Beginner

created_at = 2026-06-24 10:00:00
```

---

## Typical Flow

```text
Student

↓

Browse Products

↓

Click Favorite

↓

core_course_favorites

↓

My Favorites
```

---

## Future AI Usage

Favorite là tín hiệu hành vi rất quan trọng cho AI.

Ví dụ:

```text
Student Favorite:

- TOPIK Speaking
- TOPIK Intermediate
- Korean Business Writing
```

AI có thể suy luận:

```text
Người học quan tâm đến lộ trình TOPIK nâng cao
```

và đề xuất Product phù hợp.

---

## Final Statement

`core_course_favorites` lưu mối quan hệ giữa Student và Product mà Student quan tâm.

Nó không cấp quyền học và không lưu tiến độ học.

Vai trò:

```text
Student

↓

Favorite Product

↓

Recommendation

↓

AI Personalization
```
