# core_certificate_download_logs

## Purpose

Lưu lịch sử truy cập và tải xuống chứng chỉ.

Bảng này phục vụ:

```text
Audit

Usage Analytics

Security Tracking

Certificate Activity History
```

Bảng trả lời các câu hỏi:

```text
Ai đã xem chứng chỉ?

Ai đã tải PDF chứng chỉ?

Ai đã in chứng chỉ?

Ai đã chia sẻ chứng chỉ?

Chứng chỉ nào được tải nhiều nhất?

Có hành vi bất thường không?
```

---

## Relationships

```text
saas_customers

1

↓

N

core_certificate_download_logs
```

```text
core_certificate_issued_certificates

1

↓

N

core_certificate_download_logs
```

```text
users

1

↓

N

core_certificate_download_logs
```

---

## Business Rules

* Mọi log phải thuộc `customer_id`.
* Một log luôn thuộc một `certificate_id`.
* Một certificate có thể có nhiều activity logs.
* User có thể NULL nếu truy cập công khai.
* Không được cập nhật hoặc chỉnh sửa log sau khi tạo.
* Không lưu file chứng chỉ trong bảng này.
* Chỉ lưu lịch sử hành vi sử dụng chứng chỉ.

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

Tenant sở hữu dữ liệu.

---

## Certificate Reference

### certificate_id

```text
BIGINT UNSIGNED
NOT NULL
```

Liên kết:

```text
core_certificate_issued_certificates.id
```

---

## User Reference

### user_id

```text
BIGINT UNSIGNED
NULL
```

Người thực hiện hành động.

Liên kết:

```text
users.id
```

NULL nếu truy cập công khai.

---

## Activity

### action

```text
VARCHAR(50)
NOT NULL
```

Loại hành động.

Allowed values:

```text
view

download_pdf

download_image

download_original

print

share
```

---

### source

```text
VARCHAR(50)
NOT NULL
DEFAULT 'web'
```

Nguồn phát sinh.

Allowed values:

```text
web

mobile

api

public_verify
```

---

## Request Information

### ip_address

```text
VARCHAR(100)
NULL
```

Địa chỉ IP truy cập.

---

### user_agent

```text
VARCHAR(1000)
NULL
```

Thông tin trình duyệt hoặc thiết bị.

---

### referer_url

```text
VARCHAR(1000)
NULL
```

Nguồn truy cập.

Ví dụ:

```text
Website

Email

QR Verify Page

External Portal
```

---

## Location Information

### country

```text
VARCHAR(100)
NULL
```

Quốc gia truy cập.

---

### city

```text
VARCHAR(100)
NULL
```

Thành phố truy cập.

---

## Activity Time

### activity_at

```text
TIMESTAMP
NOT NULL
```

Thời điểm phát sinh hành động.

---

## Metadata

### metadata

```text
JSON NULL
```

Dữ liệu mở rộng.

Ví dụ:

```json
{
  "file_type": "pdf",
  "device": "desktop",
  "browser": "Chrome",
  "os": "macOS"
}
```

---

## Audit Fields

### created_at

```text
TIMESTAMP NULL
```

Thời điểm ghi log.

---

# Indexes

```sql
INDEX idx_certificate_download_logs_customer
(customer_id);
```

```sql
INDEX idx_certificate_download_logs_certificate
(customer_id, certificate_id);
```

```sql
INDEX idx_certificate_download_logs_user
(customer_id, user_id);
```

```sql
INDEX idx_certificate_download_logs_action
(customer_id, action);
```

```sql
INDEX idx_certificate_download_logs_source
(customer_id, source);
```

```sql
INDEX idx_certificate_download_logs_activity_at
(customer_id, activity_at);
```

---

# Sample Data

```text
id = 1

customer_id = 1

certificate_id = 100

user_id = 200

action = download_pdf

source = web

ip_address = 14.161.xxx.xxx

country = Vietnam

city = Hanoi

activity_at = 2026-09-30 21:30:00
```

---

# Activity Flow

```text
Student opens certificate

↓

view

↓

Student downloads PDF

↓

download_pdf

↓

Student prints certificate

↓

print

↓

Create Log
```

---

# Analytics Examples

## Total Certificate Downloads

```sql
SELECT COUNT(*)
FROM core_certificate_download_logs
WHERE action = 'download_pdf';
```

---

## Most Downloaded Certificates

```sql
SELECT
    certificate_id,
    COUNT(*) AS total_downloads
FROM core_certificate_download_logs
WHERE action = 'download_pdf'
GROUP BY certificate_id;
```

---

## Public Verification Traffic

```sql
SELECT *
FROM core_certificate_download_logs
WHERE source = 'public_verify';
```

---

# Final Statement

`core_certificate_download_logs` lưu toàn bộ lịch sử truy cập và sử dụng chứng chỉ sau khi được cấp.

Vai trò:

```text
Issued Certificate

↓

View

Download

Print

Share

↓

Audit Logs

↓

Usage Analytics
```

Bảng này giúp LearnForge theo dõi vòng đời sử dụng chứng chỉ, hỗ trợ Audit, Analytics và Security Monitoring trong môi trường Multi-Tenant.
