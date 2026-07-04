# core_certificate_issued_certificates

Version: 2.0

Status: Foundation Approved and Frozen

Last Updated: 2026-06

---

# Purpose

Lưu chứng chỉ thực tế đã cấp cho học viên.

Đây là bảng chính của Certificate Domain, ghi nhận kết quả cấp chứng chỉ sau khi học viên hoàn thành Course Product hoặc được cấp chứng chỉ thủ công.

Bảng này trả lời các câu hỏi:

```text
Học viên nào đã được cấp chứng chỉ?

Chứng chỉ thuộc Product nào?

Dựa trên Completion nào?

Dùng Template nào?

Số chứng chỉ là gì?

File chứng chỉ nằm ở đâu?

Có thể xác thực công khai không?

Chứng chỉ còn hiệu lực không?
```

---

# Domain Position

```text
Course Product

↓

Enrollment

↓

Course Completion

↓

Certificate Template Product Mapping

↓

Issued Certificate

↓

Rendered Certificate File

↓

Verification / Download Logs
```

---

# Relationships

```text
saas_customers
1
↓
N
core_certificate_issued_certificates
```

```text
core_certificate_templates
1
↓
N
core_certificate_issued_certificates
```

```text
core_certificate_template_products
1
↓
N
core_certificate_issued_certificates
```

```text
users
1
↓
N
core_certificate_issued_certificates
```

```text
core_course_products
1
↓
N
core_certificate_issued_certificates
```

```text
core_course_template_versions
1
↓
N
core_certificate_issued_certificates
```

```text
core_course_enrollments
1
↓
0..1
core_certificate_issued_certificates
```

```text
core_course_completions
1
↓
0..1
core_certificate_issued_certificates
```

```text
media_files
1
↓
N
core_certificate_issued_certificates
```

`media_files` dùng để lưu file PDF hoặc image chứng chỉ đã render.

---

# Business Rules

* Mọi issued certificate phải thuộc `customer_id`.
* Một issued certificate luôn thuộc một `student_id`.
* Một issued certificate thường gắn với một `product_id`.
* Product-based certificate phải lưu `version_id` đã khóa trên Enrollment.
* Một issued certificate nên gắn với `completion_id` nếu cấp sau khi hoàn thành Course Product.
* Product-based certificate bắt buộc gắn với `enrollment_id`.
* Certificate của mỗi learning cycle được phân biệt bằng `enrollment_id`.
* Certificate cấp tự động theo Product phải lưu `certificate_template_product_id`.
* `certificate_template_product_id` chỉ được NULL với manual/non-Product issuance.
* `certificate_template_product_id` là source mapping của rules tại thời điểm cấp.
* Certificate Domain sở hữu eligibility và issuance decision.
* Course Completion không sở hữu Certificate eligibility và không cấp certificate.
* Certificate Domain đánh giá eligibility bằng cách tiêu thụ Course Completion như Course Domain business state và approved Assessment Evidence khi Certificate rules yêu cầu.
* Issued Certificate snapshot final eligibility/issuance decision do Certificate Domain thực hiện.
* Một completion thường chỉ cấp một certificate chính thức.
* Certificate đã cấp phải có `certificate_number` duy nhất trong tenant.
* Certificate đã cấp phải có `verification_code` duy nhất trong tenant.
* Certificate đã cấp phải snapshot dữ liệu quan trọng tại thời điểm cấp.
* Rule snapshot phải được lấy từ `core_certificate_template_products`.
* Certificate đã cấp không phụ thuộc hoàn toàn vào User/Product/Template hiện tại vì các dữ liệu này có thể thay đổi sau này.
* Nếu certificate sai, không xóa. Dùng trạng thái `revoked`.
* Nếu certificate hết hạn, dùng `expires_at` và status có thể đổi thành `expired`.
* Public verification dùng `verification_code`.
* File chứng chỉ đã render lưu ở Media Domain, không lưu binary trong bảng này.
* Download/view/verify logs không lưu trong bảng này, mà lưu ở các bảng log riêng.

---

# Fields

## Identity Fields

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

Tenant sở hữu certificate.

---

## Source Relationship Fields

### certificate_template_id

```text
BIGINT UNSIGNED
NOT NULL
```

Template dùng để cấp certificate.

Liên kết:

```text
core_certificate_templates.id
```

---

### certificate_template_product_id

```text
BIGINT UNSIGNED
NULL
```

Mapping giữa Certificate Template và Course Product tại thời điểm cấp.

Liên kết:

```text
core_certificate_template_products.id
```

NULL nếu certificate được cấp thủ công và không đi qua Product mapping.

Bắt buộc có giá trị khi `issue_source = system` và certificate được cấp theo Course Product.

---

### completion_id

```text
BIGINT UNSIGNED
NULL
```

Completion dùng làm nguồn cấp certificate.

Liên kết:

```text
core_course_completions.id
```

NULL nếu certificate được cấp thủ công hoặc cấp từ nguồn khác.

---

### enrollment_id

```text
BIGINT UNSIGNED
NULL
```

Enrollment liên quan.

Liên kết:

```text
core_course_enrollments.id
```

---

### product_id

```text
BIGINT UNSIGNED
NULL
```

Product liên quan.

Liên kết:

```text
core_course_products.id
```

NULL nếu certificate không thuộc Product cụ thể.

---

### version_id

```text
BIGINT UNSIGNED
NULL
```

Course Template Version mà học viên đã hoàn thành.

Liên kết:

```text
core_course_template_versions.id
```

Bắt buộc với Product-based issuance; NULL chỉ cho certificate thủ công không
thuộc Course Product.

---

### student_id

```text
BIGINT UNSIGNED
NOT NULL
```

Học viên được cấp certificate.

Liên kết:

```text
users.id
```

---

### issued_by

```text
BIGINT UNSIGNED
NULL
```

User cấp certificate.

NULL = hệ thống tự cấp.

Ví dụ:

```text
customer_admin
teacher
system
```

---

## Certificate Identity Fields

### certificate_number

```text
VARCHAR(100)
NOT NULL
```

Số chứng chỉ chính thức.

Ví dụ:

```text
TOPIK-2026-000001

KAHA-CERT-000123
```

Dùng để tra cứu và in trên certificate.

---

### verification_code

```text
VARCHAR(100)
NOT NULL
```

Mã xác thực công khai.

Ví dụ:

```text
LF-CERT-9A8B7C6D
```

Dùng cho URL verify.

---

### verification_url

```text
VARCHAR(500)
NULL
```

Đường dẫn xác thực công khai.

Ví dụ:

```text
https://kaha.learnforge.vn/certificates/verify/LF-CERT-9A8B7C6D
```

---

## Recipient Snapshot Fields

### recipient_name

```text
VARCHAR(255)
NOT NULL
```

Tên học viên hiển thị trên chứng chỉ tại thời điểm cấp.

Snapshot từ:

```text
users.name
```

---

### recipient_email

```text
VARCHAR(255)
NULL
```

Email học viên tại thời điểm cấp.

Snapshot từ:

```text
users.email
```

---

## Product / Course Snapshot Fields

### product_title_snapshot

```text
VARCHAR(255)
NULL
```

Tên Product tại thời điểm cấp certificate.

Ví dụ:

```text
TOPIK Beginner - July 2026
```

---

### product_code_snapshot

```text
VARCHAR(100)
NULL
```

Mã Product tại thời điểm cấp nếu có.

---

### course_template_title_snapshot

```text
VARCHAR(255)
NULL
```

Tên Course Template tại Version được cấp certificate.

Dùng cho certificate cần hiển thị Course Name khác Product Name.

---

## Template Snapshot Fields

### template_code_snapshot

```text
VARCHAR(100)
NULL
```

Mã Certificate Template tại thời điểm cấp.

Snapshot từ:

```text
core_certificate_templates.template_code
```

---

### template_name_snapshot

```text
VARCHAR(255)
NULL
```

Tên Certificate Template tại thời điểm cấp.

---

### certificate_template_version_snapshot

```text
INT UNSIGNED
NULL
```

Version Template tại thời điểm cấp.

Snapshot từ:

```text
core_certificate_templates.template_version
```

---

### course_template_version_number_snapshot

```text
INT UNSIGNED
NULL
```

Snapshot `core_course_template_versions.version_number`.

Cho phép certificate audit chính xác learning content version đã hoàn thành.

---

### render_engine_snapshot

```text
VARCHAR(50)
NULL
```

Render engine dùng tại thời điểm cấp.

Ví dụ:

```text
html_pdf

pdf

image
```

Snapshot từ:

```text
core_certificate_templates.render_engine
```

---

### layout_data_snapshot

```text
JSON NULL
```

Layout data tại thời điểm render certificate.

Dùng để audit hoặc render lại file nếu cần.

---

## Completion Snapshot Fields

### completion_rule_snapshot

```text
VARCHAR(100)
NULL
```

Rule hoàn thành tại thời điểm cấp.

Ví dụ:

```text
lesson_and_assessment

manual_approval

attendance_and_final_exam
```

---

### final_score

```text
DECIMAL(8,2)
NULL
```

Điểm tổng kết nếu có.

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
TIMESTAMP NULL
```

Thời điểm học viên hoàn thành Product.

Snapshot từ:

```text
core_course_completions.completed_at
```

---

## Issuance Fields

### issued_at

```text
TIMESTAMP
NOT NULL
```

Thời điểm cấp certificate.

---

### expires_at

```text
TIMESTAMP NULL
```

Thời điểm certificate hết hạn.

NULL = không hết hạn.

Tính từ:

```text
default_validity_days
```

hoặc override tại:

```text
core_certificate_template_products.validity_days
```

---

### issue_source

```text
VARCHAR(50)
NOT NULL
DEFAULT 'system'
```

Nguồn cấp certificate.

Allowed values:

```text
system

admin

teacher

api

migration
```

---

### issue_note

```text
VARCHAR(500)
NULL
```

Ghi chú khi cấp certificate.

Dùng cho trường hợp cấp thủ công.

---

## File Fields

### file_id

```text
BIGINT UNSIGNED
NULL
```

File certificate đã render.

Liên kết logic:

```text
media_files.id
```

Ví dụ:

```text
PDF certificate
PNG certificate
```

---

### file_url

```text
VARCHAR(1000)
NULL
```

URL file certificate đã render.

V1 ưu tiên dùng `file_id` là chính.

`file_url` chỉ là cache/snapshot nếu cần.

---

### qr_code_data

```text
VARCHAR(1000)
NULL
```

Dữ liệu dùng để tạo QR code.

Thường là:

```text
verification_url
```

hoặc:

```text
verification_code
```

---

## Status Fields

### status

```text
VARCHAR(50)
NOT NULL
DEFAULT 'issued'
```

Trạng thái certificate.

Allowed values:

```text
draft

issued

expired

revoked

reissued
```

---

### revoked_at

```text
TIMESTAMP NULL
```

Thời điểm thu hồi certificate.

---

### revoked_by

```text
BIGINT UNSIGNED
NULL
```

User thu hồi certificate.

---

### revoked_reason

```text
VARCHAR(500)
NULL
```

Lý do thu hồi.

---

### reissued_from_id

```text
BIGINT UNSIGNED
NULL
```

Nếu certificate này được cấp lại từ certificate cũ, lưu ID certificate cũ.

Liên kết:

```text
core_certificate_issued_certificates.id
```

---

## Metadata Fields

### metadata

```text
JSON NULL
```

Dữ liệu mở rộng.

Ví dụ:

```json
{
  "organization_name": "KAHA",
  "teacher_name": "Kim Teacher",
  "certificate_layout_version": "v2",
  "rendered_by": "html_pdf_service"
}
```

---

## Audit Fields

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

# Indexes

```sql
INDEX idx_issued_certificates_customer
(customer_id);
```

```sql
INDEX idx_issued_certificates_template
(customer_id, certificate_template_id);
```

```sql
INDEX idx_issued_certificates_template_product
(customer_id, certificate_template_product_id);
```

```sql
INDEX idx_issued_certificates_completion
(customer_id, completion_id);
```

```sql
INDEX idx_issued_certificates_enrollment
(customer_id, enrollment_id);
```

```sql
INDEX idx_issued_certificates_product
(customer_id, product_id);
```

```sql
INDEX idx_issued_certificates_version
(customer_id, version_id);
```

```sql
INDEX idx_issued_certificates_student
(customer_id, student_id);
```

```sql
INDEX idx_issued_certificates_issued_at
(customer_id, issued_at);
```

```sql
INDEX idx_issued_certificates_expires_at
(customer_id, expires_at);
```

```sql
INDEX idx_issued_certificates_status
(customer_id, status);
```

```sql
INDEX idx_issued_certificates_verification
(customer_id, verification_code);
```

```sql
INDEX idx_issued_certificates_reissued_from
(customer_id, reissued_from_id);
```

---

# Unique Constraints

```sql
UNIQUE uniq_issued_certificates_number
(customer_id, certificate_number);
```

Số certificate không được trùng trong cùng tenant.

```sql
UNIQUE uniq_issued_certificates_verification_code
(customer_id, verification_code);
```

Mã xác thực không được trùng trong cùng tenant.

```sql
UNIQUE uniq_issued_certificates_completion
(customer_id, completion_id);
```

Một completion chỉ cấp một certificate chính thức.

Lưu ý:

Nếu MySQL cho phép nhiều NULL trong unique index, `completion_id = NULL` vẫn dùng được cho certificate cấp thủ công.

---

# Sample Data

```text
id = 1

customer_id = 1

certificate_template_id = 1

certificate_template_product_id = 3

completion_id = 10

enrollment_id = 100

product_id = 5

version_id = 30

student_id = 200

issued_by = NULL

certificate_number = TOPIK-2026-000001

verification_code = LF-CERT-9A8B7C6D

verification_url = https://kaha.learnforge.vn/certificates/verify/LF-CERT-9A8B7C6D

recipient_name = Nguyen Van A

recipient_email = student@example.com

product_title_snapshot = TOPIK Beginner - July 2026

product_code_snapshot = TOPIK-BEGINNER-2026

course_template_title_snapshot = TOPIK Beginner

template_code_snapshot = CERT-TOPIK

template_name_snapshot = TOPIK Completion Certificate

certificate_template_version_snapshot = 2

course_template_version_number_snapshot = 3

render_engine_snapshot = html_pdf

completion_rule_snapshot = lesson_and_assessment

final_score = 88.50

max_score = 100.00

passed = 1

completed_at = 2026-09-30 20:30:00

issued_at = 2026-09-30 21:00:00

expires_at = NULL

file_id = 500

file_url = NULL

qr_code_data = https://kaha.learnforge.vn/certificates/verify/LF-CERT-9A8B7C6D

issue_source = system

issue_note = NULL

status = issued
```

---

# Certificate Issuance Flow

```text
core_course_completions

↓

Certificate Domain evaluates eligibility from Course Completion and approved Assessment Evidence when required

↓

Find core_certificate_template_products by product_id + version_id

↓

Find core_certificate_templates

↓

Generate certificate_number

↓

Generate verification_code

↓

Snapshot Student / Product / Course Template Version / Certificate Template / Completion data

↓

Render PDF / Image

↓

Save file to media_files

↓

Create core_certificate_issued_certificates
```

---

# Verification Flow

```text
User opens verification URL

↓

Find by verification_code

↓

Check status

↓

Create core_certificate_verification_logs

↓

Show certificate info

↓

If status = revoked or expired, show warning
```

---

# Download Flow

```text
Student/Admin opens certificate

↓

Check permission or public policy

↓

Get file_id from media_files

↓

Generate signed URL if needed

↓

Create core_certificate_download_logs
```

---

# Final Statement

`core_certificate_issued_certificates` lưu chứng chỉ thực tế đã cấp cho học viên.

Vai trò đúng:

```text
Completion

↓

Template Product Mapping

↓

Issued Certificate

↓

Certificate File

↓

Public Verification
```

Bảng này phải ổn định, audit-friendly và không phụ thuộc hoàn toàn vào dữ liệu hiện tại của User/Product/Template vì certificate là tài liệu kết quả đã cấp tại một thời điểm cụ thể.
