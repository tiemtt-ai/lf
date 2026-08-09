# Table: core_certificate_verification_logs

Document Path: database/course/core_certificate_verification_logs.md

## Purpose

Lưu lịch sử xác thực chứng chỉ.

Bảng này ghi nhận mỗi lần người dùng hoặc bên thứ ba kiểm tra tính hợp lệ của một certificate thông qua mã xác thực công khai.

Bảng này trả lời các câu hỏi:

```text
Ai đã xác thực certificate?

Xác thực certificate nào?

Xác thực lúc nào?

Kết quả xác thực là gì?

Certificate có hợp lệ, hết hạn, bị thu hồi hay không tồn tại?
```

---

## Relationships

```text
saas_customers
1
↓
N
core_certificate_verification_logs
```

```text
core_certificate_issued_certificates
1
↓
N
core_certificate_verification_logs
```

```text
users
1
↓
N
core_certificate_verification_logs
```

`user_id` là tùy chọn.

Nếu người verify đã đăng nhập thì lưu `user_id`.

Nếu verify công khai không đăng nhập thì `user_id = NULL`.

---

## Business Rules

* Mọi verification log phải thuộc `customer_id`.
* Verification luôn chạy trong tenant context.
* Không hỗ trợ Global Verification.
* Failed lookup vẫn phải lưu owner `customer_id`.
* Verification log là dữ liệu audit, không nên sửa sau khi tạo.
* Public verification phải ghi log cả trường hợp thành công và thất bại.
* Nếu tìm thấy certificate thì lưu `certificate_id`.
* Nếu không tìm thấy certificate thì `certificate_id = NULL` nhưng vẫn lưu `verification_code`.
* Không lưu dữ liệu nhạy cảm quá mức.
* IP address và user agent chỉ dùng cho audit, fraud detection và security review.
* Bảng này không quyết định certificate hợp lệ hay không; nó chỉ ghi nhận kết quả tại thời điểm verify.
* Kết quả verify được tính từ trạng thái của `core_certificate_issued_certificates` tại thời điểm xác thực.

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

Tenant sở hữu verification log.

Tenant phải được resolve trước khi lookup `verification_code`.

---

### certificate_id

```text
BIGINT UNSIGNED
NULL
```

Certificate được xác thực.

Liên kết:

```text
core_certificate_issued_certificates.id
```

NULL nếu mã xác thực không tìm thấy certificate.

---

### user_id

```text
BIGINT UNSIGNED
NULL
```

User thực hiện xác thực nếu đã đăng nhập.

Liên kết:

```text
users.id
```

NULL nếu xác thực công khai.

---

### verification_code

```text
VARCHAR(100)
NOT NULL
```

Mã xác thực được nhập hoặc truy cập từ URL.

Ví dụ:

```text
LF-CERT-9A8B7C6D
```

---

### verification_url

```text
VARCHAR(500)
NULL
```

URL được dùng để xác thực.

Ví dụ:

```text
https://kaha.learnforge.vn/certificates/verify/LF-CERT-9A8B7C6D
```

---

### result

```text
VARCHAR(50)
NOT NULL
```

Kết quả xác thực.

Allowed values:

```text
success

not_found

expired

revoked

invalid

disabled
```

Ý nghĩa:

```text
success = certificate hợp lệ

not_found = không tìm thấy certificate

expired = certificate đã hết hạn

revoked = certificate đã bị thu hồi

invalid = mã verify sai định dạng hoặc không hợp lệ

disabled = certificate hoặc template không bật public verification
```

---

### certificate_status_snapshot

```text
VARCHAR(50)
NULL
```

Snapshot trạng thái certificate tại thời điểm verify.

Ví dụ:

```text
issued

expired

revoked
```

NULL nếu không tìm thấy certificate.

---

### recipient_name_snapshot

```text
VARCHAR(255)
NULL
```

Snapshot tên người nhận certificate tại thời điểm verify.

Lấy từ:

```text
core_certificate_issued_certificates.recipient_name
```

---

### product_title_snapshot

```text
VARCHAR(255)
NULL
```

Snapshot tên Product tại thời điểm verify.

Lấy từ:

```text
core_certificate_issued_certificates.product_title_snapshot
```

---

### certificate_number_snapshot

```text
VARCHAR(100)
NULL
```

Snapshot số certificate tại thời điểm verify.

Lấy từ:

```text
core_certificate_issued_certificates.certificate_number
```

---

### verified_at

```text
TIMESTAMP
NOT NULL
```

Thời điểm xác thực.

---

### ip_address

```text
VARCHAR(45)
NULL
```

Địa chỉ IP thực hiện xác thực.

Hỗ trợ IPv4 và IPv6.

---

### user_agent

```text
VARCHAR(1000)
NULL
```

Thông tin trình duyệt hoặc client.

---

### referer

```text
VARCHAR(1000)
NULL
```

Nguồn truy cập nếu có.

Ví dụ:

```text
LinkedIn

Email

Company HR Portal
```

---

### country

```text
VARCHAR(100)
NULL
```

Quốc gia ước tính từ IP nếu có.

---

### city

```text
VARCHAR(100)
NULL
```

Thành phố ước tính từ IP nếu có.

---

### metadata

```text
JSON NULL
```

Dữ liệu mở rộng.

Ví dụ:

```json
{
  "source": "public_verify_page",
  "device": "mobile",
  "browser": "Chrome",
  "verification_method": "qr_code"
}
```

---

### created_at

```text
TIMESTAMP NULL
```

Thời điểm tạo log.

Thông thường bằng hoặc gần bằng `verified_at`.

---

## Indexes

```sql
INDEX idx_certificate_verification_logs_customer
(customer_id);
```

```sql
INDEX idx_certificate_verification_logs_certificate
(customer_id, certificate_id);
```

```sql
INDEX idx_certificate_verification_logs_code
(verification_code);
```

```sql
INDEX idx_certificate_verification_logs_result
(customer_id, result);
```

```sql
INDEX idx_certificate_verification_logs_verified_at
(customer_id, verified_at);
```

```sql
INDEX idx_certificate_verification_logs_ip
(ip_address);
```

---

## Sample Data

```text
id = 1

customer_id = 1

certificate_id = 100

user_id = NULL

verification_code = LF-CERT-9A8B7C6D

verification_url = https://kaha.learnforge.vn/certificates/verify/LF-CERT-9A8B7C6D

result = success

certificate_status_snapshot = issued

recipient_name_snapshot = Nguyen Van A

product_title_snapshot = TOPIK Beginner - July 2026

certificate_number_snapshot = TOPIK-2026-000001

verified_at = 2026-09-30 22:00:00

ip_address = 203.0.113.10

user_agent = Mozilla/5.0

referer = LinkedIn

country = Vietnam

city = Ho Chi Minh City
```

---

## Verification Flow

```text
User opens verification URL

↓

Read verification_code

↓

Find core_certificate_issued_certificates

↓

Check certificate status

↓

Check expires_at

↓

Check verification_enabled

↓

Return verification result

↓

Create core_certificate_verification_logs
```

---

## Result Logic

```text
No certificate found
↓
result = not_found
```

```text
Certificate status = revoked
↓
result = revoked
```

```text
Certificate expires_at < now
↓
result = expired
```

```text
Verification disabled
↓
result = disabled
```

```text
Certificate status = issued
and not expired
and verification enabled
↓
result = success
```

---

## Final Statement

`core_certificate_verification_logs` là bảng audit cho public certificate verification.

Vai trò đúng:

```text
Issued Certificate

↓

Public Verification

↓

Verification Log
```

Bảng này giúp LearnForge hỗ trợ xác thực chứng chỉ minh bạch, audit-friendly và phù hợp với nhu cầu Enterprise / HR / đối tác kiểm tra certificate.
