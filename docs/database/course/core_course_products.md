# Table: core_course_products

Version: 1.5

Document Status: Approved

Implementation Status: Unknown

Last Updated: 2026-08-09

Document Path: database/course/core_course_products.md

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
* Product lifecycle dùng `active` để biểu thị Product đang được hiển thị,
  bán hoặc cấp quyền truy cập theo rule thương mại.
* `published` thuộc Course Template Version bất biến, không phải Product
  runtime status.
* Teacher không có quyền truy cập hoặc quản lý Product trực tiếp trong Phase 3
  cho đến khi có quan hệ assigned-product chính thức.

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

## Product v2 — Category, Offering, Media & Promotion

Thêm bởi Product v2 phase-one contract (ADR-0014, 2026-07-15). Xem
[core_course_products_v2.md](core_course_products_v2.md) để
biết đầy đủ business rules, activation/inheritance behavior và migration plan.
Legacy thumbnail columns ở trên vẫn được giữ trong giai đoạn compatibility.

### category_id

BIGINT UNSIGNED

Staged nullable, sau đó NOT NULL.

Same-tenant Category.

Liên kết:

```text
core_course_categories.id (RESTRICT)
```

### offering_type

VARCHAR(50) NULL (transitionally)

Canonical offering. Giá trị:

* self_paced_course
* live_online_course
* blended_course
* assessment
* learning_material

### uses_custom_description

TINYINT(1) DEFAULT 0

False ignores stored overrides — khi tắt, `short_description`/`description`
không được dùng cho runtime/public presentation; hệ thống đọc mô tả từ
published Course Template Version.

### uses_custom_intro_media

TINYINT(1) DEFAULT 0

False ignores Product media — khi tắt, hệ thống chỉ đọc media từ published
Version, bỏ qua các pointer Product bên dưới.

### intro_image_media_file_id

BIGINT UNSIGNED NULL

Ready same-tenant image.

Liên kết:

```text
media_files.id (RESTRICT)
```

### intro_video_source

VARCHAR(50) NULL

Giá trị:

* upload
* embed

### intro_video_media_file_id

BIGINT UNSIGNED NULL

Ready uploaded video.

Liên kết:

```text
media_files.id (RESTRICT)
```

### intro_video_embed_url

VARCHAR(2048) NULL

Normalized trusted URL. Chỉ chấp nhận HTTPS YouTube hoặc Vimeo URL đã
normalize; không lưu raw iframe/HTML.

### intro_video_provider

VARCHAR(50) NULL

Giá trị:

* youtube
* vimeo

### intro_document_media_file_id

BIGINT UNSIGNED NULL

Ready document.

Liên kết:

```text
media_files.id (RESTRICT)
```

### promotion_enabled

TINYINT(1) DEFAULT 0

Gates promotion fields — khi tắt, `discount_type`/`discount_value` là NULL và
không có hiệu lực.

### discount_type

VARCHAR(50) NULL

Bắt buộc khi `promotion_enabled = true`. Giá trị:

* percentage
* fixed_amount

### discount_value

DECIMAL(15,2) NULL

Bắt buộc khi `promotion_enabled = true`. Positive and bounded: `percentage`
phải `> 0` và `<= 100`; `fixed_amount` phải `> 0` và `<= price`.

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

Field này chỉ active khi `offering_type = self_paced_course`; khi đó giá trị
bắt buộc là số nguyên lớn hơn hoặc bằng `1` trước khi tạo Enrollment.

Với `live_online_course`, field bắt buộc lưu `NULL`. `NULL` trong trường hợp
này không được thay bằng duration suy ra từ Lesson, Live Class Activity,
Session, Schedule hoặc Cohort.

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

NULL = cho phép đăng ký ngay khi Product active.

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
* active
* inactive
* archived

Ý nghĩa:

* `draft` — Product đang soạn thảo, chưa dùng cho website hoặc enrollment.
* `active` — Product runtime đang khả dụng theo `visibility`, thời gian hiển
  thị, thời gian đăng ký và các rule thương mại khác.
* `inactive` — Product tạm ngưng sử dụng nhưng vẫn giữ dữ liệu lịch sử.
* `archived` — Product không còn vận hành cho bán mới hoặc hiển thị thường.

Product dùng `active` thay vì `published` vì Product là lớp commerce/display/access
packaging có thể thay đổi theo giá, chiến dịch, visibility và registration
window. Course Template Version mới là đối tượng được publish bất biến. Việc
Product đang active không làm thay đổi hoặc republish Course Template Version.

---

## Audit

### created_by

BIGINT UNSIGNED NULL

User tạo Product.

### published_at

TIMESTAMP NULL

Thời điểm Product được chuyển sang trạng thái `active` lần đầu hoặc theo rule
triển khai sau này.

Tên field được giữ vì tương thích với tài liệu Foundation hiện có, nhưng không
được hiểu là publish Course Template Version.

### created_at

TIMESTAMP

### updated_at

TIMESTAMP

---

# Business Logic

## Sale Price Rule

Sale Price được áp dụng khi:

```text
status = active

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
status = active

AND

visibility = public OR visibility = private with permission

AND

registration_starts_at <= enrollment.enrolled_at <= registration_ends_at

AND

enrollment_count < max_students OR max_students IS NULL
```

`enrollment.enrolled_at` là thời điểm đánh giá canonical cho mọi nguồn tạo
Enrollment. Không được thay bằng `current_time` khi operator hoặc request đã
chọn một thời điểm ghi danh khác.

Khoảng đăng ký là một cặp optional hoàn chỉnh: cả hai giá trị cùng `NULL` nghĩa
là không giới hạn thời gian đăng ký; chỉ một boundary có giá trị hoặc start
không nhỏ hơn end là cấu hình không hợp lệ và phải fail closed. Cohort không có
registration window và không được override hai field này.

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

Nếu Product là `live_online_course` và:

```text
access_duration_days = NULL
```

thì duration policy không tự đặt ngày hết hạn và không tự chuyển Enrollment
sang `expired`. Quyền runtime vẫn phụ thuộc Enrollment active cùng các kiểm tra
Cohort, Session và LiveClass authorization áp dụng cho nghiệp vụ.

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

(customer_id, category_id, sort_order)  -- idx_ccp_v2_cat_sort

(customer_id, offering_type)  -- idx_ccp_v2_offering
```

---

# Unique Constraints

```sql
UNIQUE(customer_id, slug)

UNIQUE(customer_id, product_code)
```

---

# Authorization Rules

## Customer Admin

Customer Admin được quản lý toàn bộ Product trong cùng tenant:

* list;
* view;
* create;
* edit;
* delete/archive theo rule bên dưới;
* thay đổi Product status.

Tất cả query bắt buộc scope theo:

```php
TenantContext::customerId()
```

## Teacher

Teacher authorization hiện tại vẫn đi qua Course Template assignment:

```text
core_course_template_teachers
```

Trong Phase 3 Product CRUD, Teacher không có quyền truy cập hoặc quản lý Product
trực tiếp vì chưa có bảng hoặc quan hệ assigned-product chính thức.

Không suy diễn quyền Product từ Template assignment, Product Items hoặc Product
Relations. Nếu tương lai cần Teacher quản lý Product được phân công, phải có
ADR/tài liệu database riêng cho assigned-product relationship trước khi
implementation.

---

# Delete / Reference Rules

## Delete Strategy

Product không dùng soft delete theo Foundation hiện tại.

Mặc định delete là logical lifecycle bằng cách chuyển:

```text
status = archived
```

Hard delete chỉ được cho phép khi Product chưa có bất kỳ dữ liệu tham chiếu
nghiệp vụ nào và không phá vỡ audit/historical state.

## Existing References

Không được hard delete Product nếu đang hoặc từng được tham chiếu bởi:

* `core_course_product_items`;
* `core_course_product_relations.product_id`;
* `core_course_product_relations.related_product_id`;
* `core_certificate_template_products`;
* Enrollment, Purchase, Payment, Progress, Completion hoặc Certificate records
  khi các bảng đó được triển khai trong tương lai.

Các quan hệ phải cùng `customer_id`.

## Foreign Key Recommendation

Khi tạo migration, dùng `RESTRICT` cho các reference từ child tables tới Product
để tránh xóa nhầm dữ liệu thương mại hoặc lịch sử học tập.

Không cascade delete từ Product sang published Course Template Version hoặc
Course Template content.

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

status = active
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

status = active
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

# Changelog

## v1.4 (2026-07-22)

* Gộp 13 cột Product v2 phase-one vào mục Fields: `category_id`,
  `offering_type`, `uses_custom_description`, `uses_custom_intro_media`,
  `intro_image_media_file_id`, `intro_video_source`,
  `intro_video_media_file_id`, `intro_video_embed_url`,
  `intro_video_provider`, `intro_document_media_file_id`,
  `promotion_enabled`, `discount_type`, `discount_value`. Nguồn: ADR-0014 /
  Product v2 phase-one contract (2026-07-15). Xem
  [core_course_products_v2.md](core_course_products_v2.md).

---

End of Document
