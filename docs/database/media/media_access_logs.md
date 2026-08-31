# Table: media_access_logs

Version: 1.3

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-08-31

Document Path: database/media/media_access_logs.md

## Purpose

Append-only audit log cho thao tác truy cập Media File.

## Relationships

`Media File 1 → N Access Logs`; `User 1 → N Access Logs`; mỗi Log thuộc Customer.

## Business Rules

* Log và Media File phải cùng tenant; `user_id` nullable cho system/guest policy hợp lệ.
* Allowed `action`: `upload`, `stream`, `view`, `download`, `delete`, `share`, `read_derived`.
* `source_type + source_id` là generic context, không hard FK sang Domain khác.
* Audit only; không dùng log để tính Course Progress, Attendance hoặc Assessment Result.
* Append-only khi có thể; privacy/retention policy áp dụng cho IP/User-Agent.
* Log không lưu full signed URL, signing query string, credential hoặc signing
  secret. Metadata chỉ chứa request/audit context an toàn.
* Append-only là ràng buộc vật lý, không phải quy ước: không UPDATE, không
  DELETE. Sửa sai bằng cách ghi bản ghi mới.
* Đọc output dẫn xuất qua Media Read Service cũng là truy cập và phải ghi log,
  với `action = 'read_derived'` và `source_type` là consumer đã gọi.
* Cả read được phép và read bị từ chối trên Media File resolve được đều ghi
  row; metadata mang `decision = allowed|denied` và error code nếu có.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| media_file_id | BIGINT UNSIGNED NOT NULL | Media File được truy cập. |
| user_id | BIGINT UNSIGNED NULL | User thực hiện. |
| action | VARCHAR(50) NOT NULL | Hành động audit. |
| source_type | VARCHAR(100) NULL | Loại context generic. |
| source_id | BIGINT UNSIGNED NULL | ID context generic. |
| ip_address | VARCHAR(45) NULL | IPv4/IPv6. |
| user_agent | TEXT NULL | User agent. |
| accessed_at | TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP | Event time. |
| metadata | JSON NULL | Request/audit metadata an toàn. |

## Constraints And Indexes

```sql
INDEX (customer_id, media_file_id, accessed_at);
INDEX (customer_id, user_id, accessed_at);
INDEX (customer_id, action, accessed_at);
INDEX (customer_id, source_type, source_id);

FOREIGN KEY (media_file_id, customer_id)
    REFERENCES media_files (id, customer_id) RESTRICT;
FOREIGN KEY (user_id, customer_id)
    REFERENCES users (id, customer_id) RESTRICT;

CHECK (action IN ('upload','stream','view','download','delete','share','read_derived'));
```

Append-only được thi hành bằng trigger `BEFORE UPDATE` và `BEFORE DELETE` cùng
kiểu với `trg_lrn_evidence_bu_immutable`; tên trigger chốt tại migration.

## Sample Data

`id=700, customer_id=1, media_file_id=100, user_id=100, action=stream, source_type=course_activity, source_id=9001, ip_address=203.0.113.10, accessed_at=2026-07-01T03:00:00Z`

## Design Notes

Behavior/progress analytics thuộc Track/Course Domain; Media log chỉ chứng minh access event.
---

## D1–D6 amendment — Approved 2026-08-31

Owner approval trong task Document Processing. D1: explicit DEFAULT CURRENT_TIMESTAMP; không backfill event time.

Migration forward mới sau review; preflight báo count và IDs vi phạm rồi abort, không tự fill/delete. Approval thiết kế không phải evidence schema đã deployed.
