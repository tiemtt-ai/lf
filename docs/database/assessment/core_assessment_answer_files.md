# Table: core_assessment_answer_files

## Purpose

Liên kết file bài làm của Student với Answer.

## Relationships

`Answer 1 → N Answer Files`; `Media File 1 → N Answer Files`.

## Business Rules

* Answer và Media File phải cùng tenant.
* Allowed `file_role`: `uploaded_answer`, `speaking_recording`, `essay_attachment`, `evidence`.
* Binary/storage/delivery/processing thuộc Media Domain; Assessment chỉ lưu reference.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu mapping. |
| answer_id | BIGINT UNSIGNED NOT NULL | Answer cha. |
| media_file_id | BIGINT UNSIGNED NOT NULL | `media_files` reference. |
| file_role | VARCHAR(50) NOT NULL | Vai trò file. |
| metadata | JSON NULL | Caption/audit metadata. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, answer_id);
INDEX (customer_id, media_file_id);
INDEX (customer_id, file_role);
UNIQUE (customer_id, answer_id, media_file_id, file_role);
```

## Sample Data

`id=3200, customer_id=1, answer_id=3101, media_file_id=7100, file_role=speaking_recording`

## Design Notes

Signed delivery, retention and transcript processing remain Media responsibilities.
