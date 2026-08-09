# Table: track_events

Document Path: database/track/track_events.md

## Purpose

Append-only event store cho learning behavior observations.

## Relationships

Mỗi Event thuộc Customer và Event Type; có thể thuộc User, Enrollment, Product,
Template Version, Version Activity và Learning Session. `source_type +
source_id` tham chiếu generic source record.

## Business Rules

* Append-only; correction phải là event mới.
* Không hard-delete ngoài retention/privacy policy đã approved.
* `customer_id` bắt buộc và mọi context reference phải cùng tenant.
* `source_domain`: `course`, `liveclass`, `assessment`, `media`,
  `certificate`, `ai`, `saas`.
* `event_code` snapshot từ Event Type để query nhanh và bảo toàn lịch sử.
* `event_uuid` là định danh bất biến của event.
* `idempotency_key` chống duplicate khi retry hoặc offline sync.
* Với cùng `customer_id + idempotency_key`, chỉ ingest một lần.
* Duplicate phải bị bỏ qua hoặc merge trước persistence theo ingestion policy;
  event đã persist không được update.
* `correlation_id` nhóm events trong cùng business flow.
* `causation_id` lưu `event_uuid` của event trực tiếp sinh event hiện tại.
* Event sai được sửa bằng Correction Event mới có `corrected_event_id`; không
  rewrite event history.
* `correction_reason` bắt buộc khi `corrected_event_id` có giá trị.
* Track Event là observation, không phải Course Progress, Assessment Result,
  Attendance, Processing State hoặc Certificate decision.
* `occurred_at` là event time; `received_at` là ingestion time.
* Metadata không chứa canonical business state của source Domain.
* IP/User-Agent phải tuân retention và privacy policy.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính, ordering kỹ thuật. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu event. |
| event_uuid | CHAR(36) NOT NULL | Định danh event bất biến. |
| idempotency_key | VARCHAR(255) NOT NULL | Retry/offline deduplication key. |
| correlation_id | CHAR(36) NULL | Business-flow correlation identifier. |
| causation_id | CHAR(36) NULL | Causing event UUID. |
| user_id | BIGINT UNSIGNED NULL | User tạo behavior khi xác định được. |
| enrollment_id | BIGINT UNSIGNED NULL | Learning cycle context. |
| product_id | BIGINT UNSIGNED NULL | Product context. |
| template_version_id | BIGINT UNSIGNED NULL | Published learning context. |
| version_activity_id | BIGINT UNSIGNED NULL | Version Activity context. |
| event_type_id | BIGINT UNSIGNED NOT NULL | Event taxonomy record. |
| event_code | VARCHAR(100) NOT NULL | Historical code snapshot. |
| source_domain | VARCHAR(50) NOT NULL | Emitting Domain. |
| source_type | VARCHAR(100) NOT NULL | Generic source entity type. |
| source_id | BIGINT UNSIGNED NOT NULL | Generic source record ID. |
| learning_session_id | BIGINT UNSIGNED NULL | Track learning session. |
| corrected_event_id | BIGINT UNSIGNED NULL | Event cũ được correction event này sửa. |
| correction_reason | TEXT NULL | Lý do correction. |
| occurred_at | TIMESTAMP(6) NOT NULL | Thời điểm behavior xảy ra. |
| received_at | TIMESTAMP(6) NOT NULL | Thời điểm Track tiếp nhận. |
| duration_ms | BIGINT UNSIGNED NULL | Duration observation nếu có. |
| device_type | VARCHAR(50) NULL | Device classification. |
| platform | VARCHAR(50) NULL | OS/client platform. |
| browser | VARCHAR(100) NULL | Browser/client family. |
| ip_address | VARCHAR(45) NULL | IPv4/IPv6 subject to policy. |
| user_agent | TEXT NULL | Raw user agent subject to policy. |
| metadata | JSON NULL | Event-specific non-canonical payload. |

## Indexes

```sql
INDEX (customer_id);
UNIQUE (customer_id, event_uuid);
UNIQUE (customer_id, idempotency_key);
INDEX (customer_id, correlation_id);
INDEX (customer_id, causation_id);
INDEX (customer_id, user_id, occurred_at);
INDEX (customer_id, enrollment_id, occurred_at);
INDEX (customer_id, version_activity_id, occurred_at);
INDEX (customer_id, event_code, occurred_at);
INDEX (customer_id, source_domain, source_type, source_id);
INDEX (customer_id, learning_session_id);
INDEX (customer_id, occurred_at);
```

## Sample Data

`id=10001, customer_id=1, event_uuid=018f4fd8-6dd0-7b10-a2f8-75e249b16121, idempotency_key=mobile-42-event-9001, correlation_id=018f4fd8-6cc0-7dc2-bc52-5fd329b8f350, user_id=100, enrollment_id=501, product_id=10, template_version_id=30, version_activity_id=9001, event_type_id=5, event_code=video_played, source_domain=media, source_type=media_file, source_id=700, learning_session_id=2001, occurred_at=2026-06-27T02:00:00.000000Z, received_at=2026-06-27T02:00:00.150000Z, device_type=desktop`

## Design Notes

Event identity, idempotency, correlation, causation và correction linkage là P1
contracts. Offline ordering và partitioning vẫn cần owner policy.

Privacy Principle: IP Address, User Agent và Device Metadata có thể được
anonymize, hash hoặc purge theo retention/privacy policy. Foundation không yêu
cầu lưu raw technical metadata mãi mãi.
