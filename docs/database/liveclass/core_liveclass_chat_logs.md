# Table: core_liveclass_chat_logs

Document Path: database/liveclass/core_liveclass_chat_logs.md

## Cohort-Centered Amendment — 2026-07-25

Chat remains a Session child. Session now belongs directly to Cohort and Room
is optional. Chat never contributes directly to Course completion.

## Purpose

Lưu lịch sử chat trong LiveClass Session để phục vụ audit, replay, AI
transcript hoặc teacher review.

Chat Log là dữ liệu tương tác vận hành, không phải Course progress.

## Relationships

```text
Customer 1 → N LiveClass Chat Logs

LiveClass Session 1 → N LiveClass Chat Logs

LiveClass Room 1 → N LiveClass Chat Logs

User 1 → N LiveClass Chat Logs

Version Activity 1 → N LiveClass Chat Logs
```

## Business Rules

* Chat Log phải thuộc `customer_id`.
* Chat Log, Session và Room phải thuộc cùng tenant và cùng Course context.
* `version_activity_id` phải có `activity_type = live_class`.
* Với message do người dùng gửi, `user_id` phải thuộc cùng tenant và có quyền
  tham gia Session.
* `user_id` có thể `NULL` chỉ với `message_type = system`.
* `parent_message_id`, nếu có, phải tham chiếu message thuộc cùng
  `customer_id` và `session_id`.
* `is_deleted = true` nghĩa là message bị ẩn khỏi UI thông thường.
* Deleted message vẫn có thể được giữ cho audit nếu policy yêu cầu.
* Chat Log không được dùng để tính Course completion trực tiếp.
* File đính kèm không được lưu trong `message` hoặc `metadata`; file phải qua
  Media Domain và metadata chỉ lưu reference hợp lệ.
* Chat phải tuân theo retention, privacy và recording-consent policy của tenant.
* Không hard-delete riêng lẻ nếu bản ghi đang nằm trong legal/audit hold.
* Allowed `message_type`: `text`, `question`, `answer`, `system`, `file`.

## Fields

### id

```text
BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT
```

Khóa chính của Chat Log.

### customer_id

```text
BIGINT UNSIGNED NOT NULL
```

Tenant sở hữu Chat Log.

### session_id

```text
BIGINT UNSIGNED NOT NULL
```

Session phát sinh message.

### room_id

```text
BIGINT UNSIGNED NOT NULL
```

Room chứa Session.

### user_id

```text
BIGINT UNSIGNED NULL
```

User gửi message; chỉ `NULL` cho system message.

### parent_message_id

```text
BIGINT UNSIGNED NULL
```

Message cha trong cùng Session, hỗ trợ reply/thread.

### product_id

```text
BIGINT UNSIGNED NOT NULL
```

Course Product context của Session.

### template_version_id

```text
BIGINT UNSIGNED NOT NULL
```

Published Template Version context của Session.

### version_activity_id

```text
BIGINT UNSIGNED NOT NULL
```

Version Activity `live_class` liên quan.

### message_type

```text
VARCHAR(50) NOT NULL DEFAULT 'text'
```

Loại message đã chuẩn hóa.

### message

```text
TEXT NULL
```

Nội dung text của message. Có thể `NULL` với file message khi nội dung chính là
Media reference trong metadata.

### is_deleted

```text
BOOLEAN NOT NULL DEFAULT false
```

Cờ soft hide message khỏi UI thông thường mà vẫn có thể giữ record cho audit.

### sent_at

```text
TIMESTAMP NOT NULL
```

Thời điểm message được gửi theo event nguồn.

### provider_message_id

```text
VARCHAR(255) NULL
```

Định danh message tại provider, dùng cho idempotent import và đối soát.

### metadata

```text
JSON NULL
```

Metadata tương tác hoặc Media reference. Không lưu file binary, secret hoặc
Course completion state.

### created_at

```text
TIMESTAMP NULL
```

Thời điểm Chat Log được ghi vào LearnForge.

### updated_at

```text
TIMESTAMP NULL
```

Thời điểm cập nhật gần nhất; imported chat nên được xem là append-only khi có
thể.

## Indexes

```sql
INDEX (customer_id);

INDEX (customer_id, session_id);

INDEX (customer_id, user_id);

INDEX (customer_id, parent_message_id);

INDEX (customer_id, is_deleted);

INDEX (customer_id, version_activity_id);

INDEX (customer_id, sent_at);
```

Sau khi provider sync contract được xác nhận, nên cân nhắc uniqueness cho
`(customer_id, session_id, provider_message_id)` để import idempotent.

## Sample Data

```text
id = 6001
customer_id = 1
session_id = 2001
room_id = 1001
user_id = 100
parent_message_id = NULL
product_id = 10
template_version_id = 30
version_activity_id = 9003
message_type = question
message = Thầy có thể giải thích lại cấu trúc câu này không?
is_deleted = false
sent_at = 2026-07-04 06:35:12
provider_message_id = msg-abc-123
metadata = {"import_source": "zoom"}
```

## Design Notes

* `sent_at` là event time từ provider; `created_at` là ingestion time của
  LearnForge.
* AI summary hoặc teacher review là consumer; kết quả AI phải nằm trong AI
  Domain, không ghi đè Chat Log gốc.
* `is_deleted` là cờ hiển thị, không phải hard-delete hoặc trạng thái retention
  lifecycle.
* Chat retention policy cần xác định theo tenant, privacy, recording consent và
  yêu cầu pháp lý trước implementation.
