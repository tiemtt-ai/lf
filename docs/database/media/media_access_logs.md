# Table: media_access_logs

Version: 1.4

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-09-13

Document Path: database/media/media_access_logs.md

## Retrieval audit amendment — Owner approved 2026-09-13

Step 5 re-reading AI chunks derived from Media is an access event. AI calls a
Media-owned audit service; it does not write the audit table directly. Each
returned chunk, and each otherwise eligible candidate denied by owner-context,
active usage or current-revision validation, appends `read_derived`, consumer `ai`, with retrieval UUID,
chunk/source identifiers, locator, revision and allowed/denied decision.
Audit insert failure aborts retrieval before returning content. Audit is not
atomic across one retrieval: `allowed` rows already appended for earlier hits
under the same retrieval UUID remain when a later append fails, although no
content is returned. Evidence may therefore overstate disclosure for that
retrieval UUID but never understate it; an `allowed` row records authorization
to disclose, not confirmed delivery. No raw text,
query, vectors, credentials or signed URL enters audit metadata. Foreign-tenant
index hits are excluded before auditing; empty/unconfigured searches access no
Media content and create no Media access event. Actor and Media references are
resolved in the current tenant. Existing processing/read consumers keep their observable behaviour. One guard
sits in the Media Read selector shared by `read()` and retrieval revalidation:
a Media File with `status = deleted` resolves as `missing`. At code level it
applies to every consumer, but canonical flows cannot reach it — `MediaService`
refuses to delete a Media File while any usage is `active` — so it defends
against non-canonical writes rather than changing canonical behaviour.
Denials preserve the Media Read error code (including `detached`, `missing`,
`ambiguous_source` and `revision_mismatch`), rather than relabeling every
failure `unauthorized`. An identity-only internal Media check is not a separate
content delivery; the final retrieval decision is audited by this service.

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
