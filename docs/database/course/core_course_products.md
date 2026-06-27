# Table: core_course_products

Version: 1.2

Status: Official Foundation

Last Updated: 2026-06

---

# Purpose

Đại diện cho sản phẩm khóa học được hiển thị và bán trên Website.

Product là lớp Commerce của Course Domain.

Product chỉ liên kết với published Course Template Version thông qua:

```text
core_course_product_items
```

Cách này cho phép một Product hoạt động như:

* Single Course
* Bundle Course

---

# Relationships

core_course_products

1

↓

N

core_course_product_items

---

core_course_template_versions

N

↓

core_course_product_items

---

core_course_products

1

↓

N

core_course_product_relations

---

core_course_products

1

↓

N

core_certificate_template_products

---

# Business Rules

* Mọi Product phải thuộc customer_id.
* Product là đối tượng hiển thị trên Website.
* Product là đối tượng đăng ký và thanh toán.
* Product có thể chứa một hoặc nhiều Product Items.
* Product không lưu hoặc expose working Template draft.
* Mọi Product Item phải tham chiếu `core_course_template_versions`.
* Product bán nội dung đã frozen tại một Template Version cụ thể.
* Product không sao chép Version Section, Lesson hoặc Activity.
* Product không được silent-update nội dung cho Enrollment hiện có.
* Khi chuyển Product sang Version mới, phải áp dụng new-sale policy có kiểm soát hoặc tạo Product/Product Version mới.
* Product không lưu Progress.
* Product không lưu Learning History.
* Product không lưu Assessment Result.
* Giá bán thuộc Product.
* Nội dung học tập thuộc Product Items.
* Certificate Template và issuance rules theo Product thuộc `core_certificate_template_products`.
* Product không dùng `certificate_template_id` làm nguồn cấu hình chứng chỉ.
* Product có thể có sale price theo thời gian.
* Product có thể giới hạn thời gian học và thời gian ôn tập.
* Product có thể hiển thị số lượng học viên thực tế hoặc số lượng marketing.
* Product có thể liên kết sản phẩm tặng kèm hoặc sản phẩm liên quan thông qua core_course_product_relations.

---

# Product Types

## single_course

Một Product chứa một published Course Template Version.

Ví dụ:

```text
TOPIK Beginner
```

---

## bundle

Một Product chứa nhiều published Course Template Version.

Ví dụ:

```text
TOPIK Master Bundle

- TOPIK Beginner
- TOPIK Intermediate
- TOPIK Advanced
```

---

# Fields

## Identity

### id

BIGINT UNSIGNED

Primary key.

### product_code

VARCHAR(100)

NOT NULL

Giá trị:

 * TOPIK-BEG-SLF
 * TOPIK-BEG-LIVE
 * TOPIK-BEG-CORP
 * BA-FOUND-01
 * AI-FUND-2026

### customer_id

BIGINT UNSIGNED

Tenant sở hữu dữ liệu.

---

## Product Information

### product_type

VARCHAR(50)

Giá trị:

* single_course
* bundle

### title

VARCHAR(255)

Tên sản phẩm.

### slug

VARCHAR(255)

Slug URL.

### short_description

VARCHAR(500) NULL

Mô tả ngắn.

### description

LONGTEXT NULL

Mô tả chi tiết.

---

## Media

### thumbnail_type

VARCHAR(50)

Giá trị:

* image
* video

### thumbnail_image

VARCHAR(500) NULL

Ảnh thumbnail.

### thumbnail_video_source

VARCHAR(50) NULL

Giá trị:

* youtube
* aws

### thumbnail_video_url

VARCHAR(1000) NULL

Youtube URL hoặc AWS URL.

### thumbnail_video_media_id

BIGINT UNSIGNED NULL

Liên kết media_files hoặc media_videos.

---

## Pricing

### price

DECIMAL(15,2)

Giá niêm yết.

### sale_price

DECIMAL(15,2) NULL

Giá khuyến mãi.

### sale_starts_at

TIMESTAMP NULL

Thời điểm bắt đầu áp dụng giá sale.

NULL = áp dụng ngay.

### sale_ends_at

TIMESTAMP NULL

Thời điểm kết thúc giá sale.

NULL = không giới hạn.

### currency

VARCHAR(10)

Ví dụ:

* VND
* USD
* KRW

---

## Sales

### enrollment_type

VARCHAR(50)

Giá trị:

* free
* paid
* invitation

### max_students

INT UNSIGNED NULL

Giới hạn số lượng đăng ký.

NULL = không giới hạn.

### enrollment_count

INT UNSIGNED DEFAULT 0

Số lượng đăng ký thực tế.

Đây là read-model/cache field.

Source of truth:

```text
COUNT(core_course_enrollments.id)

WHERE product_id = core_course_products.id
```

Phải được cập nhật transactionally hoặc recalculation khi Enrollment được tạo,
hủy, khôi phục hoặc thay đổi rule đếm.

Không cho user nhập tay.

Không dùng `enrollment_count` thay cho Enrollment records khi tính Billing,
Completion, Certificate hoặc audit lịch sử.

---

## Access Rules

### access_duration_days

INT UNSIGNED NULL

Số ngày được học kể từ ngày kích hoạt hoặc đăng ký.

Ví dụ:

* 30
* 90
* 180
* 365

NULL = không giới hạn.

---

### review_duration_days

INT UNSIGNED NULL

Số ngày ôn tập sau khi kết thúc thời gian học.

Ví dụ:

* 30
* 60
* 90

NULL = không có giai đoạn ôn tập riêng.

---

## Certificate Rules

### is_certificate_enabled

TINYINT(1) DEFAULT 0

Read-model flag cho biết Product hiện có Certificate mapping khả dụng.

Source of truth:

```text
core_certificate_template_products
```

Field này phải được recalculation từ active mapping và không chứa issuance rule.

### Deprecated / Removed: certificate_template_id

`certificate_template_id` không còn là field cấu hình chính của Product.

Certificate Template và rules theo Product phải được quản lý tại:

```text
core_certificate_template_products
```

Không tạo implementation mới đọc certificate configuration trực tiếp từ Product.

---

## Refund Rules

### is_refundable

TINYINT(1) DEFAULT 0

Product có cho phép hoàn tiền hay không.

### refund_days

INT UNSIGNED NULL

Số ngày cho phép hoàn tiền.

Ví dụ:

* 7
* 14
* 30

NULL = không áp dụng chính sách hoàn tiền theo ngày.

---

## Marketing

### tags

JSON NULL

Ví dụ:

```json
[
  "TOPIK",
  "Beginner",
  "Korean"
]
```

Dùng cho:

* Search
* Recommendation
* Product Filtering
* SEO
* Marketing

### badge_type

VARCHAR(50) NULL

Nhãn marketing hiển thị trên Website.

Giá trị gợi ý:

* new
* hot
* best_seller
* limited
* staff_pick

### show_enrollment_count

TINYINT(1) DEFAULT 1

Có hiển thị số người đăng ký trên Website hay không.

### display_enrollment_count

INT UNSIGNED NULL

Giá trị hiển thị/marketing override trên Website.

```text
NULL = hiển thị enrollment_count

Giá trị khác NULL = chỉ dùng cho presentation
```

Field này không phải số Enrollment thực và tuyệt đối không được dùng cho:

* Analytics
* AI Recommendation
* Billing
* Completion
* Certificate
* Capacity enforcement
* Audit

`core_course_enrollments` là source of truth của số Enrollment thực.

---

## Display

### is_featured

TINYINT(1) DEFAULT 0

Khóa học nổi bật.

### sort_order

INT DEFAULT 0

Thứ tự hiển thị.

---

## Visibility

### visibility

VARCHAR(50)

Giá trị:

* public
* private
* hidden

---

## Publishing Window

### available_from

TIMESTAMP NULL

Ngày bắt đầu hiển thị / bán.

### available_until

TIMESTAMP NULL

Ngày kết thúc hiển thị / bán.

---

## Registration Window

### registration_starts_at

TIMESTAMP NULL

Ngày bắt đầu cho phép đăng ký.

NULL = cho phép đăng ký ngay khi Product published.

### registration_ends_at

TIMESTAMP NULL

Ngày kết thúc cho phép đăng ký.

NULL = không giới hạn thời gian đăng ký.

---

## SEO

### meta_title

VARCHAR(255) NULL

### meta_description

VARCHAR(500) NULL

### meta_keywords

VARCHAR(500) NULL

---

## Business

### status

VARCHAR(50)

Giá trị:

* draft
* published
* inactive
* archived

---

## Audit

### created_by

BIGINT UNSIGNED NULL

User tạo Product.

### published_at

TIMESTAMP NULL

Thời điểm publish Product.

### created_at

TIMESTAMP

### updated_at

TIMESTAMP

---

# Business Logic

## Sale Price Rule

Sale Price được áp dụng khi:

```text
status = published

AND

sale_price IS NOT NULL

AND

current_time >= sale_starts_at OR sale_starts_at IS NULL

AND

current_time <= sale_ends_at OR sale_ends_at IS NULL
```

Nếu điều kiện không thỏa, hệ thống sử dụng:

```text
price
```

---

## Registration Rule

Student có thể đăng ký khi:

```text
status = published

AND

visibility = public OR visibility = private with permission

AND

current_time >= registration_starts_at OR registration_starts_at IS NULL

AND

current_time <= registration_ends_at OR registration_ends_at IS NULL

AND

enrollment_count < max_students OR max_students IS NULL
```

---

## Access Rule

Sau khi đăng ký hoặc mua thành công:

```text
Learning Period

↓

access_duration_days

↓

Review Period

↓

review_duration_days
```

Nếu:

```text
access_duration_days = NULL
```

thì học viên có quyền học không giới hạn thời gian.

---

## Enrollment Count Display Rule

Nếu:

```text
show_enrollment_count = 1
```

Website hiển thị `display_enrollment_count` nếu field này khác NULL.

Nếu `display_enrollment_count = NULL`, Website hiển thị `enrollment_count`.

Nếu:

```text
show_enrollment_count = 0
```

Website không hiển thị số lượng học viên.

---

# Indexes

```sql
(customer_id)

(customer_id, product_type)

(customer_id, product_code)

(customer_id, status)

(customer_id, visibility)

(customer_id, slug)

(customer_id, price)

(customer_id, sale_starts_at)

(customer_id, sale_ends_at)

(customer_id, access_duration_days)

(customer_id, is_featured)

(customer_id, badge_type)

(customer_id, registration_starts_at)

(customer_id, registration_ends_at)

(customer_id, created_by)

(customer_id, published_at)
```

---

# Unique Constraints

```sql
UNIQUE(customer_id, slug)

UNIQUE(customer_id, product_code)
```

---

# Sample Data

## Single Course Product

```text
id = 1

customer_id = 1

product_code = TOPIK-BEG-SLF

product_type = single_course

title = TOPIK Beginner

slug = topik-beginner

price = 299000

sale_price = 199000

sale_starts_at = 2026-07-01 00:00:00

sale_ends_at = 2026-07-15 23:59:59

currency = VND

enrollment_type = paid

access_duration_days = 180

review_duration_days = 30

is_certificate_enabled = 1

is_refundable = 1

refund_days = 7

tags = ["TOPIK", "Beginner", "Korean"]

badge_type = hot

enrollment_count = 83

display_enrollment_count = NULL

show_enrollment_count = 1

visibility = public

status = published
```

---

## Bundle Product

```text
id = 2

customer_id = 1

product_code = TOPIK-MASTER-BUNDLE

product_type = bundle

title = TOPIK Master Bundle

slug = topik-master-bundle

price = 999000

sale_price = 799000

currency = VND

enrollment_type = paid

access_duration_days = 365

review_duration_days = 90

tags = ["TOPIK", "Bundle", "Korean"]

badge_type = best_seller

visibility = public

status = published
```

---

# Notes

Product là lớp thương mại.

Product chịu trách nhiệm:

* Pricing
* Sale Campaign
* Access Duration
* Review Duration
* Visibility
* Sales
* Website Display
* Registration Entry
* Marketing Tags
* Enrollment Display
* Certificate Option
* Refund Policy

Product không chịu trách nhiệm:

* Template Structure
* Template Lesson
* Template Activity
* Progress
* Tracking
* Assessment Result
* AI Logic

Các dữ liệu đó thuộc:

* core_course_product_items
* core_course_product_relations
* core_course_template_versions
* core_course_template_version_sections
* core_course_template_version_lessons
* core_course_template_version_activities
* track_*
* core_assessment_*
* ai_*

---

# Future Notes

Tags hiện lưu dạng JSON để đơn giản cho V1.

Nếu cần search/filter nâng cao ở V2, có thể tách thành:

```text
core_course_tags

core_course_product_tags
```

Product relations như:

* gift
* related
* upsell
* cross_sell

không lưu trực tiếp trong Product.

Các quan hệ này thuộc:

```text
core_course_product_relations
```

---

End of Document
